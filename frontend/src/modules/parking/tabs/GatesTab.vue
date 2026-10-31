<script setup lang="ts">
import { onMounted, ref } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { deleteGate, listGates, saveGate } from '@/modules/parking/api'
import { gateDirection, gateStatus } from '@/modules/parking/labels'
import type { GateDirection, GateStatus, ParkingGate, ParkingGateInput, ParkingLot } from '@/modules/parking/types'
import { parseApiError } from '@/services/http'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ lot: ParkingLot }>()
const emit = defineEmits<{ changed: [] }>()

const toasts = useToastStore()
const { can } = usePermissions()

const gates = ref<ParkingGate[]>([])
const state = ref<'loading' | 'ready' | 'error'>('loading')
const errorMessage = ref('')

const editing = ref<{ id: string | null } | null>(null)
const form = ref<ParkingGateInput>({ name: '', code: '', direction: 'entry', status: 'active' })
const saving = ref(false)
const errors = useFieldErrors()

const removing = ref<ParkingGate | null>(null)
const removingBusy = ref(false)
const removeError = ref('')

const directions = Object.keys(gateDirection) as GateDirection[]
const statuses = Object.keys(gateStatus) as GateStatus[]

async function load(): Promise<void> {
  state.value = 'loading'

  try {
    gates.value = await listGates(props.lot.id)
    state.value = 'ready'
  } catch (error) {
    errorMessage.value = parseApiError(error, 'No fue posible consultar las puertas.')
    state.value = 'error'
  }
}

function open(gate: ParkingGate | null): void {
  form.value = gate
    ? { name: gate.name, code: gate.code, direction: gate.direction, status: gate.status }
    : { name: '', code: '', direction: 'entry', status: 'active' }
  errors.clear()
  editing.value = { id: gate?.id ?? null }
}

async function onSave(): Promise<void> {
  if (!editing.value) {
    return
  }

  saving.value = true
  errors.clear()

  try {
    const saved = await saveGate(props.lot.id, editing.value.id, form.value)
    toasts.success(editing.value.id === null ? `Puerta ${saved.name} creada.` : `Puerta ${saved.name} actualizada.`)
    editing.value = null
    await load()
    emit('changed')
  } catch (error) {
    errors.capture(error, 'No fue posible guardar la puerta.')
  } finally {
    saving.value = false
  }
}

async function onRemove(): Promise<void> {
  if (!removing.value) {
    return
  }

  removingBusy.value = true
  removeError.value = ''

  try {
    await deleteGate(props.lot.id, removing.value.id)
    toasts.success(`Puerta ${removing.value.name} eliminada.`)
    removing.value = null
    await load()
    emit('changed')
  } catch (error) {
    removeError.value = parseApiError(error, 'No fue posible eliminar la puerta.')
  } finally {
    removingBusy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-[13px] text-vz-muted">Accesos físicos del parqueadero. Cámaras, barreras y lectores se vinculan a una puerta desde Dispositivos.</p>
      <button v-if="can('parking.create')" class="vz-btn vz-btn-primary" type="button" @click="open(null)"><i class="ri-add-line" aria-hidden="true" /> Nueva puerta</button>
    </div>

    <StateCard v-if="state === 'loading'" variant="loading" compact />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage" compact>
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>
    <StateCard v-else-if="gates.length === 0" variant="empty" title="Sin puertas" message="Registra las entradas y salidas para conectar cámaras y barreras." compact>
      <button v-if="can('parking.create')" class="vz-btn vz-btn-primary" type="button" @click="open(null)"><i class="ri-add-line" aria-hidden="true" /> Crear puerta</button>
    </StateCard>

    <ul v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <li v-for="gate in gates" :key="gate.id" class="rounded-vz border border-vz-border bg-vz-elevated/40 p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="flex size-11 items-center justify-center rounded bg-vz-info/15 text-2xl text-vz-info-text" aria-hidden="true">
              <i :class="gateDirection[gate.direction].icon" />
            </span>
            <div>
              <p class="font-semibold text-vz-heading">{{ gate.name }}</p>
              <p class="text-xs text-vz-muted">{{ gate.code }} · {{ gateDirection[gate.direction].label }}</p>
            </div>
          </div>
          <StatusBadge :label="gateStatus[gate.status].label" :tone="gateStatus[gate.status].tone" />
        </div>
        <div v-if="can('parking.update') || can('parking.delete')" class="mt-4 flex justify-end gap-2 border-t border-vz-border pt-3">
          <button v-if="can('parking.delete')" class="vz-btn vz-btn-soft-danger vz-btn-sm" type="button" @click="removeError = ''; removing = gate">
            <i class="ri-delete-bin-line" aria-hidden="true" /> Eliminar
          </button>
          <button v-if="can('parking.update')" class="vz-btn vz-btn-light vz-btn-sm" type="button" @click="open(gate)">
            <i class="ri-edit-2-line" aria-hidden="true" /> Editar
          </button>
        </div>
      </li>
    </ul>

    <BaseModal :open="editing !== null" :title="editing?.id ? 'Editar puerta' : 'Nueva puerta'" @close="editing = null">
      <form novalidate @submit.prevent="onSave">
        <div class="vz-card-body grid gap-4 sm:grid-cols-2">
          <div>
            <label class="vz-label" for="gate-name">Nombre</label>
            <input id="gate-name" v-model="form.name" class="vz-input" type="text" maxlength="80" required autofocus :aria-invalid="!!errors.fields.value.name" />
            <p v-if="errors.fields.value.name" class="vz-error" role="alert">{{ errors.fields.value.name }}</p>
          </div>
          <div>
            <label class="vz-label" for="gate-code">Código</label>
            <input id="gate-code" v-model="form.code" class="vz-input uppercase" type="text" maxlength="40" required :aria-invalid="!!errors.fields.value.code" />
            <p v-if="errors.fields.value.code" class="vz-error" role="alert">{{ errors.fields.value.code }}</p>
          </div>
          <div>
            <label class="vz-label" for="gate-direction">Sentido</label>
            <select id="gate-direction" v-model="form.direction" class="vz-input">
              <option v-for="direction in directions" :key="direction" :value="direction">{{ gateDirection[direction].label }}</option>
            </select>
          </div>
          <div>
            <label class="vz-label" for="gate-status">Estado</label>
            <select id="gate-status" v-model="form.status" class="vz-input">
              <option v-for="status in statuses" :key="status" :value="status">{{ gateStatus[status].label }}</option>
            </select>
          </div>
          <p v-if="errors.general.value" class="text-[13px] text-vz-danger sm:col-span-2" role="alert">{{ errors.general.value }}</p>
        </div>
        <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
          <button class="vz-btn vz-btn-light" type="button" @click="editing = null">Cancelar</button>
          <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
        </div>
      </form>
    </BaseModal>

    <ConfirmDialog
      :open="removing !== null"
      title="Eliminar puerta"
      :message="`¿Eliminar la puerta ${removing?.name ?? ''}? Debe estar libre de dispositivos.`"
      :busy="removingBusy"
      :error="removeError"
      @close="removing = null"
      @confirm="onRemove"
    />
  </div>
</template>
