<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import BaseModal from '@/components/ui/BaseModal.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { cancelPaymentLink, getPayment, refundPayment, syncPayment } from '@/modules/payments/api'
import { paymentMethod, paymentNumber, paymentStatus, providerName, refundStatus } from '@/modules/payments/labels'
import type { Payment, PaymentMethod } from '@/modules/payments/types'
import { formatDateTime } from '@/services/format'
import { parseApiError } from '@/services/http'
import { newIdempotencyKey } from '@/services/idempotency'
import { formatMoney, toMajorInput, toMinorUnits } from '@/services/money'

const props = defineProps<{ paymentId: string | null; timezone: string }>()
const emit = defineEmits<{ close: []; changed: [payment: Payment, message: string] }>()
const { can } = usePermissions()

const payment = ref<Payment | null>(null)
const loadError = ref('')
const refunding = ref(false)
const refundAmount = ref('')
const refundReason = ref('')
const refundChannel = ref<'provider' | 'manual'>('provider')
const manualMethod = ref<PaymentMethod>('cash')
const saving = ref(false)
const action = ref<'' | 'sync' | 'cancel'>('')
const actionError = ref('')
const copied = ref(false)
const errors = useFieldErrors()
let attemptKey = newIdempotencyKey()

const refundable = computed(() => payment.value !== null && ['completed', 'partially_refunded'].includes(payment.value.status) && can('payments.refund'))
const remaining = computed(() => (payment.value ? payment.value.amount - payment.value.refunded_amount : 0))
const online = computed(() => payment.value !== null && payment.value.provider !== 'offline')
const pendingLink = computed(() => online.value && payment.value?.status === 'pending')

async function copyLink(): Promise<void> {
  if (!payment.value?.checkout_url) {
    return
  }

  try {
    await navigator.clipboard.writeText(payment.value.checkout_url)
    copied.value = true
  } catch {
    copied.value = false
  }
}

// Asks the provider now, or gives up the link (the provider is asked first).
async function runAction(kind: 'sync' | 'cancel'): Promise<void> {
  const current = payment.value

  if (!current || action.value !== '') {
    return
  }

  action.value = kind
  actionError.value = ''

  try {
    const updated = kind === 'sync' ? await syncPayment(current.id) : await cancelPaymentLink(current.id)
    payment.value = { ...current, ...updated }
    emit('changed', updated, kind === 'cancel'
      ? 'Enlace anulado. Si el cliente lo paga igual, el pago se registra.'
      : `Estado en la pasarela: ${paymentStatus[updated.status].label.toLowerCase()}.`)
  } catch (error) {
    actionError.value = parseApiError(error, 'No fue posible consultar la pasarela.')

    if (kind === 'cancel') {
      void load(current.id)
    }
  } finally {
    action.value = ''
  }
}

async function load(id: string): Promise<void> {
  payment.value = null
  loadError.value = ''

  try {
    payment.value = await getPayment(id)
  } catch (error) {
    loadError.value = parseApiError(error, 'No fue posible consultar el pago.')
  }
}

watch(() => props.paymentId, (id) => {
  refunding.value = false
  actionError.value = ''
  copied.value = false
  errors.clear()

  if (id) {
    void load(id)
  }
})

function startRefund(): void {
  attemptKey = newIdempotencyKey()
  refundAmount.value = toMajorInput(remaining.value, payment.value?.currency ?? 'COP')
  refundReason.value = ''
  refundChannel.value = 'provider'
  manualMethod.value = 'cash'
  errors.clear()
  refunding.value = true
}

async function onRefund(): Promise<void> {
  const current = payment.value

  if (!current || saving.value) {
    return
  }

  const amount = refundAmount.value.trim() === '' ? null : toMinorUnits(refundAmount.value, current.currency)

  if (refundAmount.value.trim() !== '' && (amount === null || amount <= 0 || amount > remaining.value)) {
    errors.fields.value = { amount: `Escribe un valor entre ${formatMoney(1, current.currency)} y ${formatMoney(remaining.value, current.currency)}.` }
    return
  }

  saving.value = true
  errors.clear()

  try {
    const updated = await refundPayment(current.id, {
      amount,
      reason: refundReason.value.trim(),
      ...(online.value ? { channel: refundChannel.value, manual_method: refundChannel.value === 'manual' ? manualMethod.value : undefined } : {}),
    }, attemptKey)
    payment.value = updated
    refunding.value = false
    const last = updated.refunds?.[updated.refunds.length - 1]
    emit('changed', updated, last?.status === 'pending'
      ? `Reembolso solicitado a ${providerName[updated.provider] ?? 'la pasarela'}; se confirma cuando lo apruebe.`
      : `Reembolso registrado en ${paymentNumber(updated)}.`)
  } catch (error) {
    errors.capture(error, 'No fue posible registrar el reembolso.')
  } finally {
    saving.value = false
  }
}

