<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { softBadge } from '@/components/ui/tones'
import { usePermissions } from '@/composables/usePermissions'
import { listVehicles } from '@/modules/vehicles/api'
import { relationship, vehicleStatus, vehicleStatusOrder, vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import type { Vehicle, VehicleStatus, VehicleType } from '@/modules/vehicles/types'
import VehicleFormModal from '@/modules/vehicles/VehicleFormModal.vue'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { PageMeta } from '@/types/api'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const { can } = usePermissions()

const vehicles = ref<Vehicle[]>([])
const meta = ref<PageMeta | null>(null)
const counts = ref<Record<VehicleStatus, number> | null>(null)
const state = ref<'loading' | 'ready' | 'denied' | 'no-tenant' | 'error'>('loading')
const errorMessage = ref('')
const creating = ref(false)

const filters = reactive<{ status: '' | VehicleStatus; type: '' | VehicleType; search: string }>({ status: '', type: '', search: '' })
const debouncedSearch = refDebounced(computed(() => filters.search), 300)
const total = computed(() => (counts.value ? Object.values(counts.value).reduce((sum, value) => sum + value, 0) : '—'))

function primaryLink(vehicle: Vehicle) {
  return vehicle.links.find((link) => link.relationship === 'owner') ?? vehicle.links[0] ?? null
}

async function load(page = 1): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  state.value = 'loading'

  try {
    const result = await listVehicles({
      status: filters.status || undefined,
      type: filters.type || undefined,
      search: debouncedSearch.value || undefined,
    }, page)
    vehicles.value = result.items
    meta.value = result.meta
    counts.value = result.status_counts
    state.value = 'ready'
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'FORBIDDEN' ? 'denied' : code === 'TENANT_REQUIRED' ? 'no-tenant' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar los vehículos.')
  }
}

function onSaved(vehicle: Vehicle): void {
  creating.value = false
  toasts.success(`Vehículo ${vehicle.plate} registrado.`)
  void router.push({ name: 'vehicle', params: { vehicleId: vehicle.id } })
}

watch(() => [filters.status, filters.type, debouncedSearch.value], () => load(1))
onMounted(() => load())
</script>

