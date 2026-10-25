<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StateCard from '@/components/ui/StateCard.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { closeReconciliation, listReconciliations, previewReconciliation } from '@/modules/payments/api'
import { paymentMethod, paymentMethods } from '@/modules/payments/labels'
import type { Reconciliation, ReconciliationPreview } from '@/modules/payments/types'
import { listMembers } from '@/modules/tenancy/api'
import { formatDateTime } from '@/services/format'
import { parseApiError } from '@/services/http'
import { formatMoney, toMinorUnits } from '@/services/money'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { PageMeta } from '@/types/api'

// Cash closing: what a cashier received and refunded since their last
// closing, what the drawer should hold and what was counted.
const props = defineProps<{ timezone: string }>()
const emit = defineEmits<{ closed: [] }>()

const auth = useAuthStore()
const toasts = useToastStore()
const { can } = usePermissions()

const cashiers = ref<Array<{ id: string; name: string }>>([])
const cashierId = ref(auth.user?.id ?? '')
const preview = ref<ReconciliationPreview[]>([])
const previewState = ref<'loading' | 'ready' | 'denied' | 'error'>('loading')
const previewError = ref('')
const counted = ref<Record<string, string>>({})
const notes = ref('')
const confirming = ref<ReconciliationPreview | null>(null)
const saving = ref(false)
const errors = useFieldErrors()
const closings = ref<Reconciliation[]>([])
const meta = ref<PageMeta | null>(null)

const canCloseOthers = computed(() => can('payments.reconcile'))
const confirmMessage = computed(() => {
  const item = confirming.value

  if (!item) {
    return ''
  }

  const value = countedMinor(item.currency) ?? 0
  const difference = value - item.expected_cash
  const outcome = difference === 0 ? 'La caja cuadra.' : difference > 0 ? `Sobran ${formatMoney(difference, item.currency)}.` : `Faltan ${formatMoney(-difference, item.currency)}.`

  return `Se cierran ${item.payments_count} pago(s) y ${item.refunds_count} reembolso(s). Efectivo esperado ${formatMoney(item.expected_cash, item.currency)}, contado ${formatMoney(value, item.currency)}. ${outcome}`
})

function countedMinor(currency: string): number | null {
  const text = counted.value[currency] ?? ''

  return text.trim() === '' ? null : toMinorUnits(text, currency)
}

async function loadPreview(): Promise<void> {
  previewState.value = 'loading'

  try {
    preview.value = await previewReconciliation(cashierId.value === auth.user?.id ? undefined : cashierId.value)
    counted.value = {}
    previewState.value = 'ready'
  } catch (error) {
    previewError.value = parseApiError(error, 'No fue posible consultar la caja.')
    previewState.value = (error as { response?: { status?: number } }).response?.status === 403 ? 'denied' : 'error'
  }
}

async function loadClosings(page = 1): Promise<void> {
  try {
    const result = await listReconciliations(page, 10)
    closings.value = result.items
    meta.value = result.meta
  } catch (error) {
    toasts.error(parseApiError(error, 'No fue posible consultar los cierres.'))
  }
}

// Members only name the cashiers to choose from; without users.view the panel closes one's own cash.
async function loadCashiers(): Promise<void> {
  if (!canCloseOthers.value || !can('users.view')) {
    return
  }

  try {
    cashiers.value = (await listMembers()).filter((member) => member.status === 'active').map((member) => ({ id: member.user.id, name: member.user.name }))
  } catch {
    cashiers.value = []
  }
}

function askClose(item: ReconciliationPreview): void {
  errors.clear()
  const value = countedMinor(item.currency)

  if (value === null || value < 0) {
    errors.fields.value = { [`counted_${item.currency}`]: 'Escribe el efectivo contado.' }
    return
  }

  confirming.value = item
}

async function onClose(): Promise<void> {
  const item = confirming.value

  if (!item || saving.value) {
    return
  }

  saving.value = true

  try {
    const closing = await closeReconciliation({
      cashier_id: cashierId.value === auth.user?.id ? undefined : cashierId.value,
      currency: item.currency,
      counted_cash: countedMinor(item.currency) ?? 0,
      notes: notes.value.trim() === '' ? null : notes.value.trim(),
    })
    confirming.value = null
    notes.value = ''
    toasts.success(`Cierre ${closing.number} registrado. Diferencia: ${formatMoney(closing.difference, closing.currency)}.`)
    emit('closed')
    await Promise.all([loadPreview(), loadClosings(1)])
  } catch (error) {
    confirming.value = null
    errors.capture(error, 'No fue posible cerrar la caja.')
  } finally {
    saving.value = false
  }
}

watch(cashierId, () => {
  void loadPreview()
})

onMounted(() => {
  void loadCashiers()
  void loadPreview()
  void loadClosings()
})
</script>

