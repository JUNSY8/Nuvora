<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { collectPayment, createPaymentLink, providerOptions } from '@/modules/payments/api'
import { draftTender, newPaymentDraft } from '@/modules/payments/labels'
import PaymentFields from '@/modules/payments/PaymentFields.vue'
import type { PayableSummary, Payment, PaymentDraft, ProviderOption } from '@/modules/payments/types'
import { formatDateTime } from '@/services/format'
import { newIdempotencyKey } from '@/services/idempotency'
import { formatMoney, toMajorInput, toMinorUnits } from '@/services/money'
import { useAuthStore } from '@/stores/auth'

// Collects what a closed stay or a membership period still owes, at the
// desk or with a payment link the customer pays online. The amount defaults
// to the whole balance; a smaller one leaves the rest pending.
const props = defineProps<{ payable: PayableSummary | null }>()
const emit = defineEmits<{ close: []; paid: [payment: Payment]; linked: [payment: Payment] }>()
const { can } = usePermissions()
const auth = useAuthStore()

const mode = ref<'desk' | 'link'>('desk')
const amount = ref('')
const notes = ref('')
const draft = ref<PaymentDraft>(newPaymentDraft())
const options = ref<ProviderOption[]>([])
const accountId = ref('')
const link = ref<Payment | null>(null)
const copied = ref(false)
const saving = ref(false)
const errors = useFieldErrors()
let attemptKey = newIdempotencyKey()

const currency = computed(() => props.payable?.currency ?? 'COP')
const amountMinor = computed(() => (amount.value.trim() === '' ? props.payable?.balance ?? 0 : toMinorUnits(amount.value, currency.value)))
const amountError = computed(() => {
  if (amountMinor.value === null || amountMinor.value <= 0) {
    return 'Escribe un valor válido.'
  }

  return props.payable && amountMinor.value > props.payable.balance ? `No puede superar el saldo de ${formatMoney(props.payable.balance, currency.value)}.` : ''
})
const tender = computed(() => draftTender(draft.value, amountMinor.value ?? 0, currency.value))
const paymentErrors = computed(() => ({
  method: errors.fields.value.method ?? '',
  tendered_amount: errors.fields.value.tendered_amount ?? '',
  reference: errors.fields.value.reference ?? '',
}))
const timezone = computed(() => auth.currentTenant?.timezone ?? 'America/Bogota')

async function loadOptions(): Promise<void> {
  try {
    options.value = await providerOptions()
    accountId.value = options.value[0]?.id ?? ''
  } catch {
    // Without online accounts the dialog collects at the desk only.
    options.value = []
  }
}

watch(() => props.payable, (payable) => {
  if (payable) {
    // A new dialog is a new attempt; retries inside it keep the key.
    attemptKey = newIdempotencyKey()
    mode.value = 'desk'
    amount.value = toMajorInput(payable.balance, payable.currency)
    notes.value = ''
    draft.value = newPaymentDraft()
    link.value = null
    copied.value = false
    errors.clear()

    if (can('payments.create')) {
      void loadOptions()
    }
  }
})

watch(mode, () => {
  attemptKey = newIdempotencyKey()
  errors.clear()
})

async function onConfirm(): Promise<void> {
  const payable = props.payable

  if (!payable || saving.value) {
    return
  }

  if (amountError.value !== '' || (mode.value === 'desk' && !tender.value.ok)) {
    errors.general.value = amountError.value || 'El efectivo recibido debe cubrir el valor a cobrar.'
    return
  }

  saving.value = true
  errors.clear()

  try {
    if (mode.value === 'link') {
      link.value = await createPaymentLink({ payable_type: payable.type, payable_id: payable.id, account_id: accountId.value, amount: amountMinor.value }, attemptKey)
      emit('linked', link.value)
      return
    }

    const result = await collectPayment({
      payable_type: payable.type,
      payable_id: payable.id,
      method: draft.value.method,
      amount: amountMinor.value,
      tendered_amount: tender.value.tendered,
      reference: tender.value.reference,
      notes: notes.value.trim() === '' ? null : notes.value.trim(),
    }, attemptKey)
    emit('paid', result.payment)
  } catch (error) {
    errors.capture(error, mode.value === 'link' ? 'No fue posible crear el enlace de pago.' : 'No fue posible registrar el pago.')
  } finally {
    saving.value = false
  }
}

async function copyLink(): Promise<void> {
  if (!link.value?.checkout_url) {
    return
  }

  try {
    await navigator.clipboard.writeText(link.value.checkout_url)
    copied.value = true
  } catch {
    copied.value = false
  }
}
</script>

