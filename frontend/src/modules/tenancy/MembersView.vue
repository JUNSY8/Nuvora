<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { usePermissions } from '@/composables/usePermissions'
import { addMember, listMembers, listRoles, suspendMember, syncMemberRoles } from '@/modules/tenancy/api'
import { initials } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { TenantMember, TenantRole } from '@/types/api'

const auth = useAuthStore()
const toasts = useToastStore()
const { can } = usePermissions()

const members = ref<TenantMember[]>([])
const roles = ref<TenantRole[]>([])
const email = ref('')
const role = ref('')
const state = ref<'loading' | 'ready' | 'empty-tenant' | 'denied' | 'error'>('loading')
const errorMessage = ref('')
const formError = ref('')
const submitting = ref(false)
const suspending = ref<TenantMember | null>(null)
const suspendBusy = ref(false)
const suspendError = ref('')
const editingRoles = ref<TenantMember | null>(null)
const roleSelection = ref<string[]>([])
const rolesBusy = ref(false)
const rolesError = ref('')

async function load(): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'empty-tenant'
    return
  }

  state.value = 'loading'
  errorMessage.value = ''

  try {
    members.value = await listMembers()
    if (can('users.manage') || can('roles.manage')) {
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
  formError.value = ''
  submitting.value = true

  try {
    await addMember(email.value, role.value)
    toasts.success(`${email.value} ahora es miembro de la empresa.`)
    email.value = ''
    await load()
  } catch (error) {
    formError.value = parseApiError(error, 'No fue posible agregar el miembro.')
  } finally {
    submitting.value = false
  }
}

async function onSuspend(): Promise<void> {
  if (!suspending.value) {
    return
  }

  suspendBusy.value = true
  suspendError.value = ''

  try {
    await suspendMember(suspending.value.id)
    toasts.success(`${suspending.value.user.name} fue suspendido.`)
    suspending.value = null
    await load()
  } catch (error) {
    suspendError.value = parseApiError(error, 'No fue posible suspender el miembro.')
  } finally {
    suspendBusy.value = false
  }
}

function openRoles(member: TenantMember): void {
  roleSelection.value = member.roles.map((item) => item.key)
  rolesError.value = ''
  editingRoles.value = member
}

async function onSaveRoles(): Promise<void> {
  if (!editingRoles.value) {
    return
  }

  rolesBusy.value = true
  rolesError.value = ''

  try {
    await syncMemberRoles(editingRoles.value.id, roleSelection.value)
    toasts.success(`Roles de ${editingRoles.value.user.name} actualizados.`)
    editingRoles.value = null
    await load()
  } catch (error) {
    rolesError.value = parseApiError(error, 'No fue posible actualizar los roles.')
  } finally {
    rolesBusy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Miembros" subtitle="Las personas necesitan una cuenta en Nuvora antes de unirse a la empresa." :breadcrumbs="[{ label: 'Administración' }, { label: 'Miembros' }]" />

    <StateCard v-if="state === 'loading'" variant="loading" />
    <StateCard v-else-if="state === 'empty-tenant'" variant="no-tenant" message="Crea o selecciona una empresa para ver sus miembros.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar los miembros de esta empresa." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>

    <div v-else class="space-y-5">
      <form v-if="can('users.manage')" class="vz-card" @submit.prevent="onAdd">
        <div class="vz-card-header">
          <h2 class="vz-card-title">Agregar miembro</h2>
        </div>
        <div class="vz-card-body">
          <div class="grid gap-4 md:grid-cols-[1fr_16rem_auto] md:items-end">
            <div>
              <label class="vz-label" for="member-email">Correo</label>
              <input id="member-email" v-model="email" class="vz-input" type="email" required :aria-invalid="formError !== ''" />
            </div>
            <div>
              <label class="vz-label" for="member-role">Rol</label>
              <select id="member-role" v-model="role" class="vz-input" required>
                <option v-for="item in roles" :key="item.key" :value="item.key">{{ item.name }}</option>
              </select>
            </div>
            <button class="vz-btn vz-btn-primary" type="submit" :disabled="submitting || role === ''">
              <i class="ri-user-add-line" aria-hidden="true" /> {{ submitting ? 'Agregando…' : 'Agregar' }}
            </button>
          </div>
          <p v-if="formError" class="mt-3 text-[13px] text-vz-danger" role="alert">{{ formError }}</p>
        </div>
      </form>

      <section class="vz-card overflow-hidden">
        <div class="vz-card-header">
          <h2 class="vz-card-title">Equipo</h2>
          <span class="text-[13px] text-vz-muted">{{ members.length }} miembros</span>
        </div>
        <div class="overflow-x-auto">
          <table class="vz-table">
            <thead>
              <tr>
                <th scope="col">Nombre</th>
                <th scope="col">Roles</th>
                <th scope="col">Estado</th>
                <th v-if="can('users.manage') || can('roles.manage')" scope="col"><span class="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="member in members" :key="member.id">
                <td>
                  <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-vz-primary/25 text-xs font-semibold text-white" aria-hidden="true">{{ initials(member.user.name) }}</span>
                    <div>
                      <p class="font-medium text-vz-heading">{{ member.user.name }}</p>
                      <p class="text-xs text-vz-muted">{{ member.user.email }}</p>
                    </div>
                  </div>
                </td>
                <td>
                  <span v-for="item in member.roles" :key="item.key" class="me-1 inline-block rounded bg-vz-elevated px-2 py-0.5 text-xs">{{ item.name }}</span>
                </td>
                <td><StatusBadge :label="member.status === 'active' ? 'Activo' : 'Suspendido'" :tone="member.status === 'active' ? 'success' : 'danger'" /></td>
                <td v-if="can('users.manage') || can('roles.manage')" class="text-right whitespace-nowrap">
                  <button v-if="can('roles.manage')" class="vz-btn vz-btn-light vz-btn-sm me-1" type="button" @click="openRoles(member)">
                    <i class="ri-shield-user-line" aria-hidden="true" /> Roles
                  </button>
                  <button
                    v-if="can('users.manage') && member.status === 'active' && member.user.id !== auth.user?.id"
                    class="vz-btn vz-btn-soft-danger vz-btn-sm"
                    type="button"
                    @click="suspendError = ''; suspending = member"
                  >
                    <i class="ri-user-unfollow-line" aria-hidden="true" /> Suspender
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="members.length === 0" class="px-5 py-6 text-vz-muted">Todavía no hay miembros.</p>
      </section>
    </div>

    <BaseModal :open="editingRoles !== null" :title="`Roles de ${editingRoles?.user.name ?? ''}`" @close="editingRoles = null">
      <form novalidate @submit.prevent="onSaveRoles">
        <fieldset class="vz-card-body">
          <legend class="sr-only">Roles asignados</legend>
          <div class="grid gap-2 sm:grid-cols-2">
            <label v-for="item in roles" :key="item.key" class="flex items-center gap-2 rounded-vz border border-vz-border px-3 py-2.5 text-[13px] hover:bg-vz-row-hover">
              <input v-model="roleSelection" class="size-4 accent-vz-primary" type="checkbox" :value="item.key" />
              <span class="flex-1">{{ item.name }}</span>
              <span class="text-[11px] text-vz-muted">{{ item.permissions.length }}</span>
            </label>
          </div>
          <p class="vz-help mt-3">Solo un propietario puede otorgar o retirar el rol de propietario, y siempre debe quedar uno activo.</p>
          <p v-if="rolesError" class="mt-3 text-[13px] text-vz-danger" role="alert">{{ rolesError }}</p>
        </fieldset>
        <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
          <button class="vz-btn vz-btn-light" type="button" @click="editingRoles = null">Cancelar</button>
          <button class="vz-btn vz-btn-primary" type="submit" :disabled="rolesBusy || roleSelection.length === 0">{{ rolesBusy ? 'Guardando…' : 'Guardar roles' }}</button>
        </div>
      </form>
    </BaseModal>

    <ConfirmDialog
      :open="suspending !== null"
      title="Suspender miembro"
      :message="`¿Suspender a ${suspending?.user.name ?? ''}? Perderá el acceso a esta empresa.`"
      confirm-label="Suspender"
      :busy="suspendBusy"
      :error="suspendError"
      @close="suspending = null"
      @confirm="onSuspend"
    />
  </div>
</template>
