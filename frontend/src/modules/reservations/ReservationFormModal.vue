<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import CustomerPicker from '@/modules/customers/CustomerPicker.vue'
import type { CustomerSummary } from '@/modules/customers/types'
import type { ParkingLot } from '@/modules/parking/types'
import { createReservation, getAvailability } from '@/modules/reservations/api'
import { reservationScope } from '@/modules/reservations/labels'
import type { Availability, Reservation, ReservationScope } from '@/modules/reservations/types'
import { describeMinutes } from '@/modules/tariffs/labels'
import { plateCountries, vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import type { VehicleType } from '@/modules/vehicles/types'
import { toLocalInput } from '@/services/format'
import { parseApiError } from '@/services/http'
import { newIdempotencyKey } from '@/services/idempotency'

const props = defineProps<{ open: boolean; lot: ParkingLot }>()
const emit = defineEmits<{ close: []; saved: [reservation: Reservation, replayed: boolean] }>()
const { can } = usePermissions()

interface FormState {
  plate: string
  country: string
  vehicle_type: VehicleType
  customer: CustomerSummary | null
  starts_at: string
  ends_at: string
  scope: ReservationScope
  zone_id: string
  space_id: string
  confirm: boolean
  notes: string
}

const form = ref<FormState>(blank())
const saving = ref(false)
const errors = useFieldErrors()
const availability = ref<Availability | null>(null)
const availabilityError = ref('')
const checking = ref(false)
let attemptKey = newIdempotencyKey()
let lastPlate = ''
let request = 0

const windowKey = computed(() => `${props.lot.id}|${form.value.vehicle_type}|${form.value.starts_at}|${form.value.ends_at}`)
const debouncedWindow = refDebounced(windowKey, 400)
const freeSpaces = computed(() => (availability.value?.spaces ?? []).filter((space) => space.free))
const duration = computed(() => {
  const minutes = (Date.parse(form.value.ends_at) - Date.parse(form.value.starts_at)) / 60000

  return Number.isFinite(minutes) && minutes > 0 ? describeMinutes(Math.round(minutes)) : null
})

function blank(): FormState {
  // Next full hour, for two hours.
  const start = new Date(Math.ceil(Date.now() / 3600000) * 3600000)

  return {
    plate: '',
    country: 'CO',
    vehicle_type: 'car',
    customer: null,
    starts_at: toLocalInput(start, props.lot.timezone),
    ends_at: toLocalInput(new Date(start.getTime() + 2 * 3600000), props.lot.timezone),
    scope: 'lot',
    zone_id: '',
    space_id: '',
    confirm: true,
    notes: '',
  }
}

async function check(): Promise<void> {
  const current = ++request
  checking.value = true
  availabilityError.value = ''

  try {
    const answer = await getAvailability({ lot_id: props.lot.id, vehicle_type: form.value.vehicle_type, starts_at: form.value.starts_at, ends_at: form.value.ends_at })

    if (current === request) {
      availability.value = answer
    }
  } catch (error) {
    if (current === request) {
      availability.value = null
      availabilityError.value = parseApiError(error, 'No fue posible consultar la disponibilidad.')
    }
  } finally {
    if (current === request) {
      checking.value = false
    }
  }
}

async function onSave(): Promise<void> {
  if (saving.value) {
    return
  }

  // A different plate is a different booking: never replay the previous one.
  if (form.value.plate.trim().toUpperCase() !== lastPlate) {
    attemptKey = newIdempotencyKey()
    lastPlate = form.value.plate.trim().toUpperCase()
  }

  saving.value = true
  errors.clear()

  try {
    const result = await createReservation({
      lot_id: props.lot.id,
      plate: form.value.plate.trim(),
      country: form.value.country,
      vehicle_type: form.value.vehicle_type,
      customer_id: form.value.customer?.id ?? null,
      zone_id: form.value.scope === 'zone' && form.value.zone_id !== '' ? form.value.zone_id : null,
      space_id: form.value.scope === 'space' && form.value.space_id !== '' ? form.value.space_id : null,
      starts_at: form.value.starts_at,
      ends_at: form.value.ends_at,
      confirm: form.value.confirm,
      notes: form.value.notes.trim() === '' ? null : form.value.notes.trim(),
    }, attemptKey)
    emit('saved', result.reservation, result.replayed)
  } catch (error) {
    errors.capture(error, 'No fue posible registrar la reserva.')
  } finally {
    saving.value = false
  }
}

watch(() => props.open, (open) => {
  if (open) {
    form.value = blank()
    errors.clear()
    attemptKey = newIdempotencyKey()
    lastPlate = ''
    void check()
  }
}, { immediate: true })

watch(debouncedWindow, () => {
  if (props.open) {
    void check()
  }
})

watch(() => form.value.scope, () => {
  form.value.zone_id = ''
  form.value.space_id = ''
})
</script>

<template>
  <BaseModal :open="open" :title="`Nueva reserva · ${lot.name}`" size="lg" @close="emit('close')">
    <form novalidate @submit.prevent="onSave">
      <div class="vz-card-body grid max-h-[70vh] gap-4 overflow-y-auto sm:grid-cols-6">
        <div class="sm:col-span-2">
          <label class="vz-label" for="reservation-country">País de la placa</label>
          <select id="reservation-country" v-model="form.country" class="vz-input">
            <option v-for="country in plateCountries" :key="country.code" :value="country.code">{{ country.name }}</option>
          </select>
        </div>
        <div class="sm:col-span-2">
          <label class="vz-label" for="reservation-plate">Placa</label>
          <input
            id="reservation-plate"
            v-model="form.plate"
            class="vz-input font-mono tracking-widest uppercase"
            type="text"
            maxlength="20"
            required
            autocomplete="off"
            placeholder="ABC123"
            :aria-invalid="!!errors.fields.value.plate"
          />
          <p v-if="errors.fields.value.plate" class="vz-error" role="alert">{{ errors.fields.value.plate }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="vz-label" for="reservation-type">Tipo de vehículo</label>
          <select id="reservation-type" v-model="form.vehicle_type" class="vz-input" aria-describedby="reservation-type-help">
            <option v-for="type in vehicleTypeOrder" :key="type" :value="type">{{ vehicleType[type].label }}</option>
          </select>
          <p id="reservation-type-help" class="vz-help">Si la placa ya está registrada, se usa su tipo.</p>
        </div>

        <div class="sm:col-span-3">
          <label class="vz-label" for="reservation-start">Llegada</label>
          <input id="reservation-start" v-model="form.starts_at" class="vz-input" type="datetime-local" required :aria-invalid="!!errors.fields.value.starts_at" />
          <p v-if="errors.fields.value.starts_at" class="vz-error" role="alert">{{ errors.fields.value.starts_at }}</p>
        </div>
        <div class="sm:col-span-3">
          <label class="vz-label" for="reservation-end">Salida</label>
          <input id="reservation-end" v-model="form.ends_at" class="vz-input" type="datetime-local" required :aria-invalid="!!errors.fields.value.ends_at" />
          <p v-if="errors.fields.value.ends_at" class="vz-error" role="alert">{{ errors.fields.value.ends_at }}</p>
        </div>

        <div class="rounded-vz border border-vz-border bg-vz-elevated/40 p-3 text-[13px] sm:col-span-6" aria-live="polite">
          <p v-if="checking && !availability" class="text-vz-muted"><i class="ri-loader-4-line animate-spin" aria-hidden="true" /> Consultando disponibilidad…</p>
          <p v-else-if="availabilityError" class="text-vz-warning-text"><i class="ri-error-warning-line" aria-hidden="true" /> {{ availabilityError }}</p>
          <template v-else-if="availability">
            <p :class="availability.available > 0 ? 'text-vz-success-text' : 'text-vz-danger-text'">
              <i :class="availability.available > 0 ? 'ri-checkbox-circle-line' : 'ri-close-circle-line'" aria-hidden="true" />
              {{ availability.available }} de {{ availability.pool }} espacios reservables libres en ese horario<template v-if="duration"> · {{ duration }}</template>
            </p>
            <p v-if="!availability.opens_throughout && availability.policy.within_operating_hours" class="mt-1 text-vz-warning-text">
              El parqueadero no está abierto todo ese horario.
            </p>
            <p class="mt-1 text-xs text-vz-muted">
              Colchón de {{ availability.policy.buffer_minutes }} min entre reservas · tolerancia de llegada {{ availability.policy.arrival_tolerance_minutes }} min.
            </p>
          </template>
        </div>

        <fieldset class="sm:col-span-6">
          <legend class="vz-label">Qué se reserva</legend>
          <div class="grid gap-2 sm:grid-cols-3">
            <label
              v-for="(meta, key) in reservationScope"
              :key="key"
              class="flex cursor-pointer gap-2 rounded-vz border p-3 text-[13px]"
              :class="form.scope === key ? 'border-vz-primary bg-vz-primary/10' : 'border-vz-border'"
            >
              <input v-model="form.scope" class="mt-0.5 size-4 accent-vz-primary" type="radio" name="reservation-scope" :value="key" />
              <span>
                <span class="block font-medium text-vz-heading">{{ meta.label }}</span>
                <span class="text-xs text-vz-muted">{{ meta.help }}</span>
              </span>
            </label>
          </div>
        </fieldset>

        <div v-if="form.scope === 'zone'" class="sm:col-span-3">
          <label class="vz-label" for="reservation-zone">Zona</label>
          <select id="reservation-zone" v-model="form.zone_id" class="vz-input" required :aria-invalid="!!errors.fields.value.zone_id">
            <option value="" disabled>Elige una zona</option>
            <option v-for="zone in availability?.zones ?? []" :key="zone.id" :value="zone.id" :disabled="zone.available === 0">
              {{ zone.name ?? zone.code }} · {{ zone.available }} libres
            </option>
          </select>
          <p v-if="errors.fields.value.zone_id" class="vz-error" role="alert">{{ errors.fields.value.zone_id }}</p>
        </div>
        <div v-if="form.scope === 'space'" class="sm:col-span-3">
          <label class="vz-label" for="reservation-space">Espacio</label>
          <select id="reservation-space" v-model="form.space_id" class="vz-input" required :aria-invalid="!!errors.fields.value.space_id">
            <option value="" disabled>{{ freeSpaces.length === 0 ? 'No hay espacios libres' : 'Elige un espacio' }}</option>
            <option v-for="space in freeSpaces" :key="space.id" :value="space.id">{{ space.code }}</option>
          </select>
          <p v-if="errors.fields.value.space_id" class="vz-error" role="alert">{{ errors.fields.value.space_id }}</p>
        </div>

        <div v-if="can('customers.view')" class="sm:col-span-6">
          <CustomerPicker v-model="form.customer" label="Cliente (opcional)" :invalid="!!errors.fields.value.customer_id" />
          <p v-if="errors.fields.value.customer_id" class="vz-error" role="alert">{{ errors.fields.value.customer_id }}</p>
        </div>

        <div class="sm:col-span-6">
          <label class="vz-label" for="reservation-notes">Observaciones <span class="font-normal text-vz-muted">(opcional)</span></label>
          <input id="reservation-notes" v-model="form.notes" class="vz-input" type="text" maxlength="500" placeholder="Llega con remolque, pidió espacio cubierto…" />
        </div>

        <label class="flex items-start gap-2 text-[13px] sm:col-span-6">
          <input v-model="form.confirm" class="mt-0.5 size-4 accent-vz-primary" type="checkbox" />
          <span>
            Confirmar ahora
            <span class="block text-xs text-vz-muted">Sin confirmar, la reserva retiene el cupo solo {{ availability?.policy.hold_minutes ?? 15 }} min y luego vence.</span>
          </span>
        </label>

        <p v-if="errors.general.value" class="text-[13px] text-vz-danger sm:col-span-6" role="alert">{{ errors.general.value }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">
          <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-calendar-check-line'" aria-hidden="true" />
          {{ form.confirm ? 'Reservar' : 'Retener cupo' }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>