<template>
  <BaseModal :open="payable !== null" :title="link ? 'Enlace de pago' : 'Registrar pago'" size="sm" @close="emit('close')">
    <div v-if="payable && link" class="space-y-4 px-5 py-5">
      <p class="text-[13px] text-vz-text">
        Envía este enlace al cliente. El pago de <strong class="text-vz-heading">{{ formatMoney(link.amount, link.currency) }}</strong> se registra solo cuando la pasarela lo apruebe.
      </p>
      <div class="flex gap-2">
        <input class="vz-input font-mono text-xs" :value="link.checkout_url ?? ''" readonly aria-label="Enlace de pago" @focus="($event.target as HTMLInputElement).select()" />
        <button class="vz-btn vz-btn-light shrink-0" type="button" @click="copyLink">
          <i :class="copied ? 'ri-check-line' : 'ri-file-copy-line'" aria-hidden="true" /> {{ copied ? 'Copiado' : 'Copiar' }}
        </button>
      </div>
      <p v-if="link.expires_at" class="text-xs text-vz-muted">Vence {{ formatDateTime(link.expires_at, timezone) }}. Lo encuentras en Pagos mientras esté pendiente.</p>
      <div class="flex justify-end gap-2 border-t border-vz-border pt-3">
        <a v-if="link.checkout_url" class="vz-btn vz-btn-light" :href="link.checkout_url" target="_blank" rel="noopener"><i class="ri-external-link-line" aria-hidden="true" /> Abrir</a>
        <button class="vz-btn vz-btn-primary" type="button" @click="emit('close')">Listo</button>
      </div>
    </div>

    <form v-else-if="payable" novalidate @submit.prevent="onConfirm">
      <div class="space-y-4 px-5 py-5">
        <div class="rounded-vz bg-vz-elevated/50 p-3">
          <p class="font-medium text-vz-heading">{{ payable.title }}</p>
          <p class="text-[13px] text-vz-muted">{{ payable.subtitle }}</p>
          <p class="mt-2 text-[13px] text-vz-muted">Saldo pendiente: <strong class="text-vz-heading">{{ formatMoney(payable.balance, payable.currency) }}</strong></p>
        </div>

        <div v-if="options.length > 0" class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Cómo se cobra">
          <button
            v-for="choice in [{ key: 'desk', label: 'En caja', icon: 'ri-store-2-line' }, { key: 'link', label: 'Enlace de pago', icon: 'ri-links-line' }] as const"
            :key="choice.key"
            type="button"
            role="radio"
            class="flex items-center justify-center gap-2 rounded-vz border px-3 py-2 text-[13px] transition-colors"
            :class="mode === choice.key ? 'border-vz-secondary bg-vz-secondary/15 text-vz-secondary-text' : 'border-vz-border text-vz-muted hover:text-vz-topbar-item'"
            :aria-checked="mode === choice.key"
            @click="mode = choice.key"
          >
            <i :class="choice.icon" aria-hidden="true" /> {{ choice.label }}
          </button>
        </div>

        <div>
          <label class="vz-label" for="collect-amount">Valor a pagar</label>
          <input id="collect-amount" v-model="amount" class="vz-input" type="text" inputmode="decimal" autocomplete="off" :aria-invalid="amountError !== ''" aria-describedby="collect-amount-help" />
          <p v-if="amountError" class="vz-error" role="alert">{{ amountError }}</p>
          <p v-else id="collect-amount-help" class="vz-help">Un valor menor deja el resto por cobrar.</p>
          <p v-if="errors.fields.value.amount" class="vz-error" role="alert">{{ errors.fields.value.amount }}</p>
        </div>

        <template v-if="mode === 'desk'">
          <PaymentFields v-model="draft" :amount="amountMinor ?? 0" :currency="payable.currency" :errors="paymentErrors" />

          <div>
            <label class="vz-label" for="collect-notes">Observaciones <span class="font-normal text-vz-muted">(opcional)</span></label>
            <input id="collect-notes" v-model="notes" class="vz-input" type="text" maxlength="500" />
          </div>
        </template>

        <div v-else>
          <label class="vz-label" for="collect-account">Pasarela</label>
          <select id="collect-account" v-model="accountId" class="vz-input">
            <option v-for="option in options" :key="option.id" :value="option.id">{{ option.name }} ({{ option.provider_label }}{{ option.sandbox ? ', pruebas' : '' }})</option>
          </select>
          <p class="vz-help">El cliente elige tarjeta, PSE u otro medio en la página de la pasarela.</p>
        </div>

        <p v-if="errors.general.value" class="vz-error" role="alert">{{ errors.general.value }}</p>
      </div>

      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving || (mode === 'link' && accountId === '')">
          <i :class="saving ? 'ri-loader-4-line animate-spin' : mode === 'link' ? 'ri-links-line' : 'ri-money-dollar-circle-line'" aria-hidden="true" />
          {{ mode === 'link' ? 'Crear enlace' : 'Cobrar' }} {{ amountMinor ? formatMoney(amountMinor, payable.currency) : '' }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>
