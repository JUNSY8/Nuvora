<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { softBadge } from '@/components/ui/tones'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { changeDeviceStatus, deleteDevice, listDevices, saveDevice } from '@/modules/devices/api'
import { deviceStatus, deviceStatusOrder, deviceType } from '@/modules/devices/labels'
import type { Device, DeviceInput, DeviceStatus, DeviceType } from '@/modules/devices/types'
import { listGates, listParkingLots } from '@/modules/parking/api'
import type { ParkingGate, ParkingLot } from '@/modules/parking/types'
import { formatDateTime } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import type { PageMeta } from '@/types/api'

const auth = useAuthStore()
const toasts = useToastStore()
const { can } = usePermissions()

const devices = ref<Device[]>([])
const meta = ref<PageMeta | null>(null)
const counts = ref<Record<DeviceStatus, number> | null>(null)
const state = ref<'loading' | 'ready' | 'denied' | 'no-tenant' | 'error'>('loading')
const errorMessage = ref('')
const lots = ref<ParkingLot[]>([])

const filters = reactive<{ parking_lot_id: string; status: '' | DeviceStatus; type: '' | DeviceType; search: string }>({
  parking_lot_id: '',
  status: '',
  type: '',
  search: '',
})
const debouncedSearch = refDebounced(computed(() => filters.search), 300)
const types = Object.keys(deviceType) as DeviceType[]

const editing = ref<{ id: string | null } | null>(null)
const form = ref<DeviceInput>(blank())
const gates = ref<ParkingGate[]>([])
const saving = ref(false)
const errors = useFieldErrors()

const removing = ref<Device | null>(null)
const removingBusy = ref(false)
const removeError = ref('')
const statusBusyId = ref<string | null>(null)

const tz = computed(() => auth.currentTenant?.timezone ?? 'America/Bogota')

function blank(): DeviceInput {
  return {
    parking_lot_id: lots.value[0]?.id ?? '',
    gate_id: null,
    name: '',
    code: '',
    type: 'camera',
    vendor: null,
    model: null,
    serial_number: null,
    ip_address: null,
    firmware_version: null,
  }
}

async function load(page = 1): Promise<void> {
  if (!auth.currentTenant) {
    state.value = 'no-tenant'
    return
  }

  state.value = 'loading'

  try {
    const result = await listDevices({
      parking_lot_id: filters.parking_lot_id || undefined,
      status: filters.status || undefined,
      type: filters.type || undefined,
      search: debouncedSearch.value || undefined,
    }, page)
    devices.value = result.items
    meta.value = result.meta
    counts.value = result.status_counts
    state.value = 'ready'
  } catch (error) {
    const code = apiErrorCode(error)
    state.value = code === 'FORBIDDEN' ? 'denied' : code === 'TENANT_REQUIRED' ? 'no-tenant' : 'error'
    errorMessage.value = parseApiError(error, 'No fue posible consultar los dispositivos.')
  }
}

async function loadLots(): Promise<void> {
  if (!can('parking.view')) {
    return
  }

  try {
    lots.value = (await listParkingLots({ per_page: 100 })).items
  } catch {
    lots.value = []
  }
}

async function loadGates(lotId: string): Promise<void> {
  gates.value = []

  if (lotId === '' || !can('parking.view')) {
    return
  }

  try {
    gates.value = await listGates(lotId)
  } catch {
    gates.value = []
  }
}

async function open(device: Device | null): Promise<void> {
  form.value = device
    ? {
        parking_lot_id: device.parking_lot?.id ?? '',
        gate_id: device.gate?.id ?? null,
        name: device.name,
        code: device.code,
        type: device.type,
        vendor: device.vendor,
        model: device.model,
        serial_number: device.serial_number,
        ip_address: device.ip_address,
        firmware_version: device.firmware_version,
      }
    : blank()
  errors.clear()
  editing.value = { id: device?.id ?? null }
  await loadGates(form.value.parking_lot_id)
}

async function onLotChange(): Promise<void> {
  form.value.gate_id = null
  await loadGates(form.value.parking_lot_id)
}

