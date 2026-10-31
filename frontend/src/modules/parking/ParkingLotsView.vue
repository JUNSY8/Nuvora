<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import BaseModal from '@/components/ui/BaseModal.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ProgressBar from '@/components/ui/ProgressBar.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { createParkingLot, listParkingLots } from '@/modules/parking/api'
import { lotStatus } from '@/modules/parking/labels'
import ParkingLotForm from '@/modules/parking/ParkingLotForm.vue'
import type { ParkingLot, ParkingLotInput } from '@/modules/parking/types'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { PageMeta } from '@/types/api'

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const { can } = usePermissions()

const lots = ref<ParkingLot[]>([])
const meta = ref<PageMeta | null>(null)
const state = ref<'loading' | 'ready' | 'denied' | 'no-tenant' | 'error'>('loading')
const errorMessage = ref('')
const search = ref('')
const debouncedSearch = refDebounced(search, 300)

const creating = ref(false)
const saving = ref(false)
const errors = useFieldErrors()

function emptyLot(): ParkingLotInput {
  return {
    name: '',
    code: '',
    description: null,
    address: null,
    city: null,
    latitude: null,
    longitude: null,
    timezone: auth.currentTenant?.timezone ?? 'America/Bogota',
    currency: 'COP',
    grace_period_minutes: 15,
    status: 'active',
  }
}

const form = ref<ParkingLotInput>(emptyLot())

async function load(page = 1): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  state.value = 'loading'

  try {
    const result = await listParkingLots({ page, per_page: 12, search: debouncedSearch.value || undefined })
    lots.value = result.items
    meta.value = result.meta
    state.value = 'ready'
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'FORBIDDEN' ? 'denied' : code === 'TENANT_REQUIRED' ? 'no-tenant' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar los parqueaderos.')
  }
}

function openCreate(): void {
  form.value = emptyLot()
  errors.clear()
  creating.value = true
}

async function onCreate(): Promise<void> {
  saving.value = true
  errors.clear()

  try {
    const lot = await createParkingLot(normalize(form.value))
    creating.value = false
    toasts.success(`Parqueadero ${lot.name} creado.`)
    await router.push({ name: 'parking-lot', params: { lotId: lot.id } })
  } catch (error) {
    errors.capture(error, 'No fue posible crear el parqueadero.')
  } finally {
    saving.value = false
  }
}

function normalize(input: ParkingLotInput): ParkingLotInput {
  const blank = (value: string | null) => (value === null || String(value).trim() === '' ? null : String(value).trim())

  return {
    ...input,
    description: blank(input.description),
    address: blank(input.address),
    city: blank(input.city),
    latitude: blank(input.latitude),
    longitude: blank(input.longitude),
    currency: input.currency.toUpperCase(),
  }
}

watch(debouncedSearch, () => load(1))

onMounted(async () => {
  await load()

  if (route.query.nuevo === '1' && can('parking.create')) {
    openCreate()
  }
})
</script>

