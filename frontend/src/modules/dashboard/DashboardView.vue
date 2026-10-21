<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import PageHeader from '@/components/ui/PageHeader.vue'
import ProgressBar from '@/components/ui/ProgressBar.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { solidFill, toneText } from '@/components/ui/tones'
import { usePermissions } from '@/composables/usePermissions'
import { listDevices } from '@/modules/devices/api'
import { deviceStatus, deviceStatusOrder } from '@/modules/devices/labels'
import type { DeviceStatus } from '@/modules/devices/types'
import { listParkingLots } from '@/modules/parking/api'
import { lotStatus, spaceStatus, spaceStatusOrder } from '@/modules/parking/labels'
import type { ParkingLot, SpaceStatus } from '@/modules/parking/types'
import { formatNumber } from '@/services/format'
import { http } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import type { ApiSuccess, ReadinessData } from '@/types/api'

const auth = useAuthStore()
const { can } = usePermissions()

type LoadState = 'idle' | 'loading' | 'ready' | 'error'

const lots = ref<ParkingLot[]>([])
const lotsState = ref<LoadState>('idle')
const deviceCounts = ref<Record<DeviceStatus, number> | null>(null)
const devicesState = ref<LoadState>('idle')
const health = ref<ReadinessData | null>(null)
const healthState = ref<LoadState>('loading')

const checkLabels: Record<string, { label: string; icon: string }> = {
  database: { label: 'Base de datos', icon: 'ri-database-2-line' },
  redis: { label: 'Caché y sesiones', icon: 'ri-flashlight-line' },
  queue: { label: 'Colas', icon: 'ri-stack-line' },
  storage: { label: 'Almacenamiento', icon: 'ri-hard-drive-2-line' },
}

const totals = computed(() => {
  const byStatus = Object.fromEntries(spaceStatusOrder.map((status) => [status, 0])) as Record<SpaceStatus, number>
  let capacity = 0
  let inUse = 0
  let available = 0
  let total = 0

  for (const lot of lots.value) {
    if (!lot.occupancy) {
      continue
    }

    capacity += lot.occupancy.capacity
    inUse += lot.occupancy.in_use
    available += lot.occupancy.available
    total += lot.occupancy.total

    for (const status of spaceStatusOrder) {
      byStatus[status] += lot.occupancy.by_status[status] ?? 0
    }
  }

  // The per-lot rate comes from the API; the tenant-wide figure is only a display aggregate.
  const rate = capacity === 0 ? 0 : Math.floor((inUse * 100) / capacity)

  return { byStatus, capacity, inUse, available, total, rate }
})

const activeLots = computed(() => lots.value.filter((lot) => lot.status === 'active').length)
const totalDevices = computed(() => (deviceCounts.value ? Object.values(deviceCounts.value).reduce((sum, value) => sum + value, 0) : 0))

async function loadLots(): Promise<void> {
  if (!can('parking.view')) {
    return
  }

  lotsState.value = 'loading'

  try {
    lots.value = (await listParkingLots({ per_page: 100 })).items
    lotsState.value = 'ready'
  } catch {
    lotsState.value = 'error'
  }
}

async function loadDevices(): Promise<void> {
  if (!can('devices.view')) {
    return
  }

  devicesState.value = 'loading'

  try {
    deviceCounts.value = (await listDevices({}, 1, 1)).status_counts
    devicesState.value = 'ready'
  } catch {
    devicesState.value = 'error'
  }
}

async function loadHealth(): Promise<void> {
  try {
    const response = await http.get<ApiSuccess<ReadinessData>>('/health/ready')
    health.value = response.data.data
    healthState.value = 'ready'
  } catch (error) {
    const response = (error as { response?: { status?: number; data?: ApiSuccess<ReadinessData> } }).response

    if (response?.status === 503 && response.data?.data) {
      health.value = response.data.data
      healthState.value = 'ready'
      return
    }

    healthState.value = 'error'
  }
}

onMounted(() => {
  void Promise.all([loadLots(), loadDevices(), loadHealth()])
})
</script>

