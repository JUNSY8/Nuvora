<script setup lang="ts">
import { ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { listTariffVersions } from '@/modules/tariffs/api'
import { describeRates, describeVehicles, describeWindow, tariffStatus } from '@/modules/tariffs/labels'
import type { Tariff, TariffVersion } from '@/modules/tariffs/types'
import { formatDateTime } from '@/services/format'
import { parseApiError } from '@/services/http'

const props = defineProps<{ tariff: Tariff | null; timezone: string }>()
const emit = defineEmits<{ close: [] }>()

const versions = ref<TariffVersion[]>([])
const state = ref<'loading' | 'ready' | 'error'>('loading')
const errorMessage = ref('')

async function load(id: string): Promise<void> {
  state.value = 'loading'

  try {
    versions.value = await listTariffVersions(id)
    state.value = 'ready'
  } catch (error) {
    errorMessage.value = parseApiError(error, 'No fue posible consultar el historial.')
    state.value = 'error'
  }
}

watch(() => props.tariff, (tariff) => {
  if (tariff) {
    void load(tariff.id)
  }
}, { immediate: true })
</script>

<template>
  <BaseModal :open="tariff !== null" :title="`Historial de ${tariff?.name ?? 'la tarifa'}`" size="lg" @close="emit('close')">
    <div class="vz-card-body max-h-[70vh] overflow-y-auto">
      <p class="mb-4 text-[13px] text-vz-muted">Cada cambio publica una versión nueva. Las sesiones cerradas guardan la versión con la que se cobraron.</p>

      <StateCard v-if="state === 'loading'" variant="loading" compact />
      <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage" compact>
        <button class="vz-btn vz-btn-primary" type="button" @click="tariff && load(tariff.id)">Reintentar</button>
      </StateCard>

      <ol v-else class="relative space-y-4 border-l border-vz-border pl-5">
        <li v-for="version in versions" :key="version.id" class="relative">
          <span class="absolute top-1 -left-[26px] size-3 rounded-full border-2 border-vz-card" :class="version.version === tariff?.version ? 'bg-vz-primary' : 'bg-vz-muted'" aria-hidden="true" />
          <div class="flex flex-wrap items-center gap-2">
            <p class="font-medium text-vz-heading">Versión {{ version.version }}</p>
            <StatusBadge :label="tariffStatus[version.status].label" :tone="tariffStatus[version.status].tone" />
            <span v-if="version.version === tariff?.version" class="text-xs text-vz-primary-text">Vigente</span>
          </div>
          <p class="text-xs text-vz-muted">
            Desde {{ formatDateTime(version.effective_from, timezone) }} · {{ version.author?.name ?? 'Sistema' }}
          </p>
          <p v-if="version.change_note" class="mt-1 text-[13px] italic">“{{ version.change_note }}”</p>
          <ul class="mt-2 space-y-0.5 text-[13px]">
            <li v-for="line in describeRates(version, tariff?.currency ?? 'COP')" :key="line">{{ line }}</li>
          </ul>
          <p class="mt-1 text-xs text-vz-muted">
            {{ describeVehicles(version.vehicle_types) }}
            <template v-if="version.zone"> · Zona {{ version.zone.name ?? version.zone.code }}</template>
            <template v-for="window in version.windows" :key="describeWindow(window)"> · {{ describeWindow(window) }}</template>
            <template v-if="version.grace_minutes !== null"> · Gracia {{ version.grace_minutes }} min</template>
            · Prioridad {{ version.priority }}
          </p>
        </li>
      </ol>
    </div>
    <div class="flex justify-end border-t border-vz-border px-5 py-3">
      <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cerrar</button>
    </div>
  </BaseModal>
</template>
