<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import StateCard from '@/components/ui/StateCard.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import type { ParkingLot, ParkingZone } from '@/modules/parking/types'
import { simulatePricing } from '@/modules/tariffs/api'
import { describeMinutes } from '@/modules/tariffs/labels'
import PriceBreakdown from '@/modules/tariffs/PriceBreakdown.vue'
import type { Discount, Quote } from '@/modules/tariffs/types'
import { vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import type { VehicleType } from '@/modules/vehicles/types'
import { toLocalInput } from '@/services/format'

const props = defineProps<{ lot: ParkingLot; zones: ParkingZone[]; discounts: Discount[] }>()

const now = new Date()
const form = ref<{ vehicle_type: VehicleType; zone_id: string; entry_at: string; exit_at: string; discount_id: string }>({
  vehicle_type: 'car',
  zone_id: '',
  entry_at: toLocalInput(now, props.lot.timezone),
  exit_at: toLocalInput(new Date(now.getTime() + 150 * 60000), props.lot.timezone),
  discount_id: '',
})
const quote = ref<Quote | null>(null)
const running = ref(false)
const errors = useFieldErrors()

const stay = computed(() => {
  const minutes = (Date.parse(form.value.exit_at) - Date.parse(form.value.entry_at)) / 60000

  return Number.isFinite(minutes) && minutes >= 0 ? describeMinutes(Math.round(minutes)) : null
})

async function run(): Promise<void> {
  running.value = true
  errors.clear()

  try {
    quote.value = await simulatePricing({
      lot_id: props.lot.id,
      vehicle_type: form.value.vehicle_type,
      zone_id: form.value.zone_id || null,
      entry_at: form.value.entry_at,
      exit_at: form.value.exit_at,
      discount_id: form.value.discount_id || null,
    })
  } catch (error) {
    quote.value = null
    errors.capture(error, 'No fue posible simular el cobro.')
  } finally {
    running.value = false
  }
}

watch(() => props.lot.id, () => {
  quote.value = null
  form.value.zone_id = ''
  form.value.discount_id = ''
})
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-5">
    <form class="space-y-4 lg:col-span-2" novalidate @submit.prevent="run">
      <p class="text-[13px] text-vz-muted">Calcula cuánto pagaría una estancia con las tarifas vigentes a la hora de entrada, igual que en la salida real.</p>
      <div>
        <label class="vz-label" for="sim-vehicle">Vehículo</label>
        <select id="sim-vehicle" v-model="form.vehicle_type" class="vz-input">
          <option v-for="type in vehicleTypeOrder" :key="type" :value="type">{{ vehicleType[type].label }}</option>
        </select>
      </div>
      <div>
        <label class="vz-label" for="sim-zone">Zona del espacio</label>
        <select id="sim-zone" v-model="form.zone_id" class="vz-input">
          <option value="">Sin zona</option>
          <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option>
        </select>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="vz-label" for="sim-entry">Entrada</label>
          <input id="sim-entry" v-model="form.entry_at" class="vz-input" type="datetime-local" required :aria-invalid="!!errors.fields.value.entry_at" />
          <p v-if="errors.fields.value.entry_at" class="vz-error" role="alert">{{ errors.fields.value.entry_at }}</p>
        </div>
        <div>
          <label class="vz-label" for="sim-exit">Salida</label>
          <input id="sim-exit" v-model="form.exit_at" class="vz-input" type="datetime-local" required :aria-invalid="!!errors.fields.value.exit_at" />
          <p v-if="errors.fields.value.exit_at" class="vz-error" role="alert">{{ errors.fields.value.exit_at }}</p>
        </div>
      </div>
      <p class="vz-help">Hora local de {{ lot.name }} ({{ lot.timezone }}).<template v-if="stay"> Estancia: {{ stay }}.</template></p>
      <div v-if="discounts.length > 0">
        <label class="vz-label" for="sim-discount">Descuento manual</label>
        <select id="sim-discount" v-model="form.discount_id" class="vz-input">
          <option value="">Ninguno (aplican las promociones vigentes)</option>
          <option v-for="discount in discounts" :key="discount.id" :value="discount.id">{{ discount.name }}</option>
        </select>
      </div>
      <p v-if="errors.general.value" class="text-[13px] text-vz-danger" role="alert">{{ errors.general.value }}</p>
      <button class="vz-btn vz-btn-primary w-full justify-center" type="submit" :disabled="running">
        <i :class="running ? 'ri-loader-4-line animate-spin' : 'ri-calculator-line'" aria-hidden="true" /> Calcular
      </button>
    </form>

    <div class="lg:col-span-3" aria-live="polite">
      <StateCard v-if="!quote" variant="empty" title="Sin simulación" message="Elige el vehículo y las horas para ver qué tarifa aplica y cómo se calcula." compact />
      <div v-else class="space-y-3">
        <PriceBreakdown
          :status="quote.status"
          :currency="quote.currency"
          :total="quote.total"
          :subtotal="quote.subtotal"
          :discount="quote.discount"
          :lines="quote.lines"
          :tariff="quote.tariff"
          :charged-minutes="quote.charged_minutes"
          :reason="quote.reason"
          total-label="Total a cobrar"
        />
        <p class="text-xs text-vz-muted">
          Estancia de {{ describeMinutes(quote.stay_minutes) }}<template v-if="quote.grace_minutes !== null"> · gracia de {{ quote.grace_minutes }} min</template><template v-if="quote.discount_rule"> · {{ quote.discount_rule.name }}</template>.
        </p>
      </div>
    </div>
  </div>
</template>
