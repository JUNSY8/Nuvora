<script setup lang="ts">
import { ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { cancelSession } from '@/modules/sessions/api'
import type { ParkingSession } from '@/modules/sessions/types'

const props = defineProps<{ session: ParkingSession | null }>()
const emit = defineEmits<{ close: []; cancelled: [session: ParkingSession] }>()

const reason = ref('')
const saving = ref(false)
const errors = useFieldErrors()

watch(() => props.session, () => {
  reason.value = ''
  errors.clear()
})

async function onConfirm(): Promise<void> {
  if (!props.session) {
    return
  }

  saving.value = true
  errors.clear()

  try {
    emit('cancelled', await cancelSession(props.session.id, reason.value.trim()))
  } catch (error) {
    errors.capture(error, 'No fue posible anular la sesión.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :open="session !== null" title="Anular sesión" size="sm" @close="emit('close')">
    <form v-if="session" novalidate @submit.prevent="onConfirm">
      <div class="space-y-3 px-5 py-5">
        <div class="flex items-start gap-3 rounded-vz border border-vz-warning/40 bg-vz-warning/10 p-3 text-[13px]">
          <i class="ri-error-warning-line text-lg text-vz-warning" aria-hidden="true" />
          <p>
            Usa la anulación solo para entradas registradas por error. La sesión de <strong class="text-vz-heading">{{ session.vehicle.plate }}</strong>
            no se cobrará, el espacio queda libre y la acción queda en la auditoría.
          </p>
        </div>
        <div>
          <label class="vz-label" for="cancel-reason">Motivo</label>
          <textarea
            id="cancel-reason"
            v-model="reason"
            class="vz-input min-h-20"
            maxlength="255"
            placeholder="Ej.: placa digitada por error"
            :aria-invalid="Boolean(errors.fields.value.reason)"
            autofocus
          />
          <p v-if="errors.fields.value.reason" class="vz-error" role="alert">{{ errors.fields.value.reason }}</p>
        </div>
        <p v-if="errors.general.value && !errors.fields.value.reason" class="vz-error" role="alert">{{ errors.general.value }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Volver</button>
        <button class="vz-btn vz-btn-danger" type="submit" :disabled="saving || reason.trim().length < 5">
          <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-close-circle-line'" aria-hidden="true" />
          Anular sesión
        </button>
      </div>
    </form>
  </BaseModal>
</template>
