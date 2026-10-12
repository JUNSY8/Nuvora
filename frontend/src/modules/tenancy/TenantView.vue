<script setup lang="ts">
import { ref } from 'vue'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const name = ref('')
const timezone = ref('America/Bogota')
const errorMessage = ref('')
const submitting = ref(false)

const canUpdate = () => auth.currentTenant?.permissions.includes('tenants.update') ?? false

async function onCreate(): Promise<void> {
  errorMessage.value = ''
  submitting.value = true

  try {
    await auth.createTenant(name.value, timezone.value)
    name.value = ''
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
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'No fue posible actualizar la empresa.'
  } finally {
    submitting.value = false
  }
}

if (auth.currentTenant) {
  name.value = auth.currentTenant.name
  timezone.value = auth.currentTenant.timezone
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-vz-heading">Empresa</h1>
      <p class="mt-2 text-vz-muted">La empresa agrupa usuarios, roles y la operación del parqueadero.</p>
    </header>

    <section v-if="!auth.currentTenant && auth.tenants.length > 1" class="rounded-vz border border-vz-border bg-vz-card p-6">
      <h2 class="text-lg font-semibold text-vz-heading">Selecciona una empresa</h2>
      <p class="mt-2 text-vz-muted">Tienes acceso a más de una empresa. Elige cuál quieres operar desde la barra superior.</p>
    </section>

    <section v-else-if="!auth.currentTenant" class="rounded-vz border border-vz-border bg-vz-card p-6">
      <h2 class="text-lg font-semibold text-vz-heading">Crear empresa</h2>
      <p class="mt-2 text-vz-muted">Quedarás como propietario. Hace falta un correo verificado.</p>
      <form class="mt-6 max-w-lg space-y-4" @submit.prevent="onCreate">
        <div>
          <label class="mb-2 block text-sm font-medium text-vz-text" for="tenant-name">Nombre</label>
          <input id="tenant-name" v-model="name" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="text" required maxlength="120" />
        </div>
        <div>
          <label class="mb-2 block text-sm font-medium text-vz-text" for="tenant-timezone">Zona horaria</label>
          <input id="tenant-timezone" v-model="timezone" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="text" required />
        </div>
        <p v-if="errorMessage" class="text-sm text-vz-danger" role="alert">{{ errorMessage }}</p>
        <button class="rounded-vz bg-vz-primary px-4 py-2 text-sm font-medium text-white disabled:opacity-60" type="submit" :disabled="submitting">
          {{ submitting ? 'Creando…' : 'Crear empresa' }}
        </button>
      </form>
    </section>

    <section v-else class="rounded-vz border border-vz-border bg-vz-card p-6">
      <h2 class="text-lg font-semibold text-vz-heading">{{ auth.currentTenant.name }}</h2>
      <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-vz-muted">Identificador</dt>
          <dd>{{ auth.currentTenant.slug }}</dd>
        </div>
        <div>
          <dt class="text-vz-muted">Zona horaria</dt>
          <dd>{{ auth.currentTenant.timezone }}</dd>
        </div>
        <div>
          <dt class="text-vz-muted">Roles</dt>
          <dd>{{ auth.currentTenant.roles.join(', ') }}</dd>
        </div>
      </dl>

      <form v-if="canUpdate()" class="mt-6 max-w-lg space-y-4" @submit.prevent="onUpdate">
        <div>
          <label class="mb-2 block text-sm font-medium text-vz-text" for="tenant-edit-name">Nombre</label>
          <input id="tenant-edit-name" v-model="name" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="text" required maxlength="120" />
        </div>
        <div>
          <label class="mb-2 block text-sm font-medium text-vz-text" for="tenant-edit-timezone">Zona horaria</label>
          <input id="tenant-edit-timezone" v-model="timezone" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="text" required />
        </div>
        <p v-if="errorMessage" class="text-sm text-vz-danger" role="alert">{{ errorMessage }}</p>
        <button class="rounded-vz bg-vz-primary px-4 py-2 text-sm font-medium text-white disabled:opacity-60" type="submit" :disabled="submitting">
          {{ submitting ? 'Guardando…' : 'Guardar cambios' }}
        </button>
      </form>
    </section>
  </div>
</template>
