<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import BaseModal from '@/components/ui/BaseModal.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { useLiveUpdates } from '@/composables/useLiveUpdates'
import { usePermissions } from '@/composables/usePermissions'
import { listParkingLots } from '@/modules/parking/api'
import { lotStatus } from '@/modules/parking/labels'
import type { ParkingLot } from '@/modules/parking/types'
import { cancelReservation, confirmReservation, getReservationPolicy, listReservations, updateReservationPolicy } from '@/modules/reservations/api'
import { isOpen, reservationStatus, reservationStatusOrder, scopeLabel } from '@/modules/reservations/labels'
import ReservationFormModal from '@/modules/reservations/ReservationFormModal.vue'
import type { Reservation, ReservationPolicy, ReservationStatus } from '@/modules/reservations/types'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import { formatDateTime, formatTime, toLocalInput } from '@/services/format'
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

const state = ref<'loading' | 'ready' | 'no-lots' | 'denied' | 'no-tenant' | 'error'>('loading')
const errorMessage = ref('')
const lots = ref<ParkingLot[]>([])
const lot = ref<ParkingLot | null>(null)
const reservations = ref<Reservation[]>([])
const meta = ref<PageMeta | null>(null)
const loadingList = ref(false)

const filters = reactive<{ date: string; status: 'open' | 'all' | ReservationStatus; search: string }>({ date: '', status: 'open', search: '' })
const debouncedSearch = refDebounced(computed(() => filters.search), 300)

const creating = ref(false)
const cancelling = ref<Reservation | null>(null)
const cancelReason = ref('')
const cancelBusy = ref(false)
const cancelErrors = useFieldErrors()
const confirming = ref<string | null>(null)

const policy = ref<ReservationPolicy | null>(null)
const policySaving = ref(false)
const policyErrors = useFieldErrors()

const tabs = computed(() => [
  { key: 'agenda', label: 'Agenda', icon: 'ri-calendar-2-line' },
  { key: 'configuracion', label: 'Configuración', icon: 'ri-settings-3-line' },
])
const activeTab = computed(() => (route.query.tab === 'configuracion' ? 'configuracion' : 'agenda'))
const storageKey = computed(() => `nuvora.reservations.lot.${auth.currentTenant?.id ?? 'none'}`)

function selectTab(key: string): void {
  void router.replace({ query: { ...route.query, tab: key } })
}

function statusFilter(): ReservationStatus[] {
  if (filters.status === 'open') {
    return ['pending', 'confirmed', 'active']
  }

  return filters.status === 'all' ? [] : [filters.status]
}

async function loadList(page = 1): Promise<void> {
  if (!lot.value) {
    return
  }

  loadingList.value = true

  try {
    const result = await listReservations({
      lot_id: lot.value.id,
      status: statusFilter(),
      search: debouncedSearch.value || undefined,
      from_date: filters.date || undefined,
      to_date: filters.date || undefined,
    }, page, 25)
    reservations.value = result.items
    meta.value = result.meta
  } catch (error) {
    toasts.error(parseApiError(error, 'No fue posible consultar las reservas.'))
  } finally {
    loadingList.value = false
  }
}

async function loadPolicy(): Promise<void> {
  if (!lot.value) {
    return
  }

  try {
    policy.value = await getReservationPolicy(lot.value.id)
  } catch {
    policy.value = null
  }
}

async function selectLot(id: string): Promise<void> {
  lot.value = lots.value.find((item) => item.id === id) ?? null

  try {
    localStorage.setItem(storageKey.value, id)
  } catch {
    // Remembering the lot is a convenience only.
  }

  await Promise.all([loadList(), loadPolicy()])
}

async function load(): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  if (!can('reservations.view')) {
    state.value = 'denied'
    return
  }

  state.value = 'loading'

  try {
    lots.value = (await listParkingLots({ per_page: 100 })).items
  } catch (error) {
    state.value = apiErrorCode(error) === 'FORBIDDEN' ? 'denied' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar los parqueaderos.')
    return
  }

  if (lots.value.length === 0) {
    state.value = 'no-lots'
    return
  }

  let saved: string | null = null

  try {
    saved = localStorage.getItem(storageKey.value)
  } catch {
    saved = null
  }

  const initial = lots.value.find((item) => item.id === saved) ?? lots.value.find((item) => item.status === 'active') ?? lots.value[0]
  filters.date = toLocalInput(new Date(), initial!.timezone).slice(0, 10)
  state.value = 'ready'
  await selectLot(initial!.id)
}

