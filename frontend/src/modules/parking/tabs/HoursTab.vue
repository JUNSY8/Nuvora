<script setup lang="ts">
import { ref, watch } from 'vue'

import { usePermissions } from '@/composables/usePermissions'
import { replaceOperatingHours } from '@/modules/parking/api'
import { dayMode, weekdays } from '@/modules/parking/labels'
import type { OperatingDayMode, OperatingHour, ParkingLot } from '@/modules/parking/types'
import { parseApiError } from '@/services/http'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ lot: ParkingLot }>()
const emit = defineEmits<{ changed: [] }>()

const toasts = useToastStore()
const { can } = usePermissions()

const days = ref<OperatingHour[]>([])
const saving = ref(false)
const errorMessage = ref('')
const modes = Object.keys(dayMode) as OperatingDayMode[]

function reset(): void {
  const current = props.lot.operating_hours ?? []
  days.value = [1, 2, 3, 4, 5, 6, 7].map((day) => {
    const found = current.find((item) => item.day_of_week === day)

    return found ? { ...found } : { day_of_week: day, mode: 'open_24h', opens_at: null, closes_at: null }
  })
}

function setMode(day: OperatingHour, mode: OperatingDayMode): void {
  day.mode = mode

  if (mode === 'scheduled') {
    day.opens_at ??= '06:00'
    day.closes_at ??= '22:00'
  } else {
    day.opens_at = null
    day.closes_at = null
  }
}

function copyToAll(source: OperatingHour): void {
  days.value = days.value.map((day) => ({ ...source, day_of_week: day.day_of_week }))
}

async function onSave(): Promise<void> {
  saving.value = true
  errorMessage.value = ''

  try {
    days.value = await replaceOperatingHours(props.lot.id, days.value)
    toasts.success('Horario actualizado.')
    emit('changed')
  } catch (error) {
    errorMessage.value = parseApiError(error, 'No fue posible guardar el horario.')
  } finally {
    saving.value = false
  }
}

watch(() => props.lot.operating_hours, reset, { immediate: true })
</script>

<template>
  <form novalidate @submit.prevent="onSave">
    <p class="mb-4 text-[13px] text-vz-muted">
      <i class="ri-time-line" aria-hidden="true" /> Horario local en {{ lot.timezone }}. Si el cierre es anterior a la apertura, el turno termina al día siguiente.
    </p>

    <div class="overflow-x-auto rounded-vz border border-vz-border">
      <table class="vz-table">
        <thead>
          <tr>
            <th scope="col">Día</th>
            <th scope="col">Modalidad</th>
            <th scope="col">Apertura</th>
            <th scope="col">Cierre</th>
            <th scope="col"><span class="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(day, index) in days" :key="day.day_of_week">
            <th scope="row" class="font-medium text-vz-heading">{{ weekdays[day.day_of_week - 1] }}</th>
            <td>
              <label class="sr-only" :for="`mode-${day.day_of_week}`">Modalidad del {{ weekdays[day.day_of_week - 1] }}</label>
              <select :id="`mode-${day.day_of_week}`" class="vz-input min-w-36" :value="day.mode" :disabled="!can('parking.update')" @change="setMode(day, ($event.target as HTMLSelectElement).value as OperatingDayMode)">
                <option v-for="mode in modes" :key="mode" :value="mode">{{ dayMode[mode] }}</option>
              </select>
            </td>
            <td>
              <label v-if="day.mode === 'scheduled'" class="sr-only" :for="`opens-${day.day_of_week}`">Apertura del {{ weekdays[day.day_of_week - 1] }}</label>
              <input v-if="day.mode === 'scheduled'" :id="`opens-${day.day_of_week}`" v-model="day.opens_at" class="vz-input min-w-28" type="time" required :disabled="!can('parking.update')" />
              <span v-else :id="`opens-${day.day_of_week}`" class="text-vz-muted">{{ day.mode === 'open_24h' ? 'Todo el día' : '—' }}</span>
            </td>
            <td>
              <label v-if="day.mode === 'scheduled'" class="sr-only" :for="`closes-${day.day_of_week}`">Cierre del {{ weekdays[day.day_of_week - 1] }}</label>
              <input v-if="day.mode === 'scheduled'" :id="`closes-${day.day_of_week}`" v-model="day.closes_at" class="vz-input min-w-28" type="time" required :disabled="!can('parking.update')" />
              <span v-else :id="`closes-${day.day_of_week}`" class="text-vz-muted">{{ day.mode === 'open_24h' ? 'Todo el día' : '—' }}</span>
            </td>
            <td class="text-right">
              <button v-if="can('parking.update') && index === 0" class="vz-btn vz-btn-light vz-btn-sm" type="button" @click="copyToAll(day)">
                <i class="ri-file-copy-line" aria-hidden="true" /> Aplicar a todos
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="errorMessage" class="mt-3 text-[13px] text-vz-danger" role="alert">{{ errorMessage }}</p>

    <div v-if="can('parking.update')" class="mt-4 flex justify-end gap-2">
      <button class="vz-btn vz-btn-light" type="button" @click="reset">Descartar</button>
      <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">
        <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-save-3-line'" aria-hidden="true" /> {{ saving ? 'Guardando…' : 'Guardar horario' }}
      </button>
    </div>
  </form>
</template>
