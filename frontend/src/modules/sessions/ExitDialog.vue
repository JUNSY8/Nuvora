<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import type { ParkingGate } from '@/modules/parking/types'
import { quoteSession, registerExit } from '@/modules/sessions/api'
import type { ParkingSession, SessionResult } from '@/modules/sessions/types'
import { listDiscounts } from '@/modules/tariffs/api'
import PriceBreakdown from '@/modules/tariffs/PriceBreakdown.vue'
import type { Discount, Quote } from '@/modules/tariffs/types'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import { formatDateTime, formatDuration, minutesSince } from '@/services/format'
import { parseApiError } from '@/services/http'
import { newIdempotencyKey } from '@/services/idempotency'

const props = defineProps<{
  session: ParkingSession | null
  gates: ParkingGate[]
  timezone: string
  now: number
}>()

const emit = defineEmits<{ close: []; exited: [result: SessionResult] }>()
const { can } = usePermissions()

const gateId = ref('')
const notes = ref('')
const discountId = ref('')
const discounts = ref<Discount[]>([])
const quote = ref<Quote | null>(null)
const quoteError = ref('')
const quoting = ref(false)
const saving = ref(false)
const errors = useFieldErrors()
let attemptKey = newIdempotencyKey()
let quoteRequest = 0

const stay = computed(() => (props.session ? formatDuration(minutesSince(props.session.entry_at, props.now)) : '—'))
// A stay covered by a membership takes no discount.
const canDiscount = computed(() => can('sessions.discount') && can('tariffs.view') && !props.session?.membership)

// The price shown is a preview: the exit prices again at its own moment.
async function loadQuote(): Promise<void> {
  const session = props.session

  if (!session) {
    return
  }

  const request = ++quoteRequest
  quoting.value = true
  quoteError.value = ''

  try {
    const next = await quoteSession(session.id, discountId.value || null)

    if (request === quoteRequest) {
      quote.value = next
    }
  } catch (error) {
    if (request === quoteRequest) {
      quote.value = null
      quoteError.value = parseApiError(error, 'No fue posible calcular el valor.')
    }
  } finally {
    if (request === quoteRequest) {
      quoting.value = false
    }
  }
}

async function loadDiscounts(lotId: string): Promise<void> {
  try {
    discounts.value = await listDiscounts({ lot_id: lotId, application: 'manual', status: 'active' })
  } catch {
    // Without the catalog the exit still works with promotions only.
    discounts.value = []
  }
}

watch(() => props.session, (session) => {
  if (session) {
    // A new dialog is a new attempt; retries inside it keep the key.
    attemptKey = newIdempotencyKey()
    gateId.value = props.gates[0]?.id ?? ''
    notes.value = ''
    discountId.value = ''
    discounts.value = []
    quote.value = null
    errors.clear()
    void loadQuote()

    if (canDiscount.value) {
      void loadDiscounts(session.parking_lot.id)
    }
  }
})

watch(discountId, () => {
  void loadQuote()
})

// The operations screen ticks every 30 s; a longer stay may reach another fraction.
watch(() => props.now, () => {
  if (props.session && !saving.value) {
    void loadQuote()
  }
})

async function onConfirm(): Promise<void> {
  if (!props.session || saving.value) {
    return
  }

  saving.value = true
  errors.clear()

  try {
    const result = await registerExit(props.session.id, {
      gate_id: gateId.value === '' ? null : gateId.value,
      notes: notes.value.trim() === '' ? null : notes.value.trim(),
      discount_id: discountId.value === '' ? null : discountId.value,
    }, attemptKey)
    emit('exited', result)
  } catch (error) {
    errors.capture(error, 'No fue posible registrar la salida.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :open="session !== null" title="Registrar salida" size="sm" @close="emit('close')">
    <form v-if="session" novalidate @submit.prevent="onConfirm">
      <div class="space-y-4 px-5 py-5">
        <div class="flex flex-col items-center gap-2 text-center">
          <PlateChip :plate="session.vehicle.plate" :country="session.vehicle.country" size="lg" />
          <p class="text-[13px] text-vz-muted">
            {{ session.space ? `Espacio ${session.space.code}` : 'Sin espacio asignado' }}<template v-if="session.customer"> · {{ session.customer.name }}</template>
          </p>
        </div>

        <dl class="grid grid-cols-2 gap-3 rounded-vz bg-vz-elevated/50 p-3 text-[13px]">
          <div>
            <dt class="text-vz-muted">Ingreso</dt>
            <dd class="font-medium text-vz-heading">{{ formatDateTime(session.entry_at, timezone) }}</dd>
          </div>
          <div>
            <dt class="text-vz-muted">Tiempo</dt>
            <dd class="font-medium text-vz-heading">{{ stay }}</dd>
          </div>
        </dl>

        <div aria-live="polite">
          <div v-if="quoting && !quote" class="flex items-center gap-2 rounded-vz border border-vz-border p-3 text-[13px] text-vz-muted" role="status">
            <i class="ri-loader-4-line animate-spin" aria-hidden="true" /> Calculando el valor…
          </div>
          <p v-else-if="quoteError" class="rounded-vz border border-vz-warning/40 bg-vz-warning/10 p-3 text-[13px] text-vz-warning-text" role="alert">{{ quoteError }}</p>
          <PriceBreakdown
            v-else-if="quote"
            :class="quoting ? 'opacity-60' : ''"
            :status="quote.status"
            :currency="quote.currency"
            :total="quote.total"
            :subtotal="quote.subtotal"
            :discount="quote.discount"
            :lines="quote.lines"
            :tariff="quote.tariff"
            :charged-minutes="quote.charged_minutes"
            :reason="quote.reason"
            :membership="quote.membership ?? null"
            :total-label="quote.status === 'covered' ? 'A cobrar (mensualidad)' : 'A cobrar'"
          />
        </div>

        <div v-if="canDiscount && discounts.length > 0">
          <label class="vz-label" for="exit-discount">Descuento</label>
          <select id="exit-discount" v-model="discountId" class="vz-input" :aria-invalid="!!errors.fields.value.discount_id">
            <option value="">Ninguno (aplican las promociones vigentes)</option>
            <option v-for="discount in discounts" :key="discount.id" :value="discount.id">{{ discount.name }}</option>
          </select>
          <p v-if="errors.fields.value.discount_id" class="vz-error" role="alert">{{ errors.fields.value.discount_id }}</p>
        </div>

        <p class="text-xs text-vz-muted">El recaudo y los medios de pago llegan con la versión de pagos; por ahora la salida registra el valor calculado.</p>

        <div v-if="gates.length > 0">
          <label class="vz-label" for="exit-gate">Puerta</label>
          <select id="exit-gate" v-model="gateId" class="vz-input">
            <option value="">Sin puerta</option>
            <option v-for="gate in gates" :key="gate.id" :value="gate.id">{{ gate.name }} ({{ gate.code }})</option>
          </select>
          <p v-if="errors.fields.value.gate_id" class="vz-error" role="alert">{{ errors.fields.value.gate_id }}</p>
        </div>

        <div>
          <label class="vz-label" for="exit-notes">Observaciones <span class="font-normal text-vz-muted">(opcional)</span></label>
          <input id="exit-notes" v-model="notes" class="vz-input" type="text" maxlength="500" />
        </div>

        <p v-if="errors.general.value" class="vz-error" role="alert">{{ errors.general.value }}</p>
      </div>

      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving" autofocus>
          <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-logout-box-r-line'" aria-hidden="true" />
          Registrar salida
        </button>
      </div>
    </form>
  </BaseModal>
</template>
