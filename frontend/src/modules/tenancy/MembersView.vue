<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { addMember, listMembers, listRoles, suspendMember } from '@/modules/tenancy/api'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import type { TenantMember, TenantRole } from '@/types/api'

const auth = useAuthStore()

const members = ref<TenantMember[]>([])
const roles = ref<TenantRole[]>([])
const email = ref('')
const role = ref('')
const state = ref<'loading' | 'ready' | 'empty-tenant' | 'denied' | 'error'>('loading')
const errorMessage = ref('')
const submitting = ref(false)
const suspendingId = ref<string | null>(null)

const canManage = () => auth.currentTenant?.permissions.includes('users.manage') ?? false

async function load(): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'empty-tenant'
    return
  }

  state.value = 'loading'
  errorMessage.value = ''

  try {
    members.value = await listMembers()
    if (canManage()) {
      roles.value = await listRoles()
      role.value = roles.value.find((item) => item.key === 'parking_operator')?.key ?? roles.value[0]?.key ?? ''
    }
    state.value = 'ready'
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

    errorMessage.value = parseApiError(error, 'No fue posible consultar los miembros.')
    state.value = 'error'
  }
}

async function onAdd(): Promise<void> {
  errorMessage.value = ''
  submitting.value = true

  try {
    await addMember(email.value, role.value)
    email.value = ''
    await load()
  } catch (error) {
    errorMessage.value = parseApiError(error, 'No fue posible agregar el miembro.')
    state.value = 'ready'
  } finally {
    submitting.value = false
  }
}

async function onSuspend(member: TenantMember): Promise<void> {
  suspendingId.value = member.id
  errorMessage.value = ''

  try {
    await suspendMember(member.id)
    await load()
  } catch (error) {
    errorMessage.value = parseApiError(error, 'No fue posible suspender el miembro.')
    state.value = 'ready'
  } finally {
    suspendingId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-vz-heading">Miembros</h1>
      <p class="mt-2 text-vz-muted">Las personas deben tener una cuenta antes de unirse a la empresa.</p>
    </header>

    <section v-if="state === 'loading'" class="rounded-vz border border-vz-border bg-vz-card p-6 text-vz-muted">Cargando miembros…</section>

    <section v-else-if="state === 'empty-tenant'" class="rounded-vz border border-dashed border-vz-border bg-vz-card p-6">
      <h2 class="text-lg font-semibold text-vz-heading">Sin empresa activa</h2>
      <p class="mt-2 text-vz-muted">
        Crea o selecciona una empresa para ver sus miembros.
        <RouterLink class="font-medium text-vz-topbar-item underline" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
      </p>
    </section>

    <section v-else-if="state === 'denied'" class="rounded-vz border border-vz-border bg-vz-card p-6" role="alert">
      <h2 class="text-lg font-semibold text-vz-heading">Sin permiso</h2>
      <p class="mt-2 text-vz-muted">Tu rol no permite consultar los miembros de esta empresa.</p>
    </section>

    <section v-else-if="state === 'error'" class="rounded-vz border border-vz-border bg-vz-card p-6">
      <p class="text-sm text-vz-danger" role="alert">{{ errorMessage }}</p>
      <button class="mt-4 rounded-vz bg-vz-primary px-4 py-2 text-sm text-white" type="button" @click="load">Reintentar</button>
    </section>

    <template v-else>
      <form v-if="canManage()" class="rounded-vz border border-vz-border bg-vz-card p-6" @submit.prevent="onAdd">
        <h2 class="text-lg font-semibold text-vz-heading">Agregar miembro</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-[1fr_16rem_auto] md:items-end">
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="member-email">Correo</label>
            <input id="member-email" v-model="email" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="email" required />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="member-role">Rol</label>
            <select id="member-role" v-model="role" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" required>
              <option v-for="item in roles" :key="item.key" :value="item.key">{{ item.name }}</option>
            </select>
          </div>
          <button class="rounded-vz bg-vz-primary px-4 py-2 text-sm font-medium text-white disabled:opacity-60" type="submit" :disabled="submitting || role === ''">
            {{ submitting ? 'Agregando…' : 'Agregar' }}
          </button>
        </div>
        <p v-if="errorMessage" class="mt-3 text-sm text-vz-danger" role="alert">{{ errorMessage }}</p>
      </form>

      <section class="overflow-x-auto rounded-vz border border-vz-border bg-vz-card">
        <table class="min-w-full text-left text-sm">
          <thead class="bg-vz-elevated text-vz-heading">
            <tr>
              <th class="px-4 py-3 font-medium" scope="col">Nombre</th>
              <th class="px-4 py-3 font-medium" scope="col">Correo</th>
              <th class="px-4 py-3 font-medium" scope="col">Roles</th>
              <th class="px-4 py-3 font-medium" scope="col">Estado</th>
              <th v-if="canManage()" class="px-4 py-3 font-medium" scope="col"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in members" :key="member.id" class="border-t border-vz-border hover:bg-[#25282c]">
              <td class="px-4 py-3">{{ member.user.name }}</td>
              <td class="px-4 py-3">{{ member.user.email }}</td>
              <td class="px-4 py-3">{{ member.roles.map((item) => item.name).join(', ') }}</td>
              <td class="px-4 py-3">{{ member.status === 'active' ? 'Activo' : 'Suspendido' }}</td>
              <td v-if="canManage()" class="px-4 py-3">
                <button
                  v-if="member.status === 'active' && member.user.id !== auth.user?.id"
                  class="text-sm text-vz-danger"
                  type="button"
                  :disabled="suspendingId === member.id"
                  @click="onSuspend(member)"
                >
                  Suspender
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="members.length === 0" class="px-4 py-6 text-vz-muted">Todavía no hay miembros.</p>
      </section>
    </template>
  </div>
</template>
