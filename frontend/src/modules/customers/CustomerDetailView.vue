<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { softBadge } from '@/components/ui/tones'
import { usePermissions } from '@/composables/usePermissions'
import { changeCustomerStatus, getCustomer, listCustomers } from '@/modules/customers/api'
import CustomerFormModal from '@/modules/customers/CustomerFormModal.vue'
import { customerStatus, customerType } from '@/modules/customers/labels'
import type { Customer, CustomerSummary } from '@/modules/customers/types'
import { listVehicles } from '@/modules/vehicles/api'
import { relationship, vehicleStatus, vehicleType } from '@/modules/vehicles/labels'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import type { Vehicle } from '@/modules/vehicles/types'
import VehicleFormModal from '@/modules/vehicles/VehicleFormModal.vue'
import { formatDateTime, initials } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ customerId: string }>()

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const { can } = usePermissions()

const customer = ref<Customer | null>(null)
const state = ref<'loading' | 'ready' | 'not-found' | 'denied' | 'error'>('loading')
const errorMessage = ref('')

const vehicles = ref<Vehicle[]>([])
const vehiclesState = ref<'loading' | 'ready' | 'hidden' | 'error'>('loading')
const members = ref<Customer[]>([])

const editing = ref(false)
const addingVehicle = ref(false)
const toggling = ref(false)
const toggleBusy = ref(false)
const toggleError = ref('')

const tz = computed(() => auth.currentTenant?.timezone ?? 'America/Bogota')
const summary = computed<CustomerSummary | null>(() => customer.value ? { id: customer.value.id, name: customer.value.name, type: customer.value.type, status: customer.value.status } : null)

function linkFor(vehicle: Vehicle) {
  return vehicle.links.find((link) => link.customer?.id === props.customerId) ?? null
}

async function load(quiet = false): Promise<void> {
  if (!quiet) {
    state.value = 'loading'
  }

  try {
    customer.value = await getCustomer(props.customerId)
    state.value = 'ready'
    await Promise.all([loadVehicles(), loadMembers()])
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'NOT_FOUND' ? 'not-found' : code === 'FORBIDDEN' ? 'denied' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar el cliente.')
  }
}

async function loadVehicles(): Promise<void> {
  if (!can('vehicles.view')) {
    vehiclesState.value = 'hidden'
    return
  }

  try {
    vehicles.value = (await listVehicles({ customer_id: props.customerId }, 1, 100)).items
    vehiclesState.value = 'ready'
  } catch {
    vehiclesState.value = 'error'
  }
}

async function loadMembers(): Promise<void> {
  members.value = customer.value && customer.value.type !== 'individual'
    ? (await listCustomers({ business_id: customer.value.id }, 1, 50)).items
    : []
}

async function onToggle(): Promise<void> {
  if (!customer.value) {
    return
  }

  toggleBusy.value = true
  toggleError.value = ''
  const target = customer.value.status === 'active' ? 'inactive' : 'active'

  try {
    customer.value = await changeCustomerStatus(customer.value.id, target)
    toasts.success(target === 'active' ? 'Cliente reactivado.' : 'Cliente inactivado.')
    toggling.value = false
  } catch (error) {
    toggleError.value = parseApiError(error, 'No fue posible cambiar el estado.')
  } finally {
    toggleBusy.value = false
  }
}

async function onSaved(): Promise<void> {
  editing.value = false
  toasts.success('Cliente actualizado.')
  await load(true)
}

async function onVehicleSaved(vehicle: Vehicle): Promise<void> {
  addingVehicle.value = false
  toasts.success(`Vehículo ${vehicle.plate} registrado.`)
  await loadVehicles()
}

watch(() => props.customerId, () => load())
onMounted(() => load())
</script>

