<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { http } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import type { ApiSuccess, ReadinessData } from '@/types/api'

const auth = useAuthStore()

const healthStatus = ref<'loading' | 'success' | 'error'>('loading')
const health = ref<ReadinessData | null>(null)

const checkLabels: Record<string, string> = {
  database: 'Base de datos',
  redis: 'Redis',
  queue: 'Colas',
  storage: 'Almacenamiento',
}

onMounted(async () => {
  try {
    const response = await http.get<ApiSuccess<ReadinessData>>('/health/ready')
    health.value = response.data.data
    healthStatus.value = 'success'
  } catch (error) {
    const response = (error as { response?: { status?: number; data?: ApiSuccess<ReadinessData> } }).response

    if (response?.status === 503 && response.data?.data) {
      health.value = response.data.data
      healthStatus.value = 'success'
      return
    }

    healthStatus.value = 'error'
  }
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-vz-heading">Hola, {{ auth.user?.name }}</h1>
      <p v-if="auth.currentTenant" class="mt-2 text-vz-muted">Empresa activa: {{ auth.currentTenant.name }}.</p>
      <p v-else class="mt-2 text-vz-muted">
        Crea o selecciona una empresa para operar el parqueadero.
        <RouterLink class="font-medium text-vz-topbar-item underline" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
      </p>
    </header>

    <section class="rounded-vz border border-dashed border-vz-border bg-vz-card p-6" aria-labelledby="empty-operations">
      <h2 id="empty-operations" class="text-lg font-semibold text-vz-heading">Sin operación configurada</h2>
      <p class="mt-2 text-vz-muted">Los parqueaderos, zonas y espacios se incorporan en la siguiente etapa del producto.</p>
    </section>

    <section class="rounded-vz border border-vz-border bg-vz-card p-6" aria-labelledby="platform-health">
      <h2 id="platform-health" class="text-lg font-semibold text-vz-heading">Estado de la plataforma</h2>
      <p v-if="healthStatus === 'loading'" class="mt-3 text-vz-muted">Consultando dependencias…</p>
      <p v-else-if="healthStatus === 'error'" class="mt-3 text-vz-danger" role="alert">No fue posible consultar el estado de la plataforma.</p>
      <ul v-else-if="health" class="mt-4 divide-y divide-vz-border">
        <li v-for="(state, name) in health.checks" :key="name" class="flex items-center justify-between py-3">
          <span>{{ checkLabels[name] ?? name }}</span>
          <span :class="state === 'ok' ? 'text-vz-success' : 'text-vz-danger'">
            {{ state === 'ok' ? 'Disponible' : 'No disponible' }}
          </span>
        </li>
      </ul>
    </section>
  </div>
</template>