async function onSave(): Promise<void> {
  if (!editing.value) {
    return
  }

  saving.value = true
  errors.clear()
  const clean = (value: string | null) => (value === null || value.trim() === '' ? null : value.trim())

  try {
    const saved = await saveDevice(editing.value.id, {
      ...form.value,
      vendor: clean(form.value.vendor),
      model: clean(form.value.model),
      serial_number: clean(form.value.serial_number),
      ip_address: clean(form.value.ip_address),
      firmware_version: clean(form.value.firmware_version),
    })
    toasts.success(editing.value.id === null ? `Dispositivo ${saved.code} registrado.` : `Dispositivo ${saved.code} actualizado.`)
    editing.value = null
    await load(meta.value?.current_page ?? 1)
  } catch (error) {
    errors.capture(error, 'No fue posible guardar el dispositivo.')
  } finally {
    saving.value = false
  }
}

async function onStatus(device: Device, target: DeviceStatus): Promise<void> {
  statusBusyId.value = device.id

  try {
    await changeDeviceStatus(device.id, target)
    toasts.success(target === 'maintenance' ? `${device.code} en mantenimiento.` : `${device.code} vuelve a monitoreo.`)
    await load(meta.value?.current_page ?? 1)
  } catch (error) {
    toasts.error(parseApiError(error, 'No fue posible cambiar el estado.'))
  } finally {
    statusBusyId.value = null
  }
}

async function onRemove(): Promise<void> {
  if (!removing.value) {
    return
  }

  removingBusy.value = true
  removeError.value = ''

  try {
    await deleteDevice(removing.value.id)
    toasts.success(`Dispositivo ${removing.value.code} retirado.`)
    removing.value = null
    await load(1)
  } catch (error) {
    removeError.value = parseApiError(error, 'No fue posible retirar el dispositivo.')
  } finally {
    removingBusy.value = false
  }
}

watch(() => [filters.parking_lot_id, filters.status, filters.type, debouncedSearch.value], () => load(1))
onMounted(async () => {
  await Promise.all([load(), loadLots()])
})
</script>