function detail(key: string): string | null {
  const value = payment.value?.details?.[key]

  return typeof value === 'string' || typeof value === 'number' ? String(value) : null
}
</script>

<template>
  <BaseModal :open="paymentId !== null" :title="payment ? `Pago ${paymentNumber(payment)}` : 'Pago'" size="md" @close="emit('close')">
    <div class="px-5 py-5">
      <p v-if="loadError" class="vz-error" role="alert">{{ loadError }}</p>
      <div v-else-if="!payment" class="flex items-center gap-2 text-[13px] text-vz-muted" role="status">
        <i class="ri-loader-4-line animate-spin" aria-hidden="true" /> Cargando…
      </div>

      <div v-else class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium text-vz-heading">{{ payment.description }}</p>
            <p class="text-[13px] text-vz-muted">{{ formatDateTime(payment.received_at, timezone) }} · {{ payment.received_by?.name ?? (online ? `En línea, enlace de ${payment.requested_by?.name ?? 'caja'}` : 'Sistema') }}</p>
          </div>
          <StatusBadge :label="paymentStatus[payment.status].label" :tone="paymentStatus[payment.status].tone" />
        </div>

        <dl class="grid grid-cols-2 gap-3 rounded-vz bg-vz-elevated/50 p-3 text-[13px] sm:grid-cols-3">
          <div>
            <dt class="text-vz-muted">Valor</dt>
            <dd class="text-base font-semibold text-vz-heading">{{ formatMoney(payment.amount, payment.currency) }}</dd>
          </div>
          <div>
            <dt class="text-vz-muted">Medio</dt>
            <dd class="font-medium text-vz-heading"><i :class="paymentMethod[payment.method].icon" aria-hidden="true" /> {{ paymentMethod[payment.method].label }}</dd>
          </div>
          <div v-if="payment.method === 'cash'">
            <dt class="text-vz-muted">Recibido / vueltas</dt>
            <dd class="font-medium text-vz-heading">{{ formatMoney(payment.tendered_amount, payment.currency) }} / {{ formatMoney(payment.change_amount, payment.currency) }}</dd>
          </div>
          <div v-else-if="!online">
            <dt class="text-vz-muted">Referencia</dt>
            <dd class="font-medium text-vz-heading">{{ payment.reference ?? '—' }}</dd>
          </div>
          <div v-if="payment.refunded_amount > 0">
            <dt class="text-vz-muted">Reembolsado</dt>
            <dd class="font-medium text-vz-heading">{{ formatMoney(payment.refunded_amount, payment.currency) }}</dd>
          </div>
          <div v-if="detail('membership_code')">
            <dt class="text-vz-muted">Periodo</dt>
            <dd class="font-medium text-vz-heading">{{ detail('starts_on') }} a {{ detail('ends_on') }}</dd>
          </div>
          <div v-if="!online">
            <dt class="text-vz-muted">Cierre de caja</dt>
            <dd class="font-medium text-vz-heading">{{ payment.reconciliation?.number ?? 'Pendiente' }}</dd>
          </div>
        </dl>

        <section v-if="online" class="space-y-2 rounded-vz border border-vz-border p-3 text-[13px]" aria-label="Pago en línea">
          <p class="text-vz-heading">
            <i class="ri-global-line" aria-hidden="true" /> {{ providerName[payment.provider] ?? payment.provider }}<template v-if="payment.provider_account?.name"> · {{ payment.provider_account.name }}</template>
          </p>
          <p v-if="payment.provider_reference" class="text-vz-muted">Transacción <code>{{ payment.provider_reference }}</code><template v-if="payment.provider_method"> · {{ payment.provider_method }}</template></p>
          <p v-if="pendingLink && payment.expires_at" class="text-vz-muted">El enlace vence {{ formatDateTime(payment.expires_at, timezone) }}.</p>
          <p v-if="payment.failure_reason" class="text-vz-warning-text">{{ payment.failure_reason }}</p>
          <div v-if="pendingLink && payment.checkout_url" class="flex gap-2">
            <input class="vz-input font-mono text-xs" :value="payment.checkout_url" readonly aria-label="Enlace de pago" />
            <button class="vz-btn vz-btn-light shrink-0" type="button" @click="copyLink">
              <i :class="copied ? 'ri-check-line' : 'ri-file-copy-line'" aria-hidden="true" /> {{ copied ? 'Copiado' : 'Copiar' }}
            </button>
          </div>
          <div v-if="pendingLink" class="flex flex-wrap gap-2">
            <button class="vz-btn vz-btn-sm vz-btn-light" type="button" :disabled="action !== ''" @click="runAction('sync')">
              <i :class="action === 'sync' ? 'ri-loader-4-line animate-spin' : 'ri-refresh-line'" aria-hidden="true" /> Consultar estado
            </button>
            <button v-if="can('payments.create')" class="vz-btn vz-btn-sm vz-btn-soft-danger" type="button" :disabled="action !== ''" @click="runAction('cancel')">
              <i :class="action === 'cancel' ? 'ri-loader-4-line animate-spin' : 'ri-close-circle-line'" aria-hidden="true" /> Anular enlace
            </button>
          </div>
          <p v-if="actionError" class="vz-error" role="alert">{{ actionError }}</p>
        </section>

        <p v-if="payment.notes" class="text-[13px] text-vz-muted">{{ payment.notes }}</p>

        <section v-if="payment.refunds && payment.refunds.length > 0" aria-label="Reembolsos">
          <h3 class="mb-2 text-[13px] font-semibold text-vz-heading">Reembolsos</h3>
          <ul class="divide-y divide-vz-border rounded-vz border border-vz-border text-[13px]">
            <li v-for="refund in payment.refunds" :key="refund.id" class="flex flex-wrap justify-between gap-2 px-3 py-2">
              <span>
                <code class="text-xs text-vz-muted">{{ refund.number }}</code> {{ refund.reason }}
                <StatusBadge v-if="refund.status !== 'completed'" class="ms-1" :label="refundStatus[refund.status].label" :tone="refundStatus[refund.status].tone" />
              </span>
              <span class="text-vz-muted">{{ formatMoney(refund.amount, refund.currency) }} · {{ formatDateTime(refund.refunded_at, timezone) }} · {{ refund.refunded_by?.name ?? '—' }}</span>
            </li>
          </ul>
        </section>

        <form v-if="refunding" class="space-y-3 rounded-vz border border-vz-warning/40 p-3" novalidate @submit.prevent="onRefund">
          <p v-if="!online" class="text-[13px] text-vz-warning-text">El dinero sale de tu caja por el mismo medio del pago y queda en tu próximo cierre.</p>
          <div v-else class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="vz-label" for="refund-channel">Cómo se devuelve</label>
              <select id="refund-channel" v-model="refundChannel" class="vz-input">
                <option value="provider">Por {{ providerName[payment.provider] ?? 'la pasarela' }} (pago completo)</option>
                <option value="manual">A mano, fuera de la pasarela</option>
              </select>
            </div>
            <div v-if="refundChannel === 'manual'">
              <label class="vz-label" for="refund-method">Medio</label>
              <select id="refund-method" v-model="manualMethod" class="vz-input">
                <option value="cash">Efectivo (sale de tu caja)</option>
                <option value="transfer">Transferencia</option>
                <option value="other">Otro</option>
              </select>
            </div>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="vz-label" for="refund-amount">Valor</label>
              <input id="refund-amount" v-model="refundAmount" class="vz-input" type="text" inputmode="decimal" :aria-invalid="!!errors.fields.value.amount" />
              <p v-if="errors.fields.value.amount" class="vz-error" role="alert">{{ errors.fields.value.amount }}</p>
            </div>
            <div>
              <label class="vz-label" for="refund-reason">Motivo</label>
              <input id="refund-reason" v-model="refundReason" class="vz-input" type="text" maxlength="255" required :aria-invalid="!!errors.fields.value.reason" />
              <p v-if="errors.fields.value.reason" class="vz-error" role="alert">{{ errors.fields.value.reason }}</p>
            </div>
          </div>
          <p v-if="errors.general.value" class="vz-error" role="alert">{{ errors.general.value }}</p>
          <div class="flex justify-end gap-2">
            <button class="vz-btn vz-btn-sm vz-btn-light" type="button" @click="refunding = false">Cancelar</button>
            <button class="vz-btn vz-btn-sm vz-btn-danger" type="submit" :disabled="saving">
              <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-arrow-go-back-line'" aria-hidden="true" /> Confirmar reembolso
            </button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="payment" class="flex flex-wrap justify-end gap-2 border-t border-vz-border px-5 py-3">
      <button v-if="refundable && !refunding" class="vz-btn vz-btn-soft-danger" type="button" @click="startRefund">
        <i class="ri-arrow-go-back-line" aria-hidden="true" /> Reembolsar
      </button>
      <RouterLink class="vz-btn vz-btn-light" :to="{ name: 'payment-receipt', params: { paymentId: payment.id } }" target="_blank">
        <i class="ri-printer-line" aria-hidden="true" /> Imprimir recibo
      </RouterLink>
      <button class="vz-btn vz-btn-primary" type="button" @click="emit('close')">Cerrar</button>
    </div>
  </BaseModal>
</template>
