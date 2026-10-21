<script setup lang="ts">
import { onMounted, ref } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { deleteZone, listZones, saveZone } from '@/modules/parking/api'
import type { ParkingLot, ParkingZone, ParkingZoneInput } from '@/modules/parking/types'
import { parseApiError } from '@/services/http'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ lot: ParkingLot }>()
const emit = defineEmits<{ changed: [] }>()

const toasts = useToastStore()
const { can } = usePermissions()

const zones = ref<ParkingZone[]>([])
const state = ref<'loading' | 'ready' | 'error'>('loading')
const errorMessage = ref('')

const editing = ref<{ id: string | null } | null>(null)
const form = ref<ParkingZoneInput>(blank())
const saving = ref(false)
const errors = useFieldErrors()

const removing = ref<ParkingZone | null>(null)
const removingBusy = ref(false)
const removeError = ref('')

function blank(): ParkingZoneInput {
  return { name: '', code: '', description: null, is_active: true, sort_order: zones.value.length }
}

async function load(): Promise<void> {
  state.value = 'loading'

  try {
    zones.value = await listZones(props.lot.id)
    state.value = 'ready'
  } catch (error) {
    errorMessage.value = parseApiError(error, 'No fue posible consultar las zonas.')
    state.value = 'error'
  }
}

function open(zone: ParkingZone | null): void {
  form.value = zone
    ? { name: zone.name, code: zone.code, description: zone.description, is_active: zone.is_active, sort_order: zone.sort_order }
    : blank()
  errors.clear()
  editing.value = { id: zone?.id ?? null }
}

async function onSave(): Promise<void> {
  if (!editing.value) {
    return
  }

  saving.value = true
  errors.clear()

  try {
    const saved = await saveZone(props.lot.id, editing.value.id, { ...form.value, description: form.value.description?.trim() || null })
    toasts.success(editing.value.id === null ? `Zona ${saved.name} creada.` : `Zona ${saved.name} actualizada.`)
    editing.value = null
    await load()
    emit('changed')
  } catch (error) {
    errors.capture(error, 'No fue posible guardar la zona.')
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
    await deleteZone(props.lot.id, removing.value.id)
    toasts.success(`Zona ${removing.value.name} eliminada.`)
    removing.value = null
    await load()
    emit('changed')
  } catch (error) {
    removeError.value = parseApiError(error, 'No fue posible eliminar la zona.')
  } finally {
    removingBusy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-[13px] text-vz-muted">Agrupa los espacios por uso: general, motos, movilidad reducida, mensualidades…</p>
      <button v-if="can('parking.create')" class="vz-btn vz-btn-primary" type="button" @click="open(null)"><i class="ri-add-line" aria-hidden="true" /> Nueva zona</button>
    </div>

    <StateCard v-if="state === 'loading'" variant="loading" compact />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage" compact>
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>
    <StateCard v-else-if="zones.length === 0" variant="empty" title="Sin zonas" message="Las zonas son opcionales, pero ayudan a ubicar y filtrar los espacios." compact>
      <button v-if="can('parking.create')" class="vz-btn vz-btn-primary" type="button" @click="open(null)"><i class="ri-add-line" aria-hidden="true" /> Crear zona</button>
    </StateCard>

    <div v-else class="overflow-x-auto rounded-vz border border-vz-border">
      <table class="vz-table">
        <thead>
          <tr>
            <th scope="col">Zona</th>
            <th scope="col">Código</th>
            <th scope="col">Espacios</th>
            <th scope="col">Estado</th>
            <th scope="col"><span class="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="zone in zones" :key="zone.id">
            <td>
              <p class="font-medium text-vz-heading">{{ zone.name }}</p>
              <p v-if="zone.description" class="text-xs text-vz-muted">{{ zone.description }}</p>
            </td>
            <td><code class="rounded bg-vz-elevated px-1.5 py-0.5 text-xs">{{ zone.code }}</code></td>
            <td>{{ zone.spaces_count }}</td>
            <td><StatusBadge :label="zone.is_active ? 'Activa' : 'Inactiva'" :tone="zone.is_active ? 'success' : 'muted'" /></td>
            <td class="text-right whitespace-nowrap">
              <button v-if="can('parking.update')" class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" type="button" :aria-label="`Editar ${zone.name}`" @click="open(zone)">
                <i class="ri-edit-2-line" aria-hidden="true" />
              </button>
              <button v-if="can('parking.delete')" class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger" type="button" :aria-label="`Eliminar ${zone.name}`" @click="removeError = ''; removing = zone">
                <i class="ri-delete-bin-line" aria-hidden="true" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <BaseModal :open="editing !== null" :title="editing?.id ? 'Editar zona' : 'Nueva zona'" @close="editing = null">
      <form novalidate @submit.prevent="onSave">
        <div class="vz-card-body grid gap-4 sm:grid-cols-2">
          <div>
            <label class="vz-label" for="zone-name">Nombre</label>
            <input id="zone-name" v-model="form.name" class="vz-input" type="text" maxlength="80" required autofocus :aria-invalid="!!errors.fields.value.name" />
            <p v-if="errors.fields.value.name" class="vz-error" role="alert">{{ errors.fields.value.name }}</p>
          </div>
          <div>
            <label class="vz-label" for="zone-code">Código</label>
            <input id="zone-code" v-model="form.code" class="vz-input uppercase" type="text" maxlength="40" required :aria-invalid="!!errors.fields.value.code" />
            <p v-if="errors.fields.value.code" class="vz-error" role="alert">{{ errors.fields.value.code }}</p>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="zone-description">Descripción</label>
            <input id="zone-description" v-model="form.description" class="vz-input" type="text" maxlength="300" />
          </div>
          <div>
            <label class="vz-label" for="zone-order">Orden</label>
            <input id="zone-order" v-model.number="form.sort_order" class="vz-input" type="number" min="0" max="1000" />
          </div>
          <label class="flex items-center gap-2 self-end pb-2 text-[13px]"><input v-model="form.is_active" class="size-4 accent-vz-primary" type="checkbox" /> Zona activa</label>
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
      title="Eliminar zona"
      :message="`¿Eliminar la zona ${removing?.name ?? ''}? Solo es posible si no tiene espacios.`"
      :busy="removingBusy"
      :error="removeError"
      @close="removing = null"
      @confirm="onRemove"
    />
  </div>
</template>
