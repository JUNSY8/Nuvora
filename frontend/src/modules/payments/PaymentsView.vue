<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { usePermissions } from '@/composables/usePermissions'
import { listParkingLots } from '@/modules/parking/api'
import type { ParkingLot } from '@/modules/parking/types'
import { listPayments } from '@/modules/payments/api'
import CashClosingPanel from '@/modules/payments/CashClosingPanel.vue'
import { paymentMethod, paymentMethods, paymentNumber, paymentStatus, providerName } from '@/modules/payments/labels'
import PaymentDetailModal from '@/modules/payments/PaymentDetailModal.vue'
import ProvidersPanel from '@/modules/payments/ProvidersPanel.vue'
import type { Payment, PaymentMethod, PaymentStatus, PaymentTotals } from '@/modules/payments/types'
import { formatDateTime, formatNumber } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { formatMoney } from '@/services/money'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { PageMeta } from '@/types/api'

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()

const state = ref<'loading' | 'ready' | 'denied' | 'no-tenant' | 'error'>('loading')
const errorMessage = ref('')
const lots = ref<ParkingLot[]>([])
const payments = ref<Payment[]>([])
const totals = ref<PaymentTotals | null>(null)
const meta = ref<PageMeta | null>(null)
const loadingList = ref(false)
const viewing = ref<string | null>(null)

const filters = reactive<{ search: string; lot_id: string; method: '' | PaymentMethod; status: '' | PaymentStatus; from_date: string; to_date: string }>({
  search: '',
  lot_id: '',
  method: '',
  status: '',
  from_date: '',
  to_date: '',
})
const debouncedSearch = refDebounced(computed(() => filters.search), 300)

const tabs = computed(() => [
  { key: 'pagos', label: 'Pagos', icon: 'ri-bank-card-line' },
  { key: 'caja', label: 'Cierre de caja', icon: 'ri-safe-2-line' },
  ...(can('payments.configure') || can('payments.reconcile') ? [{ key: 'pasarelas', label: 'Pasarelas', icon: 'ri-global-line' }] : []),
])
const activeTab = computed(() => tabs.value.find((tab) => tab.key === route.query.tab)?.key ?? 'pagos')
const timezone = computed(() => auth.currentTenant?.timezone ?? 'America/Bogota')
const currency = computed(() => totals.value?.currencies[0] ?? lots.value[0]?.currency ?? 'COP')
const mixedCurrencies = computed(() => (totals.value?.currencies.length ?? 0) > 1)

function selectTab(key: string): void {
  void router.replace({ query: { ...route.query, tab: key } })
}

let listRequest = 0

async function loadList(page = 1): Promise<void> {
  const current = ++listRequest
  loadingList.value = true

  try {
    const result = await listPayments({
      search: debouncedSearch.value.trim() || undefined,
      lot_id: filters.lot_id || undefined,
      method: filters.method || undefined,
      status: filters.status || undefined,
      from_date: filters.from_date || undefined,
      to_date: filters.to_date || undefined,
    }, page, 25)

    if (current === listRequest) {
      payments.value = result.items
      totals.value = result.totals
      meta.value = result.meta
    }
  } catch (error) {
    if (current === listRequest) {
      toasts.error(parseApiError(error, 'No fue posible consultar los pagos.'))
    }
  } finally {
    if (current === listRequest) {
      loadingList.value = false
    }
  }
}

async function load(): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  if (!can('payments.view')) {
    state.value = 'denied'
    return
  }

  state.value = 'loading'

  try {
    if (can('parking.view')) {
      lots.value = (await listParkingLots({ per_page: 100 })).items
    }

    await loadList()
  } catch (error) {
    state.value = apiErrorCode(error) === 'FORBIDDEN' ? 'denied' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar los pagos.')
    return
  }

  state.value = 'ready'
}

function onChanged(_payment: Payment, message: string): void {
  toasts.success(message)
  void loadList(meta.value?.current_page ?? 1)
}

function clearFilters(): void {
  Object.assign(filters, { search: '', lot_id: '', method: '', status: '', from_date: '', to_date: '' })
}