<template>
  <div>
    <PageHeader title="Vehículos" subtitle="Placas normalizadas, propietarios y conductores autorizados." :breadcrumbs="[{ label: 'Clientes' }, { label: 'Vehículos' }]">
      <template v-if="can('vehicles.create') && state !== 'no-tenant'" #actions>
        <button class="vz-btn vz-btn-primary" type="button" @click="creating = true">
          <i class="ri-add-line" aria-hidden="true" /> Registrar vehículo
        </button>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para ver sus vehículos.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar los vehículos." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load()">Reintentar</button>
    </StateCard>

    <template v-else>
      <section class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Resumen por estado">
        <button
          class="vz-card flex items-center gap-3 p-4 text-left transition-colors hover:border-vz-primary/50"
          :class="filters.status === '' ? 'border-vz-secondary' : ''"
          type="button"
          :aria-pressed="filters.status === ''"
          @click="filters.status = ''"
        >
          <span class="flex size-10 items-center justify-center rounded text-xl" :class="softBadge.primary" aria-hidden="true"><i class="ri-car-line" /></span>
          <span>
            <span class="block text-lg font-semibold text-vz-heading">{{ total }}</span>
            <span class="block text-xs text-vz-muted">Registrados</span>
          </span>
        </button>
        <button
          v-for="status in vehicleStatusOrder"
          :key="status"
          class="vz-card flex items-center gap-3 p-4 text-left transition-colors hover:border-vz-primary/50"
          :class="filters.status === status ? 'border-vz-secondary' : ''"
          type="button"
          :aria-pressed="filters.status === status"
          @click="filters.status = filters.status === status ? '' : status"
        >
          <span class="flex size-10 items-center justify-center rounded text-xl" :class="softBadge[vehicleStatus[status].tone]" aria-hidden="true">
            <i :class="vehicleStatus[status].icon" />
          </span>
          <span>
            <span class="block text-lg font-semibold text-vz-heading">{{ counts?.[status] ?? '—' }}</span>
            <span class="block text-xs text-vz-muted">{{ vehicleStatus[status].label }}s</span>
          </span>
        </button>
      </section>

      <div class="vz-card">
        <div class="grid gap-3 border-b border-vz-border p-4 sm:grid-cols-3">
          <div class="sm:col-span-2">
            <label class="sr-only" for="vehicle-search">Buscar</label>
            <div class="relative">
              <i class="ri-search-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
              <input id="vehicle-search" v-model="filters.search" class="vz-input ps-9" type="search" placeholder="Placa (abc-123 o ABC123), marca, línea o color" />
            </div>
          </div>
          <div>
            <label class="sr-only" for="vehicle-type-filter">Tipo</label>
            <select id="vehicle-type-filter" v-model="filters.type" class="vz-input">
              <option value="">Todos los tipos</option>
              <option v-for="type in vehicleTypeOrder" :key="type" :value="type">{{ vehicleType[type].label }}</option>
            </select>
          </div>
        </div>

        <div v-if="state === 'loading'" class="p-8 text-center text-vz-muted" role="status"><i class="ri-loader-4-line animate-spin text-2xl" aria-hidden="true" /><p>Cargando vehículos…</p></div>
        <div v-else-if="vehicles.length === 0" class="p-10 text-center">
          <i class="ri-car-line text-4xl text-vz-muted" aria-hidden="true" />
          <p class="mt-2 font-medium text-vz-heading">Sin vehículos</p>
          <p class="text-[13px] text-vz-muted">{{ filters.search || filters.status || filters.type ? 'Ningún vehículo coincide con los filtros.' : 'Registra los vehículos frecuentes para identificarlos en la entrada.' }}</p>
        </div>
        <div v-else class="overflow-x-auto">
          <table class="vz-table">
            <thead>
              <tr>
                <th scope="col">Placa</th>
                <th scope="col">Vehículo</th>
                <th scope="col">Cliente</th>
                <th scope="col">Estado</th>
                <th scope="col"><span class="sr-only">Abrir</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="vehicle in vehicles" :key="vehicle.id" class="cursor-pointer" @click="router.push({ name: 'vehicle', params: { vehicleId: vehicle.id } })">
                <td><PlateChip :plate="vehicle.plate" :country="vehicle.country" /></td>
                <td>
                  <div class="flex items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded bg-vz-elevated text-lg text-vz-secondary-text" aria-hidden="true">
                      <i :class="vehicleType[vehicle.type].icon" />
                    </span>
                    <div>
                      <p class="font-medium text-vz-heading">
                        {{ [vehicle.brand, vehicle.model].filter(Boolean).join(' ') || vehicleType[vehicle.type].label }}
                        <i v-if="vehicle.is_electric" class="ri-flashlight-fill text-vz-success" title="Eléctrico" aria-label="Eléctrico" />
                      </p>
                      <p class="text-xs text-vz-muted">{{ [vehicleType[vehicle.type].label, vehicle.color, vehicle.year].filter(Boolean).join(' · ') }}</p>
                    </div>
                  </div>
                </td>
                <td>
                  <template v-if="primaryLink(vehicle)?.customer">
                    <p class="text-vz-heading">{{ primaryLink(vehicle)?.customer?.name }}</p>
                    <p class="text-xs text-vz-muted">
                      {{ relationship[primaryLink(vehicle)!.relationship].label }}<template v-if="vehicle.links.length > 1"> · +{{ vehicle.links.length - 1 }} más</template>
                    </p>
                  </template>
                  <span v-else class="text-xs text-vz-muted">Sin cliente</span>
                </td>
                <td>
                  <StatusBadge :label="vehicleStatus[vehicle.status].label" :tone="vehicleStatus[vehicle.status].tone" :icon="vehicleStatus[vehicle.status].icon" />
                </td>
                <td class="text-right">
                  <RouterLink class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" :to="{ name: 'vehicle', params: { vehicleId: vehicle.id } }" :aria-label="`Abrir ${vehicle.plate}`" @click.stop>
                    <i class="ri-arrow-right-s-line text-lg" aria-hidden="true" />
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <PaginationBar v-if="meta" :meta="meta" @change="load" />
      </div>
    </template>

    <VehicleFormModal :open="creating" @close="creating = false" @saved="onSaved" />
  </div>
</template>
