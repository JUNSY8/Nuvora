<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import CustomerPicker from '@/modules/customers/CustomerPicker.vue'
import type { CustomerSummary } from '@/modules/customers/types'
import { checkPlate, saveVehicle } from '@/modules/vehicles/api'
import { plateCountries, relationship, relationshipOrder, vehicleStatus, vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import type { PlateCheck, Vehicle, VehicleInput, VehicleRelationship } from '@/modules/vehicles/types'
import { parseApiError } from '@/services/http'

const props = withDefaults(defineProps<{
  open: boolean
  vehicle?: Vehicle | null
  presetCustomer?: CustomerSummary | null
}>(), {
  vehicle: null,
  presetCustomer: null,
})

const emit = defineEmits<{ close: []; saved: [vehicle: Vehicle] }>()
const { can } = usePermissions()

const form = ref<VehicleInput>(blank())
const owner = ref<CustomerSummary | null>(null)
const ownerRelationship = ref<VehicleRelationship>('owner')
const typeTouched = ref(false)
const saving = ref(false)
const errors = useFieldErrors()

const plateQuery = computed(() => `${form.value.country}|${form.value.plate}`)
const debouncedPlate = refDebounced(plateQuery, 350)
const check = ref<PlateCheck | null>(null)
const checkError = ref('')

const isEdit = computed(() => props.vehicle !== null)
const duplicate = computed(() => (check.value?.existing && check.value.existing.id !== props.vehicle?.id ? check.value.existing : null))
const canPickCustomer = computed(() => !isEdit.value && can('customers.view'))
const currentYear = new Date().getFullYear()

function blank(): VehicleInput {
  return { plate: '', country: 'CO', type: 'car', brand: null, model: null, color: null, year: null, is_electric: false, notes: null }
}

function reset(): void {
  const vehicle = props.vehicle
  form.value = vehicle
    ? {
        plate: vehicle.plate,
        country: vehicle.country,
        type: vehicle.type,
        brand: vehicle.brand,
        model: vehicle.model,
        color: vehicle.color,
        year: vehicle.year,
        is_electric: vehicle.is_electric,
        notes: vehicle.notes,
      }
    : blank()
  owner.value = props.presetCustomer
  ownerRelationship.value = 'owner'
  typeTouched.value = vehicle !== null
  check.value = null
  checkError.value = ''
  errors.clear()
}

async function runCheck(): Promise<void> {
  const plate = form.value.plate.trim()

  if (plate.replace(/[^a-z0-9]/gi, '').length < 2) {
    check.value = null
    checkError.value = ''
    return
  }

  try {
    check.value = await checkPlate(plate, form.value.country)
    checkError.value = ''

    if (!typeTouched.value && check.value.suggested_type) {
      form.value.type = check.value.suggested_type
    }
  } catch (error) {
    check.value = null
    checkError.value = parseApiError(error, 'La placa no es válida.')
  }
}

async function onSave(): Promise<void> {
  saving.value = true
  errors.clear()
  const clean = (value: string | null) => (value === null || value.trim() === '' ? null : value.trim())

  try {
    const saved = await saveVehicle(props.vehicle?.id ?? null, {
      ...form.value,
      brand: clean(form.value.brand),
      model: clean(form.value.model),
      color: clean(form.value.color),
      notes: clean(form.value.notes),
      year: form.value.year === null || String(form.value.year) === '' ? null : Number(form.value.year),
      ...(isEdit.value ? {} : { customer_id: owner.value?.id ?? null, relationship: ownerRelationship.value }),
    })
    emit('saved', saved)
  } catch (error) {
    errors.capture(error, 'No fue posible guardar el vehículo.')
  } finally {
    saving.value = false
  }
}

watch(() => props.open, (open) => {
  if (open) {
    reset()
  }
}, { immediate: true })

watch(debouncedPlate, () => {
  if (props.open) {
    void runCheck()
  }
})
</script>

<template>
  <BaseModal :open="open" :title="isEdit ? 'Editar vehículo' : 'Registrar vehículo'" size="lg" @close="emit('close')">
    <form novalidate @submit.prevent="onSave">
      <div class="vz-card-body grid gap-4 sm:grid-cols-6">
        <div class="sm:col-span-2">
          <label class="vz-label" for="vehicle-country">País de la placa</label>
          <select id="vehicle-country" v-model="form.country" class="vz-input" :aria-invalid="!!errors.fields.value.country">
            <option v-for="country in plateCountries" :key="country.code" :value="country.code">{{ country.name }}</option>
          </select>
          <p v-if="errors.fields.value.country" class="vz-error" role="alert">{{ errors.fields.value.country }}</p>
        </div>
        <div class="sm:col-span-4">
          <label class="vz-label" for="vehicle-plate">Placa</label>
          <input
            id="vehicle-plate"
            v-model="form.plate"
            class="vz-input font-mono text-base tracking-widest uppercase"
            type="text"
            maxlength="20"
            required
            autofocus
            autocomplete="off"
            placeholder="ABC123"
            :aria-invalid="!!errors.fields.value.plate || !!checkError || !!duplicate"
            aria-describedby="vehicle-plate-help"
          />
          <div id="vehicle-plate-help" aria-live="polite">
            <p v-if="errors.fields.value.plate" class="vz-error" role="alert">{{ errors.fields.value.plate }}</p>
            <p v-else-if="checkError" class="vz-error">{{ checkError }}</p>
            <p v-else-if="duplicate" class="vz-error">
              Ya está registrada ({{ vehicleStatus[duplicate.status].label.toLowerCase() }}).
              <RouterLink class="underline" :to="{ name: 'vehicle', params: { vehicleId: duplicate.id } }" @click="emit('close')">Ver vehículo</RouterLink>
            </p>
            <p v-else-if="check" class="vz-help">
              Se guardará como <span class="font-mono font-semibold text-vz-heading">{{ check.plate }}</span>
              <template v-if="check.format"> · <i class="ri-checkbox-circle-line text-vz-success" aria-hidden="true" /> {{ check.format.label }}</template>
              <template v-else-if="check.rules_available"> · <i class="ri-error-warning-line text-vz-warning" aria-hidden="true" /> No coincide con un formato conocido; revísala.</template>
            </p>
            <p v-else class="vz-help">Mayúsculas, espacios y guiones no importan: abc-123 y ABC 123 son la misma placa.</p>
          </div>
        </div>

        <div class="sm:col-span-3">
          <label class="vz-label" for="vehicle-type">Tipo</label>
          <select id="vehicle-type" v-model="form.type" class="vz-input" @change="typeTouched = true">
            <option v-for="type in vehicleTypeOrder" :key="type" :value="type">{{ vehicleType[type].label }}</option>
          </select>
        </div>
        <div class="sm:col-span-3">
          <label class="vz-label" for="vehicle-year">Año (modelo)</label>
          <input id="vehicle-year" v-model.number="form.year" class="vz-input" type="number" min="1900" :max="currentYear + 1" inputmode="numeric" placeholder="2022" :aria-invalid="!!errors.fields.value.year" />
          <p v-if="errors.fields.value.year" class="vz-error" role="alert">{{ errors.fields.value.year }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="vz-label" for="vehicle-brand">Marca</label>
          <input id="vehicle-brand" v-model="form.brand" class="vz-input" type="text" maxlength="60" placeholder="Mazda" />
        </div>
        <div class="sm:col-span-2">
          <label class="vz-label" for="vehicle-model">Línea</label>
          <input id="vehicle-model" v-model="form.model" class="vz-input" type="text" maxlength="60" placeholder="CX-30" />
        </div>
        <div class="sm:col-span-2">
          <label class="vz-label" for="vehicle-color">Color</label>
          <input id="vehicle-color" v-model="form.color" class="vz-input" type="text" maxlength="40" placeholder="Rojo" />
        </div>
        <label class="flex items-center gap-2 text-[13px] sm:col-span-6">
          <input v-model="form.is_electric" class="size-4 accent-vz-primary" type="checkbox" />
          <i class="ri-charging-pile-2-line text-vz-success" aria-hidden="true" /> Vehículo eléctrico (puede usar espacios de carga)
        </label>

        <template v-if="canPickCustomer">
          <div class="sm:col-span-4">
            <CustomerPicker v-model="owner" label="Cliente (opcional)" :invalid="!!errors.fields.value.customer_id" />
            <p v-if="errors.fields.value.customer_id" class="vz-error" role="alert">{{ errors.fields.value.customer_id }}</p>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="vehicle-relationship">Relación</label>
            <select id="vehicle-relationship" v-model="ownerRelationship" class="vz-input" :disabled="owner === null">
              <option v-for="key in relationshipOrder" :key="key" :value="key">{{ relationship[key].label }}</option>
            </select>
          </div>
        </template>
        <p v-else-if="!isEdit && presetCustomer" class="rounded-vz border border-vz-border bg-vz-elevated px-3 py-2 text-[13px] sm:col-span-6">
          <i class="ri-key-2-line text-vz-primary-text" aria-hidden="true" /> Quedará a nombre de <span class="font-medium text-vz-heading">{{ presetCustomer.name }}</span> como propietario.
        </p>

        <div class="sm:col-span-6">
          <label class="vz-label" for="vehicle-notes">Notas internas</label>
          <textarea id="vehicle-notes" v-model="form.notes" class="vz-input min-h-16" maxlength="1000" rows="2" placeholder="Rayón en la puerta trasera, usa portabicicletas…" />
        </div>
        <p v-if="errors.general.value && !errors.fields.value.plate" class="text-[13px] text-vz-danger sm:col-span-6" role="alert">{{ errors.general.value }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving || !!duplicate">{{ saving ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Registrar' }}</button>
      </div>
    </form>
  </BaseModal>
</template>
