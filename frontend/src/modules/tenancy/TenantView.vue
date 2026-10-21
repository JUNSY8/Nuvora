<script setup lang="ts">
import { ref } from 'vue'

import PageHeader from '@/components/ui/PageHeader.vue'
import StateCard from '@/components/ui/StateCard.vue'
import { usePermissions } from '@/composables/usePermissions'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const toasts = useToastStore()
const { can } = usePermissions()

const name = ref(auth.currentTenant?.name ?? '')
const timezone = ref(auth.currentTenant?.timezone ?? 'America/Bogota')
const errorMessage = ref('')
const submitting = ref(false)

const timezones = ['America/Bogota', 'America/Lima', 'America/Mexico_City', 'America/Panama', 'America/Guayaquil', 'America/Santiago', 'America/Argentina/Buenos_Aires', 'UTC']

const roleNames: Record<string, string> = {
  tenant_owner: 'Propietario',
  tenant_administrator: 'Administrador',
  supervisor: 'Supervisor',
  cashier: 'Cajero',
  parking_operator: 'Operador',
  accountant: 'Contador',
  report_viewer: 'Consulta',
}

async function onCreate(): Promise<void> {
  errorMessage.value = ''
  submitting.value = true

  try {
    await auth.createTenant(name.value, timezone.value)
    toasts.success(`Empresa ${auth.currentTenant?.name ?? ''} creada. Ya eres su propietario.`)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'No fue posible crear la empresa.'
  } finally {
    submitting.value = false
  }
}

async function onUpdate(): Promise<void> {
  errorMessage.value = ''
  submitting.value = true

  try {
    await auth.updateTenant(name.value || (auth.currentTenant?.name ?? ''), timezone.value)
    toasts.success('Datos de la empresa actualizados.')
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'No fue posible actualizar la empresa.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader title="Empresa" subtitle="La empresa agrupa usuarios, roles y la operación de sus parqueaderos." :breadcrumbs="[{ label: 'Administración' }, { label: 'Empresa' }]" />

    <StateCard
      v-if="!auth.currentTenant && auth.tenants.length > 1"
      variant="no-tenant"
      title="Selecciona una empresa"
      message="Tienes acceso a más de una empresa. Elige cuál operar desde el selector de la barra superior."
    />

    <section v-else-if="!auth.currentTenant" class="vz-card max-w-2xl">
      <div class="vz-card-header">
        <h2 class="vz-card-title">Crear empresa</h2>
      </div>
      <form class="vz-card-body space-y-4" @submit.prevent="onCreate">
        <p class="text-[13px] text-vz-muted">Quedarás como propietario. Necesitas un correo verificado.</p>
        <div>
          <label class="vz-label" for="tenant-name">Nombre</label>
          <input id="tenant-name" v-model="name" class="vz-input" type="text" required maxlength="120" :aria-invalid="errorMessage !== ''" />
        </div>
        <div>
          <label class="vz-label" for="tenant-timezone">Zona horaria</label>
          <select id="tenant-timezone" v-model="timezone" class="vz-input">
            <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
          </select>
        </div>
        <p v-if="errorMessage" class="text-[13px] text-vz-danger" role="alert">{{ errorMessage }}</p>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="submitting">
          <i class="ri-building-line" aria-hidden="true" /> {{ submitting ? 'Creando…' : 'Crear empresa' }}
        </button>
      </form>
    </section>

    <div v-else class="grid gap-5 xl:grid-cols-3">
      <section class="vz-card">
        <div class="vz-card-body flex flex-col items-center text-center">
          <span class="flex size-20 items-center justify-center rounded-full bg-vz-primary/20 text-4xl text-vz-primary-text" aria-hidden="true">
            <i class="ri-building-4-line" />
          </span>
          <h2 class="mt-4 text-base font-semibold text-vz-heading">{{ auth.currentTenant.name }}</h2>
          <p class="text-[13px] text-vz-muted">{{ auth.currentTenant.slug }}</p>
          <dl class="mt-6 w-full divide-y divide-vz-border text-left text-[13px]">
            <div class="flex justify-between py-2.5">
              <dt class="text-vz-muted">Zona horaria</dt>
              <dd>{{ auth.currentTenant.timezone }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-vz-muted">Tu rol</dt>
              <dd class="text-right">{{ auth.currentTenant.roles.map((role) => roleNames[role] ?? role).join(', ') }}</dd>
            </div>
            <div class="flex justify-between py-2.5">
              <dt class="text-vz-muted">Permisos</dt>
              <dd>{{ auth.currentTenant.permissions.length }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <section class="vz-card xl:col-span-2">
        <div class="vz-card-header">
          <h2 class="vz-card-title">Datos de la empresa</h2>
        </div>
        <form v-if="can('tenants.update')" class="vz-card-body grid gap-4 sm:grid-cols-2" @submit.prevent="onUpdate">
          <div>
            <label class="vz-label" for="tenant-edit-name">Nombre</label>
            <input id="tenant-edit-name" v-model="name" class="vz-input" type="text" required maxlength="120" :aria-invalid="errorMessage !== ''" />
          </div>
          <div>
            <label class="vz-label" for="tenant-edit-timezone">Zona horaria</label>
            <select id="tenant-edit-timezone" v-model="timezone" class="vz-input">
              <option v-for="zone in Array.from(new Set([timezone, ...timezones]))" :key="zone" :value="zone">{{ zone }}</option>
            </select>
            <p class="vz-help">Se usa para mostrar fechas y como valor inicial de nuevos parqueaderos.</p>
          </div>
          <p v-if="errorMessage" class="text-[13px] text-vz-danger sm:col-span-2" role="alert">{{ errorMessage }}</p>
          <div class="sm:col-span-2">
            <button class="vz-btn vz-btn-primary" type="submit" :disabled="submitting">
              <i class="ri-save-3-line" aria-hidden="true" /> {{ submitting ? 'Guardando…' : 'Guardar cambios' }}
            </button>
          </div>
        </form>
        <p v-else class="vz-card-body text-[13px] text-vz-muted" role="status">Tu rol permite consultar la empresa, pero no modificarla.</p>
      </section>
    </div>
  </div>
</template>
