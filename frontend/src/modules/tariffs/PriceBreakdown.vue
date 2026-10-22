<script setup lang="ts">
import { describeMinutes } from '@/modules/tariffs/labels'
import type { PriceLine } from '@/modules/tariffs/types'
import { formatMoney } from '@/services/money'

// Explanation of a price as the engine computed it. Used by the exit, the
// simulator and the session history; it never recomputes anything.
defineProps<{
  status: 'priced' | 'unpriced' | 'covered'
  currency: string
  total: number | null
  subtotal: number | null
  discount: number | null
  lines: PriceLine[]
  tariff: { name: string; code: string; version: number } | null
  chargedMinutes?: number | null
  reason?: string | null
  totalLabel?: string
  membership?: { code: string; plan: string } | null
}>()

// Discounts and the membership's coverage subtract from the price.
function credits(line: PriceLine): boolean {
  return line.type === 'discount' || line.type === 'membership'
}
</script>

<template>
  <div class="rounded-vz border border-vz-border bg-vz-elevated/40 text-[13px]">
    <div v-if="status === 'unpriced'" class="flex items-start gap-2 p-3 text-vz-warning-text" role="status">
      <i class="ri-error-warning-line mt-0.5" aria-hidden="true" />
      <p>{{ reason ?? 'Ninguna tarifa aplica a esta estancia.' }} La salida se registra sin cobro.</p>
    </div>

    <template v-else>
      <p v-if="status === 'covered'" class="flex items-center gap-2 border-b border-vz-border bg-vz-success/10 px-3 py-2 text-vz-success-text" role="status">
        <i class="ri-id-card-line" aria-hidden="true" />
        <span>Cubierta por la mensualidad <strong>{{ membership?.code }}</strong><template v-if="membership?.plan"> ({{ membership.plan }})</template>.</span>
      </p>

      <div v-if="tariff" class="flex items-center justify-between gap-3 border-b border-vz-border px-3 py-2">
        <p class="min-w-0 truncate text-vz-muted">
          <i class="ri-price-tag-3-line" aria-hidden="true" />
          {{ tariff.name }} <span class="text-xs">· {{ tariff.code }} v{{ tariff.version }}</span>
        </p>
        <p v-if="chargedMinutes !== null && chargedMinutes !== undefined" class="shrink-0 text-xs text-vz-muted">Cobrado: {{ describeMinutes(chargedMinutes) }}</p>
      </div>

      <ul class="space-y-1 px-3 py-2">
        <li v-for="(line, index) in lines" :key="index" class="flex items-baseline justify-between gap-3">
          <span :class="credits(line) ? 'text-vz-success-text' : 'text-vz-text'">{{ line.label }}</span>
          <span class="shrink-0 tabular-nums" :class="credits(line) ? 'text-vz-success-text' : 'text-vz-heading'">
            {{ line.amount < 0 ? `− ${formatMoney(-line.amount, currency)}` : formatMoney(line.amount, currency) }}
          </span>
        </li>
      </ul>

      <dl class="border-t border-vz-border px-3 py-2">
        <div v-if="discount" class="flex justify-between text-xs text-vz-muted">
          <dt>{{ status === 'covered' ? 'Valor de la estancia' : 'Subtotal' }}</dt>
          <dd class="tabular-nums">{{ formatMoney(subtotal, currency) }}</dd>
        </div>
        <div class="flex items-baseline justify-between">
          <dt class="font-medium text-vz-heading">{{ totalLabel ?? 'Total' }}</dt>
          <dd class="text-lg font-semibold text-vz-heading tabular-nums">{{ formatMoney(total, currency) }}</dd>
        </div>
      </dl>
    </template>
  </div>
</template>
