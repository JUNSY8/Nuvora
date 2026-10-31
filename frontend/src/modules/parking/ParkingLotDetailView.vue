<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import PageHeader from '@/components/ui/PageHeader.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { usePermissions } from '@/composables/usePermissions'
import { getParkingLot } from '@/modules/parking/api'
import { lotStatus } from '@/modules/parking/labels'
import GatesTab from '@/modules/parking/tabs/GatesTab.vue'
import HoursTab from '@/modules/parking/tabs/HoursTab.vue'
import SettingsTab from '@/modules/parking/tabs/SettingsTab.vue'
import SpacesTab from '@/modules/parking/tabs/SpacesTab.vue'
import ZonesTab from '@/modules/parking/tabs/ZonesTab.vue'
import type { ParkingLot } from '@/modules/parking/types'
import { apiErrorCode, parseApiError } from '@/services/http'

const props = defineProps<{ lotId: string }>()

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()

const lot = ref<ParkingLot | null>(null)
const state = ref<'loading' | 'ready' | 'not-found' | 'denied' | 'error'>('loading')
const errorMessage = ref('')

const tabs = computed(() => [
  { key: 'espacios', label: 'Mapa de espacios', icon: 'ri-layout-grid-line' },
  { key: 'zonas', label: 'Zonas', icon: 'ri-stack-line' },
  { key: 'puertas', label: 'Puertas', icon: 'ri-door-open-line' },
  { key: 'horario', label: 'Horario', icon: 'ri-time-line' },
  ...(can('parking.update') || can('parking.delete') ? [{ key: 'configuracion', label: 'Configuración', icon: 'ri-settings-3-line' }] : []),
])

const activeTab = computed(() => {
  const requested = String(route.query.tab ?? 'espacios')

  return tabs.value.some((tab) => tab.key === requested) ? requested : 'espacios'
})

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
  const next = tabs.value[(index + offset + tabs.value.length) % tabs.value.length]

  if (next) {
    selectTab(next.key)
    document.getElementById(`tab-${next.key}`)?.focus()
  }
}

async function load(quiet = false): Promise<void> {
  if (!quiet) {
    state.value = 'loading'
  }

  try {
    lot.value = await getParkingLot(props.lotId)
    state.value = 'ready'
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'NOT_FOUND' ? 'not-found' : code === 'FORBIDDEN' ? 'denied' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar el parqueadero.')
  }
}

watch(() => props.lotId, () => load())
onMounted(() => load())
</script>

<template>
  <div>
    <StateCard v-if="state === 'loading'" variant="loading" />
    <StateCard v-else-if="state === 'not-found'" variant="empty" title="Parqueadero no encontrado" message="No existe o pertenece a otra empresa.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'parking-lots' }">Volver a parqueaderos</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar este parqueadero." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load()">Reintentar</button>
    </StateCard>

    <template v-else-if="lot">
      <PageHeader
        :title="lot.name"
        :subtitle="[lot.code, lot.address, lot.city].filter(Boolean).join(' · ')"
        :breadcrumbs="[{ label: 'Parqueaderos', to: { name: 'parking-lots' } }, { label: lot.name }]"
      >
        <template #actions>
          <StatusBadge :label="lotStatus[lot.status].label" :tone="lotStatus[lot.status].tone" :icon="lotStatus[lot.status].icon" />
          <button class="vz-btn vz-btn-light" type="button" @click="load(true)">
            <i class="ri-refresh-line" aria-hidden="true" /> Actualizar
          </button>
        </template>
      </PageHeader>

      <section class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen del parqueadero">
        <StatCard label="Ocupación" :value="`${lot.occupancy?.rate ?? 0}%`" icon="ri-pie-chart-2-line" tone="primary" :hint="`${lot.occupancy?.in_use ?? 0} espacios en uso`" />
        <StatCard label="Disponibles" :value="lot.occupancy?.available ?? 0" icon="ri-checkbox-circle-line" tone="success" :hint="`De ${lot.occupancy?.capacity ?? 0} de capacidad`" />
        <StatCard label="Fuera de operación" :value="(lot.occupancy?.by_status.blocked ?? 0) + (lot.occupancy?.by_status.maintenance ?? 0) + (lot.occupancy?.by_status.out_of_service ?? 0)" icon="ri-tools-line" tone="warning" hint="Bloqueados, mantenimiento o fuera de servicio" />
        <StatCard label="Zonas y puertas" :value="`${lot.zones_count} / ${lot.gates_count}`" icon="ri-door-open-line" tone="info" :hint="`Gracia de ${lot.grace_period_minutes} min · ${lot.currency}`" />
      </section>

      <div class="vz-card mt-5">
        <div class="overflow-x-auto border-b border-vz-border px-2" role="tablist" aria-label="Secciones del parqueadero">
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
          <SpacesTab v-if="activeTab === 'espacios'" :lot="lot" @changed="load(true)" />
          <ZonesTab v-else-if="activeTab === 'zonas'" :lot="lot" @changed="load(true)" />
          <GatesTab v-else-if="activeTab === 'puertas'" :lot="lot" @changed="load(true)" />
          <HoursTab v-else-if="activeTab === 'horario'" :lot="lot" @changed="load(true)" />
          <SettingsTab v-else-if="activeTab === 'configuracion'" :lot="lot" @changed="load(true)" />
        </div>
      </div>
    </template>
  </div>
</template>
