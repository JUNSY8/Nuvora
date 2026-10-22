<script setup lang="ts">
import { refDebounced, useIntervalFn } from '@vueuse/core'
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ProgressBar from '@/components/ui/ProgressBar.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useLiveUpdates } from '@/composables/useLiveUpdates'
import { usePermissions } from '@/composables/usePermissions'
import { listGates, listParkingLots, listSpaces } from '@/modules/parking/api'
import { lotStatus } from '@/modules/parking/labels'
import type { ParkingGate, ParkingLot, ParkingSpace } from '@/modules/parking/types'
import { getOperationsSummary, listSessions } from '@/modules/sessions/api'
import CancelSessionModal from '@/modules/sessions/CancelSessionModal.vue'
import EntryForm from '@/modules/sessions/EntryForm.vue'
import ExitDialog from '@/modules/sessions/ExitDialog.vue'
import { LONG_STAY_MINUTES } from '@/modules/sessions/labels'
import UpcomingArrivals from '@/modules/reservations/UpcomingArrivals.vue'
import MoveSpaceModal from '@/modules/sessions/MoveSpaceModal.vue'
import type { OperationsSummary, ParkingSession, SessionNotice, SessionResult } from '@/modules/sessions/types'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import { vehicleType } from '@/modules/vehicles/labels'
import { formatDuration, formatNumber, formatTime, minutesSince } from '@/services/format'
import { formatMoney } from '@/services/money'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { PageMeta } from '@/types/api'

const auth = useAuthStore()
const toasts = useToastStore()
const { can } = usePermissions()

const state = ref<'loading' | 'ready' | 'no-lots' | 'denied' | 'no-tenant' | 'error'>('loading')
const errorMessage = ref('')
const lots = ref<ParkingLot[]>([])
const lotId = ref<string | null>(null)
const summary = ref<OperationsSummary | null>(null)
const sessions = ref<ParkingSession[]>([])
const meta = ref<PageMeta | null>(null)
const gates = ref<ParkingGate[]>([])
const spaces = ref<ParkingSpace[]>([])
const refreshing = ref(false)
// Bumped after every reload so side panels refresh with the same live signal.
const refreshes = ref(0)
const search = ref('')
const debouncedSearch = refDebounced(search, 300)
const lastEntry = ref<ParkingSession | null>(null)
// What the entry answered about the vehicle (membership covered or not).
const lastNotices = ref<SessionNotice[]>([])
const now = ref(Date.now())

const exiting = ref<ParkingSession | null>(null)
const moving = ref<ParkingSession | null>(null)
const cancelling = ref<ParkingSession | null>(null)

const timezone = computed(() => summary.value?.lot.timezone ?? auth.currentTenant?.timezone ?? 'America/Bogota')
const operating = computed(() => summary.value?.lot.status === 'active')
const chargeHint = computed(() => {
  const value = summary.value
  const parts = [
    value && value.unpriced_today > 0 ? `${value.unpriced_today} sin tarifa` : '',
    value && value.covered_today > 0 ? `${value.covered_today} por mensualidad` : '',
  ].filter(Boolean)

  return parts.length > 0 ? `Salidas: ${parts.join(' · ')}` : 'Calculado en las salidas'
})
const usableGates = (direction: 'entry' | 'exit') => gates.value.filter((gate) => gate.status === 'active' && (gate.direction === direction || gate.direction === 'bidirectional'))
const entryGates = computed(() => usableGates('entry'))
const exitGates = computed(() => usableGates('exit'))

const storageKey = computed(() => `nuvora.operations.lot.${auth.currentTenant?.id ?? 'none'}`)

function remember(id: string): void {
  try {
    localStorage.setItem(storageKey.value, id)
  } catch {
    // Remembering the lot is a convenience only.
  }
}

function remembered(): string | null {
  try {
    return localStorage.getItem(storageKey.value)
  } catch {
    return null
  }
}

async function loadSessions(page = meta.value?.current_page ?? 1): Promise<void> {
  if (!lotId.value) {
    return
  }

  const result = await listSessions({ lot_id: lotId.value, status: 'open', search: debouncedSearch.value || undefined }, page, 50)
  sessions.value = result.items
  meta.value = result.meta
}

