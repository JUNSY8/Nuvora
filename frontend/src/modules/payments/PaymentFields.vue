<script setup lang="ts">
import { computed, useId } from 'vue'

import { changeFor, paymentMethod, paymentMethods, suggestedTenders } from '@/modules/payments/labels'
import type { PaymentDraft, PaymentMethod } from '@/modules/payments/types'
import { formatMoney, toMajorInput, toMinorUnits } from '@/services/money'

// How the money is received: method, cash handed over (with its change) and
// the reference of a manual payment. The parent owns the draft.
const props = defineProps<{
  amount: number
  currency: string
  errors?: Record<string, string>
}>()

const draft = defineModel<PaymentDraft>({ required: true })
const id = useId()

const tenderedMinor = computed(() => (draft.value.tendered.trim() === '' ? null : toMinorUnits(draft.value.tendered, props.currency)))
const change = computed(() => changeFor(props.amount, tenderedMinor.value))
const invalidTender = computed(() => draft.value.tendered.trim() !== '' && tenderedMinor.value === null)
const suggestions = computed(() => suggestedTenders(props.amount, props.currency))

function setMethod(method: PaymentMethod): void {
  draft.value = { ...draft.value, method }
}

function setTendered(minor: number): void {
  draft.value = { ...draft.value, tendered: toMajorInput(minor, props.currency) }
}
</script>

<template>
  <div class="space-y-3">
    <fieldset>
      <legend class="vz-label">Medio de pago</legend>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <button
          v-for="method in paymentMethods"
          :key="method"
          type="button"
          class="flex flex-col items-center gap-1 rounded-vz border px-2 py-2 text-[12px] transition-colors"
          :class="draft.method === method ? 'border-vz-secondary bg-vz-secondary/15 text-vz-secondary-text' : 'border-vz-border text-vz-muted hover:text-vz-topbar-item'"
          :aria-pressed="draft.method === method"
          @click="setMethod(method)"
        >
          <i :class="paymentMethod[method].icon" class="text-lg" aria-hidden="true" />
          {{ paymentMethod[method].label }}
        </button>
      </div>
      <p v-if="errors?.method" class="vz-error" role="alert">{{ errors.method }}</p>
    </fieldset>

    <div v-if="draft.method === 'cash'">
      <label class="vz-label" :for="`${id}-tendered`">Efectivo recibido <span class="font-normal text-vz-muted">(opcional)</span></label>
      <input
        :id="`${id}-tendered`"
        :value="draft.tendered"
        class="vz-input"
        type="text"
        inputmode="decimal"
        autocomplete="off"
        :placeholder="toMajorInput(amount, currency)"
        :aria-invalid="invalidTender || change === null"
        :aria-describedby="`${id}-change`"
        @input="draft = { ...draft, tendered: ($event.target as HTMLInputElement).value }"
      />
      <div v-if="suggestions.length > 0" class="mt-2 flex flex-wrap gap-1.5" aria-label="Valores rápidos">
        <button v-for="value in suggestions" :key="value" class="vz-btn vz-btn-sm vz-btn-light" type="button" @click="setTendered(value)">
          {{ formatMoney(value, currency) }}
        </button>
      </div>
      <p :id="`${id}-change`" class="mt-2 text-[13px]" aria-live="polite">
        <span v-if="invalidTender" class="text-vz-danger" role="alert">Escribe un valor válido.</span>
        <span v-else-if="change === null" class="text-vz-danger" role="alert">El efectivo no alcanza para {{ formatMoney(amount, currency) }}.</span>
        <span v-else class="text-vz-muted">Vueltas: <strong class="text-vz-heading">{{ formatMoney(change, currency) }}</strong></span>
      </p>
      <p v-if="errors?.tendered_amount" class="vz-error" role="alert">{{ errors.tendered_amount }}</p>
    </div>

    <div v-else>
      <label class="vz-label" :for="`${id}-reference`">Referencia <span class="font-normal text-vz-muted">(aprobación o comprobante)</span></label>
      <input
        :id="`${id}-reference`"
        :value="draft.reference"
        class="vz-input"
        type="text"
        maxlength="100"
        autocomplete="off"
        @input="draft = { ...draft, reference: ($event.target as HTMLInputElement).value }"
      />
      <p v-if="errors?.reference" class="vz-error" role="alert">{{ errors.reference }}</p>
    </div>
  </div>
</template>
