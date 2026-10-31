<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { getReceipt } from '@/modules/payments/api'
import type { Receipt } from '@/modules/payments/types'
import { formatDateTime, formatDuration } from '@/services/format'
import { parseApiError } from '@/services/http'
import { formatMoney } from '@/services/money'

// Printable receipt. It opens in its own tab, without the app frame, so the
// browser prints only the receipt. It is not an electronic invoice.
const props = defineProps<{ paymentId: string }>()

const receipt = ref<Receipt | null>(null)
const error = ref('')

const lines = computed(() => {
  const value = receipt.value?.details.lines

  return Array.isArray(value) ? value as Array<{ label: string; amount: number }> : []
})

function detail(key: string): string | null {
  const value = receipt.value?.details[key]

  return typeof value === 'string' || typeof value === 'number' ? String(value) : null
}

function onPrint(): void {
  window.print()
}

onMounted(async () => {
  try {
    receipt.value = await getReceipt(props.paymentId)
  } catch (failure) {
    error.value = parseApiError(failure, 'No fue posible consultar el recibo.')
  }
})
</script>

<template>
  <main class="receipt-page flex min-h-screen justify-center bg-vz-body px-4 py-8">
    <p v-if="error" class="vz-error" role="alert">{{ error }}</p>
    <p v-else-if="!receipt" class="text-vz-muted" role="status">Cargando…</p>

    <article v-else class="receipt w-full self-start max-w-sm rounded-vz bg-white p-6 font-mono text-[13px] text-neutral-900 shadow-lg" aria-label="Recibo de pago">
      <header class="border-b border-dashed border-neutral-400 pb-3 text-center">
        <p class="text-base font-bold">{{ receipt.tenant.name }}</p>
        <p v-if="receipt.parking_lot">{{ receipt.parking_lot.name }}</p>
        <p class="mt-2 font-bold">Recibo {{ receipt.number }}</p>
        <p>{{ formatDateTime(receipt.issued_at, receipt.timezone) }}</p>
      </header>

      <section class="space-y-1 border-b border-dashed border-neutral-400 py-3">
        <p class="font-bold">{{ receipt.description }}</p>
        <template v-if="detail('kind') === 'parking_session'">
          <p>Placa: {{ receipt.plate }}<template v-if="detail('space_code')"> · Espacio {{ detail('space_code') }}</template></p>
          <p>Entrada: {{ formatDateTime(detail('entry_at'), receipt.timezone) }}</p>
          <p>Salida: {{ formatDateTime(detail('exit_at'), receipt.timezone) }}</p>
          <p v-if="detail('duration_minutes')">Tiempo: {{ formatDuration(Number(detail('duration_minutes'))) }}</p>
          <p v-if="detail('tariff')">Tarifa: {{ detail('tariff') }}</p>
          <ul v-if="lines.length > 0" class="mt-2">
            <li v-for="(line, index) in lines" :key="index" class="flex justify-between gap-3"><span>{{ line.label }}</span><span>{{ formatMoney(line.amount, receipt.currency) }}</span></li>
          </ul>
        </template>
        <template v-else>
          <p v-if="detail('customer')">Cliente: {{ detail('customer') }}</p>
          <p>Plan: {{ detail('plan') }}</p>
          <p>Periodo: {{ detail('starts_on') }} a {{ detail('ends_on') }}</p>
        </template>
      </section>

      <section class="space-y-1 py-3">
        <p class="flex justify-between text-base font-bold"><span>Total pagado</span><span>{{ formatMoney(receipt.amount, receipt.currency) }}</span></p>
        <p class="flex justify-between"><span>Medio</span><span>{{ receipt.method_label }}</span></p>
        <template v-if="receipt.method === 'cash'">
          <p class="flex justify-between"><span>Recibido</span><span>{{ formatMoney(receipt.tendered, receipt.currency) }}</span></p>
          <p class="flex justify-between"><span>Vueltas</span><span>{{ formatMoney(receipt.change, receipt.currency) }}</span></p>
        </template>
        <p v-else-if="receipt.reference" class="flex justify-between"><span>Referencia</span><span>{{ receipt.reference }}</span></p>
        <template v-for="refund in receipt.refunds" :key="refund.number">
          <p class="flex justify-between"><span>Reembolso {{ refund.number }}</span><span>-{{ formatMoney(refund.amount, receipt.currency) }}</span></p>
        </template>
      </section>

      <footer class="border-t border-dashed border-neutral-400 pt-3 text-center text-[12px]">
        <p v-if="receipt.cashier">Atendió: {{ receipt.cashier }}</p>
        <p>Comprobante de pago. No es factura electrónica.</p>
      </footer>

      <div class="no-print mt-5 flex justify-center">
        <button class="vz-btn vz-btn-primary" type="button" @click="onPrint"><i class="ri-printer-line" aria-hidden="true" /> Imprimir</button>
      </div>
    </article>
  </main>
</template>

<style scoped>
@media print {
  .receipt-page {
    background: white;
    padding: 0;
  }

  .receipt {
    box-shadow: none;
    max-width: none;
  }

  .no-print {
    display: none;
  }
}
</style>