// A change announced while a reload runs triggers one more reload afterwards.
let pendingRefresh = false

async function refresh(): Promise<void> {
  if (!lotId.value) {
    return
  }

  if (refreshing.value) {
    pendingRefresh = true
    return
  }

  refreshing.value = true
  pendingRefresh = false
  const id = lotId.value

  try {
    const [nextSummary, , nextSpaces] = await Promise.all([
      getOperationsSummary(id),
      loadSessions(),
      can('parking.view') ? listSpaces(id, { status: 'available' }, 1, 500).then((page) => page.items) : Promise.resolve([]),
    ])

    // Ignore answers for a lot the operator already left.
    if (lotId.value === id) {
      summary.value = nextSummary
      spaces.value = nextSpaces
      refreshes.value++
    }
  } catch (error) {
    toasts.error(parseApiError(error, 'No fue posible actualizar la operación.'))
  } finally {
    refreshing.value = false
  }

  if (pendingRefresh) {
    await refresh()
  }
}

async function selectLot(id: string): Promise<void> {
  lotId.value = id
  remember(id)
  summary.value = null
  sessions.value = []
  meta.value = null
  lastEntry.value = null
  search.value = ''

  try {
    gates.value = can('parking.view') ? await listGates(id) : []
  } catch {
    gates.value = []
  }

  await refresh()
}

async function load(): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  if (!can('sessions.view')) {
    state.value = 'denied'
    return
  }

  state.value = 'loading'

  try {
    lots.value = (await listParkingLots({ per_page: 100 })).items
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'FORBIDDEN' ? 'denied' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar los parqueaderos.')
    return
  }

  if (lots.value.length === 0) {
    state.value = 'no-lots'
    return
  }

  const saved = remembered()
  const initial = lots.value.find((lot) => lot.id === saved) ?? lots.value.find((lot) => lot.status === 'active') ?? lots.value[0]
  state.value = 'ready'
  await selectLot(initial!.id)
}

function onEntered(result: SessionResult): void {
  lastEntry.value = result.session
  lastNotices.value = result.notices ?? []
  const where = (result.session.space ? `espacio ${result.session.space.code}` : 'sin espacio asignado')
    + (result.session.reservation ? `, reserva ${result.session.reservation.code}` : '')
    + (result.session.membership ? `, mensualidad ${result.session.membership.code}` : '')

  if (result.replayed) {
    toasts.info(`La entrada de ${result.session.vehicle.plate} ya estaba registrada (${where}).`)
  } else {
    toasts.success(`Entrada de ${result.session.vehicle.plate} registrada: ${where}.`)
  }

  void refresh()
}

function onExited(result: SessionResult): void {
  exiting.value = null
  const charge = result.session.charge
  const amount = charge?.status === 'priced'
    ? `Valor: ${formatMoney(charge.total, charge.currency ?? 'COP')}.`
    : charge?.status === 'covered'
      ? `Cubierta por la mensualidad ${charge.membership?.code ?? ''}: sin cobro.`
      : 'Sin tarifa aplicable: no se calculó cobro.'
  toasts.success(`Salida de ${result.session.vehicle.plate} registrada. Tiempo: ${formatDuration(result.session.duration_minutes)}. ${amount}`)

  if (lastEntry.value?.id === result.session.id) {
    lastEntry.value = null
  }

  void refresh()
}

function onMoved(session: ParkingSession): void {
  moving.value = null
  toasts.success(`${session.vehicle.plate} quedó en el espacio ${session.space?.code ?? '—'}.`)
  void refresh()
}

function onCancelled(session: ParkingSession): void {
  cancelling.value = null
  toasts.success(`Sesión de ${session.vehicle.plate} anulada.`)
  void refresh()
}

// Enter in the search box opens the exit of the only matching vehicle.
function onSearchEnter(): void {
  if (sessions.value.length === 1 && can('sessions.close')) {
    exiting.value = sessions.value[0]!
  }
}

function stayMinutes(session: ParkingSession): number {
  return minutesSince(session.entry_at, now.value)
}

