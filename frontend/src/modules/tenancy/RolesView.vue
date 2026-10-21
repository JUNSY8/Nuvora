<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import BaseModal from '@/components/ui/BaseModal.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { createRole, listRoles } from '@/modules/tenancy/api'
import { permissionGroups, permissionLabel } from '@/modules/tenancy/permissions'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { TenantRole } from '@/types/api'

const auth = useAuthStore()
const toasts = useToastStore()
const { can } = usePermissions()

const roles = ref<TenantRole[]>([])
const state = ref<'loading' | 'ready' | 'no-tenant' | 'denied' | 'error'>('loading')
const errorMessage = ref('')
const expanded = ref<string | null>(null)

const creating = ref(false)
const name = ref('')
const selected = ref<string[]>([])
const saving = ref(false)
const errors = useFieldErrors()

async function load(): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  state.value = 'loading'

  try {
    roles.value = await listRoles()
    state.value = 'ready'
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'FORBIDDEN' ? 'denied' : code === 'TENANT_REQUIRED' ? 'no-tenant' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar los roles.')
  }
}

function openCreate(): void {
  name.value = ''
  selected.value = []
  errors.clear()
  creating.value = true
}

function toggleGroup(keys: string[]): void {
  const allSelected = keys.every((key) => selected.value.includes(key))
  selected.value = allSelected ? selected.value.filter((key) => !keys.includes(key)) : Array.from(new Set([...selected.value, ...keys]))
}

async function onCreate(): Promise<void> {
  saving.value = true
  errors.clear()

  try {
    const role = await createRole(name.value, selected.value)
    toasts.success(`Rol ${role.name} creado.`)
    creating.value = false
    await load()
  } catch (error) {
    errors.capture(error, 'No fue posible crear el rol.')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Roles y permisos" subtitle="Los roles de sistema cubren la operación típica. Crea roles propios con permisos del catálogo." :breadcrumbs="[{ label: 'Administración' }, { label: 'Roles' }]">
      <template v-if="can('roles.manage') && state === 'ready'" #actions>
        <button class="vz-btn vz-btn-primary" type="button" @click="openCreate"><i class="ri-add-line" aria-hidden="true" /> Nuevo rol</button>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'loading'" variant="loading" />
    <StateCard v-else-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para ver sus roles.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar los roles." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>

    <ul v-else class="grid gap-4 lg:grid-cols-2">
      <li v-for="role in roles" :key="role.id" class="vz-card">
        <div class="flex items-start justify-between gap-3 p-5">
          <div class="flex items-center gap-3">
            <span class="flex size-10 items-center justify-center rounded text-xl" :class="role.is_system ? 'bg-vz-primary/15 text-vz-primary-text' : 'bg-vz-success/15 text-vz-success-text'" aria-hidden="true">
              <i :class="role.is_system ? 'ri-shield-star-line' : 'ri-shield-user-line'" />
            </span>
            <div>
              <h2 class="font-semibold text-vz-heading">{{ role.name }}</h2>
              <p class="text-xs text-vz-muted">{{ role.permissions.length }} permisos</p>
            </div>
          </div>
          <StatusBadge :label="role.is_system ? 'Sistema' : 'Personalizado'" :tone="role.is_system ? 'primary' : 'success'" />
        </div>
        <div class="border-t border-vz-border px-5 py-3">
          <button class="text-[13px] text-vz-secondary-text hover:underline" type="button" :aria-expanded="expanded === role.id" @click="expanded = expanded === role.id ? null : role.id">
            {{ expanded === role.id ? 'Ocultar permisos' : 'Ver permisos' }}
          </button>
          <ul v-if="expanded === role.id" class="mt-3 flex flex-wrap gap-1.5">
            <li v-for="permission in role.permissions" :key="permission" class="rounded bg-vz-elevated px-2 py-0.5 text-xs">{{ permissionLabel(permission) }}</li>
          </ul>
        </div>
      </li>
    </ul>

    <BaseModal :open="creating" title="Nuevo rol" size="lg" @close="creating = false">
      <form novalidate @submit.prevent="onCreate">
        <div class="vz-card-body space-y-5">
          <div class="max-w-md">
            <label class="vz-label" for="role-name">Nombre</label>
            <input id="role-name" v-model="name" class="vz-input" type="text" maxlength="80" required autofocus placeholder="Portería" :aria-invalid="!!errors.fields.value.name" />
            <p v-if="errors.fields.value.name" class="vz-error" role="alert">{{ errors.fields.value.name }}</p>
          </div>
          <fieldset v-for="group in permissionGroups" :key="group.title" class="rounded-vz border border-vz-border p-4">
            <legend class="flex items-center gap-2 px-1 text-[13px] font-semibold text-vz-heading">
              <i :class="group.icon" aria-hidden="true" /> {{ group.title }}
            </legend>
            <button class="mb-3 text-xs text-vz-secondary-text hover:underline" type="button" @click="toggleGroup(group.permissions.map((item) => item.key))">Seleccionar o quitar todos</button>
            <div class="grid gap-2 sm:grid-cols-2">
              <label v-for="item in group.permissions" :key="item.key" class="flex items-center gap-2 text-[13px]">
                <input v-model="selected" class="size-4 accent-vz-primary" type="checkbox" :value="item.key" /> {{ item.label }}
              </label>
            </div>
          </fieldset>
          <p v-if="errors.fields.value.permissions" class="vz-error" role="alert">{{ errors.fields.value.permissions }}</p>
          <p v-if="errors.general.value && !errors.fields.value.permissions && !errors.fields.value.name" class="text-[13px] text-vz-danger" role="alert">{{ errors.general.value }}</p>
        </div>
        <div class="flex items-center justify-between gap-2 border-t border-vz-border px-5 py-3">
          <span class="text-xs text-vz-muted">{{ selected.length }} permisos seleccionados</span>
          <div class="flex gap-2">
            <button class="vz-btn vz-btn-light" type="button" @click="creating = false">Cancelar</button>
            <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving || selected.length === 0">{{ saving ? 'Creando…' : 'Crear rol' }}</button>
          </div>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
