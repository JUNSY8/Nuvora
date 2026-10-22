<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { usePermissions } from '@/composables/usePermissions'
import { listParkingLots } from '@/modules/parking/api'
import type { ParkingLot } from '@/modules/parking/types'
import { listSessions } from '@/modules/sessions/api'
import { sessionStatus, sessionStatusOrder } from '@/modules/sessions/labels'
import type { ParkingSession, SessionStatus } from '@/modules/sessions/types'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import { formatDateTime, formatDuration } from '@/services/format'
import { formatMoney } from '@/services/money'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import type { PageMeta } from '@/types/api'

const auth = useAuthStore()
const route = useRoute()
const { can } = usePermissions()

const sessions = ref<ParkingSession[]>([])
const meta = ref<PageMeta | null>(null)
const lots = ref<ParkingLot[]>([])
const state = ref<'loading' | 'ready' | 'denied' | 'no-tenant' | 'error'>('loading')
const errorMessage = ref('')

const filters = reactive<{ lot_id: string; status: '' | SessionStatus; search: string; from_date: string; to_date: string }>({
  lot_id: '',
  status: '',
  search: typeof route.query.search === 'string' ? route.query.search : '',
  from_date: '',
  to_date: '',
})
const debouncedSearch = refDebounced(computed(() => filters.search), 300)
const datesNeedLot = computed(() => filters.lot_id === '' && (filters.from_date !== '' || filters.to_date !== ''))

function lotTimezone(session: ParkingSession): string {
  return lots.value.find((lot) => lot.id === session.parking_lot.id)?.timezone ?? auth.currentTenant?.timezone ?? 'America/Bogota'
}

async function load(page = 1): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  if (datesNeedLot.value) {
    return
  }

  state.value = 'loading'

  try {
    const result = await listSessions({
      lot_id: filters.lot_id || undefined,
      status: filters.status || undefined,
      search: debouncedSearch.value || undefined,
      from_date: filters.from_date || undefined,
      to_date: filters.to_date || undefined,
    }, page)
    sessions.value = result.items
    meta.value = result.meta
    state.value = 'ready'
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'FORBIDDEN' ? 'denied' : code === 'TENANT_REQUIRED' ? 'no-tenant' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar las sesiones.')
  }
}

onMounted(async () => {
  if (can('parking.view')) {
    try {
      lots.value = (await listParkingLots({ per_page: 100 })).items
    } catch {
      lots.value = []
    }
  }

  await load()
})

watch(() => [filters.lot_id, filters.status, filters.from_date, filters.to_date, debouncedSearch.value], () => load(1))
</script>

