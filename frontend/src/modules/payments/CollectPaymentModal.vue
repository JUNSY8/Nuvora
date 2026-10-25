<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { collectPayment } from '@/modules/payments/api'
import { draftTender, newPaymentDraft } from '@/modules/payments/labels'
import PaymentFields from '@/modules/payments/PaymentFields.vue'
import type { PayableSummary, Payment, PaymentDraft } from '@/modules/payments/types'
import { newIdempotencyKey } from '@/services/idempotency'
import { formatMoney, toMajorInput, toMinorUnits } from '@/services/money'

// Collects what a closed stay or a membership period still owes. The amount
// defaults to the whole balance; a smaller one leaves the rest pending.
const props = defineProps<{ payable: PayableSummary | null }>()
const emit = defineEmits<{ close: []; paid: [payment: Payment] }>()

const amount = ref('')
const notes = ref('')
const draft = ref<PaymentDraft>(newPaymentDraft())
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

watch(() => props.payable, (payable) => {
  if (payable) {
    // A new dialog is a new attempt; retries inside it keep the key.
    attemptKey = newIdempotencyKey()
    amount.value = toMajorInput(payable.balance, payable.currency)
    notes.value = ''
    draft.value = newPaymentDraft()
    errors.clear()
  }
})

async function onConfirm(): Promise<void> {
  const payable = props.payable

  if (!payable || saving.value) {
    return
  }

  if (amountError.value !== '' || !tender.value.ok) {
    errors.general.value = amountError.value || 'El efectivo recibido debe cubrir el valor a cobrar.'
    return
  }

  saving.value = true
  errors.clear()

  try {
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
    errors.capture(error, 'No fue posible registrar el pago.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :open="payable !== null" title="Registrar pago" size="sm" @close="emit('close')">
    <form v-if="payable" novalidate @submit.prevent="onConfirm">
      <div class="space-y-4 px-5 py-5">
        <div class="rounded-vz bg-vz-elevated/50 p-3">
          <p class="font-medium text-vz-heading">{{ payable.title }}</p>
          <p class="text-[13px] text-vz-muted">{{ payable.subtitle }}</p>
          <p class="mt-2 text-[13px] text-vz-muted">Saldo pendiente: <strong class="text-vz-heading">{{ formatMoney(payable.balance, payable.currency) }}</strong></p>
        </div>

        <div>
          <label class="vz-label" for="collect-amount">Valor a pagar</label>
          <input id="collect-amount" v-model="amount" class="vz-input" type="text" inputmode="decimal" autocomplete="off" :aria-invalid="amountError !== ''" aria-describedby="collect-amount-help" />
          <p v-if="amountError" class="vz-error" role="alert">{{ amountError }}</p>
          <p v-else id="collect-amount-help" class="vz-help">Un valor menor deja el resto por cobrar.</p>
          <p v-if="errors.fields.value.amount" class="vz-error" role="alert">{{ errors.fields.value.amount }}</p>
        </div>

        <PaymentFields v-model="draft" :amount="amountMinor ?? 0" :currency="payable.currency" :errors="paymentErrors" />

        <div>
          <label class="vz-label" for="collect-notes">Observaciones <span class="font-normal text-vz-muted">(opcional)</span></label>
          <input id="collect-notes" v-model="notes" class="vz-input" type="text" maxlength="500" />
        </div>

        <p v-if="errors.general.value" class="vz-error" role="alert">{{ errors.general.value }}</p>
      </div>

      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">
          <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-money-dollar-circle-line'" aria-hidden="true" />
          Cobrar {{ amountMinor ? formatMoney(amountMinor, payable.currency) : '' }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>