function onCreated(reservation: Reservation, replayed: boolean): void {
  creating.value = false
  toasts.success(replayed ? `La reserva ${reservation.code} ya estaba registrada.` : `Reserva ${reservation.code} para ${reservation.vehicle.plate} registrada.`)
  void loadList(meta.value?.current_page ?? 1)
}

async function onConfirm(reservation: Reservation): Promise<void> {
  confirming.value = reservation.id

  try {
    await confirmReservation(reservation.id)
    toasts.success(`Reserva ${reservation.code} confirmada.`)
    await loadList(meta.value?.current_page ?? 1)
  } catch (error) {
    toasts.error(parseApiError(error, 'No fue posible confirmar la reserva.'))
  } finally {
    confirming.value = null
  }
}

function askCancel(reservation: Reservation): void {
  cancelReason.value = ''
  cancelErrors.clear()
  cancelling.value = reservation
}

async function onCancel(): Promise<void> {
  if (!cancelling.value) {
    return
  }

  cancelBusy.value = true
  cancelErrors.clear()

  try {
    const cancelled = await cancelReservation(cancelling.value.id, cancelReason.value.trim())
    toasts.success(`Reserva ${cancelled.code} cancelada.`)
    cancelling.value = null
    await loadList(meta.value?.current_page ?? 1)
  } catch (error) {
    cancelErrors.capture(error, 'No fue posible cancelar la reserva.')
  } finally {
    cancelBusy.value = false
  }
}

async function onSavePolicy(): Promise<void> {
  if (!lot.value || !policy.value) {
    return
  }

  policySaving.value = true
  policyErrors.clear()

  try {
    policy.value = await updateReservationPolicy(lot.value.id, policy.value)
    toasts.success('Reglas de reserva guardadas.')
  } catch (error) {
    policyErrors.capture(error, 'No fue posible guardar las reglas.')
  } finally {
    policySaving.value = false
  }
}

useLiveUpdates({ lotId: () => lot.value?.id ?? null, onChange: () => loadList(meta.value?.current_page ?? 1) })

