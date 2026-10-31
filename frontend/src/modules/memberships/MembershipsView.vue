<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ProgressBar from '@/components/ui/ProgressBar.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { usePermissions } from '@/composables/usePermissions'
import { changePlanStatus, listMemberships, listPlans } from '@/modules/memberships/api'
import { coverageHint, describeAccess, describePrice, membershipStatus, membershipStatusOrder, planCategory } from '@/modules/memberships/labels'
import MembershipDetailModal from '@/modules/memberships/MembershipDetailModal.vue'
import MembershipFormModal from '@/modules/memberships/MembershipFormModal.vue'
import PlanFormModal from '@/modules/memberships/PlanFormModal.vue'
import type { Membership, MembershipPlan, MembershipStatus } from '@/modules/memberships/types'
import { listParkingLots, listZones } from '@/modules/parking/api'
import type { ParkingLot, ParkingZone } from '@/modules/parking/types'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import { formatDate, toLocalInput } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
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
const zones = ref<ParkingZone[]>([])
const plans = ref<MembershipPlan[]>([])
const memberships = ref<Membership[]>([])
const meta = ref<PageMeta | null>(null)
const loadingList = ref(false)

const filters = reactive<{ status: '' | MembershipStatus; plan_id: string; search: string }>({ status: 'active', plan_id: '', search: '' })
const debouncedSearch = refDebounced(computed(() => filters.search), 300)

const selling = ref(false)
const editingPlan = ref<MembershipPlan | null>(null)
const planFormOpen = ref(false)
const viewing = ref<string | null>(null)
const togglingPlan = ref<string | null>(null)

const tabs = [
  { key: 'mensualidades', label: 'Mensualidades', icon: 'ri-id-card-line' },
  { key: 'planes', label: 'Planes', icon: 'ri-price-tag-3-line' },
]
const activeTab = computed(() => (route.query.tab === 'planes' ? 'planes' : 'mensualidades'))
const timezone = computed(() => auth.currentTenant?.timezone ?? 'America/Bogota')
const today = computed(() => toLocalInput(new Date(), timezone.value).slice(0, 10))
const currency = computed(() => lots.value[0]?.currency ?? 'COP')

function lotName(id: string): string {
  return lots.value.find((lot) => lot.id === id)?.name ?? 'Parqueadero'
}

function zoneName(id: string): string {
  return zones.value.find((zone) => zone.id === id)?.name ?? 'Zona'
}

function selectTab(key: string): void {
  void router.replace({ query: { ...route.query, tab: key } })
}

// Only the latest request may fill the table; an older answer arriving late
// would show data from before a change.
let listRequest = 0

async function loadList(page = 1): Promise<void> {
  const current = ++listRequest
  loadingList.value = true

  try {
    const result = await listMemberships({
      status: filters.status || undefined,
      plan_id: filters.plan_id || undefined,
      search: debouncedSearch.value.trim() || undefined,
    }, page, 25)

    if (current === listRequest) {
      memberships.value = result.items
      meta.value = result.meta
    }
  } catch (error) {
    if (current === listRequest) {
      toasts.error(parseApiError(error, 'No fue posible consultar las mensualidades.'))
    }
  } finally {
    if (current === listRequest) {
      loadingList.value = false
    }
  }
}

async function loadPlans(): Promise<void> {
  plans.value = await listPlans()
}

// Lots and zones only name the plan's restrictions; without parking.view the
// screen still works with generic names.
async function loadInfrastructure(): Promise<void> {
  if (!can('parking.view')) {
    return
  }

  try {
    lots.value = (await listParkingLots({ per_page: 100 })).items
    zones.value = (await Promise.all(lots.value.map((lot) => listZones(lot.id)))).flat()
  } catch {
    lots.value = []
    zones.value = []
  }
}

async function load(): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  if (!can('memberships.view')) {
    state.value = 'denied'
    return
  }

  state.value = 'loading'

  try {
    await Promise.all([loadPlans(), loadInfrastructure()])
  } catch (error) {
    state.value = apiErrorCode(error) === 'FORBIDDEN' ? 'denied' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar los planes.')
    return
  }

  state.value = 'ready'
  await loadList()
}

function openPlan(plan: MembershipPlan | null): void {
  editingPlan.value = plan
  planFormOpen.value = true
}

async function onPlanSaved(plan: MembershipPlan): Promise<void> {
  const created = editingPlan.value === null
  planFormOpen.value = false
  editingPlan.value = null
  toasts.success(created ? `Plan ${plan.name} creado.` : `Plan ${plan.name} guardado.`)
  await loadPlans()
}

