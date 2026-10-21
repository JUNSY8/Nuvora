<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StateCard from '@/components/ui/StateCard.vue'
import { listAudit } from '@/modules/tenancy/api'
import { formatDateTime } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import type { AuditEvent, PageMeta } from '@/types/api'

const auth = useAuthStore()

const items = ref<AuditEvent[]>([])
const meta = ref<PageMeta | null>(null)
const state = ref<'loading' | 'ready' | 'empty' | 'empty-tenant' | 'denied' | 'error'>('loading')
const errorMessage = ref('')

const actions: Record<string, { label: string; icon: string }> = {
  'tenant.created': { label: 'Empresa creada', icon: 'ri-building-line' },
  'tenant.updated': { label: 'Empresa actualizada', icon: 'ri-building-line' },
  'membership.created': { label: 'Miembro agregado', icon: 'ri-user-add-line' },
  'membership.status_changed': { label: 'Estado de membresía', icon: 'ri-user-settings-line' },
  'membership.roles_synced': { label: 'Roles actualizados', icon: 'ri-shield-user-line' },
  'role.created': { label: 'Rol creado', icon: 'ri-shield-user-line' },
  'auth.logout': { label: 'Cierre de sesión', icon: 'ri-logout-box-r-line' },
  'parking_lot.created': { label: 'Parqueadero creado', icon: 'ri-parking-box-line' },
  'parking_lot.updated': { label: 'Parqueadero actualizado', icon: 'ri-parking-box-line' },
  'parking_lot.deleted': { label: 'Parqueadero eliminado', icon: 'ri-parking-box-line' },
  'parking_lot.hours_updated': { label: 'Horario actualizado', icon: 'ri-time-line' },
  'parking_zone.created': { label: 'Zona creada', icon: 'ri-stack-line' },
  'parking_zone.updated': { label: 'Zona actualizada', icon: 'ri-stack-line' },
  'parking_zone.deleted': { label: 'Zona eliminada', icon: 'ri-stack-line' },
  'parking_space.created': { label: 'Espacio creado', icon: 'ri-layout-grid-line' },
  'parking_space.bulk_created': { label: 'Espacios creados en bloque', icon: 'ri-layout-grid-line' },
  'parking_space.updated': { label: 'Espacio actualizado', icon: 'ri-layout-grid-line' },
  'parking_space.status_changed': { label: 'Estado de espacio', icon: 'ri-forbid-2-line' },
  'parking_space.deleted': { label: 'Espacio retirado', icon: 'ri-layout-grid-line' },
  'parking_gate.created': { label: 'Puerta creada', icon: 'ri-door-open-line' },
  'parking_gate.updated': { label: 'Puerta actualizada', icon: 'ri-door-open-line' },
  'parking_gate.deleted': { label: 'Puerta eliminada', icon: 'ri-door-open-line' },
  'device.created': { label: 'Dispositivo registrado', icon: 'ri-router-line' },
  'device.updated': { label: 'Dispositivo actualizado', icon: 'ri-router-line' },
  'device.status_changed': { label: 'Estado de dispositivo', icon: 'ri-tools-line' },
  'device.deleted': { label: 'Dispositivo retirado', icon: 'ri-router-line' },
  'customer.created': { label: 'Cliente creado', icon: 'ri-user-add-line' },
  'customer.updated': { label: 'Cliente actualizado', icon: 'ri-contacts-book-line' },
  'customer.status_changed': { label: 'Estado de cliente', icon: 'ri-contacts-book-line' },
  'vehicle.created': { label: 'Vehículo registrado', icon: 'ri-car-line' },
  'vehicle.updated': { label: 'Vehículo actualizado', icon: 'ri-car-line' },
  'vehicle.status_changed': { label: 'Estado de vehículo', icon: 'ri-forbid-line' },
  'vehicle.customer_linked': { label: 'Cliente vinculado a vehículo', icon: 'ri-link' },
  'vehicle.relationship_changed': { label: 'Relación con vehículo', icon: 'ri-arrow-left-right-line' },
  'vehicle.customer_unlinked': { label: 'Cliente desvinculado', icon: 'ri-link-unlink' },
}

const resources: Record<string, string> = {
  tenant: 'Empresa',
  membership: 'Membresía',
  role: 'Rol',
  user: 'Usuario',
  parking_lot: 'Parqueadero',
  parking_zone: 'Zona',
  parking_space: 'Espacio',
  parking_gate: 'Puerta',
  device: 'Dispositivo',
  customer: 'Cliente',
  vehicle: 'Vehículo',
}

async function load(page = 1): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'empty-tenant'
    return
  }

  state.value = 'loading'
  errorMessage.value = ''

  try {
    const pageData = await listAudit(page)
    items.value = pageData.items
    meta.value = pageData.meta
    state.value = pageData.items.length === 0 ? 'empty' : 'ready'
  } catch (error) {
    const code = apiErrorCode(error)

    if (code === 'FORBIDDEN') {
      state.value = 'denied'
      return
    }

    if (code === 'TENANT_REQUIRED') {
      state.value = 'empty-tenant'
      return
    }

    errorMessage.value = parseApiError(error, 'No fue posible consultar la auditoría.')
    state.value = 'error'
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <PageHeader title="Auditoría" subtitle="Acciones sensibles registradas en esta empresa." :breadcrumbs="[{ label: 'Administración' }, { label: 'Auditoría' }]" />

    <StateCard v-if="state === 'loading'" variant="loading" />
    <StateCard v-else-if="state === 'empty-tenant'" variant="no-tenant" message="Selecciona una empresa para ver su auditoría.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar la auditoría." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load(meta?.current_page ?? 1)">Reintentar</button>
    </StateCard>
    <StateCard v-else-if="state === 'empty'" variant="empty" title="Sin eventos" message="Todavía no hay acciones registradas para esta empresa." />

    <section v-else class="vz-card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="vz-table">
          <thead>
            <tr>
              <th scope="col">Cuándo</th>
              <th scope="col">Acción</th>
              <th scope="col">Recurso</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.id">
              <td class="whitespace-nowrap text-vz-muted">{{ formatDateTime(item.created_at, auth.currentTenant?.timezone ?? 'America/Bogota') }}</td>
              <td>
                <span class="flex items-center gap-2">
                  <span class="flex size-7 items-center justify-center rounded bg-vz-elevated text-vz-secondary-text" aria-hidden="true">
                    <i :class="actions[item.action]?.icon ?? 'ri-history-line'" />
                  </span>
                  {{ actions[item.action]?.label ?? item.action }}
                </span>
              </td>
              <td>{{ resources[item.resource_type] ?? item.resource_type }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="meta" :meta="meta" @change="load" />
    </section>
  </div>
</template>
