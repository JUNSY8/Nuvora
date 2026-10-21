<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { softBadge } from '@/components/ui/tones'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { customerType } from '@/modules/customers/labels'
import { changeVehicleStatus, getVehicle, listVehicleHistory, unlinkCustomer } from '@/modules/vehicles/api'
import { describeEvent, eventMeta, plateCountries, relationship, vehicleStatus, vehicleStatusOrder, vehicleType } from '@/modules/vehicles/labels'
import LinkCustomerModal from '@/modules/vehicles/LinkCustomerModal.vue'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import type { Vehicle, VehicleEvent, VehicleLink, VehicleStatus } from '@/modules/vehicles/types'
import VehicleFormModal from '@/modules/vehicles/VehicleFormModal.vue'
import { formatDateTime } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { PageMeta } from '@/types/api'

const props = defineProps<{ vehicleId: string }>()

const auth = useAuthStore()
const toasts = useToastStore()
const { can } = usePermissions()

const vehicle = ref<Vehicle | null>(null)
const state = ref<'loading' | 'ready' | 'not-found' | 'denied' | 'error'>('loading')
const errorMessage = ref('')

const events = ref<VehicleEvent[]>([])
const eventsMeta = ref<PageMeta | null>(null)
const eventsState = ref<'loading' | 'ready' | 'error'>('loading')

const editing = ref(false)
const linking = ref(false)
const unlinking = ref<VehicleLink | null>(null)
const unlinkBusy = ref(false)
const unlinkError = ref('')

const statusTarget = ref<VehicleStatus | null>(null)
const statusReason = ref('')
const statusBusy = ref(false)
const statusErrors = useFieldErrors()

const tz = computed(() => auth.currentTenant?.timezone ?? 'America/Bogota')
const countryName = computed(() => plateCountries.find((country) => country.code === vehicle.value?.country)?.name ?? vehicle.value?.country ?? '')
const statusTargets = computed(() => vehicleStatusOrder.filter((status) => status !== vehicle.value?.status))
const statusAction: Record<VehicleStatus, { label: string; icon: string; button: string }> = {
  active: { label: 'Activar', icon: 'ri-checkbox-circle-line', button: 'vz-btn-soft-success' },
  inactive: { label: 'Inactivar', icon: 'ri-pause-circle-line', button: 'vz-btn-light' },
  blocked: { label: 'Bloquear', icon: 'ri-forbid-line', button: 'vz-btn-soft-danger' },
}

async function load(quiet = false): Promise<void> {
  if (!quiet) {
    state.value = 'loading'
  }

  try {
    vehicle.value = await getVehicle(props.vehicleId)
    state.value = 'ready'
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'NOT_FOUND' ? 'not-found' : code === 'FORBIDDEN' ? 'denied' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar el vehículo.')
  }
}

async function loadHistory(page = 1): Promise<void> {
  if (page === 1) {
    eventsState.value = 'loading'
  }

  try {
    const result = await listVehicleHistory(props.vehicleId, page)
    events.value = page === 1 ? result.items : [...events.value, ...result.items]
    eventsMeta.value = result.meta
    eventsState.value = 'ready'
  } catch {
    eventsState.value = 'error'
  }
}

async function refresh(): Promise<void> {
  await Promise.all([load(true), loadHistory()])
}

function openStatus(target: VehicleStatus): void {
  statusTarget.value = target
  statusReason.value = ''
  statusErrors.clear()
}

async function onStatus(): Promise<void> {
  if (!vehicle.value || !statusTarget.value) {
    return
  }

  statusBusy.value = true
  statusErrors.clear()

  try {
    await changeVehicleStatus(vehicle.value.id, statusTarget.value, statusReason.value.trim() || null)
    toasts.success(`${vehicle.value.plate}: ${vehicleStatus[statusTarget.value].label.toLowerCase()}.`)
    statusTarget.value = null
    await refresh()
  } catch (error) {
    statusErrors.capture(error, 'No fue posible cambiar el estado.')
  } finally {
    statusBusy.value = false
  }
}