watch([debouncedSearch, () => filters.lot_id, () => filters.method, () => filters.status, () => filters.from_date, () => filters.to_date], () => {
  if (state.value === 'ready') {
    void loadList(1)
  }
})
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Pagos" subtitle="Recaudo, reembolsos y cierres de caja" :breadcrumbs="[{ label: 'Operación' }, { label: 'Pagos' }]" />

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para ver sus pagos.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar pagos." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>
    <StateCard v-else-if="state === 'loading'" variant="loading" />

    <template v-else>
      <section v-if="activeTab === 'pagos'" class="mb-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4" aria-label="Totales de los filtros">
        <StatCard label="Recaudado" :value="totals ? formatMoney(totals.collected, currency) : '—'" icon="ri-money-dollar-circle-line" tone="success" :loading="loadingList && !totals" :hint="`${formatNumber(totals?.count ?? 0)} pago(s)`" />
        <StatCard label="Reembolsado" :value="totals ? formatMoney(totals.refunded, currency) : '—'" icon="ri-arrow-go-back-line" tone="warning" :loading="loadingList && !totals" hint="Devuelto de estos pagos" />
        <StatCard label="Neto" :value="totals ? formatMoney(totals.net, currency) : '—'" icon="ri-safe-2-line" tone="primary" :loading="loadingList && !totals" :hint="mixedCurrencies ? 'Hay varias monedas: filtra por parqueadero' : 'Recaudado menos reembolsos'" />
        <StatCard
          label="En efectivo"
          :value="totals ? formatMoney(totals.by_method.cash.net, currency) : '—'"
          icon="ri-money-dollar-box-line"
          tone="info"
          :loading="loadingList && !totals"
          :hint="totals ? `Otros medios: ${formatMoney(totals.net - totals.by_method.cash.net, currency)}` : ''"
        />
      </section>

      <div class="vz-card">
        <div class="overflow-x-auto border-b border-vz-border px-2" role="tablist" aria-label="Secciones de pagos">
          <div class="flex min-w-max">
            <button
              v-for="tab in tabs"
              :id="`tab-${tab.key}`"
              :key="tab.key"
              class="flex items-center gap-2 border-b-2 px-4 py-3.5 text-[13px] font-medium transition-colors"
              :class="activeTab === tab.key ? 'border-vz-secondary text-vz-secondary-text' : 'border-transparent text-vz-muted hover:text-vz-topbar-item'"
              type="button"
              role="tab"
              :aria-selected="activeTab === tab.key"
              :aria-controls="`panel-${tab.key}`"
              :tabindex="activeTab === tab.key ? 0 : -1"
              @click="selectTab(tab.key)"
            >
              <i :class="tab.icon" class="text-base" aria-hidden="true" /> {{ tab.label }}
            </button>
          </div>
        </div>

        <div v-if="activeTab === 'pagos'" id="panel-pagos" role="tabpanel" aria-labelledby="tab-pagos">
          <div class="grid gap-3 border-b border-vz-border p-4 sm:grid-cols-2 lg:grid-cols-6">
            <div class="lg:col-span-2">
              <label class="vz-label" for="payments-search">Recibo o placa</label>
              <input id="payments-search" v-model="filters.search" class="vz-input font-mono uppercase" type="search" maxlength="20" placeholder="RC-000123 o ABC123" />
            </div>
            <div v-if="lots.length > 1">
              <label class="vz-label" for="payments-lot">Parqueadero</label>
              <select id="payments-lot" v-model="filters.lot_id" class="vz-input">
                <option value="">Todos</option>
                <option v-for="lot in lots" :key="lot.id" :value="lot.id">{{ lot.name }}</option>
              </select>
            </div>
            <div>
              <label class="vz-label" for="payments-method">Medio</label>
              <select id="payments-method" v-model="filters.method" class="vz-input">
                <option value="">Todos</option>
                <option v-for="method in paymentMethods" :key="method" :value="method">{{ paymentMethod[method].label }}</option>
              </select>
            </div>
            <div>
              <label class="vz-label" for="payments-status">Estado</label>
              <select id="payments-status" v-model="filters.status" class="vz-input">
                <option value="">Todos</option>
                <option v-for="(item, key) in paymentStatus" :key="key" :value="key">{{ item.label }}</option>
              </select>
            </div>
            <div>
              <label class="vz-label" for="payments-from">Desde</label>
              <input id="payments-from" v-model="filters.from_date" class="vz-input" type="date" />
            </div>
            <div>
              <label class="vz-label" for="payments-to">Hasta</label>
              <input id="payments-to" v-model="filters.to_date" class="vz-input" type="date" :min="filters.from_date || undefined" />
            </div>
          </div>

          <StateCard v-if="loadingList && payments.length === 0" variant="loading" compact />
          <StateCard v-else-if="payments.length === 0" variant="empty" title="Sin pagos" message="Los pagos aparecen al cobrar una salida, una estancia pendiente o un periodo de mensualidad." compact>
            <button class="vz-btn vz-btn-light" type="button" @click="clearFilters">Quitar filtros</button>
          </StateCard>

          <div v-else class="overflow-x-auto">
            <table class="vz-table">
              <thead>
                <tr>
                  <th scope="col">Recibo</th>
                  <th scope="col">Concepto</th>
                  <th scope="col">Medio</th>
                  <th scope="col" class="text-right">Valor</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Atendió</th>
                  <th scope="col"><span class="sr-only">Acciones</span></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="payment in payments" :key="payment.id">
                  <td class="whitespace-nowrap">
                    <code class="text-xs" :class="payment.number ? 'text-vz-heading' : 'text-vz-muted'">{{ paymentNumber(payment) }}</code>
                    <p class="text-xs text-vz-muted">{{ formatDateTime(payment.received_at, timezone) }}</p>
                  </td>
                  <td>
                    {{ payment.description }}
                    <p v-if="payment.reference" class="text-xs text-vz-muted">Ref. {{ payment.reference }}</p>
                  </td>
                  <td class="whitespace-nowrap">
                    <template v-if="payment.status === 'pending' && payment.provider !== 'offline'"><i class="ri-links-line" aria-hidden="true" /> Enlace</template>
                    <template v-else><i :class="paymentMethod[payment.method].icon" aria-hidden="true" /> {{ paymentMethod[payment.method].label }}</template>
                    <p v-if="payment.provider !== 'offline'" class="text-xs text-vz-muted">{{ providerName[payment.provider] ?? payment.provider }}</p>
                  </td>
                  <td class="text-right whitespace-nowrap">
                    <span class="font-medium text-vz-heading">{{ formatMoney(payment.amount, payment.currency) }}</span>
                    <p v-if="payment.refunded_amount > 0" class="text-xs text-vz-muted">-{{ formatMoney(payment.refunded_amount, payment.currency) }}</p>
                  </td>
                  <td>
                    <div class="flex flex-col items-start gap-1">
                      <StatusBadge :label="paymentStatus[payment.status].label" :tone="paymentStatus[payment.status].tone" />
                      <StatusBadge v-if="payment.reconciled" label="Cerrado" tone="muted" icon="ri-safe-2-line" />
                    </div>
                  </td>
                  <td>{{ payment.received_by?.name ?? (payment.provider !== 'offline' ? 'En línea' : '—') }}</td>
                  <td class="text-right">
                    <button class="vz-btn vz-btn-sm vz-btn-light" type="button" :aria-label="`Ver el pago ${paymentNumber(payment)}`" @click="viewing = payment.id">
                      <i class="ri-eye-line" aria-hidden="true" /> Ver
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <PaginationBar v-if="meta" :meta="meta" @change="loadList" />
        </div>

        <div v-else-if="activeTab === 'caja'" id="panel-caja" role="tabpanel" aria-labelledby="tab-caja">
          <CashClosingPanel :timezone="timezone" @closed="loadList(1)" />
        </div>

        <div v-else id="panel-pasarelas" role="tabpanel" aria-labelledby="tab-pasarelas">
          <ProvidersPanel :timezone="timezone" @reconciled="loadList(meta?.current_page ?? 1)" />
        </div>
      </div>
    </template>

    <PaymentDetailModal :payment-id="viewing" :timezone="timezone" @close="viewing = null" @changed="onChanged" />
  </div>
</template>