<template>
  <div>
    <PageHeader title="Parqueaderos" subtitle="Sedes, capacidad y ocupación en tiempo real." :breadcrumbs="[{ label: 'Operación' }, { label: 'Parqueaderos' }]">
      <template v-if="can('parking.create') && state !== 'no-tenant'" #actions>
        <button class="vz-btn vz-btn-primary" type="button" @click="openCreate">
          <i class="ri-add-line" aria-hidden="true" /> Nuevo parqueadero
        </button>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Crea o selecciona una empresa para registrar parqueaderos.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar los parqueaderos de esta empresa." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load()"><i class="ri-refresh-line" aria-hidden="true" /> Reintentar</button>
    </StateCard>

    <template v-else>
      <div class="vz-card mb-5 p-4">
        <label class="sr-only" for="lot-search">Buscar parqueadero</label>
        <div class="relative max-w-sm">
          <i class="ri-search-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
          <input id="lot-search" v-model="search" class="vz-input ps-9" type="search" placeholder="Buscar por nombre, código o ciudad" />
        </div>
      </div>

      <StateCard v-if="state === 'loading'" variant="loading" compact />

      <StateCard
        v-else-if="lots.length === 0"
        variant="empty"
        :title="search ? 'Sin resultados' : 'Aún no hay parqueaderos'"
        :message="search ? 'Ningún parqueadero coincide con la búsqueda.' : 'Registra tu primera sede para configurar zonas, espacios y puertas.'"
      >
        <button v-if="!search && can('parking.create')" class="vz-btn vz-btn-primary" type="button" @click="openCreate">
          <i class="ri-add-line" aria-hidden="true" /> Nuevo parqueadero
        </button>
      </StateCard>

      <template v-else>
        <ul class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
          <li v-for="lot in lots" :key="lot.id">
            <RouterLink
              :to="{ name: 'parking-lot', params: { lotId: lot.id } }"
              class="vz-card group flex h-full flex-col p-5 transition-colors hover:border-vz-primary/60"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-center gap-3">
                  <span class="flex size-11 shrink-0 items-center justify-center rounded bg-vz-primary/15 text-2xl text-vz-primary-text" aria-hidden="true">
                    <i class="ri-parking-box-line" />
                  </span>
                  <div class="min-w-0">
                    <h2 class="truncate text-[15px] font-semibold text-vz-heading group-hover:text-white">{{ lot.name }}</h2>
                    <p class="truncate text-xs text-vz-muted">
                      <i class="ri-map-pin-2-line" aria-hidden="true" /> {{ [lot.address, lot.city].filter(Boolean).join(', ') || 'Sin dirección' }}
                    </p>
                  </div>
                </div>
                <StatusBadge :label="lotStatus[lot.status].label" :tone="lotStatus[lot.status].tone" />
              </div>

              <div class="mt-5">
                <div class="mb-1.5 flex items-center justify-between text-xs">
                  <span class="text-vz-muted">Ocupación</span>
                  <span class="font-semibold text-vz-heading">{{ lot.occupancy?.rate ?? 0 }}%</span>
                </div>
                <ProgressBar :value="lot.occupancy?.rate ?? 0" :label="`Ocupación de ${lot.name}`" />
              </div>

              <dl class="mt-5 grid grid-cols-4 gap-2 border-t border-vz-border pt-4 text-center">
                <div>
                  <dt class="text-[11px] text-vz-muted">Capacidad</dt>
                  <dd class="text-[15px] font-semibold text-vz-heading">{{ lot.occupancy?.capacity ?? 0 }}</dd>
                </div>
                <div>
                  <dt class="text-[11px] text-vz-muted">Libres</dt>
                  <dd class="text-[15px] font-semibold text-vz-success-text">{{ lot.occupancy?.available ?? 0 }}</dd>
                </div>
                <div>
                  <dt class="text-[11px] text-vz-muted">Zonas</dt>
                  <dd class="text-[15px] font-semibold text-vz-heading">{{ lot.zones_count }}</dd>
                </div>
                <div>
                  <dt class="text-[11px] text-vz-muted">Puertas</dt>
                  <dd class="text-[15px] font-semibold text-vz-heading">{{ lot.gates_count }}</dd>
                </div>
              </dl>
            </RouterLink>
          </li>
        </ul>
        <div v-if="meta && meta.last_page > 1" class="vz-card mt-5">
          <PaginationBar :meta="meta" @change="load" />
        </div>
      </template>
    </template>

    <BaseModal :open="creating" title="Nuevo parqueadero" size="lg" @close="creating = false">
      <form novalidate @submit.prevent="onCreate">
        <div class="vz-card-body">
          <ParkingLotForm v-model="form" :default-timezone="auth.currentTenant?.timezone ?? 'America/Bogota'" :errors="errors.fields.value" id-prefix="new-lot" />
          <p v-if="errors.general.value" class="mt-4 text-[13px] text-vz-danger" role="alert">{{ errors.general.value }}</p>
          <p class="mt-4 text-xs text-vz-muted"><i class="ri-information-line" aria-hidden="true" /> El parqueadero arranca abierto 24 horas. Puedes ajustar el horario después.</p>
        </div>
        <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
          <button class="vz-btn vz-btn-light" type="button" @click="creating = false">Cancelar</button>
          <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">
            <i v-if="saving" class="ri-loader-4-line animate-spin" aria-hidden="true" />
            {{ saving ? 'Creando…' : 'Crear parqueadero' }}
          </button>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
