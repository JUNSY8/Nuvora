<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import type { ParkingLot } from '@/modules/parking/types'
import { saveDiscount } from '@/modules/tariffs/api'
import { discountApplication, discountType, weekDays } from '@/modules/tariffs/labels'
import type { Discount, DiscountApplication, DiscountInput, DiscountType } from '@/modules/tariffs/types'
import { vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import type { VehicleType } from '@/modules/vehicles/types'
import { toMajorInput, toMinorUnits } from '@/services/money'

const props = defineProps<{
  open: boolean
  lot: ParkingLot
  discount: Discount | null
}>()

const emit = defineEmits<{ close: []; saved: [discount: Discount] }>()

interface FormState {
  only_this_lot: boolean
  code: string
  name: string
  type: DiscountType
  value: string
  application: DiscountApplication
  vehicle_types: VehicleType[]
  days: number[]
  valid_from: string
  valid_until: string
}

const form = ref<FormState>(blank())
const saving = ref(false)
const errors = useFieldErrors()
const isEdit = computed(() => props.discount !== null)
const valueLabel = computed(() => ({ percentage: 'Porcentaje (%)', fixed_amount: `Valor (${props.lot.currency})`, free_minutes: 'Minutos gratis' })[form.value.type])

function blank(): FormState {
  return { only_this_lot: true, code: '', name: '', type: 'percentage', value: '', application: 'manual', vehicle_types: [], days: [], valid_from: '', valid_until: '' }
}

function reset(): void {
  const discount = props.discount
  errors.clear()

  if (!discount) {
    form.value = blank()
    return
  }

  const value = discount.type === 'percentage'
    ? String(discount.value / 100)
    : discount.type === 'fixed_amount' ? toMajorInput(discount.value, discount.currency ?? props.lot.currency) : String(discount.value)

  form.value = {
    only_this_lot: discount.parking_lot_id !== null,
    code: discount.code,
    name: discount.name,
    type: discount.type,
    value,
    application: discount.application,
    vehicle_types: [...discount.vehicle_types],
    days: [...discount.days],
    valid_from: discount.valid_from ?? '',
    valid_until: discount.valid_until ?? '',
  }
}

function toggle<T>(list: T[], value: T): void {
  const index = list.indexOf(value)

  if (index === -1) {
    list.push(value)
  } else {
    list.splice(index, 1)
  }
}

// Percentages travel as basis points, fixed amounts as minor units.
function parsedValue(): number | null {
  const text = form.value.value.trim().replace(',', '.')

  switch (form.value.type) {
    case 'percentage': {
      const percent = Number(text)

      return text !== '' && Number.isFinite(percent) ? Math.round(percent * 100) : null
    }
    case 'fixed_amount':
      return toMinorUnits(form.value.value, props.lot.currency)
    case 'free_minutes':
      return /^\d+$/.test(text) ? Number(text) : null
  }
}

async function onSave(): Promise<void> {
  errors.clear()
  const value = parsedValue()

  if (value === null || value <= 0) {
    errors.fields.value = { value: 'Escribe un valor válido mayor que cero.' }
    errors.general.value = 'Revisa los campos marcados.'

    return
  }

  const input: DiscountInput = {
    parking_lot_id: form.value.only_this_lot ? props.lot.id : null,
    code: form.value.code.trim(),
    name: form.value.name.trim(),
    type: form.value.type,
    value,
    // A fixed amount for every lot takes the currency of the lot it is created from.
    currency: form.value.type === 'fixed_amount' ? props.lot.currency : null,
    application: form.value.application,
    vehicle_types: vehicleTypeOrder.filter((type) => form.value.vehicle_types.includes(type)),
    days: [...form.value.days].sort((a, b) => a - b),
    valid_from: form.value.valid_from || null,
    valid_until: form.value.valid_until || null,
  }

  saving.value = true

  try {
    emit('saved', await saveDiscount(props.discount?.id ?? null, input))
  } catch (error) {
    errors.capture(error, 'No fue posible guardar el descuento.')
  } finally {
    saving.value = false
  }
}

watch(() => props.open, (open) => {
  if (open) {
    reset()
  }
}, { immediate: true })
</script>

<template>
  <BaseModal :open="open" :title="isEdit ? `Editar ${discount?.name}` : 'Nuevo descuento o promoción'" size="lg" @close="emit('close')">
    <form novalidate @submit.prevent="onSave">
      <div class="vz-card-body grid max-h-[70vh] gap-4 overflow-y-auto sm:grid-cols-6">
        <fieldset class="sm:col-span-6">
          <legend class="vz-label">Cómo se aplica</legend>
          <div class="grid gap-2 sm:grid-cols-2">
            <label
              v-for="(meta, key) in discountApplication"
              :key="key"
              class="flex cursor-pointer gap-3 rounded-vz border p-3 text-[13px]"
              :class="form.application === key ? 'border-vz-primary bg-vz-primary/10' : 'border-vz-border'"
            >
              <input v-model="form.application" class="mt-0.5 size-4 accent-vz-primary" type="radio" name="discount-application" :value="key" />
              <span>
                <span class="block font-medium text-vz-heading">{{ meta.label }}</span>
                <span class="text-vz-muted">{{ meta.help }}</span>
              </span>
            </label>
          </div>
        </fieldset>

        <div class="sm:col-span-4">
          <label class="vz-label" for="discount-name">Nombre</label>
          <input id="discount-name" v-model="form.name" class="vz-input" type="text" maxlength="80" required placeholder="Convenio cine" :aria-invalid="!!errors.fields.value.name" />
          <p v-if="errors.fields.value.name" class="vz-error" role="alert">{{ errors.fields.value.name }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="vz-label" for="discount-code">Código</label>
          <input id="discount-code" v-model="form.code" class="vz-input font-mono uppercase" type="text" maxlength="40" required placeholder="CINE" :aria-invalid="!!errors.fields.value.code" />
          <p v-if="errors.fields.value.code" class="vz-error" role="alert">{{ errors.fields.value.code }}</p>
        </div>

        <div class="sm:col-span-3">
          <label class="vz-label" for="discount-type">Tipo</label>
          <select id="discount-type" v-model="form.type" class="vz-input">
            <option v-for="(meta, key) in discountType" :key="key" :value="key">{{ meta.label }}</option>
          </select>
        </div>
        <div class="sm:col-span-3">
          <label class="vz-label" for="discount-value">{{ valueLabel }}</label>
          <input id="discount-value" v-model="form.value" class="vz-input" type="text" inputmode="decimal" required :aria-invalid="!!errors.fields.value.value" />
          <p v-if="errors.fields.value.value" class="vz-error" role="alert">{{ errors.fields.value.value }}</p>
          <p v-else-if="form.type === 'free_minutes'" class="vz-help">Se descuentan del tiempo cobrable antes de aplicar la tarifa.</p>
        </div>

        <label class="flex items-center gap-2 text-[13px] sm:col-span-6">
          <input v-model="form.only_this_lot" class="size-4 accent-vz-primary" type="checkbox" />
          Solo en {{ lot.name }} <span class="text-vz-muted">(sin marcar aplica en todos los parqueaderos de la empresa)</span>
        </label>

        <fieldset class="sm:col-span-6">
          <legend class="vz-label">Vehículos</legend>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="type in vehicleTypeOrder"
              :key="type"
              type="button"
              class="vz-btn vz-btn-sm"
              :class="form.vehicle_types.includes(type) ? 'vz-btn-primary' : 'vz-btn-light'"
              :aria-pressed="form.vehicle_types.includes(type)"
              @click="toggle(form.vehicle_types, type)"
            >
              <i :class="vehicleType[type].icon" aria-hidden="true" /> {{ vehicleType[type].label }}
            </button>
          </div>
          <p class="vz-help">Sin selección aplica a todos.</p>
        </fieldset>

        <fieldset class="sm:col-span-6">
          <legend class="vz-label">Días de la semana (de la salida)</legend>
          <div class="flex gap-1">
            <button
              v-for="day in weekDays"
              :key="day.day"
              type="button"
              class="size-9 rounded-vz text-xs font-semibold"
              :class="form.days.includes(day.day) ? 'bg-vz-primary text-white' : 'bg-vz-elevated text-vz-muted'"
              :aria-pressed="form.days.includes(day.day)"
              :aria-label="day.name"
              @click="toggle(form.days, day.day)"
            >
              {{ day.short }}
            </button>
          </div>
          <p class="vz-help">Sin selección aplica todos los días.</p>
        </fieldset>

        <div class="sm:col-span-3">
          <label class="vz-label" for="discount-from">Vigente desde</label>
          <input id="discount-from" v-model="form.valid_from" class="vz-input" type="date" />
        </div>
        <div class="sm:col-span-3">
          <label class="vz-label" for="discount-until">Vigente hasta</label>
          <input id="discount-until" v-model="form.valid_until" class="vz-input" type="date" :aria-invalid="!!errors.fields.value.valid_until" />
          <p v-if="errors.fields.value.valid_until" class="vz-error" role="alert">{{ errors.fields.value.valid_until }}</p>
        </div>

        <p v-if="errors.general.value" class="text-[13px] text-vz-danger sm:col-span-6" role="alert">{{ errors.general.value }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Crear' }}</button>
      </div>
    </form>
  </BaseModal>
</template>