<template>
  <div class="space-y-5 p-4">
    <div v-if="canCloseOthers && cashiers.length > 0" class="max-w-xs">
      <label class="vz-label" for="closing-cashier">Cajero</label>
      <select id="closing-cashier" v-model="cashierId" class="vz-input">
        <option v-for="cashier in cashiers" :key="cashier.id" :value="cashier.id">{{ cashier.name }}{{ cashier.id === auth.user?.id ? ' (yo)' : '' }}</option>
      </select>
    </div>

    <StateCard v-if="previewState === 'loading'" variant="loading" compact />
    <StateCard v-else-if="previewState === 'denied'" variant="denied" message="Tu rol no permite cerrar caja." compact />
    <StateCard v-else-if="previewState === 'error'" variant="error" :message="previewError" compact>
      <button class="vz-btn vz-btn-primary" type="button" @click="loadPreview">Reintentar</button>
    </StateCard>
    <StateCard v-else-if="preview.length === 0" variant="empty" title="Caja al día" message="No hay pagos ni reembolsos pendientes de cierre." compact />

    <section v-for="item in preview" v-else :key="item.currency" class="rounded-vz border border-vz-border" :aria-label="`Caja en ${item.currency}`">
      <header class="flex flex-wrap items-center justify-between gap-2 border-b border-vz-border px-4 py-3">
        <h3 class="text-[14px] font-semibold text-vz-heading">Movimientos sin cerrar · {{ item.currency }}</h3>
        <p class="text-xs text-vz-muted">Desde {{ formatDateTime(item.first_movement_at, props.timezone) }} · {{ item.payments_count }} pago(s), {{ item.refunds_count }} reembolso(s)</p>
      </header>
      <div class="overflow-x-auto">
        <table class="vz-table">
          <thead>
            <tr>
              <th scope="col">Medio</th>
              <th scope="col" class="text-right">Pagos</th>
              <th scope="col" class="text-right">Recibido</th>
              <th scope="col" class="text-right">Reembolsado</th>
              <th scope="col" class="text-right">Neto</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="method in paymentMethods" :key="method">
              <td><i :class="paymentMethod[method].icon" aria-hidden="true" /> {{ paymentMethod[method].label }}</td>
              <td class="text-right">{{ item.by_method[method].count }}</td>
              <td class="text-right">{{ formatMoney(item.by_method[method].collected, item.currency) }}</td>
              <td class="text-right">{{ formatMoney(item.by_method[method].refunded, item.currency) }}</td>
              <td class="text-right font-medium text-vz-heading">{{ formatMoney(item.by_method[method].net, item.currency) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <form class="grid gap-3 border-t border-vz-border p-4 sm:grid-cols-[1fr_1fr_2fr_auto] sm:items-end" novalidate @submit.prevent="askClose(item)">
        <div>
          <p class="vz-label">Efectivo esperado</p>
          <p class="py-2 text-base font-semibold text-vz-heading">{{ formatMoney(item.expected_cash, item.currency) }}</p>
        </div>
        <div>
          <label class="vz-label" :for="`counted-${item.currency}`">Efectivo contado</label>
          <input :id="`counted-${item.currency}`" v-model="counted[item.currency]" class="vz-input" type="text" inputmode="decimal" autocomplete="off" :aria-invalid="!!errors.fields.value[`counted_${item.currency}`]" />
          <p v-if="errors.fields.value[`counted_${item.currency}`]" class="vz-error" role="alert">{{ errors.fields.value[`counted_${item.currency}`] }}</p>
        </div>
        <div>
          <label class="vz-label" :for="`closing-notes-${item.currency}`">Observaciones <span class="font-normal text-vz-muted">(opcional)</span></label>
          <input :id="`closing-notes-${item.currency}`" v-model="notes" class="vz-input" type="text" maxlength="500" />
        </div>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">
          <i class="ri-safe-2-line" aria-hidden="true" /> Cerrar caja
        </button>
      </form>
    </section>
    <p v-if="errors.general.value" class="vz-error" role="alert">{{ errors.general.value }}</p>

    <section aria-labelledby="closings-title">
      <h3 id="closings-title" class="mb-2 text-[14px] font-semibold text-vz-heading">Cierres anteriores</h3>
      <p v-if="closings.length === 0" class="text-[13px] text-vz-muted">Todavía no hay cierres de caja.</p>
      <div v-else class="overflow-x-auto rounded-vz border border-vz-border">
        <table class="vz-table">
          <thead>
            <tr>
              <th scope="col">Cierre</th>
              <th scope="col">Cajero</th>
              <th scope="col" class="text-right">Esperado</th>
              <th scope="col" class="text-right">Contado</th>
              <th scope="col" class="text-right">Diferencia</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="closing in closings" :key="closing.id">
              <td>
                <code class="text-xs">{{ closing.number }}</code>
                <p class="text-xs text-vz-muted">{{ formatDateTime(closing.closed_at, props.timezone) }}</p>
              </td>
              <td>
                {{ closing.cashier?.name ?? '—' }}
                <p v-if="closing.closed_by && closing.closed_by.id !== closing.cashier?.id" class="text-xs text-vz-muted">Cerró {{ closing.closed_by.name }}</p>
              </td>
              <td class="text-right">{{ formatMoney(closing.expected_cash, closing.currency) }}</td>
              <td class="text-right">{{ formatMoney(closing.counted_cash, closing.currency) }}</td>
              <td class="text-right font-medium" :class="closing.difference === 0 ? 'text-vz-success-text' : closing.difference > 0 ? 'text-vz-info-text' : 'text-vz-danger-text'">
                {{ formatMoney(closing.difference, closing.currency) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="meta && meta.last_page > 1" :meta="meta" @change="loadClosings" />
    </section>

    <BaseModal :open="confirming !== null" title="Cerrar caja" size="sm" @close="confirming = null">
      <p class="px-5 py-5 text-[13px] text-vz-text">{{ confirmMessage }}</p>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="confirming = null">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="button" :disabled="saving" @click="onClose">
          <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-safe-2-line'" aria-hidden="true" /> Confirmar cierre
        </button>
      </div>
    </BaseModal>
  </div>
</template>
