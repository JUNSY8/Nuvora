<script setup lang="ts">
import { ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { spaceType } from '@/modules/parking/labels'
import type { ParkingSpace } from '@/modules/parking/types'
import { moveSession } from '@/modules/sessions/api'
import type { ParkingSession } from '@/modules/sessions/types'
import { parseApiError } from '@/services/http'

const props = defineProps<{
  session: ParkingSession | null
  spaces: ParkingSpace[]
}>()

const emit = defineEmits<{ close: []; moved: [session: ParkingSession] }>()

const spaceId = ref('')
const saving = ref(false)
const error = ref('')

watch(() => props.session, () => {
  spaceId.value = ''
  error.value = ''
})

async function onSave(): Promise<void> {
  if (!props.session || spaceId.value === '') {
    return
  }

  saving.value = true
  error.value = ''

  try {
    emit('moved', await moveSession(props.session.id, spaceId.value))
  } catch (failure) {
    error.value = parseApiError(failure, 'No fue posible cambiar el espacio.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :open="session !== null" :title="session?.space ? 'Cambiar espacio' : 'Asignar espacio'" size="sm" @close="emit('close')">
    <form v-if="session" novalidate @submit.prevent="onSave">
      <div class="space-y-3 px-5 py-5">
        <p class="text-[13px] text-vz-text">
          {{ session.vehicle.plate }} {{ session.space ? `está en ${session.space.code}.` : 'no tiene espacio asignado.' }}
          El espacio actual queda disponible al guardar.
        </p>
        <div>
          <label class="vz-label" for="move-space">Nuevo espacio</label>
          <select id="move-space" v-model="spaceId" class="vz-input" :disabled="spaces.length === 0">
            <option value="" disabled>{{ spaces.length === 0 ? 'No hay espacios disponibles' : 'Selecciona un espacio' }}</option>
            <option v-for="space in spaces" :key="space.id" :value="space.id">{{ space.code }} · {{ spaceType[space.type].label }}</option>
          </select>
        </div>
        <p v-if="error" class="vz-error" role="alert">{{ error }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving || spaceId === ''">
          <i v-if="saving" class="ri-loader-4-line animate-spin" aria-hidden="true" />
          Guardar
        </button>
      </div>
    </form>
  </BaseModal>
</template>