const live = useLiveUpdates({ lotId: () => lotId.value, onChange: refresh })
useIntervalFn(() => {
  now.value = Date.now()
}, 30000)

watch(debouncedSearch, () => {
  void loadSessions(1).catch((error) => toasts.error(parseApiError(error, 'No fue posible buscar.')))
})
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Entradas y salidas" :subtitle="summary ? `Operación de ${summary.lot.name}` : 'Registro manual de vehículos'" :breadcrumbs="[{ label: 'Operación' }, { label: 'Entradas y salidas' }]">
      <template v-if="state === 'ready'" #actions>
        <span
          class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
          :class="live.status.value === 'live' ? 'bg-vz-success/15 text-vz-success-text' : 'bg-vz-warning/15 text-vz-warning-text'"
          role="status"
        >
          <span class="size-2 rounded-full" :class="live.status.value === 'live' ? 'animate-pulse bg-vz-success' : 'bg-vz-warning'" aria-hidden="true" />
          {{ live.status.value === 'live' ? 'En vivo' : live.status.value === 'offline' ? 'Reconectando…' : 'Conectando…' }}
        </span>
        <label class="sr-only" for="operations-lot">Parqueadero</label>
        <select id="operations-lot" class="vz-input w-auto min-w-48" :value="lotId ?? ''" @change="selectLot(($event.target as HTMLSelectElement).value)">
          <option v-for="lot in lots" :key="lot.id" :value="lot.id">{{ lot.name }}{{ lot.status !== 'active' ? ` (${lotStatus[lot.status].label.toLowerCase()})` : '' }}</option>
        </select>
        <button class="vz-btn vz-btn-light" type="button" :disabled="refreshing" aria-label="Actualizar" @click="refresh">
          <i class="ri-refresh-line" :class="refreshing ? 'animate-spin' : ''" aria-hidden="true" />
        </button>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para operar sus parqueaderos.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite operar entradas y salidas." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>
    <StateCard v-else-if="state === 'loading'" variant="loading" />
    <StateCard v-else-if="state === 'no-lots'" variant="empty" title="Sin parqueaderos" message="Crea un parqueadero con sus espacios para empezar a registrar entradas.">
      <RouterLink v-if="can('parking.create')" class="vz-btn vz-btn-primary" :to="{ name: 'parking-lots' }">Ir a parqueaderos</RouterLink>
    </StateCard>

    <template v-else>
      <div v-if="summary && !operating" class="mb-5 flex items-start gap-3 rounded-vz border border-vz-warning/40 bg-vz-warning/10 p-4 text-[13px]" role="alert">
        <i class="ri-tools-line text-xl text-vz-warning" aria-hidden="true" />
        <p>
          <span class="font-semibold text-vz-warning-text">{{ summary.lot.name }} está {{ lotStatus[summary.lot.status].label.toLowerCase() }}.</span>
          No se registran entradas; las salidas siguen disponibles.
        </p>
      </div>

      <section class="mb-5 grid grid-cols-2 gap-4 xl:grid-cols-5" aria-label="Resumen de la operación">
        <article class="vz-card p-5">
          <p class="text-xs font-medium tracking-wide text-vz-muted uppercase">Ocupación</p>
          <p class="mt-4 text-[22px] font-semibold text-vz-heading">{{ summary ? `${summary.occupancy.rate}%` : '—' }}</p>
          <ProgressBar class="mt-3" :value="summary?.occupancy.rate ?? 0" label="Ocupación del parqueadero" />
          <p class="mt-2 text-xs text-vz-muted">{{ summary ? `${formatNumber(summary.occupancy.in_use)} de ${formatNumber(summary.occupancy.capacity)} espacios en uso` : '' }}</p>
        </article>
        <StatCard label="Disponibles" :value="summary ? formatNumber(summary.occupancy.available) : '—'" icon="ri-parking-box-line" tone="success" :loading="!summary" hint="Espacios libres ahora" />
        <StatCard
          label="Vehículos dentro"
          :value="summary ? formatNumber(summary.open) : '—'"
          icon="ri-car-line"
          tone="info"
          :loading="!summary"
          :hint="summary && summary.open_without_space > 0 ? `${summary.open_without_space} sin espacio asignado` : 'Todos con espacio asignado'"
        />
        <StatCard
          label="Entradas / salidas hoy"
          :value="summary ? `${summary.entries_today} / ${summary.exits_today}` : '—'"
          icon="ri-arrow-left-right-line"
          tone="primary"
          :loading="!summary"
          :hint="summary?.average_stay_minutes_today != null ? `Estancia promedio: ${formatDuration(summary.average_stay_minutes_today)}` : 'Sin salidas hoy'"
        />
        <StatCard
          label="Valor cobrado hoy"
          :value="summary ? formatMoney(summary.charged_today, summary.currency) : '—'"
          icon="ri-money-dollar-circle-line"
          tone="success"
          :loading="!summary"
          :hint="chargeHint"
        />
      </section>

      <div class="grid items-start gap-5 lg:grid-cols-3">
        <div v-if="can('sessions.create') && lotId" class="lg:sticky lg:top-[94px]">
          <EntryForm :lot-id="lotId" :gates="entryGates" :spaces="spaces" :disabled="!operating" @entered="onEntered" />
          <UpcomingArrivals v-if="can('reservations.view')" :lot-id="lotId" :timezone="timezone" :version="refreshes" />
        </div>

        <div :class="can('sessions.create') ? 'lg:col-span-2' : 'lg:col-span-3'">
          <div v-if="lastEntry" class="mb-4 flex flex-wrap items-center gap-4 rounded-vz border border-vz-success/40 bg-vz-success/10 p-4" role="status">
            <i class="ri-checkbox-circle-line text-2xl text-vz-success" aria-hidden="true" />
            <div class="min-w-0 flex-1">
              <p class="text-[13px] text-vz-muted">Última entrada</p>
              <div class="mt-1 flex flex-wrap items-center gap-3">
                <PlateChip :plate="lastEntry.vehicle.plate" :country="lastEntry.vehicle.country" />
                <span class="text-lg font-semibold text-vz-heading">{{ lastEntry.space ? `Espacio ${lastEntry.space.code}` : 'Sin espacio asignado' }}</span>
              </div>
              <ul v-if="lastNotices.length > 0" class="mt-2 space-y-1 text-[13px]">
                <li v-for="(notice, index) in lastNotices" :key="index" :class="notice.type === 'warning' ? 'text-vz-warning-text' : 'text-vz-success-text'">
                  <i :class="notice.type === 'warning' ? 'ri-error-warning-line' : 'ri-id-card-line'" aria-hidden="true" /> {{ notice.message }}
                </li>
              </ul>
            </div>
            <button class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" type="button" aria-label="Ocultar" @click="lastEntry = null">
              <i class="ri-close-line" aria-hidden="true" />
            </button>
          </div>

          <div class="vz-card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-vz-border p-4">
              <h2 class="vz-card-title">Vehículos dentro <span class="text-vz-muted">({{ meta?.total ?? 0 }})</span></h2>
              <div class="relative w-full sm:w-64">
                <label class="sr-only" for="operations-search">Buscar placa</label>
                <i class="ri-search-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
                <input
                  id="operations-search"
                  v-model="search"
                  class="vz-input ps-9 font-mono uppercase"
                  type="search"
                  autocomplete="off"
                  placeholder="Buscar placa para la salida"
                  @keydown.enter.prevent="onSearchEnter"
                />
              </div>
            </div>

            <div v-if="!summary" class="p-8 text-center text-vz-muted" role="status"><i class="ri-loader-4-line animate-spin text-2xl" aria-hidden="true" /><p>Cargando…</p></div>
            <div v-else-if="sessions.length === 0" class="p-10 text-center">
              <i class="ri-parking-line text-4xl text-vz-muted" aria-hidden="true" />
              <p class="mt-2 font-medium text-vz-heading">{{ search ? 'Sin coincidencias' : 'No hay vehículos dentro' }}</p>
              <p class="text-[13px] text-vz-muted">{{ search ? 'Ningún vehículo dentro tiene esa placa.' : 'Las entradas aparecen aquí al registrarse, también las de otros operadores.' }}</p>
            </div>
            <div v-else class="overflow-x-auto">
              <table class="vz-table">
                <thead>
                  <tr>
                    <th scope="col">Placa</th>
                    <th scope="col">Espacio</th>
                    <th scope="col">Ingreso</th>
                    <th scope="col">Tiempo</th>
                    <th scope="col">Cliente</th>
                    <th scope="col"><span class="sr-only">Acciones</span></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="session in sessions" :key="session.id">
                    <td>
                      <div class="flex items-center gap-2">
                        <PlateChip :plate="session.vehicle.plate" :country="session.vehicle.country" />
                        <i :class="vehicleType[session.vehicle.type].icon" class="text-vz-muted" :title="vehicleType[session.vehicle.type].label" :aria-label="vehicleType[session.vehicle.type].label" />
                      </div>
                      <p v-if="session.reservation" class="mt-1 text-xs text-vz-primary-text"><i class="ri-calendar-check-line" aria-hidden="true" /> Reserva {{ session.reservation.code }}</p>
                      <p v-if="session.membership" class="mt-1 text-xs text-vz-success-text" :title="session.membership.plan"><i class="ri-id-card-line" aria-hidden="true" /> Mensualidad {{ session.membership.code }}</p>
                    </td>
                    <td>
                      <span v-if="session.space" class="font-semibold text-vz-heading">{{ session.space.code }}</span>
                      <StatusBadge v-else label="Sin espacio" tone="warning" icon="ri-map-pin-line" />
                    </td>
                    <td class="whitespace-nowrap">
                      {{ formatTime(session.entry_at, timezone) }}
                      <p class="text-xs text-vz-muted">{{ session.opened_by?.name ?? '—' }}</p>
                    </td>
                    <td class="whitespace-nowrap">
                      <span :class="stayMinutes(session) >= LONG_STAY_MINUTES ? 'font-semibold text-vz-warning-text' : 'text-vz-heading'">
                        {{ formatDuration(stayMinutes(session)) }}
                      </span>
                      <i v-if="stayMinutes(session) >= LONG_STAY_MINUTES" class="ri-alarm-warning-line ms-1 text-vz-warning" title="Estancia prolongada" aria-label="Estancia prolongada" />
                    </td>
                    <td>
                      <RouterLink v-if="session.customer && can('customers.view')" class="hover:underline" :to="{ name: 'customer', params: { customerId: session.customer.id } }">{{ session.customer.name }}</RouterLink>
                      <span v-else-if="session.customer">{{ session.customer.name }}</span>
                      <span v-else class="text-xs text-vz-muted">Visitante</span>
                    </td>
                    <td class="text-right whitespace-nowrap">
                      <button v-if="can('sessions.close')" class="vz-btn vz-btn-soft-primary vz-btn-sm" type="button" @click="exiting = session">
                        <i class="ri-logout-box-r-line" aria-hidden="true" /> Salida
                      </button>
                      <button
                        v-if="can('sessions.create') && operating"
                        class="vz-btn-icon ms-1 size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item"
                        type="button"
                        :aria-label="`${session.space ? 'Cambiar' : 'Asignar'} espacio de ${session.vehicle.plate}`"
                        :title="session.space ? 'Cambiar espacio' : 'Asignar espacio'"
                        @click="moving = session"
                      >
                        <i class="ri-arrow-left-right-line" aria-hidden="true" />
                      </button>
                      <button
                        v-if="can('sessions.cancel')"
                        class="vz-btn-icon ms-1 size-8 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger"
                        type="button"
                        :aria-label="`Anular sesión de ${session.vehicle.plate}`"
                        title="Anular"
                        @click="cancelling = session"
                      >
                        <i class="ri-close-circle-line" aria-hidden="true" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <PaginationBar v-if="meta" :meta="meta" @change="loadSessions" />
          </div>
        </div>
      </div>
    </template>

    <ExitDialog :session="exiting" :gates="exitGates" :timezone="timezone" :now="now" @close="exiting = null" @exited="onExited" />
    <MoveSpaceModal :session="moving" :spaces="spaces" @close="moving = null" @moved="onMoved" />
    <CancelSessionModal :session="cancelling" @close="cancelling = null" @cancelled="onCancelled" />
  </div>
</template>
