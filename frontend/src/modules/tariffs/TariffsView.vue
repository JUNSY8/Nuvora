<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import BaseModal from '@/components/ui/BaseModal.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { usePermissions } from '@/composables/usePermissions'
import { listParkingLots, listZones } from '@/modules/parking/api'
import { lotStatus } from '@/modules/parking/labels'
import type { ParkingLot, ParkingZone } from '@/modules/parking/types'
import { changeDiscountStatus, changeTariffStatus, listDiscounts, listTariffs } from '@/modules/tariffs/api'
import DiscountFormModal from '@/modules/tariffs/DiscountFormModal.vue'
import { describeDays, describeDiscountValue, describeRates, describeVehicles, describeWindow, discountApplication, discountType, tariffStatus } from '@/modules/tariffs/labels'
import PricingSimulator from '@/modules/tariffs/PricingSimulator.vue'
import TariffFormModal from '@/modules/tariffs/TariffFormModal.vue'
import TariffHistoryModal from '@/modules/tariffs/TariffHistoryModal.vue'
import type { Discount, Tariff } from '@/modules/tariffs/types'
import { formatDateTime } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()

const state = ref<'loading' | 'ready' | 'no-lots' | 'denied' | 'no-tenant' | 'error'>('loading')
const errorMessage = ref('')
const lots = ref<ParkingLot[]>([])
const lot = ref<ParkingLot | null>(null)
const zones = ref<ParkingZone[]>([])
const tariffs = ref<Tariff[]>([])
const discounts = ref<Discount[]>([])
const loadingLot = ref(false)

const editingTariff = ref<{ tariff: Tariff | null } | null>(null)
const historyOf = ref<Tariff | null>(null)
const editingDiscount = ref<{ discount: Discount | null } | null>(null)
const toggling = ref<Tariff | null>(null)
const toggleNote = ref('')
const toggleBusy = ref(false)
const toggleError = ref('')

const tabs = [
  { key: 'tarifas', label: 'Tarifas', icon: 'ri-price-tag-3-line' },
  { key: 'descuentos', label: 'Descuentos y promociones', icon: 'ri-coupon-3-line' },
  { key: 'simulador', label: 'Simulador', icon: 'ri-calculator-line' },
]

const activeTab = computed(() => {
  const requested = String(route.query.tab ?? 'tarifas')

  return tabs.some((tab) => tab.key === requested) ? requested : 'tarifas'
})

const activeCount = computed(() => tariffs.value.filter((tariff) => tariff.status === 'active').length)
const manualDiscounts = computed(() => discounts.value.filter((discount) => discount.application === 'manual' && discount.status === 'active'))
const storageKey = computed(() => `nuvora.tariffs.lot.${auth.currentTenant?.id ?? 'none'}`)

function selectTab(key: string): void {
  void router.replace({ query: { ...route.query, tab: key } })
}

function onTabKeydown(event: KeyboardEvent, index: number): void {
  const offsets: Record<string, number> = { ArrowRight: 1, ArrowLeft: -1 }
  const offset = offsets[event.key]

  if (offset === undefined) {
    return
  }

  event.preventDefault()
  const next = tabs[(index + offset + tabs.length) % tabs.length]

  if (next) {
    selectTab(next.key)
    document.getElementById(`tab-${next.key}`)?.focus()
  }
}

async function loadLot(id: string): Promise<void> {
  const next = lots.value.find((item) => item.id === id)

  if (!next) {
    return
  }

  lot.value = next
  loadingLot.value = true

  try {
    localStorage.setItem(storageKey.value, id)
  } catch {
    // Remembering the lot is a convenience only.
  }

  try {
    const [nextTariffs, nextDiscounts, nextZones] = await Promise.all([
      listTariffs(id),
      listDiscounts({ lot_id: id }),
      can('parking.view') ? listZones(id) : Promise.resolve([]),
    ])

    if (lot.value?.id === id) {
      tariffs.value = nextTariffs
      discounts.value = nextDiscounts
      zones.value = nextZones
    }
  } catch (error) {
    toasts.error(parseApiError(error, 'No fue posible consultar las tarifas.'))
  } finally {
    loadingLot.value = false
  }
}

async function load(): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  if (!can('tariffs.view')) {
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
  state.value = 'ready'
  await loadLot(initial!.id)
}

function onTariffSaved(saved: Tariff): void {
  const isNew = editingTariff.value?.tariff === null
  editingTariff.value = null
  toasts.success(isNew ? `Tarifa ${saved.name} creada.` : `Tarifa ${saved.name}: versión ${saved.version} vigente.`)
  void loadLot(saved.parking_lot_id)
}