async function onTogglePlan(plan: MembershipPlan): Promise<void> {
  togglingPlan.value = plan.id

  try {
    const next = plan.status === 'active' ? 'inactive' : 'active'
    await changePlanStatus(plan.id, next)
    toasts.success(next === 'active' ? `El plan ${plan.name} vuelve a venderse.` : `El plan ${plan.name} dejó de venderse; las mensualidades vendidas siguen vigentes.`)
    await loadPlans()
  } catch (error) {
    toasts.error(parseApiError(error, 'No fue posible cambiar el estado del plan.'))
  } finally {
    togglingPlan.value = null
  }
}

async function onSold(membership: Membership): Promise<void> {
  selling.value = false
  toasts.success(`Mensualidad ${membership.code} vendida a ${membership.customer?.name ?? 'el cliente'}: cubre hasta el ${formatDate(membership.covered_until)}.`)
  await Promise.all([loadList(1), loadPlans()])
  viewing.value = membership.id
}

function onChanged(_membership: Membership, message: string): void {
  toasts.success(message)
  void loadList(meta.value?.current_page ?? 1)
  void loadPlans()
}

watch([debouncedSearch, () => filters.status, () => filters.plan_id], () => {
  void loadList(1)
})
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Mensualidades" subtitle="Planes, clientes recurrentes y su cobertura" :breadcrumbs="[{ label: 'Operación' }, { label: 'Mensualidades' }]">
      <template v-if="state === 'ready' && can('memberships.manage')" #actions>
        <button class="vz-btn vz-btn-light" type="button" @click="openPlan(null)">
          <i class="ri-add-line" aria-hidden="true" /> Nuevo plan
        </button>
        <button class="vz-btn vz-btn-primary" type="button" :disabled="!plans.some((plan) => plan.status === 'active')" @click="selling = true">
          <i class="ri-id-card-line" aria-hidden="true" /> Vender mensualidad
        </button>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para ver sus mensualidades.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar mensualidades." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>
    <StateCard v-else-if="state === 'loading'" variant="loading" />

    <div v-else class="vz-card">
      <div class="overflow-x-auto border-b border-vz-border px-2" role="tablist" aria-label="Secciones de mensualidades">
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
            <span v-if="tab.key === 'planes'" class="rounded bg-vz-elevated px-1.5 text-[11px] text-vz-muted">{{ plans.length }}</span>
          </button>
        </div>
      </div>

      <div v-if="activeTab === 'mensualidades'" id="panel-mensualidades" role="tabpanel" aria-labelledby="tab-mensualidades">
        <div class="grid gap-3 border-b border-vz-border p-4 sm:grid-cols-3">
          <div>
            <label class="vz-label" for="memberships-search">Placa o código</label>
            <input id="memberships-search" v-model="filters.search" class="vz-input font-mono uppercase" type="search" maxlength="20" placeholder="ABC123 o M-7K3M9Q" />
          </div>
          <div>
            <label class="vz-label" for="memberships-status">Estado</label>
            <select id="memberships-status" v-model="filters.status" class="vz-input">
              <option value="">Todas</option>
              <option v-for="status in membershipStatusOrder" :key="status" :value="status">{{ membershipStatus[status].label }}</option>
            </select>
          </div>
          <div>
            <label class="vz-label" for="memberships-plan">Plan</label>
            <select id="memberships-plan" v-model="filters.plan_id" class="vz-input">
              <option value="">Todos los planes</option>
              <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
            </select>
          </div>
        </div>

        <StateCard v-if="loadingList && memberships.length === 0" variant="loading" compact />
        <StateCard
          v-else-if="memberships.length === 0"
          variant="empty"
          title="Sin mensualidades"
          :message="plans.length === 0 ? 'Crea un plan para empezar a vender mensualidades.' : 'No hay mensualidades con estos filtros.'"
          compact
        >
          <template v-if="can('memberships.manage')">
            <button v-if="plans.length === 0" class="vz-btn vz-btn-primary" type="button" @click="openPlan(null)"><i class="ri-add-line" aria-hidden="true" /> Nuevo plan</button>
            <button v-else class="vz-btn vz-btn-primary" type="button" @click="selling = true"><i class="ri-id-card-line" aria-hidden="true" /> Vender mensualidad</button>
          </template>
        </StateCard>

        <div v-else class="overflow-x-auto">
          <table class="vz-table">
            <thead>
              <tr>
                <th scope="col">Mensualidad</th>
                <th scope="col">Plan</th>
                <th scope="col">Vehículos</th>
                <th scope="col">Cubre hasta</th>
                <th scope="col">Estado</th>
                <th scope="col"><span class="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="membership in memberships" :key="membership.id">
                <td>
                  <p class="font-medium text-vz-heading">{{ membership.customer?.name ?? '—' }}</p>
                  <code class="text-xs text-vz-muted">{{ membership.code }}</code>
                </td>
                <td>
                  {{ membership.plan.name }}
                  <p class="text-xs text-vz-muted">{{ describePrice(membership) }}<template v-if="membership.assigned_space"> · espacio {{ membership.assigned_space.code }}</template></p>
                </td>
                <td>
                  <div class="flex max-w-64 flex-wrap gap-1">
                    <PlateChip v-for="vehicle in membership.vehicles.slice(0, 3)" :key="vehicle.id" :plate="vehicle.plate" :country="vehicle.country ?? 'CO'" />
                    <span v-if="membership.vehicles.length > 3" class="self-center text-xs text-vz-muted">+{{ membership.vehicles.length - 3 }}</span>
                  </div>
                </td>
                <td class="whitespace-nowrap">
                  {{ formatDate(membership.covered_until) }}
                  <p class="text-xs text-vz-muted">periodo hasta {{ formatDate(membership.current_period_end) }}{{ membership.auto_renew ? ' · renueva sola' : '' }}</p>
                </td>
                <td>
                  <div class="flex flex-col items-start gap-1">
                    <StatusBadge :label="membershipStatus[membership.status].label" :tone="membershipStatus[membership.status].tone" :icon="membershipStatus[membership.status].icon" />
                    <StatusBadge v-if="coverageHint(membership, today)" :label="coverageHint(membership, today)!.text" :tone="coverageHint(membership, today)!.tone" />
                  </div>
                </td>
                <td class="text-right">
                  <button class="vz-btn vz-btn-sm vz-btn-light" type="button" :aria-label="`Ver la mensualidad ${membership.code}`" @click="viewing = membership.id">
                    <i class="ri-eye-line" aria-hidden="true" /> Ver
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <PaginationBar v-if="meta" :meta="meta" @change="loadList" />
      </div>

      <div v-else id="panel-planes" role="tabpanel" aria-labelledby="tab-planes" class="p-4">
        <StateCard v-if="plans.length === 0" variant="empty" title="Sin planes" message="Un plan define precio, periodo, vehículos y dónde y cuándo cubre." compact>
          <button v-if="can('memberships.manage')" class="vz-btn vz-btn-primary" type="button" @click="openPlan(null)"><i class="ri-add-line" aria-hidden="true" /> Nuevo plan</button>
        </StateCard>
        <ul v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <li v-for="plan in plans" :key="plan.id" class="flex flex-col rounded-vz border border-vz-border p-4" :class="plan.status === 'inactive' ? 'opacity-70' : ''">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate font-semibold text-vz-heading">{{ plan.name }}</p>
                <p class="text-xs text-vz-muted"><code>{{ plan.code }}</code> · <i :class="planCategory[plan.category].icon" aria-hidden="true" /> {{ planCategory[plan.category].label }}</p>
              </div>
              <StatusBadge :label="plan.status === 'active' ? 'A la venta' : 'Sin venta'" :tone="plan.status === 'active' ? 'success' : 'muted'" />
            </div>
            <p class="mt-3 text-lg font-semibold text-vz-heading">{{ describePrice(plan) }}</p>
            <ul class="mt-2 space-y-0.5 text-[13px] text-vz-muted">
              <li v-for="line in describeAccess(plan, lotName, zoneName)" :key="line">{{ line }}</li>
              <li>Hasta {{ plan.max_vehicles }} vehículo(s) · {{ plan.grace_days }} día(s) de gracia</li>
            </ul>
            <div class="mt-3">
              <p class="mb-1 flex justify-between text-xs text-vz-muted">
                <span>Vigentes</span>
                <span>{{ plan.sold ?? 0 }}{{ plan.capacity !== null ? ` de ${plan.capacity}` : '' }}</span>
              </p>
              <ProgressBar v-if="plan.capacity !== null" :value="((plan.sold ?? 0) / plan.capacity) * 100" :label="`Cupos vendidos de ${plan.name}`" />
            </div>
            <p v-if="plan.description" class="mt-2 text-xs text-vz-muted">{{ plan.description }}</p>
            <div v-if="can('memberships.manage')" class="mt-auto flex justify-end gap-2 pt-4">
              <button class="vz-btn vz-btn-sm vz-btn-light" type="button" :disabled="togglingPlan === plan.id" @click="onTogglePlan(plan)">
                <i :class="plan.status === 'active' ? 'ri-pause-circle-line' : 'ri-play-circle-line'" aria-hidden="true" /> {{ plan.status === 'active' ? 'Dejar de vender' : 'Volver a vender' }}
              </button>
              <button class="vz-btn vz-btn-sm vz-btn-soft-primary" type="button" @click="openPlan(plan)">
                <i class="ri-edit-2-line" aria-hidden="true" /> Editar
              </button>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <PlanFormModal :open="planFormOpen" :plan="editingPlan" :lots="lots" :zones="zones" :currency="currency" @close="planFormOpen = false" @saved="onPlanSaved" />
    <MembershipFormModal :open="selling" :plans="plans" :lots="lots" :zones="zones" :today="today" @close="selling = false" @saved="onSold" />
    <MembershipDetailModal :membership-id="viewing" :today="today" :plans="plans" @close="viewing = null" @changed="onChanged" />
  </div>
</template>