<template>
  <div>
    <PageHeader
      title="Panel de operación"
      :subtitle="auth.currentTenant ? `Hola, ${auth.user?.name}. Este es el estado de ${auth.currentTenant.name}.` : `Hola, ${auth.user?.name}.`"
    >
      <template v-if="can('parking.view')" #actions>
        <RouterLink class="vz-btn vz-btn-soft-primary" :to="{ name: 'parking-lots' }">
          <i class="ri-parking-box-line" aria-hidden="true" /> Ver parqueaderos
        </RouterLink>
      </template>
    </PageHeader>

    <StateCard
      v-if="!auth.currentTenant"
      variant="no-tenant"
      message="Crea o selecciona una empresa para empezar a configurar tus parqueaderos."
    >
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }"><i class="ri-building-line" aria-hidden="true" /> Ir a empresa</RouterLink>
    </StateCard>

    <template v-else>
      <section class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicadores">
        <StatCard
          label="Ocupación actual"
          :value="`${totals.rate}%`"
          icon="ri-pie-chart-2-line"
          tone="primary"
          :hint="`${formatNumber(totals.inUse)} de ${formatNumber(totals.capacity)} espacios en uso`"
          :loading="lotsState === 'loading'"
        />
        <StatCard
          label="Espacios disponibles"
          :value="formatNumber(totals.available)"
          icon="ri-checkbox-circle-line"
          tone="success"
          :hint="`${formatNumber(totals.total)} espacios registrados`"
          :loading="lotsState === 'loading'"
        />
        <StatCard
          label="Parqueaderos activos"
          :value="`${activeLots} / ${lots.length}`"
          icon="ri-parking-box-line"
          tone="info"
          hint="Sedes operando hoy"
          :loading="lotsState === 'loading'"
        />
        <StatCard
          label="Dispositivos en línea"
          :value="deviceCounts ? `${deviceCounts.online} / ${totalDevices}` : '—'"
          icon="ri-router-line"
          tone="warning"
          :hint="can('devices.view') ? `${deviceCounts?.offline ?? 0} sin conexión` : 'Sin permiso para ver dispositivos'"
          :loading="devicesState === 'loading'"
        />
      </section>

      <div class="mt-5 grid gap-5 xl:grid-cols-3">
        <section class="vz-card xl:col-span-2" aria-labelledby="occupancy-title">
          <div class="vz-card-header">
            <h2 id="occupancy-title" class="vz-card-title">Ocupación por parqueadero</h2>
            <RouterLink v-if="can('parking.view')" class="text-[13px] text-vz-secondary-text hover:underline" :to="{ name: 'parking-lots' }">Ver todos</RouterLink>
          </div>
          <div class="vz-card-body">
            <p v-if="!can('parking.view')" class="text-vz-muted" role="status">Tu rol no permite consultar los parqueaderos.</p>
            <p v-else-if="lotsState === 'loading'" class="text-vz-muted" role="status">Cargando ocupación…</p>
            <p v-else-if="lotsState === 'error'" class="text-vz-danger" role="alert">No fue posible consultar los parqueaderos.</p>
            <div v-else-if="lots.length === 0" class="py-6 text-center">
              <i class="ri-parking-box-line text-4xl text-vz-muted" aria-hidden="true" />
              <p class="mt-2 text-vz-muted">Aún no hay parqueaderos registrados.</p>
              <RouterLink v-if="can('parking.create')" class="vz-btn vz-btn-primary mt-4" :to="{ name: 'parking-lots', query: { nuevo: '1' } }">
                <i class="ri-add-line" aria-hidden="true" /> Crear el primero
              </RouterLink>
            </div>
            <ul v-else class="space-y-5">
              <li v-for="lot in lots" :key="lot.id">
                <div class="mb-2 flex items-center justify-between gap-3">
                  <RouterLink :to="{ name: 'parking-lot', params: { lotId: lot.id } }" class="flex min-w-0 items-center gap-3 hover:text-vz-topbar-item">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded bg-vz-elevated text-lg text-vz-secondary-text" aria-hidden="true">
                      <i class="ri-parking-box-line" />
                    </span>
                    <span class="min-w-0">
                      <span class="block truncate font-medium text-vz-heading">{{ lot.name }}</span>
                      <span class="block truncate text-xs text-vz-muted">{{ lot.code }}<template v-if="lot.city"> · {{ lot.city }}</template></span>
                    </span>
                  </RouterLink>
                  <div class="flex shrink-0 items-center gap-3">
                    <StatusBadge v-if="lot.status !== 'active'" :label="lotStatus[lot.status].label" :tone="lotStatus[lot.status].tone" />
                    <span class="text-right text-xs text-vz-muted">
                      <span class="block text-[15px] font-semibold text-vz-heading">{{ lot.occupancy?.rate ?? 0 }}%</span>
                      {{ lot.occupancy?.available ?? 0 }} libres
                    </span>
                  </div>
                </div>
                <ProgressBar :value="lot.occupancy?.rate ?? 0" :label="`Ocupación de ${lot.name}`" />
              </li>
            </ul>
          </div>
        </section>

        <section class="vz-card" aria-labelledby="spaces-title">
          <div class="vz-card-header">
            <h2 id="spaces-title" class="vz-card-title">Estado de los espacios</h2>
          </div>
          <div class="vz-card-body">
            <div v-if="totals.total > 0" class="mb-5 flex h-3 overflow-hidden rounded-full bg-vz-elevated" aria-hidden="true">
              <div
                v-for="status in spaceStatusOrder"
                :key="status"
                :class="solidFill[spaceStatus[status].tone]"
                :style="{ width: `${(totals.byStatus[status] * 100) / totals.total}%` }"
              />
            </div>
            <ul class="divide-y divide-vz-border">
              <li v-for="status in spaceStatusOrder" :key="status" class="flex items-center justify-between py-2.5">
                <span class="flex items-center gap-2.5">
                  <span class="size-2.5 rounded-full" :class="solidFill[spaceStatus[status].tone]" aria-hidden="true" />
                  {{ spaceStatus[status].label }}
                </span>
                <span class="font-semibold text-vz-heading">{{ formatNumber(totals.byStatus[status]) }}</span>
              </li>
            </ul>
          </div>
        </section>
      </div>

      <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <section class="vz-card" aria-labelledby="devices-title">
          <div class="vz-card-header">
            <h2 id="devices-title" class="vz-card-title">Dispositivos</h2>
            <RouterLink v-if="can('devices.view')" class="text-[13px] text-vz-secondary-text hover:underline" :to="{ name: 'devices' }">Administrar</RouterLink>
          </div>
          <div class="vz-card-body">
            <p v-if="!can('devices.view')" class="text-vz-muted" role="status">Tu rol no permite consultar los dispositivos.</p>
            <p v-else-if="devicesState === 'error'" class="text-vz-danger" role="alert">No fue posible consultar los dispositivos.</p>
            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-5">
              <div v-for="status in deviceStatusOrder" :key="status" class="rounded-vz border border-vz-border bg-vz-elevated/40 p-3 text-center">
                <i class="text-xl" :class="[deviceStatus[status].icon, toneText[deviceStatus[status].tone]]" aria-hidden="true" />
                <p class="mt-1 text-lg font-semibold text-vz-heading">{{ deviceCounts?.[status] ?? 0 }}</p>
                <p class="text-[11px] text-vz-muted">{{ deviceStatus[status].label }}</p>
              </div>
            </div>
            <p v-if="can('devices.view')" class="mt-4 text-xs text-vz-muted">
              <i class="ri-information-line" aria-hidden="true" /> El estado en línea lo reporta Nuvora Edge. Hasta su primer reporte, un equipo aparece como “Sin reporte”.
            </p>
          </div>
        </section>

        <section class="vz-card" aria-labelledby="platform-title">
          <div class="vz-card-header">
            <h2 id="platform-title" class="vz-card-title">Estado de la plataforma</h2>
            <StatusBadge
              v-if="health"
              :label="health.status === 'ok' ? 'Operativa' : 'Degradada'"
              :tone="health.status === 'ok' ? 'success' : 'warning'"
            />
          </div>
          <div class="vz-card-body">
            <p v-if="healthState === 'loading'" class="text-vz-muted" role="status">Consultando dependencias…</p>
            <p v-else-if="healthState === 'error'" class="text-vz-danger" role="alert">No fue posible consultar el estado de la plataforma.</p>
            <ul v-else-if="health" class="divide-y divide-vz-border">
              <li v-for="(state, name) in health.checks" :key="name" class="flex items-center justify-between py-2.5">
                <span class="flex items-center gap-2.5">
                  <i class="text-lg text-vz-muted" :class="checkLabels[name]?.icon ?? 'ri-server-line'" aria-hidden="true" />
                  {{ checkLabels[name]?.label ?? name }}
                </span>
                <StatusBadge :label="state === 'ok' ? 'Disponible' : 'No disponible'" :tone="state === 'ok' ? 'success' : 'danger'" />
              </li>
            </ul>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>