async function onUnlink(): Promise<void> {
  if (!vehicle.value || !unlinking.value) {
    return
  }

  unlinkBusy.value = true
  unlinkError.value = ''

  try {
    await unlinkCustomer(vehicle.value.id, unlinking.value.id)
    toasts.success(`${unlinking.value.customer?.name ?? 'Cliente'} desvinculado.`)
    unlinking.value = null
    await refresh()
  } catch (error) {
    unlinkError.value = parseApiError(error, 'No fue posible desvincular el cliente.')
  } finally {
    unlinkBusy.value = false
  }
}

async function onSaved(saved: Vehicle): Promise<void> {
  editing.value = false
  toasts.success(`Vehículo ${saved.plate} actualizado.`)
  await refresh()
}

async function onLinked(): Promise<void> {
  linking.value = false
  toasts.success('Cliente vinculado.')
  await refresh()
}

watch(() => props.vehicleId, () => {
  void load()
  void loadHistory()
})
onMounted(() => {
  void load()
  void loadHistory()
})
</script>

<template>
  <div>
    <StateCard v-if="state === 'loading'" variant="loading" />
    <StateCard v-else-if="state === 'not-found'" variant="empty" title="Vehículo no encontrado" message="No existe o pertenece a otra empresa.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'vehicles' }">Volver a vehículos</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar este vehículo." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load()">Reintentar</button>
    </StateCard>

    <template v-else-if="vehicle">
      <PageHeader
        :title="[vehicle.brand, vehicle.model].filter(Boolean).join(' ') || vehicleType[vehicle.type].label"
        :subtitle="[vehicleType[vehicle.type].label, vehicle.color, vehicle.year].filter(Boolean).join(' · ')"
        :breadcrumbs="[{ label: 'Vehículos', to: { name: 'vehicles' } }, { label: vehicle.plate }]"
      >
        <template #actions>
          <PlateChip :plate="vehicle.plate" :country="vehicle.country" size="lg" />
          <StatusBadge :label="vehicleStatus[vehicle.status].label" :tone="vehicleStatus[vehicle.status].tone" :icon="vehicleStatus[vehicle.status].icon" />
          <button v-if="can('vehicles.update')" class="vz-btn vz-btn-light" type="button" @click="editing = true">
            <i class="ri-edit-2-line" aria-hidden="true" /> Editar
          </button>
        </template>
      </PageHeader>

      <div v-if="vehicle.status === 'blocked'" class="mb-5 flex items-start gap-3 rounded-vz border border-vz-danger/40 bg-vz-danger/10 p-4" role="alert">
        <i class="ri-forbid-line text-xl text-vz-danger" aria-hidden="true" />
        <div class="text-[13px]">
          <p class="font-semibold text-vz-danger-text">Vehículo bloqueado</p>
          <p>{{ vehicle.status_reason }}</p>
          <p v-if="vehicle.status_changed_at" class="mt-1 text-xs text-vz-muted">Desde {{ formatDateTime(vehicle.status_changed_at, tz) }}. Se negará su ingreso cuando el control de acceso esté activo.</p>
        </div>
      </div>

      <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
          <section class="vz-card">
            <div class="vz-card-header"><h2 class="vz-card-title">Datos del vehículo</h2></div>
            <dl class="vz-card-body grid gap-x-6 gap-y-4 text-[13px] sm:grid-cols-3">
              <div>
                <dt class="text-xs text-vz-muted">Placa</dt>
                <dd class="font-mono font-semibold text-vz-heading">{{ vehicle.plate }}</dd>
              </div>
              <div>
                <dt class="text-xs text-vz-muted">País</dt>
                <dd>{{ countryName }}</dd>
              </div>
              <div>
                <dt class="text-xs text-vz-muted">Formato</dt>
                <dd>{{ vehicle.plate_format?.label ?? 'No reconocido' }}</dd>
              </div>
              <div>
                <dt class="text-xs text-vz-muted">Tipo</dt>
                <dd><i :class="vehicleType[vehicle.type].icon" aria-hidden="true" /> {{ vehicleType[vehicle.type].label }}</dd>
              </div>
              <div>
                <dt class="text-xs text-vz-muted">Marca y línea</dt>
                <dd>{{ [vehicle.brand, vehicle.model].filter(Boolean).join(' ') || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs text-vz-muted">Color y año</dt>
                <dd>{{ [vehicle.color, vehicle.year].filter(Boolean).join(' · ') || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs text-vz-muted">Energía</dt>
                <dd>{{ vehicle.is_electric ? 'Eléctrico' : 'Combustión / híbrido' }}</dd>
              </div>
              <div>
                <dt class="text-xs text-vz-muted">Registrado</dt>
                <dd>{{ formatDateTime(vehicle.created_at, tz) }}</dd>
              </div>
              <div v-if="vehicle.notes" class="sm:col-span-3">
                <dt class="text-xs text-vz-muted">Notas internas</dt>
                <dd class="whitespace-pre-line">{{ vehicle.notes }}</dd>
              </div>
            </dl>
            <div v-if="can('vehicles.update')" class="flex flex-wrap gap-2 border-t border-vz-border px-5 py-3">
              <button v-for="target in statusTargets" :key="target" class="vz-btn vz-btn-sm" :class="statusAction[target].button" type="button" @click="openStatus(target)">
                <i :class="statusAction[target].icon" aria-hidden="true" /> {{ statusAction[target].label }}
              </button>
            </div>
          </section>

          <section class="vz-card">
            <div class="vz-card-header flex items-center justify-between gap-3">
              <h2 class="vz-card-title">Clientes vinculados</h2>
              <button v-if="can('vehicles.update') && can('customers.view')" class="vz-btn vz-btn-soft-primary vz-btn-sm" type="button" @click="linking = true">
                <i class="ri-link" aria-hidden="true" /> Vincular cliente
              </button>
            </div>
            <ul v-if="vehicle.links.length > 0" class="divide-y divide-vz-border">
              <li v-for="link in vehicle.links" :key="link.id" class="flex items-center gap-3 px-5 py-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded text-lg" :class="softBadge[relationship[link.relationship].tone]" aria-hidden="true">
                  <i :class="relationship[link.relationship].icon" />
                </span>
                <div class="min-w-0 flex-1">
                  <RouterLink v-if="link.customer && can('customers.view')" class="font-medium text-vz-heading hover:text-vz-secondary-text" :to="{ name: 'customer', params: { customerId: link.customer.id } }">{{ link.customer.name }}</RouterLink>
                  <p v-else class="font-medium text-vz-heading">{{ link.customer?.name ?? 'Cliente' }}</p>
                  <p class="text-xs text-vz-muted">
                    {{ relationship[link.relationship].label }}
                    <template v-if="link.customer"> · {{ customerType[link.customer.type].label }}</template>
                    · desde {{ formatDateTime(link.started_at, tz) }}
                  </p>
                </div>
                <StatusBadge v-if="link.customer?.status === 'inactive'" label="Cliente inactivo" tone="muted" />
                <button v-if="can('vehicles.update')" class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger" type="button" :aria-label="`Desvincular ${link.customer?.name ?? 'cliente'}`" @click="unlinkError = ''; unlinking = link">
                  <i class="ri-link-unlink" aria-hidden="true" />
                </button>
              </li>
            </ul>
            <p v-else class="px-5 py-6 text-center text-[13px] text-vz-muted">Sin clientes vinculados. Vincula al propietario o a los conductores habituales.</p>
          </section>
        </div>

        <section class="vz-card self-start" aria-labelledby="history-title">
          <div class="vz-card-header flex items-center justify-between">
            <h2 id="history-title" class="vz-card-title">Historial</h2>
            <span class="text-xs text-vz-muted">{{ eventsMeta?.total ?? 0 }} eventos</span>
          </div>
          <div class="vz-card-body">
            <p v-if="eventsState === 'loading'" class="text-center text-[13px] text-vz-muted" role="status">Cargando historial…</p>
            <p v-else-if="eventsState === 'error'" class="text-center text-[13px] text-vz-danger">
              No fue posible cargar el historial. <button class="underline" type="button" @click="loadHistory()">Reintentar</button>
            </p>
            <ol v-else class="relative space-y-5 border-s border-vz-border ps-6">
              <li v-for="event in events" :key="event.id" class="relative">
                <span class="absolute top-0 -left-[37px] flex size-7 items-center justify-center rounded-full text-sm ring-4 ring-vz-card" :class="softBadge[eventMeta[event.type].tone]" aria-hidden="true">
                  <i :class="eventMeta[event.type].icon" />
                </span>
                <p class="text-[13px] font-medium text-vz-heading">{{ eventMeta[event.type].label }}</p>
                <p class="text-[13px]">{{ describeEvent(event) }}</p>
                <p class="mt-0.5 text-xs text-vz-muted">{{ formatDateTime(event.occurred_at, tz) }}<template v-if="event.actor"> · {{ event.actor.name }}</template></p>
              </li>
            </ol>
            <button v-if="eventsMeta && eventsMeta.current_page < eventsMeta.last_page" class="vz-btn vz-btn-light vz-btn-sm mt-4 w-full" type="button" @click="loadHistory(eventsMeta.current_page + 1)">Ver eventos anteriores</button>
            <p class="mt-4 text-xs text-vz-muted"><i class="ri-information-line" aria-hidden="true" /> Las entradas, salidas y lecturas de placa se sumarán aquí con la operación de parqueo.</p>
          </div>
        </section>
      </div>

      <VehicleFormModal :open="editing" :vehicle="vehicle" @close="editing = false" @saved="onSaved" />
      <LinkCustomerModal :open="linking" :vehicle="vehicle" @close="linking = false" @linked="onLinked" />

      <BaseModal :open="statusTarget !== null" :title="statusTarget ? `${statusAction[statusTarget].label} ${vehicle.plate}` : ''" size="sm" @close="statusTarget = null">
        <form novalidate @submit.prevent="onStatus">
          <div class="vz-card-body space-y-3">
            <p class="text-[13px]">
              <template v-if="statusTarget === 'blocked'">Un vehículo bloqueado no podrá ingresar cuando el control de acceso esté activo.</template>
              <template v-else-if="statusTarget === 'inactive'">Úsalo para vehículos vendidos o que ya no frecuentan la sede. Su historial se conserva.</template>
              <template v-else>El vehículo vuelve a operar con normalidad.</template>
            </p>
            <div>
              <label class="vz-label" for="vehicle-status-reason">Motivo <span v-if="statusTarget !== 'blocked'" class="text-vz-muted">(opcional)</span></label>
              <textarea id="vehicle-status-reason" v-model="statusReason" class="vz-input min-h-16" maxlength="255" rows="2" :required="statusTarget === 'blocked'" :aria-invalid="!!statusErrors.fields.value.reason" />
              <p v-if="statusErrors.fields.value.reason" class="vz-error" role="alert">{{ statusErrors.fields.value.reason }}</p>
            </div>
            <p v-if="statusErrors.general.value && !statusErrors.fields.value.reason" class="text-[13px] text-vz-danger" role="alert">{{ statusErrors.general.value }}</p>
          </div>
          <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
            <button class="vz-btn vz-btn-light" type="button" @click="statusTarget = null">Cancelar</button>
            <button class="vz-btn" :class="statusTarget === 'blocked' ? 'vz-btn-danger' : 'vz-btn-primary'" type="submit" :disabled="statusBusy">{{ statusBusy ? 'Guardando…' : 'Confirmar' }}</button>
          </div>
        </form>
      </BaseModal>

      <ConfirmDialog
        :open="unlinking !== null"
        title="Desvincular cliente"
        :message="`¿Desvincular a ${unlinking?.customer?.name ?? 'este cliente'} como ${unlinking ? relationship[unlinking.relationship].label.toLowerCase() : ''}? El vínculo queda en el historial.`"
        confirm-label="Desvincular"
        :busy="unlinkBusy"
        :error="unlinkError"
        @close="unlinking = null"
        @confirm="onUnlink"
      />
    </template>
  </div>
</template>