<template>
  <div>
    <PageHeader title="Dispositivos" subtitle="Cámaras, barreras, lectores y terminales de cada sede." :breadcrumbs="[{ label: 'Operación' }, { label: 'Dispositivos' }]">
      <template v-if="can('devices.manage') && state !== 'no-tenant'" #actions>
        <button class="vz-btn vz-btn-primary" type="button" :disabled="lots.length === 0" :title="lots.length === 0 ? 'Primero registra un parqueadero' : undefined" @click="open(null)">
          <i class="ri-add-line" aria-hidden="true" /> Registrar dispositivo
        </button>
      </template>
    </PageHeader>

    <StateCard v-if="state === 'no-tenant'" variant="no-tenant" message="Selecciona una empresa para ver sus dispositivos.">
      <RouterLink class="vz-btn vz-btn-primary" :to="{ name: 'tenant' }">Ir a empresa</RouterLink>
    </StateCard>
    <StateCard v-else-if="state === 'denied'" variant="denied" message="Tu rol no permite consultar los dispositivos." />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage">
      <button class="vz-btn vz-btn-primary" type="button" @click="load()">Reintentar</button>
    </StateCard>

    <template v-else>
      <section class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5" aria-label="Resumen por estado">
        <button
          v-for="status in deviceStatusOrder"
          :key="status"
          class="vz-card flex items-center gap-3 p-4 text-left transition-colors hover:border-vz-primary/50"
          :class="filters.status === status ? 'border-vz-secondary' : ''"
          type="button"
          :aria-pressed="filters.status === status"
          @click="filters.status = filters.status === status ? '' : status"
        >
          <span class="flex size-10 items-center justify-center rounded text-xl" :class="softBadge[deviceStatus[status].tone]" aria-hidden="true">
            <i :class="deviceStatus[status].icon" />
          </span>
          <span>
            <span class="block text-lg font-semibold text-vz-heading">{{ counts?.[status] ?? 0 }}</span>
            <span class="block text-xs text-vz-muted">{{ deviceStatus[status].label }}</span>
          </span>
        </button>
      </section>

      <div class="vz-card">
        <div class="grid gap-3 border-b border-vz-border p-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label class="sr-only" for="device-search">Buscar</label>
            <div class="relative">
              <i class="ri-search-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
              <input id="device-search" v-model="filters.search" class="vz-input ps-9" type="search" placeholder="Nombre, código o serial" />
            </div>
          </div>
          <div>
            <label class="sr-only" for="device-lot">Parqueadero</label>
            <select id="device-lot" v-model="filters.parking_lot_id" class="vz-input">
              <option value="">Todos los parqueaderos</option>
              <option v-for="lot in lots" :key="lot.id" :value="lot.id">{{ lot.name }}</option>
            </select>
          </div>
          <div>
            <label class="sr-only" for="device-type">Tipo</label>
            <select id="device-type" v-model="filters.type" class="vz-input">
              <option value="">Todos los tipos</option>
              <option v-for="type in types" :key="type" :value="type">{{ deviceType[type].label }}</option>
            </select>
          </div>
          <div>
            <label class="sr-only" for="device-status">Estado</label>
            <select id="device-status" v-model="filters.status" class="vz-input">
              <option value="">Todos los estados</option>
              <option v-for="status in deviceStatusOrder" :key="status" :value="status">{{ deviceStatus[status].label }}</option>
            </select>
          </div>
        </div>

        <div v-if="state === 'loading'" class="p-8 text-center text-vz-muted" role="status"><i class="ri-loader-4-line animate-spin text-2xl" aria-hidden="true" /><p>Cargando dispositivos…</p></div>
        <div v-else-if="devices.length === 0" class="p-10 text-center">
          <i class="ri-router-line text-4xl text-vz-muted" aria-hidden="true" />
          <p class="mt-2 font-medium text-vz-heading">Sin dispositivos</p>
          <p class="text-[13px] text-vz-muted">{{ filters.search || filters.status || filters.type || filters.parking_lot_id ? 'Ningún dispositivo coincide con los filtros.' : 'Registra cámaras, barreras y lectores para preparar la integración con Nuvora Edge.' }}</p>
        </div>
        <div v-else class="overflow-x-auto">
          <table class="vz-table">
            <thead>
              <tr>
                <th scope="col">Dispositivo</th>
                <th scope="col">Tipo</th>
                <th scope="col">Ubicación</th>
                <th scope="col">Red</th>
                <th scope="col">Estado</th>
                <th scope="col">Último reporte</th>
                <th v-if="can('devices.manage')" scope="col"><span class="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="device in devices" :key="device.id">
                <td>
                  <div class="flex items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded bg-vz-elevated text-lg text-vz-secondary-text" aria-hidden="true">
                      <i :class="deviceType[device.type].icon" />
                    </span>
                    <div>
                      <p class="font-medium text-vz-heading">{{ device.name }}</p>
                      <p class="text-xs text-vz-muted">{{ device.code }}<template v-if="device.vendor || device.model"> · {{ [device.vendor, device.model].filter(Boolean).join(' ') }}</template></p>
                    </div>
                  </div>
                </td>
                <td>{{ deviceType[device.type].label }}</td>
                <td>
                  <p>{{ device.parking_lot?.name ?? '—' }}</p>
                  <p v-if="device.gate" class="text-xs text-vz-muted"><i class="ri-door-open-line" aria-hidden="true" /> {{ device.gate.name }}</p>
                </td>
                <td class="font-mono text-xs">{{ device.ip_address ?? '—' }}</td>
                <td><StatusBadge :label="deviceStatus[device.status].label" :tone="deviceStatus[device.status].tone" :icon="deviceStatus[device.status].icon" /></td>
                <td class="text-xs text-vz-muted">{{ device.last_seen_at ? formatDateTime(device.last_seen_at, tz) : 'Nunca' }}</td>
                <td v-if="can('devices.manage')" class="text-right whitespace-nowrap">
                  <button
                    v-for="target in device.manual_transitions"
                    :key="target"
                    class="vz-btn vz-btn-sm me-1"
                    :class="target === 'maintenance' ? 'vz-btn-soft-warning' : 'vz-btn-soft-success'"
                    type="button"
                    :disabled="statusBusyId === device.id"
                    @click="onStatus(device, target)"
                  >
                    <i :class="target === 'maintenance' ? 'ri-tools-line' : 'ri-pulse-line'" aria-hidden="true" />
                    {{ target === 'maintenance' ? 'Mantenimiento' : 'Monitorear' }}
                  </button>
                  <button class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-elevated hover:text-vz-topbar-item" type="button" :aria-label="`Editar ${device.name}`" @click="open(device)">
                    <i class="ri-edit-2-line" aria-hidden="true" />
                  </button>
                  <button class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger" type="button" :aria-label="`Retirar ${device.name}`" @click="removeError = ''; removing = device">
                    <i class="ri-delete-bin-line" aria-hidden="true" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <PaginationBar v-if="meta" :meta="meta" @change="load" />
      </div>
    </template>

    <BaseModal :open="editing !== null" :title="editing?.id ? 'Editar dispositivo' : 'Registrar dispositivo'" size="lg" @close="editing = null">
      <form novalidate @submit.prevent="onSave">
        <div class="vz-card-body grid gap-4 sm:grid-cols-2">
          <div>
            <label class="vz-label" for="device-form-name">Nombre</label>
            <input id="device-form-name" v-model="form.name" class="vz-input" type="text" maxlength="80" required autofocus :aria-invalid="!!errors.fields.value.name" />
            <p v-if="errors.fields.value.name" class="vz-error" role="alert">{{ errors.fields.value.name }}</p>
          </div>
          <div>
            <label class="vz-label" for="device-form-code">Código</label>
            <input id="device-form-code" v-model="form.code" class="vz-input uppercase" type="text" maxlength="40" required :aria-invalid="!!errors.fields.value.code" aria-describedby="device-code-help" />
            <p v-if="errors.fields.value.code" class="vz-error" role="alert">{{ errors.fields.value.code }}</p>
            <p v-else id="device-code-help" class="vz-help">Con este código Nuvora Edge reporta sus eventos.</p>
          </div>
          <div>
            <label class="vz-label" for="device-form-type">Tipo</label>
            <select id="device-form-type" v-model="form.type" class="vz-input">
              <option v-for="type in types" :key="type" :value="type">{{ deviceType[type].label }}</option>
            </select>
          </div>
          <div>
            <label class="vz-label" for="device-form-lot">Parqueadero</label>
            <select id="device-form-lot" v-model="form.parking_lot_id" class="vz-input" required :aria-invalid="!!errors.fields.value.parking_lot_id" @change="onLotChange">
              <option v-for="lot in lots" :key="lot.id" :value="lot.id">{{ lot.name }}</option>
            </select>
            <p v-if="errors.fields.value.parking_lot_id" class="vz-error" role="alert">{{ errors.fields.value.parking_lot_id }}</p>
          </div>
          <div>
            <label class="vz-label" for="device-form-gate">Puerta <span class="text-vz-muted">(opcional)</span></label>
            <select id="device-form-gate" v-model="form.gate_id" class="vz-input" :aria-invalid="!!errors.fields.value.gate_id">
              <option :value="null">Sin puerta</option>
              <option v-for="gate in gates" :key="gate.id" :value="gate.id">{{ gate.name }} ({{ gate.code }})</option>
            </select>
            <p v-if="errors.fields.value.gate_id" class="vz-error" role="alert">{{ errors.fields.value.gate_id }}</p>
          </div>
          <div>
            <label class="vz-label" for="device-form-ip">Dirección IP</label>
            <input id="device-form-ip" v-model="form.ip_address" class="vz-input font-mono" type="text" maxlength="45" placeholder="192.168.1.20" :aria-invalid="!!errors.fields.value.ip_address" />
            <p v-if="errors.fields.value.ip_address" class="vz-error" role="alert">{{ errors.fields.value.ip_address }}</p>
          </div>
          <div>
            <label class="vz-label" for="device-form-vendor">Fabricante</label>
            <input id="device-form-vendor" v-model="form.vendor" class="vz-input" type="text" maxlength="80" />
          </div>
          <div>
            <label class="vz-label" for="device-form-model">Modelo</label>
            <input id="device-form-model" v-model="form.model" class="vz-input" type="text" maxlength="80" />
          </div>
          <div>
            <label class="vz-label" for="device-form-serial">Serial</label>
            <input id="device-form-serial" v-model="form.serial_number" class="vz-input" type="text" maxlength="80" />
          </div>
          <div>
            <label class="vz-label" for="device-form-firmware">Firmware</label>
            <input id="device-form-firmware" v-model="form.firmware_version" class="vz-input" type="text" maxlength="40" />
          </div>
          <p class="rounded-vz border border-vz-info/30 bg-vz-info/10 px-3 py-2.5 text-xs sm:col-span-2">
            <i class="ri-shield-keyhole-line text-vz-info" aria-hidden="true" /> Las credenciales del equipo no se guardan aquí. Se configurarán cifradas en Nuvora Edge.
          </p>
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
      title="Retirar dispositivo"
      :message="`¿Retirar ${removing?.name ?? ''} (${removing?.code ?? ''}) del registro?`"
      confirm-label="Retirar"
      :busy="removingBusy"
      :error="removeError"
      @close="removing = null"
      @confirm="onRemove"
    />
  </div>
</template>
