<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import CustomerPicker from '@/modules/customers/CustomerPicker.vue'
import type { CustomerSummary } from '@/modules/customers/types'
import { sellMembership } from '@/modules/memberships/api'
import { billingPeriod, describeAccess, describePrice, planCategory } from '@/modules/memberships/labels'
import type { Membership, MembershipPlan } from '@/modules/memberships/types'
import { listSpaces } from '@/modules/parking/api'
import type { ParkingLot, ParkingSpace, ParkingZone } from '@/modules/parking/types'
import { plateCountries, vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import type { VehicleType } from '@/modules/vehicles/types'

const props = defineProps<{
  open: boolean
  plans: MembershipPlan[]
  lots: ParkingLot[]
  zones: ParkingZone[]
  today: string
}>()

const emit = defineEmits<{ close: []; saved: [membership: Membership] }>()

interface VehicleRow { plate: string; country: string; vehicle_type: VehicleType }
interface FormState {
  plan_id: string
  customer: CustomerSummary | null
  starts_on: string
  vehicles: VehicleRow[]
  lot_id: string
  space_id: string
  auto_renew: boolean
  notes: string
}

const form = ref<FormState>(blank())
const saving = ref(false)
const errors = useFieldErrors()
const spaces = ref<ParkingSpace[]>([])
const loadingSpaces = ref(false)
let spacesRequest = 0

const offered = computed(() => props.plans.filter((plan) => plan.status === 'active'))
const plan = computed(() => offered.value.find((item) => item.id === form.value.plan_id) ?? null)
const full = computed(() => plan.value !== null && plan.value.capacity !== null && (plan.value.sold ?? 0) >= plan.value.capacity)
// Lots where the plan lets the member park; a space can only be assigned in them.
const planLots = computed(() => props.lots.filter((lot) => !plan.value || plan.value.parking_lot_ids.length === 0 || plan.value.parking_lot_ids.includes(lot.id)))
const assignable = computed(() => spaces.value.filter((space) => space.is_assignable
  && (!plan.value || plan.value.zone_ids.length === 0 || (space.zone !== null && plan.value.zone_ids.includes(space.zone.id)))))
const access = computed(() => plan.value
  ? describeAccess(plan.value, (id) => props.lots.find((lot) => lot.id === id)?.name ?? 'Parqueadero', (id) => props.zones.find((zone) => zone.id === id)?.name ?? 'Zona')
  : [])

function blankVehicle(): VehicleRow {
  return { plate: '', country: 'CO', vehicle_type: 'car' }
}

function blank(): FormState {
  return {
    plan_id: '',
    customer: null,
    starts_on: props.today,
    vehicles: [blankVehicle()],
    lot_id: '',
    space_id: '',
    auto_renew: true,
    notes: '',
  }
}

async function loadSpaces(lotId: string): Promise<void> {
  const current = ++spacesRequest
  spaces.value = []

  if (lotId === '') {
    return
  }

  loadingSpaces.value = true

  try {
    const page = await listSpaces(lotId, {})

    if (current === spacesRequest) {
      spaces.value = page.items
    }
  } catch {
    if (current === spacesRequest) {
      spaces.value = []
    }
  } finally {
    if (current === spacesRequest) {
      loadingSpaces.value = false
    }
  }
}

function vehicleError(index: number): string | undefined {
  return errors.fields.value[`vehicles.${index}.plate`] ?? errors.fields.value[`vehicles.${index}.country`]
}

async function onSave(): Promise<void> {
  if (saving.value) {
    return
  }

  saving.value = true
  errors.clear()

  try {
    const membership = await sellMembership({
      plan_id: form.value.plan_id,
      customer_id: form.value.customer?.id ?? '',
      starts_on: form.value.starts_on,
      vehicles: form.value.vehicles
        .filter((vehicle) => vehicle.plate.trim() !== '' || form.value.vehicles.length === 1)
        .map((vehicle) => ({ plate: vehicle.plate.trim(), country: vehicle.country, vehicle_type: vehicle.vehicle_type })),
      assigned_lot_id: form.value.space_id === '' ? null : form.value.lot_id,
      assigned_space_id: form.value.space_id === '' ? null : form.value.space_id,
      auto_renew: form.value.auto_renew,
      notes: form.value.notes.trim() === '' ? null : form.value.notes.trim(),
    })
    emit('saved', membership)
  } catch (error) {
    errors.capture(error, 'No fue posible vender la mensualidad.')
  } finally {
    saving.value = false
  }
}

watch(() => props.open, (open) => {
  if (open) {
    form.value = blank()
    form.value.plan_id = offered.value.length === 1 ? offered.value[0]!.id : ''
    spaces.value = []
    errors.clear()
  }
}, { immediate: true })

// Another plan may cover fewer vehicles or other lots.
watch(plan, (current) => {
  if (current && form.value.vehicles.length > current.max_vehicles) {
    form.value.vehicles.splice(current.max_vehicles)
  }

  if (form.value.lot_id !== '' && !planLots.value.some((lot) => lot.id === form.value.lot_id)) {
    form.value.lot_id = ''
  }

  form.value.space_id = ''
})

watch(() => form.value.lot_id, (lotId) => {
  form.value.space_id = ''
  void loadSpaces(lotId)
})
</script>

<template>
  <BaseModal :open="open" title="Vender mensualidad" size="lg" @close="emit('close')">
    <form novalidate @submit.prevent="onSave">
      <div class="vz-card-body grid max-h-[70vh] gap-4 overflow-y-auto sm:grid-cols-6">
        <div class="sm:col-span-4">
          <label class="vz-label" for="membership-plan">Plan</label>
          <select id="membership-plan" v-model="form.plan_id" class="vz-input" required :aria-invalid="!!errors.fields.value.plan_id">
            <option value="" disabled>{{ offered.length === 0 ? 'No hay planes activos' : 'Elige un plan' }}</option>
            <option v-for="item in offered" :key="item.id" :value="item.id">{{ item.name }} · {{ describePrice(item) }}</option>
          </select>
          <p v-if="errors.fields.value.plan_id" class="vz-error" role="alert">{{ errors.fields.value.plan_id }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="vz-label" for="membership-start">Inicio</label>
          <input id="membership-start" v-model="form.starts_on" class="vz-input" type="date" required :aria-invalid="!!errors.fields.value.starts_on" />
          <p v-if="errors.fields.value.starts_on" class="vz-error" role="alert">{{ errors.fields.value.starts_on }}</p>
        </div>

        <div v-if="plan" class="rounded-vz border border-vz-border bg-vz-elevated/40 p-3 text-[13px] sm:col-span-6">
          <p class="font-medium text-vz-heading">
            <i :class="planCategory[plan.category].icon" aria-hidden="true" /> {{ planCategory[plan.category].label }} · {{ describePrice(plan) }}
          </p>
          <ul class="mt-1 space-y-0.5 text-vz-muted">
            <li v-for="line in access" :key="line">{{ line }}</li>
            <li>Hasta {{ plan.max_vehicles }} vehículo(s) · {{ plan.grace_days }} día(s) de gracia · periodo {{ billingPeriod[plan.billing_period].label.toLowerCase() }}</li>
          </ul>
          <p v-if="full" class="mt-1 text-vz-danger-text" role="alert"><i class="ri-error-warning-line" aria-hidden="true" /> El plan no tiene cupos: {{ plan.sold }} de {{ plan.capacity }} vendidos.</p>
          <p v-else-if="plan.capacity !== null" class="mt-1 text-xs text-vz-muted">{{ plan.capacity - (plan.sold ?? 0) }} cupo(s) disponibles de {{ plan.capacity }}.</p>
        </div>

        <div class="sm:col-span-6">
          <CustomerPicker v-model="form.customer" label="Cliente titular" :invalid="!!errors.fields.value.customer_id" />
          <p v-if="errors.fields.value.customer_id" class="vz-error" role="alert">{{ errors.fields.value.customer_id }}</p>
        </div>

        <fieldset class="sm:col-span-6">
          <div class="flex items-center justify-between gap-2">
            <legend class="vz-label">Vehículos</legend>
            <button
              class="vz-btn vz-btn-sm vz-btn-soft-primary"
              type="button"
              :disabled="form.vehicles.length >= (plan?.max_vehicles ?? 1)"
              @click="form.vehicles.push(blankVehicle())"
            >
              <i class="ri-add-line" aria-hidden="true" /> Agregar vehículo
            </button>
          </div>
          <p class="vz-help">Las placas nuevas se registran como vehículos. Un vehículo solo puede estar en una mensualidad vigente.</p>
          <ul class="mt-2 space-y-2">
            <li v-for="(vehicle, index) in form.vehicles" :key="index" class="flex flex-wrap items-center gap-2">
              <label class="sr-only" :for="`membership-country-${index}`">País de la placa {{ index + 1 }}</label>
              <select :id="`membership-country-${index}`" v-model="vehicle.country" class="vz-input w-32">
                <option v-for="country in plateCountries" :key="country.code" :value="country.code">{{ country.name }}</option>
              </select>
              <label class="sr-only" :for="`membership-plate-${index}`">Placa {{ index + 1 }}</label>
              <input
                :id="`membership-plate-${index}`"
                v-model="vehicle.plate"
                class="vz-input w-36 font-mono tracking-widest uppercase"
                type="text"
                maxlength="20"
                autocomplete="off"
                placeholder="ABC123"
                :aria-invalid="!!vehicleError(index)"
              />
              <label class="sr-only" :for="`membership-type-${index}`">Tipo del vehículo {{ index + 1 }}</label>
              <select :id="`membership-type-${index}`" v-model="vehicle.vehicle_type" class="vz-input w-40">
                <option v-for="type in vehicleTypeOrder" :key="type" :value="type">{{ vehicleType[type].label }}</option>
              </select>
              <button
                v-if="form.vehicles.length > 1"
                class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger"
                type="button"
                :aria-label="`Quitar vehículo ${index + 1}`"
                @click="form.vehicles.splice(index, 1)"
              >
                <i class="ri-close-line" aria-hidden="true" />
              </button>
              <p v-if="vehicleError(index)" class="vz-error basis-full" role="alert">{{ vehicleError(index) }}</p>
            </li>
          </ul>
          <p v-if="errors.fields.value.vehicles" class="vz-error" role="alert">{{ errors.fields.value.vehicles }}</p>
        </fieldset>

        <fieldset class="grid gap-4 sm:col-span-6 sm:grid-cols-2">
          <legend class="vz-label sm:col-span-2">Espacio asignado <span class="font-normal text-vz-muted">(opcional)</span></legend>
          <div>
            <label class="vz-label" for="membership-lot">Parqueadero</label>
            <select id="membership-lot" v-model="form.lot_id" class="vz-input">
              <option value="">Sin espacio fijo</option>
              <option v-for="lot in planLots" :key="lot.id" :value="lot.id">{{ lot.name }}</option>
            </select>
          </div>
          <div v-if="form.lot_id !== ''">
            <label class="vz-label" for="membership-space">Espacio</label>
            <select id="membership-space" v-model="form.space_id" class="vz-input" :disabled="loadingSpaces" :aria-invalid="!!errors.fields.value.assigned_space_id">
              <option value="">{{ loadingSpaces ? 'Cargando…' : assignable.length === 0 ? 'Sin espacios asignables' : 'Elige un espacio' }}</option>
              <option v-for="space in assignable" :key="space.id" :value="space.id">{{ space.code }}{{ space.zone ? ` · ${space.zone.name}` : '' }}</option>
            </select>
            <p v-if="errors.fields.value.assigned_space_id" class="vz-error" role="alert">{{ errors.fields.value.assigned_space_id }}</p>
          </div>
          <p class="vz-help sm:col-span-2">Solo los espacios marcados como asignables a mensualidades. Al llegar, el vehículo ocupa su espacio si está libre.</p>
        </fieldset>

        <div class="sm:col-span-6">
          <label class="vz-label" for="membership-notes">Observaciones <span class="font-normal text-vz-muted">(opcional)</span></label>
          <input id="membership-notes" v-model="form.notes" class="vz-input" type="text" maxlength="500" placeholder="Contrato 2026-114, factura a nombre de la empresa…" />
        </div>

        <label class="flex items-start gap-2 text-[13px] sm:col-span-6">
          <input v-model="form.auto_renew" class="mt-0.5 size-4 accent-vz-primary" type="checkbox" />
          <span>
            Renovar automáticamente
            <span class="block text-xs text-vz-muted">Al terminar cada periodo se abre el siguiente por cobrar. Sin renovación, vence al acabar la gracia.</span>
          </span>
        </label>

        <p v-if="errors.general.value" class="text-[13px] text-vz-danger sm:col-span-6" role="alert">{{ errors.general.value }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving || full || offered.length === 0">
          <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-id-card-line'" aria-hidden="true" /> Vender
        </button>
      </div>
    </form>
  </BaseModal>
</template>
