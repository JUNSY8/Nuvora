<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { deleteParkingLot, updateParkingLot } from '@/modules/parking/api'
import ParkingLotForm from '@/modules/parking/ParkingLotForm.vue'
import type { ParkingLot, ParkingLotInput } from '@/modules/parking/types'
import { parseApiError } from '@/services/http'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ lot: ParkingLot }>()
const emit = defineEmits<{ changed: [] }>()

const router = useRouter()
const toasts = useToastStore()
const { can } = usePermissions()

const form = ref<ParkingLotInput>(fromLot(props.lot))
const saving = ref(false)
const errors = useFieldErrors()

const confirmDelete = ref(false)
const deleting = ref(false)
const deleteError = ref('')

function fromLot(lot: ParkingLot): ParkingLotInput {
  return {
    name: lot.name,
    code: lot.code,
    description: lot.description,
    address: lot.address,
    city: lot.city,
    latitude: lot.latitude,
    longitude: lot.longitude,
    timezone: lot.timezone,
    currency: lot.currency,
    grace_period_minutes: lot.grace_period_minutes,
    status: lot.status,
  }
}

function blank(value: string | null): string | null {
  return value === null || String(value).trim() === '' ? null : String(value).trim()
}

async function onSave(): Promise<void> {
  saving.value = true
  errors.clear()

  try {
    await updateParkingLot(props.lot.id, {
      ...form.value,
      description: blank(form.value.description),
      address: blank(form.value.address),
      city: blank(form.value.city),
      latitude: blank(form.value.latitude),
      longitude: blank(form.value.longitude),
      currency: form.value.currency.toUpperCase(),
    })
    toasts.success('Configuración guardada.')
    emit('changed')
  } catch (error) {
    errors.capture(error, 'No fue posible guardar la configuración.')
  } finally {
    saving.value = false
  }
}

async function onDelete(): Promise<void> {
  deleting.value = true
  deleteError.value = ''

  try {
    await deleteParkingLot(props.lot.id)
    toasts.success(`Parqueadero ${props.lot.name} eliminado.`)
    await router.push({ name: 'parking-lots' })
  } catch (error) {
    deleteError.value = parseApiError(error, 'No fue posible eliminar el parqueadero.')
  } finally {
    deleting.value = false
  }
}

watch(() => props.lot, (lot) => {
  form.value = fromLot(lot)
})
</script>

<template>
  <div class="space-y-6">
    <form v-if="can('parking.update')" novalidate @submit.prevent="onSave">
      <ParkingLotForm v-model="form" :default-timezone="lot.timezone" :errors="errors.fields.value" id-prefix="lot-settings" />
      <p v-if="errors.general.value" class="mt-4 text-[13px] text-vz-danger" role="alert">{{ errors.general.value }}</p>
      <div class="mt-5 flex justify-end">
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">
          <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-save-3-line'" aria-hidden="true" /> {{ saving ? 'Guardando…' : 'Guardar cambios' }}
        </button>
      </div>
    </form>

    <section v-if="can('parking.delete')" class="rounded-vz border border-vz-danger/40 bg-vz-danger/5 p-5" aria-labelledby="danger-zone">
      <h3 id="danger-zone" class="flex items-center gap-2 text-[14px] font-semibold text-vz-danger-text">
        <i class="ri-error-warning-line" aria-hidden="true" /> Zona de riesgo
      </h3>
      <p class="mt-1 text-[13px] text-vz-muted">
        Eliminar un parqueadero solo es posible cuando no tiene espacios, puertas ni dispositivos. Para dejar de operarlo sin perder su historial, cámbialo a “Inactivo”.
      </p>
      <button class="vz-btn vz-btn-danger mt-4" type="button" @click="deleteError = ''; confirmDelete = true">
        <i class="ri-delete-bin-line" aria-hidden="true" /> Eliminar parqueadero
      </button>
    </section>

    <ConfirmDialog
      :open="confirmDelete"
      title="Eliminar parqueadero"
      :message="`¿Eliminar ${lot.name}? Esta acción no se puede deshacer.`"
      :busy="deleting"
      :error="deleteError"
      @close="confirmDelete = false"
      @confirm="onDelete"
    />
  </div>
</template>
