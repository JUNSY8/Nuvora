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
import { listCustomers } from '@/modules/customers/api'
import CustomerFormModal from '@/modules/customers/CustomerFormModal.vue'
import { customerStatus, customerType, customerTypeOrder } from '@/modules/customers/labels'
import type { Customer, CustomerStatus, CustomerType } from '@/modules/customers/types'
import { initials } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { PageMeta } from '@/types/api'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const { can } = usePermissions()

const customers = ref<Customer[]>([])
const meta = ref<PageMeta | null>(null)
const counts = ref<Record<CustomerType, number> | null>(null)
const state = ref<'loading' | 'ready' | 'denied' | 'no-tenant' | 'error'>('loading')
const errorMessage = ref('')
const creating = ref(false)

const filters = reactive<{ type: '' | CustomerType; status: '' | CustomerStatus; search: string }>({ type: '', status: 'active', search: '' })
const debouncedSearch = refDebounced(computed(() => filters.search), 300)

async function load(page = 1): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  state.value = 'loading'

  try {
    const result = await listCustomers({
      type: filters.type || undefined,
      status: filters.status || undefined,
      search: debouncedSearch.value || undefined,
    }, page)
    customers.value = result.items
    meta.value = result.meta
    counts.value = result.type_counts
    state.value = 'ready'
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'FORBIDDEN' ? 'denied' : code === 'TENANT_REQUIRED' ? 'no-tenant' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar los clientes.')
  }
}

function onSaved(customer: Customer): void {
  creating.value = false
  toasts.success(`Cliente ${customer.name} creado.`)
  void router.push({ name: 'customer', params: { customerId: customer.id } })
}

watch(() => [filters.type, filters.status, debouncedSearch.value], () => load(1))
onMounted(() => load())
</script>

<template>
  <div>
    <PageHeader title="Clientes" subtitle="Personas, empresas y corporativos con sus vehículos." :breadcrumbs="[{ label: 'Clientes' }, { label: 'Directorio' }]">
      <template v-if="can('customers.create') && state !== 'no-tenant'" #actions>
        <button class="vz-btn vz-btn-primary" type="button" @click="creating = true">
          <i class="ri-user-add-line" aria-hidden="true" /> Nuevo cliente
        </button>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para ver sus clientes.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar los clientes." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load()">Reintentar</button>
    </StateCard>

    <template v-else>
      <section class="mb-5 grid gap-3 sm:grid-cols-3" aria-label="Clientes por tipo">
        <button
          v-for="type in customerTypeOrder"
          :key="type"
          class="vz-card flex items-center gap-3 p-4 text-left transition-colors hover:border-vz-primary/50"
          :class="filters.type === type ? 'border-vz-secondary' : ''"
          type="button"
          :aria-pressed="filters.type === type"
          @click="filters.type = filters.type === type ? '' : type"
        >
          <span class="flex size-10 items-center justify-center rounded text-xl" :class="softBadge[customerType[type].tone]" aria-hidden="true">
            <i :class="customerType[type].icon" />
          </span>
          <span>
            <span class="block text-lg font-semibold text-vz-heading">{{ counts?.[type] ?? '—' }}</span>
            <span class="block text-xs text-vz-muted">{{ customerType[type].plural }}</span>
          </span>
        </button>
      </section>

      <div class="vz-card">
        <div class="grid gap-3 border-b border-vz-border p-4 sm:grid-cols-3">
          <div class="sm:col-span-2">
            <label class="sr-only" for="customer-search">Buscar</label>
            <div class="relative">
              <i class="ri-search-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
              <input id="customer-search" v-model="filters.search" class="vz-input ps-9" type="search" placeholder="Nombre, documento, correo o teléfono" />
            </div>
          </div>
          <div>
            <label class="sr-only" for="customer-status-filter">Estado</label>
            <select id="customer-status-filter" v-model="filters.status" class="vz-input">
              <option value="">Todos los estados</option>
              <option value="active">Activos</option>
              <option value="inactive">Inactivos</option>
            </select>
          </div>
        </div>

        <div v-if="state === 'loading'" class="p-8 text-center text-vz-muted" role="status"><i class="ri-loader-4-line animate-spin text-2xl" aria-hidden="true" /><p>Cargando clientes…</p></div>
        <div v-else-if="customers.length === 0" class="p-10 text-center">
          <i class="ri-contacts-book-line text-4xl text-vz-muted" aria-hidden="true" />
          <p class="mt-2 font-medium text-vz-heading">Sin clientes</p>
          <p class="text-[13px] text-vz-muted">{{ filters.search || filters.type ? 'Ningún cliente coincide con los filtros.' : 'Registra a los clientes frecuentes y empresas con convenio.' }}</p>
        </div>
        <div v-else class="overflow-x-auto">
          <table class="vz-table">
            <thead>
              <tr>
                <th scope="col">Cliente</th>
                <th scope="col">Tipo</th>
                <th scope="col">Documento</th>
                <th scope="col">Contacto</th>
                <th scope="col">Estado</th>
                <th scope="col"><span class="sr-only">Abrir</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="customer in customers" :key="customer.id" class="cursor-pointer" @click="router.push({ name: 'customer', params: { customerId: customer.id } })">
                <td>
                  <div class="flex items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold" :class="softBadge[customerType[customer.type].tone]" aria-hidden="true">
                      {{ initials(customer.name) }}
                    </span>
                    <div class="min-w-0">
                      <p class="truncate font-medium text-vz-heading">{{ customer.name }}</p>
                      <p v-if="customer.business" class="truncate text-xs text-vz-muted"><i class="ri-building-2-line" aria-hidden="true" /> {{ customer.business.name }}</p>
                      <p v-else-if="customer.type !== 'individual'" class="text-xs text-vz-muted">{{ customer.members_count }} {{ customer.members_count === 1 ? 'persona vinculada' : 'personas vinculadas' }}</p>
                    </div>
                  </div>
                </td>
                <td><StatusBadge :label="customerType[customer.type].label" :tone="customerType[customer.type].tone" :icon="customerType[customer.type].icon" /></td>
                <td class="whitespace-nowrap">
                  <template v-if="customer.document_number"><span class="text-xs text-vz-muted">{{ customer.document_type }}</span> {{ customer.document_number }}</template>
                  <span v-else class="text-vz-muted">—</span>
                </td>
                <td>
                  <p class="truncate">{{ customer.email ?? '—' }}</p>
                  <p v-if="customer.phone" class="text-xs text-vz-muted">{{ customer.phone }}</p>
                </td>
                <td><StatusBadge :label="customerStatus[customer.status].label" :tone="customerStatus[customer.status].tone" /></td>
                <td class="text-right">
                  <RouterLink class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" :to="{ name: 'customer', params: { customerId: customer.id } }" :aria-label="`Abrir ${customer.name}`" @click.stop>
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

    <CustomerFormModal :open="creating" @close="creating = false" @saved="onSaved" />
  </div>
</template>