function onDiscountSaved(saved: Discount): void {
  const isNew = editingDiscount.value?.discount === null
  editingDiscount.value = null
  toasts.success(isNew ? `${saved.name} creado.` : `${saved.name} actualizado.`)

  if (lot.value) {
    void loadLot(lot.value.id)
  }
}

function askToggle(tariff: Tariff): void {
  toggleNote.value = ''
  toggleError.value = ''
  toggling.value = tariff
}

async function onToggle(): Promise<void> {
  const tariff = toggling.value

  if (!tariff) {
    return
  }

  toggleBusy.value = true
  toggleError.value = ''

  try {
    const next = tariff.status === 'active' ? 'inactive' : 'active'
    const saved = await changeTariffStatus(tariff.id, next, toggleNote.value.trim() || null)
    toasts.success(`Tarifa ${saved.name} ${next === 'active' ? 'activada' : 'desactivada'}.`)
    toggling.value = null
    await loadLot(saved.parking_lot_id)
  } catch (error) {
    toggleError.value = parseApiError(error, 'No fue posible cambiar el estado.')
  } finally {
    toggleBusy.value = false
  }
}

async function onToggleDiscount(discount: Discount): Promise<void> {
  try {
    const saved = await changeDiscountStatus(discount.id, discount.status === 'active' ? 'inactive' : 'active')
    toasts.success(`${saved.name} ${saved.status === 'active' ? 'activado' : 'desactivado'}.`)

    if (lot.value) {
      await loadLot(lot.value.id)
    }
  } catch (error) {
    toasts.error(parseApiError(error, 'No fue posible cambiar el estado.'))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Tarifas" :subtitle="lot ? `Precios de ${lot.name} en ${lot.currency}` : 'Precios por parqueadero'" :breadcrumbs="[{ label: 'Operación' }, { label: 'Tarifas' }]">
      <template v-if="state === 'ready'" #actions>
        <label class="sr-only" for="tariffs-lot">Parqueadero</label>
        <select id="tariffs-lot" class="vz-input w-auto min-w-48" :value="lot?.id ?? ''" @change="loadLot(($event.target as HTMLSelectElement).value)">
          <option v-for="item in lots" :key="item.id" :value="item.id">{{ item.name }}{{ item.status !== 'active' ? ` (${lotStatus[item.status].label.toLowerCase()})` : '' }}</option>
        </select>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para ver sus tarifas.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar tarifas." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>
    <StateCard v-else-if="state === 'loading'" variant="loading" />
    <StateCard v-else-if="state === 'no-lots'" variant="empty" title="Sin parqueaderos" message="Las tarifas se definen por parqueadero. Crea uno primero.">
      <RouterLink v-if="can('parking.create')" class="vz-btn vz-btn-primary" :to="{ name: 'parking-lots' }">Ir a parqueaderos</RouterLink>
    </StateCard>

    <div v-else-if="lot" class="vz-card">
      <div class="overflow-x-auto border-b border-vz-border px-2" role="tablist" aria-label="Secciones de tarifas">
        <div class="flex min-w-max">
          <button
            v-for="(tab, index) in tabs"
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
            @keydown="onTabKeydown($event, index)"
          >
            <i :class="tab.icon" class="text-base" aria-hidden="true" /> {{ tab.label }}
          </button>
        </div>
      </div>

      <div :id="`panel-${activeTab}`" role="tabpanel" :aria-labelledby="`tab-${activeTab}`" class="p-5">
        <StateCard v-if="loadingLot && tariffs.length === 0 && activeTab !== 'simulador'" variant="loading" compact />

        <template v-else-if="activeTab === 'tarifas'">
          <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-[13px] text-vz-muted">
              {{ activeCount }} activa{{ activeCount === 1 ? '' : 's' }}. La tarifa se elige con la hora de entrada; cambiar una tarifa no afecta a quien ya está dentro.
            </p>
            <button v-if="can('tariffs.manage')" class="vz-btn vz-btn-primary" type="button" @click="editingTariff = { tariff: null }">
              <i class="ri-add-line" aria-hidden="true" /> Nueva tarifa
            </button>
          </div>

          <div v-if="tariffs.length > 0 && activeCount === 0" class="mb-4 flex items-start gap-2 rounded-vz border border-vz-warning/40 bg-vz-warning/10 p-3 text-[13px]" role="alert">
            <i class="ri-error-warning-line text-vz-warning" aria-hidden="true" />
            <p>No hay tarifas activas: las salidas se registrarán sin cobro.</p>
          </div>

          <StateCard v-if="tariffs.length === 0" variant="empty" title="Sin tarifas" message="Sin una tarifa, las salidas se registran sin cobro. Crea al menos una tarifa general." compact>
            <button v-if="can('tariffs.manage')" class="vz-btn vz-btn-primary" type="button" @click="editingTariff = { tariff: null }"><i class="ri-add-line" aria-hidden="true" /> Crear tarifa</button>
          </StateCard>

          <div v-else class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            <article v-for="tariff in tariffs" :key="tariff.id" class="flex flex-col rounded-vz border border-vz-border" :class="tariff.status === 'inactive' ? 'opacity-70' : ''">
              <header class="flex items-start justify-between gap-3 border-b border-vz-border p-4">
                <div class="min-w-0">
                  <h2 class="truncate font-semibold text-vz-heading">{{ tariff.name }}</h2>
                  <p class="text-xs text-vz-muted">
                    <code class="rounded bg-vz-elevated px-1.5 py-0.5">{{ tariff.code }}</code> · v{{ tariff.version }} · prioridad {{ tariff.priority }}
                  </p>
                </div>
                <StatusBadge :label="tariffStatus[tariff.status].label" :tone="tariffStatus[tariff.status].tone" :icon="tariffStatus[tariff.status].icon" />
              </header>
              <div class="flex-1 space-y-3 p-4 text-[13px]">
                <ul class="space-y-1">
                  <li v-for="line in describeRates(tariff, tariff.currency)" :key="line" class="flex gap-2">
                    <i class="ri-arrow-right-s-line text-vz-muted" aria-hidden="true" /> {{ line }}
                  </li>
                </ul>
                <div class="flex flex-wrap gap-1.5 text-xs">
                  <span class="rounded-full bg-vz-elevated px-2 py-0.5"><i class="ri-car-line" aria-hidden="true" /> {{ describeVehicles(tariff.vehicle_types) }}</span>
                  <span class="rounded-full bg-vz-elevated px-2 py-0.5"><i class="ri-stack-line" aria-hidden="true" /> {{ tariff.zone ? `Zona ${tariff.zone.name ?? tariff.zone.code}` : 'Todas las zonas' }}</span>
                  <span v-for="window in tariff.windows" :key="describeWindow(window)" class="rounded-full bg-vz-info/15 px-2 py-0.5 text-vz-info-text"><i class="ri-time-line" aria-hidden="true" /> {{ describeWindow(window) }}</span>
                  <span class="rounded-full bg-vz-elevated px-2 py-0.5"><i class="ri-hourglass-line" aria-hidden="true" /> Gracia {{ tariff.grace_minutes ?? lot.grace_period_minutes }} min</span>
                </div>
              </div>
              <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-vz-border px-4 py-2.5">
                <p class="text-xs text-vz-muted">Desde {{ formatDateTime(tariff.effective_from, lot.timezone) }}</p>
                <div class="flex gap-1">
                  <button class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" type="button" :aria-label="`Historial de ${tariff.name}`" title="Historial" @click="historyOf = tariff">
                    <i class="ri-history-line" aria-hidden="true" />
                  </button>
                  <template v-if="can('tariffs.manage')">
                    <button class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" type="button" :aria-label="`Editar ${tariff.name}`" title="Editar" @click="editingTariff = { tariff }">
                      <i class="ri-edit-2-line" aria-hidden="true" />
                    </button>
                    <button
                      class="vz-btn-icon size-8 text-vz-muted"
                      :class="tariff.status === 'active' ? 'hover:bg-vz-warning/15 hover:text-vz-warning' : 'hover:bg-vz-success/15 hover:text-vz-success'"
                      type="button"
                      :aria-label="`${tariff.status === 'active' ? 'Desactivar' : 'Activar'} ${tariff.name}`"
                      :title="tariff.status === 'active' ? 'Desactivar' : 'Activar'"
                      @click="askToggle(tariff)"
                    >
                      <i :class="tariff.status === 'active' ? 'ri-pause-circle-line' : 'ri-play-circle-line'" aria-hidden="true" />
                    </button>
                  </template>
                </div>
              </footer>
            </article>
          </div>
        </template>

        <template v-else-if="activeTab === 'descuentos'">
          <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-[13px] text-vz-muted">Los descuentos no se acumulan: aplica el que elige el cajero o, si no elige, la mejor promoción vigente.</p>
            <button v-if="can('tariffs.manage')" class="vz-btn vz-btn-primary" type="button" @click="editingDiscount = { discount: null }">
              <i class="ri-add-line" aria-hidden="true" /> Nuevo descuento
            </button>
          </div>

          <StateCard v-if="discounts.length === 0" variant="empty" title="Sin descuentos" message="Crea convenios para el cajero o promociones que se apliquen solas." compact>
            <button v-if="can('tariffs.manage')" class="vz-btn vz-btn-primary" type="button" @click="editingDiscount = { discount: null }"><i class="ri-add-line" aria-hidden="true" /> Crear descuento</button>
          </StateCard>

          <div v-else class="overflow-x-auto rounded-vz border border-vz-border">
            <table class="vz-table">
              <thead>
                <tr>
                  <th scope="col">Descuento</th>
                  <th scope="col">Valor</th>
                  <th scope="col">Aplicación</th>
                  <th scope="col">Condiciones</th>
                  <th scope="col">Estado</th>
                  <th scope="col"><span class="sr-only">Acciones</span></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="discount in discounts" :key="discount.id">
                  <td>
                    <p class="font-medium text-vz-heading">{{ discount.name }}</p>
                    <p class="text-xs text-vz-muted"><code>{{ discount.code }}</code> · {{ discount.parking_lot_id ? 'Este parqueadero' : 'Todos los parqueaderos' }}</p>
                  </td>
                  <td class="whitespace-nowrap">
                    <i :class="discountType[discount.type].icon" class="text-vz-muted" aria-hidden="true" /> {{ describeDiscountValue(discount) }}
                  </td>
                  <td><StatusBadge :label="discountApplication[discount.application].label" :tone="discountApplication[discount.application].tone" /></td>
                  <td class="text-xs">
                    <p>{{ describeVehicles(discount.vehicle_types) }} · {{ discount.days.length === 0 ? 'Todos los días' : describeDays(discount.days) }}</p>
                    <p v-if="discount.valid_from || discount.valid_until" class="text-vz-muted">
                      Vigencia: {{ discount.valid_from ?? '…' }} a {{ discount.valid_until ?? '…' }}
                    </p>
                  </td>
                  <td><StatusBadge :label="tariffStatus[discount.status].label" :tone="tariffStatus[discount.status].tone" /></td>
                  <td class="text-right whitespace-nowrap">
                    <template v-if="can('tariffs.manage')">
                      <button class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" type="button" :aria-label="`Editar ${discount.name}`" @click="editingDiscount = { discount }">
                        <i class="ri-edit-2-line" aria-hidden="true" />
                      </button>
                      <button class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" type="button" :aria-label="`${discount.status === 'active' ? 'Desactivar' : 'Activar'} ${discount.name}`" @click="onToggleDiscount(discount)">
                        <i :class="discount.status === 'active' ? 'ri-pause-circle-line' : 'ri-play-circle-line'" aria-hidden="true" />
                      </button>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <PricingSimulator v-else :lot="lot" :zones="zones" :discounts="manualDiscounts" />
      </div>
    </div>

    <TariffFormModal
      v-if="lot"
      :open="editingTariff !== null"
      :lot="lot"
      :zones="zones"
      :tariff="editingTariff?.tariff ?? null"
      @close="editingTariff = null"
      @saved="onTariffSaved"
    />
    <TariffHistoryModal :tariff="historyOf" :timezone="lot?.timezone ?? 'America/Bogota'" @close="historyOf = null" />
    <DiscountFormModal
      v-if="lot"
      :open="editingDiscount !== null"
      :lot="lot"
      :discount="editingDiscount?.discount ?? null"
      @close="editingDiscount = null"
      @saved="onDiscountSaved"
    />

    <BaseModal :open="toggling !== null" :title="toggling?.status === 'active' ? 'Desactivar tarifa' : 'Activar tarifa'" size="sm" @close="toggling = null">
      <form v-if="toggling" novalidate @submit.prevent="onToggle">
        <div class="space-y-3 px-5 py-5 text-[13px]">
          <p v-if="toggling.status === 'active'">
            <span class="font-medium text-vz-heading">{{ toggling.name }}</span> dejará de aplicar a las entradas desde ahora. Los vehículos que ya están dentro conservan la versión con la que entraron.
          </p>
          <p v-else><span class="font-medium text-vz-heading">{{ toggling.name }}</span> volverá a aplicar a las entradas desde ahora.</p>
          <div>
            <label class="vz-label" for="toggle-note">Motivo <span class="font-normal text-vz-muted">(opcional)</span></label>
            <input id="toggle-note" v-model="toggleNote" class="vz-input" type="text" maxlength="255" />
          </div>
          <p v-if="toggleError" class="vz-error" role="alert">{{ toggleError }}</p>
        </div>
        <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
          <button class="vz-btn vz-btn-light" type="button" @click="toggling = null">Cancelar</button>
          <button class="vz-btn" :class="toggling.status === 'active' ? 'vz-btn-danger' : 'vz-btn-success'" type="submit" :disabled="toggleBusy">
            <i v-if="toggleBusy" class="ri-loader-4-line animate-spin" aria-hidden="true" />
            {{ toggling.status === 'active' ? 'Desactivar' : 'Activar' }}
          </button>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