watch([debouncedSearch, () => filters.status, () => filters.date], () => {
  void loadList(1)
})
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Reservas" :subtitle="lot ? `Agenda de ${lot.name}` : 'Reservas por parqueadero'" :breadcrumbs="[{ label: 'Operación' }, { label: 'Reservas' }]">
      <template v-if="state === 'ready'" #actions>
        <label class="sr-only" for="reservations-lot">Parqueadero</label>
        <select id="reservations-lot" class="vz-input w-auto min-w-48" :value="lot?.id ?? ''" @change="selectLot(($event.target as HTMLSelectElement).value)">
          <option v-for="item in lots" :key="item.id" :value="item.id">{{ item.name }}{{ item.status !== 'active' ? ` (${lotStatus[item.status].label.toLowerCase()})` : '' }}</option>
        </select>
        <button v-if="can('reservations.manage') && lot" class="vz-btn vz-btn-primary" type="button" @click="creating = true">
          <i class="ri-add-line" aria-hidden="true" /> Nueva reserva
        </button>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para ver sus reservas.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar reservas." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>
    <StateCard v-else-if="state === 'loading'" variant="loading" />
    <StateCard v-else-if="state === 'no-lots'" variant="empty" title="Sin parqueaderos" message="Las reservas se hacen por parqueadero. Crea uno con espacios reservables." />

    <div v-else-if="lot" class="vz-card">
      <div class="overflow-x-auto border-b border-vz-border px-2" role="tablist" aria-label="Secciones de reservas">
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

      <div v-if="activeTab === 'agenda'" id="panel-agenda" role="tabpanel" aria-labelledby="tab-agenda">
        <div class="grid gap-3 border-b border-vz-border p-4 sm:grid-cols-3">
          <div>
            <label class="vz-label" for="reservations-search">Placa o código</label>
            <input id="reservations-search" v-model="filters.search" class="vz-input font-mono uppercase" type="search" maxlength="20" placeholder="ABC123 o R-7K3M9Q" />
          </div>
          <div>
            <label class="vz-label" for="reservations-date">Día de llegada</label>
            <input id="reservations-date" v-model="filters.date" class="vz-input" type="date" />
          </div>
          <div>
            <label class="vz-label" for="reservations-status">Estado</label>
            <select id="reservations-status" v-model="filters.status" class="vz-input">
              <option value="open">Abiertas (por confirmar, confirmadas, en curso)</option>
              <option value="all">Todas</option>
              <option v-for="status in reservationStatusOrder" :key="status" :value="status">{{ reservationStatus[status].label }}</option>
            </select>
          </div>
        </div>

        <StateCard v-if="loadingList && reservations.length === 0" variant="loading" compact />
        <StateCard v-else-if="reservations.length === 0" variant="empty" title="Sin reservas" :message="filters.date ? 'No hay reservas para ese día con estos filtros.' : 'No hay reservas con estos filtros.'" compact>
          <button v-if="can('reservations.manage')" class="vz-btn vz-btn-primary" type="button" @click="creating = true"><i class="ri-add-line" aria-hidden="true" /> Nueva reserva</button>
        </StateCard>

        <div v-else class="overflow-x-auto">
          <table class="vz-table">
            <thead>
              <tr>
                <th scope="col">Reserva</th>
                <th scope="col">Horario</th>
                <th scope="col">Lugar</th>
                <th scope="col" class="text-right">Valor estimado</th>
                <th scope="col">Estado</th>
                <th scope="col"><span class="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="reservation in reservations" :key="reservation.id">
                <td>
                  <div class="flex items-center gap-2">
                    <PlateChip :plate="reservation.vehicle.plate" :country="reservation.vehicle.country" />
                    <code class="text-xs text-vz-muted">{{ reservation.code }}</code>
                  </div>
                  <p v-if="reservation.customer" class="mt-1 text-xs text-vz-muted">{{ reservation.customer.name }}</p>
                </td>
                <td class="whitespace-nowrap">
                  {{ formatDateTime(reservation.starts_at, lot.timezone) }}
                  <p class="text-xs text-vz-muted">
                    hasta {{ formatTime(reservation.ends_at, lot.timezone) }}
                    <template v-if="reservation.status === 'pending' && reservation.hold_expires_at"> · vence {{ formatTime(reservation.hold_expires_at, lot.timezone) }}</template>
                    <template v-else-if="reservation.status === 'confirmed'"> · llega hasta {{ formatTime(reservation.no_show_after, lot.timezone) }}</template>
                  </p>
                </td>
                <td>{{ scopeLabel(reservation) }}</td>
                <td class="text-right whitespace-nowrap tabular-nums">{{ reservation.quoted_amount === null ? 'Sin tarifa' : formatMoney(reservation.quoted_amount, reservation.currency) }}</td>
                <td>
                  <StatusBadge :label="reservationStatus[reservation.status].label" :tone="reservationStatus[reservation.status].tone" :icon="reservationStatus[reservation.status].icon" />
                  <p v-if="reservation.cancel_reason" class="mt-1 max-w-48 truncate text-xs text-vz-muted" :title="reservation.cancel_reason">{{ reservation.cancel_reason }}</p>
                </td>
                <td class="text-right whitespace-nowrap">
                  <template v-if="can('reservations.manage') && isOpen(reservation.status)">
                    <button
                      v-if="reservation.status === 'pending'"
                      class="vz-btn vz-btn-sm vz-btn-soft-success"
                      type="button"
                      :disabled="confirming === reservation.id"
                      @click="onConfirm(reservation)"
                    >
                      <i class="ri-check-line" aria-hidden="true" /> Confirmar
                    </button>
                    <button
                      v-if="reservation.status !== 'active'"
                      class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger"
                      type="button"
                      :aria-label="`Cancelar la reserva ${reservation.code}`"
                      @click="askCancel(reservation)"
                    >
                      <i class="ri-close-circle-line" aria-hidden="true" />
                    </button>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <PaginationBar v-if="meta" :meta="meta" @change="loadList" />
      </div>

      <div v-else id="panel-configuracion" role="tabpanel" aria-labelledby="tab-configuracion" class="p-5">
        <StateCard v-if="!policy" variant="loading" compact />
        <form v-else class="max-w-3xl space-y-5" novalidate @submit.prevent="onSavePolicy">
          <p class="text-[13px] text-vz-muted">
            Solo los espacios marcados como <strong class="text-vz-heading">reservables</strong> se pueden prometer. Los cambios aplican a las reservas nuevas; las existentes conservan sus horarios.
          </p>
          <label class="flex items-center gap-2 text-[13px]">
            <input v-model="policy.enabled" class="size-4 accent-vz-primary" type="checkbox" :disabled="!can('parking.update')" />
            Recibir reservas en {{ lot.name }}
          </label>
          <div class="grid gap-4 sm:grid-cols-3">
            <div>
              <label class="vz-label" for="policy-buffer">Colchón entre reservas (min)</label>
              <input id="policy-buffer" v-model.number="policy.buffer_minutes" class="vz-input" type="number" min="0" max="240" :disabled="!can('parking.update')" />
            </div>
            <div>
              <label class="vz-label" for="policy-tolerance">Tolerancia de llegada (min)</label>
              <input id="policy-tolerance" v-model.number="policy.arrival_tolerance_minutes" class="vz-input" type="number" min="0" max="720" :disabled="!can('parking.update')" />
            </div>
            <div>
              <label class="vz-label" for="policy-hold">Retención sin confirmar (min)</label>
              <input id="policy-hold" v-model.number="policy.hold_minutes" class="vz-input" type="number" min="1" max="1440" :disabled="!can('parking.update')" />
            </div>
            <div>
              <label class="vz-label" for="policy-min">Duración mínima (min)</label>
              <input id="policy-min" v-model.number="policy.min_duration_minutes" class="vz-input" type="number" min="15" max="1440" :disabled="!can('parking.update')" />
            </div>
            <div>
              <label class="vz-label" for="policy-max">Duración máxima (min)</label>
              <input id="policy-max" v-model.number="policy.max_duration_minutes" class="vz-input" type="number" min="15" max="44640" :disabled="!can('parking.update')" />
            </div>
            <div>
              <label class="vz-label" for="policy-advance">Anticipación máxima (días)</label>
              <input id="policy-advance" v-model.number="policy.max_advance_days" class="vz-input" type="number" min="1" max="365" :disabled="!can('parking.update')" />
            </div>
          </div>
          <label class="flex items-center gap-2 text-[13px]">
            <input v-model="policy.within_operating_hours" class="size-4 accent-vz-primary" type="checkbox" :disabled="!can('parking.update')" />
            Solo dentro del horario de operación
          </label>
          <p v-if="policyErrors.general.value" class="vz-error" role="alert">{{ policyErrors.general.value }}</p>
          <button v-if="can('parking.update')" class="vz-btn vz-btn-primary" type="submit" :disabled="policySaving">{{ policySaving ? 'Guardando…' : 'Guardar reglas' }}</button>
          <p v-else class="text-xs text-vz-muted">Solo quien administra la infraestructura puede cambiar estas reglas.</p>
        </form>
      </div>
    </div>

    <ReservationFormModal v-if="lot" :open="creating" :lot="lot" @close="creating = false" @saved="onCreated" />

    <BaseModal :open="cancelling !== null" title="Cancelar reserva" size="sm" @close="cancelling = null">
      <form v-if="cancelling" novalidate @submit.prevent="onCancel">
        <div class="space-y-3 px-5 py-5 text-[13px]">
          <p>
            La reserva <strong class="text-vz-heading">{{ cancelling.code }}</strong> de {{ cancelling.vehicle.plate }} libera su cupo
            <template v-if="cancelling.space"> y el espacio {{ cancelling.space.code }}</template>. La acción queda en la auditoría.
          </p>
          <div>
            <label class="vz-label" for="reservation-cancel-reason">Motivo</label>
            <textarea id="reservation-cancel-reason" v-model="cancelReason" class="vz-input min-h-20" maxlength="255" placeholder="Ej.: el cliente canceló por teléfono" :aria-invalid="!!cancelErrors.fields.value.reason" />
            <p v-if="cancelErrors.fields.value.reason" class="vz-error" role="alert">{{ cancelErrors.fields.value.reason }}</p>
            <p v-else-if="cancelErrors.general.value" class="vz-error" role="alert">{{ cancelErrors.general.value }}</p>
          </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
          <button class="vz-btn vz-btn-light" type="button" @click="cancelling = null">Volver</button>
          <button class="vz-btn vz-btn-danger" type="submit" :disabled="cancelBusy || cancelReason.trim().length < 3">
            <i :class="cancelBusy ? 'ri-loader-4-line animate-spin' : 'ri-close-circle-line'" aria-hidden="true" /> Cancelar reserva
          </button>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