<template>
  <div>
    <StateCard v-if="state === 'loading'" variant="loading" />
    <StateCard v-else-if="state === 'not-found'" variant="empty" title="Cliente no encontrado" message="No existe o pertenece a otra empresa.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'customers' }">Volver a clientes</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar este cliente." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load()">Reintentar</button>
    </StateCard>

    <template v-else-if="customer">
      <PageHeader
        :title="customer.name"
        :subtitle="customer.document_number ? `${customer.document_type} ${customer.document_number}` : 'Sin documento registrado'"
        :breadcrumbs="[{ label: 'Clientes', to: { name: 'customers' } }, { label: customer.name }]"
      >
        <template #actions>
          <StatusBadge :label="customerType[customer.type].label" :tone="customerType[customer.type].tone" :icon="customerType[customer.type].icon" />
          <StatusBadge :label="customerStatus[customer.status].label" :tone="customerStatus[customer.status].tone" />
          <template v-if="can('customers.update')">
            <button class="vz-btn vz-btn-light" type="button" @click="toggleError = ''; toggling = true">
              <i :class="customer.status === 'active' ? 'ri-pause-circle-line' : 'ri-play-circle-line'" aria-hidden="true" />
              {{ customer.status === 'active' ? 'Inactivar' : 'Reactivar' }}
            </button>
            <button class="vz-btn vz-btn-primary" type="button" @click="editing = true"><i class="ri-edit-2-line" aria-hidden="true" /> Editar</button>
          </template>
        </template>
      </PageHeader>

      <div class="grid gap-5 xl:grid-cols-3">
        <section class="vz-card self-start">
          <div class="vz-card-body flex flex-col items-center text-center">
            <span class="flex size-16 items-center justify-center rounded-full text-xl font-semibold" :class="softBadge[customerType[customer.type].tone]" aria-hidden="true">{{ initials(customer.name) }}</span>
            <h2 class="mt-3 font-semibold text-vz-heading">{{ customer.name }}</h2>
            <p class="text-xs text-vz-muted">Cliente desde {{ formatDateTime(customer.created_at, tz) }}</p>
          </div>
          <dl class="space-y-3 border-t border-vz-border px-5 py-4 text-[13px]">
            <div class="flex gap-3">
              <dt class="w-5 text-vz-muted"><i class="ri-mail-line" aria-hidden="true" /><span class="sr-only">Correo</span></dt>
              <dd class="min-w-0 break-words">{{ customer.email ?? 'Sin correo' }}</dd>
            </div>
            <div class="flex gap-3">
              <dt class="w-5 text-vz-muted"><i class="ri-phone-line" aria-hidden="true" /><span class="sr-only">Teléfono</span></dt>
              <dd>{{ customer.phone ?? 'Sin teléfono' }}</dd>
            </div>
            <div class="flex gap-3">
              <dt class="w-5 text-vz-muted"><i class="ri-profile-line" aria-hidden="true" /><span class="sr-only">Documento</span></dt>
              <dd>{{ customer.document_number ? `${customer.document_type} ${customer.document_number}` : 'Sin documento' }}</dd>
            </div>
            <div v-if="customer.business" class="flex gap-3">
              <dt class="w-5 text-vz-muted"><i class="ri-building-2-line" aria-hidden="true" /><span class="sr-only">Empresa</span></dt>
              <dd><RouterLink class="text-vz-secondary-text hover:underline" :to="{ name: 'customer', params: { customerId: customer.business.id } }">{{ customer.business.name }}</RouterLink></dd>
            </div>
          </dl>
          <div v-if="customer.notes" class="border-t border-vz-border px-5 py-4 text-[13px]">
            <p class="mb-1 text-xs text-vz-muted">Notas internas</p>
            <p class="whitespace-pre-line">{{ customer.notes }}</p>
          </div>
        </section>

        <div class="space-y-5 xl:col-span-2">
          <section v-if="vehiclesState !== 'hidden'" class="vz-card">
            <div class="vz-card-header flex items-center justify-between gap-3">
              <h2 class="vz-card-title">Vehículos</h2>
              <button v-if="can('vehicles.create') && customer.status === 'active'" class="vz-btn vz-btn-soft-primary vz-btn-sm" type="button" @click="addingVehicle = true">
                <i class="ri-add-line" aria-hidden="true" /> Agregar vehículo
              </button>
            </div>
            <p v-if="vehiclesState === 'loading'" class="px-5 py-6 text-center text-[13px] text-vz-muted" role="status">Cargando vehículos…</p>
            <p v-else-if="vehiclesState === 'error'" class="px-5 py-6 text-center text-[13px] text-vz-danger">No fue posible cargar los vehículos.</p>
            <p v-else-if="vehicles.length === 0" class="px-5 py-6 text-center text-[13px] text-vz-muted">Sin vehículos vinculados.</p>
            <ul v-else class="divide-y divide-vz-border">
              <li v-for="vehicle in vehicles" :key="vehicle.id">
                <RouterLink class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-vz-row-hover" :to="{ name: 'vehicle', params: { vehicleId: vehicle.id } }">
                  <PlateChip :plate="vehicle.plate" :country="vehicle.country" />
                  <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium text-vz-heading"><i :class="vehicleType[vehicle.type].icon" aria-hidden="true" /> {{ [vehicle.brand, vehicle.model].filter(Boolean).join(' ') || vehicleType[vehicle.type].label }}</span>
                    <span class="block text-xs text-vz-muted">{{ [vehicle.color, vehicle.year].filter(Boolean).join(' · ') }}</span>
                  </span>
                  <StatusBadge v-if="linkFor(vehicle)" :label="relationship[linkFor(vehicle)!.relationship].label" :tone="relationship[linkFor(vehicle)!.relationship].tone" />
                  <StatusBadge v-if="vehicle.status !== 'active'" :label="vehicleStatus[vehicle.status].label" :tone="vehicleStatus[vehicle.status].tone" />
                </RouterLink>
              </li>
            </ul>
          </section>

          <section v-if="customer.type !== 'individual'" class="vz-card">
            <div class="vz-card-header flex items-center justify-between">
              <h2 class="vz-card-title">Personas vinculadas</h2>
              <span class="text-xs text-vz-muted">{{ customer.members_count }}</span>
            </div>
            <p v-if="members.length === 0" class="px-5 py-6 text-center text-[13px] text-vz-muted">Ninguna persona vinculada. Al crear o editar una persona puedes asociarla a esta empresa.</p>
            <ul v-else class="divide-y divide-vz-border">
              <li v-for="member in members" :key="member.id">
                <button class="flex w-full items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-vz-row-hover" type="button" @click="router.push({ name: 'customer', params: { customerId: member.id } })">
                  <span class="flex size-8 items-center justify-center rounded-full text-xs font-semibold" :class="softBadge.info" aria-hidden="true">{{ initials(member.name) }}</span>
                  <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium text-vz-heading">{{ member.name }}</span>
                    <span class="block truncate text-xs text-vz-muted">{{ member.email ?? member.phone ?? 'Sin contacto' }}</span>
                  </span>
                  <StatusBadge v-if="member.status === 'inactive'" label="Inactivo" tone="muted" />
                </button>
              </li>
            </ul>
          </section>
        </div>
      </div>

      <CustomerFormModal :open="editing" :customer="customer" @close="editing = false" @saved="onSaved" />
      <VehicleFormModal :open="addingVehicle" :preset-customer="summary" @close="addingVehicle = false" @saved="onVehicleSaved" />
      <ConfirmDialog
        :open="toggling"
        :title="customer.status === 'active' ? 'Inactivar cliente' : 'Reactivar cliente'"
        :message="customer.status === 'active' ? `${customer.name} no podrá vincularse a nuevos vehículos. Sus vínculos e historial se conservan.` : `${customer.name} volverá a estar disponible para vincular vehículos.`"
        :confirm-label="customer.status === 'active' ? 'Inactivar' : 'Reactivar'"
        :busy="toggleBusy"
        :error="toggleError"
        @close="toggling = false"
        @confirm="onToggle"
      />
    </template>
  </div>
</template>