<template>
  <div>
    <PageHeader title="Historial de sesiones" subtitle="Estancias registradas, con su duración y quién las operó." :breadcrumbs="[{ label: 'Operación' }, { label: 'Historial' }]">
      <template v-if="can('sessions.create')" #actions>
        <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'operations' }">
          <i class="ri-arrow-left-right-line" aria-hidden="true" /> Ir a entradas y salidas
        </RouterLink>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para ver sus sesiones.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar las sesiones." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load()">Reintentar</button>
    </StateCard>

    <div v-else class="vz-card">
      <div class="grid gap-3 border-b border-vz-border p-4 sm:grid-cols-2 xl:grid-cols-5">
        <div class="xl:col-span-1">
          <label class="vz-label" for="history-search">Placa</label>
          <input id="history-search" v-model="filters.search" class="vz-input font-mono uppercase" type="search" autocomplete="off" placeholder="ABC123" />
        </div>
        <div>
          <label class="vz-label" for="history-lot">Parqueadero</label>
          <select id="history-lot" v-model="filters.lot_id" class="vz-input">
            <option value="">Todos</option>
            <option v-for="lot in lots" :key="lot.id" :value="lot.id">{{ lot.name }}</option>
          </select>
        </div>
        <div>
          <label class="vz-label" for="history-status">Estado</label>
          <select id="history-status" v-model="filters.status" class="vz-input">
            <option value="">Todos</option>
            <option v-for="status in sessionStatusOrder" :key="status" :value="status">{{ sessionStatus[status].label }}</option>
          </select>
        </div>
        <div>
          <label class="vz-label" for="history-from">Desde</label>
          <input id="history-from" v-model="filters.from_date" class="vz-input" type="date" :max="filters.to_date || undefined" />
        </div>
        <div>
          <label class="vz-label" for="history-to">Hasta</label>
          <input id="history-to" v-model="filters.to_date" class="vz-input" type="date" :min="filters.from_date || undefined" />
        </div>
        <p v-if="datesNeedLot" class="vz-error sm:col-span-2 xl:col-span-5" role="alert">Elige un parqueadero para filtrar por fecha: los días se cuentan en su zona horaria.</p>
      </div>

      <div v-if="state === 'loading'" class="p-8 text-center text-vz-muted" role="status"><i class="ri-loader-4-line animate-spin text-2xl" aria-hidden="true" /><p>Cargando sesiones…</p></div>
      <div v-else-if="sessions.length === 0" class="p-10 text-center">
        <i class="ri-history-line text-4xl text-vz-muted" aria-hidden="true" />
        <p class="mt-2 font-medium text-vz-heading">Sin sesiones</p>
        <p class="text-[13px] text-vz-muted">Ninguna sesión coincide con los filtros.</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="vz-table">
          <thead>
            <tr>
              <th scope="col">Placa</th>
              <th scope="col">Parqueadero</th>
              <th scope="col">Entrada</th>
              <th scope="col">Salida</th>
              <th scope="col">Duración</th>
              <th scope="col" class="text-right">Valor</th>
              <th scope="col">Estado</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="session in sessions" :key="session.id">
              <td>
                <RouterLink v-if="can('vehicles.view')" :to="{ name: 'vehicle', params: { vehicleId: session.vehicle.id } }" :aria-label="`Ver vehículo ${session.vehicle.plate}`">
                  <PlateChip :plate="session.vehicle.plate" :country="session.vehicle.country" />
                </RouterLink>
                <PlateChip v-else :plate="session.vehicle.plate" :country="session.vehicle.country" />
                <p v-if="session.customer" class="mt-1 text-xs text-vz-muted">{{ session.customer.name }}</p>
                <p v-if="session.reservation" class="mt-1 text-xs text-vz-primary-text"><i class="ri-calendar-check-line" aria-hidden="true" /> {{ session.reservation.code }}</p>
                <p v-if="session.membership" class="mt-1 text-xs text-vz-success-text" :title="session.membership.plan"><i class="ri-id-card-line" aria-hidden="true" /> {{ session.membership.code }}</p>
              </td>
              <td>
                <p class="text-vz-heading">{{ session.parking_lot.name }}</p>
                <p class="text-xs text-vz-muted">{{ session.space ? `Espacio ${session.space.code}` : 'Sin espacio' }}</p>
              </td>
              <td class="whitespace-nowrap">
                {{ formatDateTime(session.entry_at, lotTimezone(session)) }}
                <p class="text-xs text-vz-muted">{{ session.opened_by?.name ?? '—' }}<template v-if="session.entry_gate"> · {{ session.entry_gate.code }}</template></p>
              </td>
              <td class="whitespace-nowrap">
                <template v-if="session.exit_at">
                  {{ formatDateTime(session.exit_at, lotTimezone(session)) }}
                  <p class="text-xs text-vz-muted">{{ session.closed_by?.name ?? '—' }}<template v-if="session.exit_gate"> · {{ session.exit_gate.code }}</template></p>
                </template>
                <template v-else-if="session.cancelled_at">
                  <span class="text-vz-muted">{{ formatDateTime(session.cancelled_at, lotTimezone(session)) }}</span>
                  <p class="max-w-56 truncate text-xs text-vz-muted" :title="session.cancel_reason ?? ''">{{ session.cancel_reason }}</p>
                </template>
                <span v-else class="text-vz-muted">—</span>
              </td>
              <td class="whitespace-nowrap">{{ formatDuration(session.duration_minutes) }}</td>
              <td class="text-right whitespace-nowrap">
                <template v-if="session.charge?.status === 'priced'">
                  <span class="font-medium text-vz-heading tabular-nums">{{ formatMoney(session.charge.total, session.charge.currency ?? 'COP') }}</span>
                  <p class="text-xs text-vz-muted">
                    {{ session.charge.grace_applied ? 'Gracia' : session.charge.tariff?.name }}<template v-if="session.charge.discount_rule"> · {{ session.charge.discount_rule.name }}</template>
                  </p>
                </template>
                <template v-else-if="session.charge?.status === 'covered'">
                  <span class="font-medium text-vz-success-text tabular-nums">{{ formatMoney(0, session.charge.currency ?? 'COP') }}</span>
                  <p class="text-xs text-vz-muted" :title="`Valor de la estancia: ${formatMoney(session.charge.subtotal, session.charge.currency ?? 'COP')}`">Mensualidad {{ session.charge.membership?.code }}</p>
                </template>
                <span v-else-if="session.charge?.status === 'unpriced'" class="text-xs text-vz-warning-text" :title="session.charge.reason ?? ''">Sin tarifa</span>
                <span v-else class="text-vz-muted">—</span>
              </td>
              <td>
                <StatusBadge :label="sessionStatus[session.status].label" :tone="sessionStatus[session.status].tone" :icon="sessionStatus[session.status].icon" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="meta && state === 'ready'" :meta="meta" @change="load" />
    </div>
  </div>
</template>
