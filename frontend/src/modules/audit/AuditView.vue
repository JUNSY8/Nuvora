<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { listAudit } from '@/modules/tenancy/api'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import type { AuditEvent, PageMeta } from '@/types/api'

const auth = useAuthStore()

const items = ref<AuditEvent[]>([])
const meta = ref<PageMeta | null>(null)
const state = ref<'loading' | 'ready' | 'empty' | 'empty-tenant' | 'denied' | 'error'>('loading')
const errorMessage = ref('')

const actionLabels: Record<string, string> = {
  'tenant.created': 'Empresa creada',
  'tenant.updated': 'Empresa actualizada',
  'membership.created': 'Miembro agregado',
  'membership.status_changed': 'Estado de membresía',
  'membership.roles_synced': 'Roles actualizados',
  'role.created': 'Rol creado',
  'auth.logout': 'Cierre de sesión',
}

function label(action: string): string {
  return actionLabels[action] ?? action
}

function formatWhen(value: string | null): string {
  if (!value) {
    return ''
  }

  return new Intl.DateTimeFormat('es-CO', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: auth.currentTenant?.timezone ?? 'America/Bogota',
  }).format(new Date(value))
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
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-vz-heading">Auditoría</h1>
      <p class="mt-2 text-vz-muted">Acciones sensibles de esta empresa.</p>
    </header>

    <section v-if="state === 'loading'" class="rounded-vz border border-vz-border bg-vz-card p-6 text-vz-muted">Cargando auditoría…</section>

    <section v-else-if="state === 'empty-tenant'" class="rounded-vz border border-dashed border-vz-border bg-vz-card p-6">
      <h2 class="text-lg font-semibold text-vz-heading">Sin empresa activa</h2>
      <p class="mt-2 text-vz-muted">
        Selecciona una empresa para ver su auditoría.
        <RouterLink class="font-medium text-vz-topbar-item underline" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
      </p>
    </section>

    <section v-else-if="state === 'denied'" class="rounded-vz border border-vz-border bg-vz-card p-6" role="alert">
      <h2 class="text-lg font-semibold text-vz-heading">Sin permiso</h2>
      <p class="mt-2 text-vz-muted">Tu rol no permite consultar la auditoría.</p>
    </section>

    <section v-else-if="state === 'error'" class="rounded-vz border border-vz-border bg-vz-card p-6">
      <p class="text-sm text-vz-danger" role="alert">{{ errorMessage }}</p>
      <button class="mt-4 rounded-vz bg-vz-primary px-4 py-2 text-sm text-white" type="button" @click="load(meta?.current_page ?? 1)">Reintentar</button>
    </section>

    <section v-else-if="state === 'empty'" class="rounded-vz border border-dashed border-vz-border bg-vz-card p-6">
      <h2 class="text-lg font-semibold text-vz-heading">Sin eventos</h2>
      <p class="mt-2 text-vz-muted">Todavía no hay acciones registradas para esta empresa.</p>
    </section>

    <section v-else class="overflow-x-auto rounded-vz border border-vz-border bg-vz-card">
      <table class="min-w-full text-left text-sm">
        <thead class="bg-vz-elevated text-vz-heading">
          <tr>
            <th class="px-4 py-3 font-medium" scope="col">Cuándo</th>
            <th class="px-4 py-3 font-medium" scope="col">Acción</th>
            <th class="px-4 py-3 font-medium" scope="col">Recurso</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id" class="border-t border-vz-border hover:bg-[#25282c]">
            <td class="px-4 py-3">{{ formatWhen(item.created_at) }}</td>
            <td class="px-4 py-3">{{ label(item.action) }}</td>
            <td class="px-4 py-3">{{ item.resource_type }}</td>
          </tr>
        </tbody>
      </table>
      <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-vz-border px-4 py-3">
        <button class="rounded-vz bg-vz-topbar-button px-3 py-2 text-sm disabled:opacity-40" type="button" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Anterior</button>
        <p class="text-vz-muted">Página {{ meta.current_page }} de {{ meta.last_page }}</p>
        <button class="rounded-vz bg-vz-topbar-button px-3 py-2 text-sm disabled:opacity-40" type="button" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Siguiente</button>
      </div>
    </section>
  </div>
</template>
