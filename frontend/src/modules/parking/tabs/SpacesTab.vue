<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, onMounted, reactive, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { solidFill, tileSurface } from '@/components/ui/tones'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { changeSpaceStatus, createSpaceRange, deleteSpace, listSpaces, listZones, saveSpace } from '@/modules/parking/api'
import { spaceStatus, spaceStatusOrder, spaceType } from '@/modules/parking/labels'
import type { ParkingLot, ParkingSpace, ParkingSpaceInput, ParkingSpaceRangeInput, ParkingZone, SpaceStatus, SpaceType } from '@/modules/parking/types'
import { formatDateTime } from '@/services/format'
import { parseApiError } from '@/services/http'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ lot: ParkingLot }>()
const emit = defineEmits<{ changed: [] }>()

const toasts = useToastStore()
const { can } = usePermissions()

const spaces = ref<ParkingSpace[]>([])
const total = ref(0)
const zones = ref<ParkingZone[]>([])
const state = ref<'loading' | 'ready' | 'error'>('loading')
const errorMessage = ref('')
const view = ref<'map' | 'list'>('map')

const filters = reactive<{ zone_id: string; status: '' | SpaceStatus; type: '' | SpaceType; search: string }>({
  zone_id: '',
  status: '',
  type: '',
  search: '',
})
const debouncedSearch = refDebounced(computed(() => filters.search), 300)

const selected = ref<ParkingSpace | null>(null)
const reason = ref('')
const statusBusy = ref<SpaceStatus | null>(null)

const editor = ref<{ mode: 'create' | 'edit'; spaceId: string | null } | null>(null)
const spaceForm = ref<ParkingSpaceInput>(blankSpace())
const savingSpace = ref(false)
const spaceErrors = useFieldErrors()

const bulkOpen = ref(false)
const bulkForm = ref<ParkingSpaceRangeInput>(blankRange())
const savingBulk = ref(false)
const bulkErrors = useFieldErrors()

const deleting = ref(false)
const confirmDelete = ref(false)
const deleteError = ref('')

const spaceTypes = Object.keys(spaceType) as SpaceType[]

const grouped = computed(() => {
  const groups = new Map<string, { title: string; spaces: ParkingSpace[] }>()

  for (const space of spaces.value) {
    const key = space.zone?.id ?? 'none'

    if (!groups.has(key)) {
      groups.set(key, { title: space.zone ? `${space.zone.name} · ${space.zone.code}` : 'Sin zona', spaces: [] })
    }

    groups.get(key)?.spaces.push(space)
  }

  return Array.from(groups.values())
})

const preview = computed(() => {
  const { prefix, from, to, padding } = bulkForm.value

  if (!Number.isInteger(from) || !Number.isInteger(to) || to < from) {
    return ''
  }

  const code = (n: number) => `${prefix.trim().toUpperCase()}${String(n).padStart(padding, '0')}`

  return `${to - from + 1} espacios: ${code(from)} … ${code(to)}`
})

function blankSpace(): ParkingSpaceInput {
  return { code: '', zone_id: null, type: 'car', floor: null, is_reservable: false, is_assignable: false }
}

function blankRange(): ParkingSpaceRangeInput {
  return { prefix: 'A-', from: 1, to: 20, padding: 2, zone_id: null, type: 'car', floor: null, is_reservable: false, is_assignable: false }
}

async function load(): Promise<void> {
  state.value = 'loading'

  try {
    const [page, zoneList] = await Promise.all([
      listSpaces(props.lot.id, {
        zone_id: filters.zone_id || undefined,
        status: filters.status || undefined,
        type: filters.type || undefined,
        search: debouncedSearch.value || undefined,
      }),
      listZones(props.lot.id),
    ])
    spaces.value = page.items
    total.value = page.meta.total
    zones.value = zoneList
    state.value = 'ready'

    if (selected.value) {
      selected.value = page.items.find((space) => space.id === selected.value?.id) ?? null
    }
  } catch (error) {
    errorMessage.value = parseApiError(error, 'No fue posible consultar los espacios.')
    state.value = 'error'
  }
}

function openSpace(space: ParkingSpace): void {
  selected.value = space
  reason.value = ''
}

async function onChangeStatus(target: SpaceStatus): Promise<void> {
  if (!selected.value) {
    return
  }

  statusBusy.value = target

  try {
    const updated = await changeSpaceStatus(props.lot.id, selected.value.id, target, reason.value.trim() || null)
    spaces.value = spaces.value.map((space) => (space.id === updated.id ? updated : space))
    selected.value = updated
    reason.value = ''
    toasts.success(`Espacio ${updated.code}: ${spaceStatus[updated.status].label.toLowerCase()}.`)
    emit('changed')
  } catch (error) {
    toasts.error(parseApiError(error, 'No fue posible cambiar el estado.'))
  } finally {
    statusBusy.value = null
  }
}

function openCreate(): void {
  spaceForm.value = { ...blankSpace(), zone_id: filters.zone_id && filters.zone_id !== 'none' ? filters.zone_id : null }
  spaceErrors.clear()
  editor.value = { mode: 'create', spaceId: null }
}

function openEdit(space: ParkingSpace): void {
  spaceForm.value = {
    code: space.code,
    zone_id: space.zone?.id ?? null,
    type: space.type,
    floor: space.floor,
    is_reservable: space.is_reservable,
    is_assignable: space.is_assignable,
  }
  spaceErrors.clear()
  editor.value = { mode: 'edit', spaceId: space.id }
  selected.value = null
}

async function onSaveSpace(): Promise<void> {
  if (!editor.value) {
    return
  }

  savingSpace.value = true
  spaceErrors.clear()

  try {
    const saved = await saveSpace(props.lot.id, editor.value.spaceId, { ...spaceForm.value, floor: spaceForm.value.floor?.trim() || null })
    toasts.success(editor.value.mode === 'create' ? `Espacio ${saved.code} creado.` : `Espacio ${saved.code} actualizado.`)
    editor.value = null
    await load()
    emit('changed')
  } catch (error) {
    spaceErrors.capture(error, 'No fue posible guardar el espacio.')
  } finally {
    savingSpace.value = false
  }
}

function openBulk(): void {
  bulkForm.value = { ...blankRange(), zone_id: filters.zone_id && filters.zone_id !== 'none' ? filters.zone_id : null }
  bulkErrors.clear()
  bulkOpen.value = true
}

async function onBulk(): Promise<void> {
  savingBulk.value = true
  bulkErrors.clear()

  try {
    const created = await createSpaceRange(props.lot.id, { ...bulkForm.value, floor: bulkForm.value.floor?.trim() || null })
    toasts.success(`Se crearon ${created} espacios.`)
    bulkOpen.value = false
    await load()
    emit('changed')
  } catch (error) {
    bulkErrors.capture(error, 'No fue posible crear los espacios.')
  } finally {
    savingBulk.value = false
  }
}

async function onDelete(): Promise<void> {
  if (!selected.value) {
    return
  }

  deleting.value = true
  deleteError.value = ''

  try {
    const code = selected.value.code
    await deleteSpace(props.lot.id, selected.value.id)
    confirmDelete.value = false
    selected.value = null
    toasts.success(`Espacio ${code} retirado.`)
    await load()
    emit('changed')
  } catch (error) {
    deleteError.value = parseApiError(error, 'No fue posible retirar el espacio.')
  } finally {
    deleting.value = false
  }
}

watch(() => [filters.zone_id, filters.status, filters.type, debouncedSearch.value], () => load())
onMounted(load)
</script>

<template>
  <div>
    <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
      <div class="grid flex-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label class="vz-label" for="space-search">Buscar</label>
          <div class="relative">
            <i class="ri-search-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
            <input id="space-search" v-model="filters.search" class="vz-input ps-9" type="search" placeholder="Código, p. ej. A-01" />
          </div>
        </div>
        <div>
          <label class="vz-label" for="space-zone">Zona</label>
          <select id="space-zone" v-model="filters.zone_id" class="vz-input">
            <option value="">Todas</option>
            <option value="none">Sin zona</option>
            <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option>
          </select>
        </div>
        <div>
          <label class="vz-label" for="space-status">Estado</label>
          <select id="space-status" v-model="filters.status" class="vz-input">
            <option value="">Todos</option>
            <option v-for="status in spaceStatusOrder" :key="status" :value="status">{{ spaceStatus[status].label }}</option>
          </select>
        </div>
        <div>
          <label class="vz-label" for="space-type">Tipo</label>
          <select id="space-type" v-model="filters.type" class="vz-input">
            <option value="">Todos</option>
            <option v-for="type in spaceTypes" :key="type" :value="type">{{ spaceType[type].label }}</option>
          </select>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <div class="inline-flex rounded-vz bg-vz-elevated p-0.5" role="group" aria-label="Vista">
          <button class="vz-btn vz-btn-sm" :class="view === 'map' ? 'bg-vz-card text-vz-topbar-item' : 'text-vz-muted'" type="button" :aria-pressed="view === 'map'" @click="view = 'map'">
            <i class="ri-layout-grid-line" aria-hidden="true" /> Mapa
          </button>
          <button class="vz-btn vz-btn-sm" :class="view === 'list' ? 'bg-vz-card text-vz-topbar-item' : 'text-vz-muted'" type="button" :aria-pressed="view === 'list'" @click="view = 'list'">
            <i class="ri-list-unordered" aria-hidden="true" /> Lista
          </button>
        </div>
        <template v-if="can('parking.create')">
          <button class="vz-btn vz-btn-soft-primary" type="button" @click="openBulk"><i class="ri-stack-line" aria-hidden="true" /> Crear en bloque</button>
          <button class="vz-btn vz-btn-primary" type="button" @click="openCreate"><i class="ri-add-line" aria-hidden="true" /> Espacio</button>
        </template>
      </div>
    </div>

    <ul class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs text-vz-muted" aria-label="Leyenda">
      <li v-for="status in spaceStatusOrder" :key="status" class="flex items-center gap-1.5">
        <span class="size-2.5 rounded-sm" :class="solidFill[spaceStatus[status].tone]" aria-hidden="true" />
        {{ spaceStatus[status].label }}
      </li>
    </ul>

    <div class="mt-5">
      <StateCard v-if="state === 'loading'" variant="loading" compact />
      <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage" compact>
        <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
      </StateCard>
      <StateCard
        v-else-if="spaces.length === 0"
        variant="empty"
        compact
        :title="filters.search || filters.status || filters.type || filters.zone_id ? 'Sin coincidencias' : 'Sin espacios'"
        :message="filters.search || filters.status || filters.type || filters.zone_id ? 'Ningún espacio coincide con los filtros.' : 'Crea los espacios uno a uno o genera una numeración completa en bloque.'"
      >
        <button v-if="can('parking.create') && !filters.search && !filters.status && !filters.type && !filters.zone_id" class="vz-btn vz-btn-primary" type="button" @click="openBulk">
          <i class="ri-stack-line" aria-hidden="true" /> Crear en bloque
        </button>
      </StateCard>

      <template v-else-if="view === 'map'">
        <p v-if="total > spaces.length" class="mb-3 text-xs text-vz-warning" role="status">Se muestran {{ spaces.length }} de {{ total }} espacios. Usa los filtros para acotar.</p>
        <section v-for="group in grouped" :key="group.title" class="mb-6 last:mb-0" :aria-label="group.title">
          <h3 class="mb-3 flex items-center gap-2 text-xs font-semibold tracking-wide text-vz-muted uppercase">
            <i class="ri-stack-line" aria-hidden="true" /> {{ group.title }} <span class="font-normal">({{ group.spaces.length }})</span>
          </h3>
          <ul class="grid grid-cols-[repeat(auto-fill,minmax(84px,1fr))] gap-2.5">
            <li v-for="space in group.spaces" :key="space.id">
              <button
                class="flex h-[78px] w-full flex-col items-center justify-center gap-1 rounded-vz border border-dashed transition-colors"
                :class="tileSurface[spaceStatus[space.status].tone]"
                type="button"
                :aria-label="`Espacio ${space.code}, ${spaceType[space.type].label}, ${spaceStatus[space.status].label}`"
                @click="openSpace(space)"
              >
                <i class="text-xl" :class="space.status === 'occupied' ? 'ri-car-fill' : spaceType[space.type].icon" aria-hidden="true" />
                <span class="text-[13px] font-semibold">{{ space.code }}</span>
              </button>
            </li>
          </ul>
        </section>
      </template>

      <div v-else class="overflow-x-auto rounded-vz border border-vz-border">
        <table class="vz-table">
          <thead>
            <tr>
              <th scope="col">Código</th>
              <th scope="col">Tipo</th>
              <th scope="col">Zona</th>
              <th scope="col">Piso</th>
              <th scope="col">Estado</th>
              <th scope="col">Opciones</th>
              <th scope="col"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="space in spaces" :key="space.id">
              <td class="font-semibold text-vz-heading">{{ space.code }}</td>
              <td><i :class="spaceType[space.type].icon" class="me-1 text-vz-muted" aria-hidden="true" />{{ spaceType[space.type].label }}</td>
              <td>{{ space.zone?.name ?? '—' }}</td>
              <td>{{ space.floor ?? '—' }}</td>
              <td><StatusBadge :label="spaceStatus[space.status].label" :tone="spaceStatus[space.status].tone" /></td>
              <td class="text-xs text-vz-muted">
                <span v-if="space.is_reservable" class="me-2"><i class="ri-calendar-check-line" aria-hidden="true" /> Reservable</span>
                <span v-if="space.is_assignable"><i class="ri-user-star-line" aria-hidden="true" /> Asignable</span>
                <span v-if="!space.is_reservable && !space.is_assignable">—</span>
              </td>
              <td class="text-right">
                <button class="vz-btn vz-btn-light vz-btn-sm" type="button" @click="openSpace(space)">Detalle</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <BaseModal :open="selected !== null" :title="selected ? `Espacio ${selected.code}` : ''" @close="selected = null">
      <div v-if="selected" class="vz-card-body space-y-5">
        <div class="flex items-center gap-4">
          <span class="flex size-14 items-center justify-center rounded-vz border border-dashed text-3xl" :class="tileSurface[spaceStatus[selected.status].tone]" aria-hidden="true">
            <i :class="spaceType[selected.type].icon" />
          </span>
          <div>
            <StatusBadge :label="spaceStatus[selected.status].label" :tone="spaceStatus[selected.status].tone" :icon="spaceStatus[selected.status].icon" />
            <p class="mt-1.5 text-xs text-vz-muted">Último cambio: {{ formatDateTime(selected.status_changed_at, lot.timezone) }}</p>
          </div>
        </div>

        <dl class="grid grid-cols-2 gap-4 rounded-vz bg-vz-elevated/50 p-4 text-[13px]">
          <div><dt class="text-xs text-vz-muted">Tipo</dt><dd>{{ spaceType[selected.type].label }}</dd></div>
          <div><dt class="text-xs text-vz-muted">Zona</dt><dd>{{ selected.zone?.name ?? 'Sin zona' }}</dd></div>
          <div><dt class="text-xs text-vz-muted">Piso</dt><dd>{{ selected.floor ?? '—' }}</dd></div>
          <div>
            <dt class="text-xs text-vz-muted">Uso</dt>
            <dd>{{ [selected.is_reservable ? 'Reservable' : '', selected.is_assignable ? 'Asignable' : ''].filter(Boolean).join(' · ') || 'Rotación' }}</dd>
          </div>
        </dl>

        <div v-if="can('parking.update')">
          <h3 class="mb-2 text-[13px] font-semibold text-vz-heading">Cambiar estado</h3>
          <p v-if="selected.manual_transitions.length === 0" class="rounded-vz border border-vz-info/30 bg-vz-info/10 px-3 py-2.5 text-[13px]" role="status">
            <i class="ri-information-line text-vz-info" aria-hidden="true" /> Este espacio está {{ spaceStatus[selected.status].label.toLowerCase() }}. Su estado cambia con la salida del vehículo o la reserva.
          </p>
          <template v-else>
            <label class="vz-label" for="status-reason">Motivo <span class="text-vz-muted">(opcional, queda en auditoría)</span></label>
            <input id="status-reason" v-model="reason" class="vz-input" type="text" maxlength="200" placeholder="Ej.: derrame de aceite, señalización" />
            <div class="mt-3 flex flex-wrap gap-2">
              <button
                v-for="target in selected.manual_transitions"
                :key="target"
                class="vz-btn vz-btn-light"
                type="button"
                :disabled="statusBusy !== null"
                @click="onChangeStatus(target)"
              >
                <i :class="statusBusy === target ? 'ri-loader-4-line animate-spin' : spaceStatus[target].icon" aria-hidden="true" />
                {{ spaceStatus[target].label }}
              </button>
            </div>
          </template>
        </div>
      </div>
      <div v-if="selected" class="flex flex-wrap justify-between gap-2 border-t border-vz-border px-5 py-3">
        <button v-if="can('parking.delete') && selected.removable" class="vz-btn vz-btn-soft-danger" type="button" @click="deleteError = ''; confirmDelete = true">
          <i class="ri-delete-bin-line" aria-hidden="true" /> Retirar
        </button>
        <span v-else />
        <div class="flex gap-2">
          <button class="vz-btn vz-btn-light" type="button" @click="selected = null">Cerrar</button>
          <button v-if="can('parking.update')" class="vz-btn vz-btn-primary" type="button" @click="openEdit(selected)">
            <i class="ri-edit-2-line" aria-hidden="true" /> Editar
          </button>
        </div>
      </div>
    </BaseModal>

    <ConfirmDialog
      :open="confirmDelete"
      title="Retirar espacio"
      :message="`¿Retirar el espacio ${selected?.code ?? ''}? Esta acción no se puede deshacer.`"
      confirm-label="Retirar"
      :busy="deleting"
      :error="deleteError"
      @close="confirmDelete = false"
      @confirm="onDelete"
    />

    <BaseModal :open="editor !== null" :title="editor?.mode === 'create' ? 'Nuevo espacio' : 'Editar espacio'" @close="editor = null">
      <form novalidate @submit.prevent="onSaveSpace">
        <div class="vz-card-body grid gap-4 sm:grid-cols-2">
          <div>
            <label class="vz-label" for="space-code">Código</label>
            <input id="space-code" v-model="spaceForm.code" class="vz-input uppercase" type="text" maxlength="40" required autofocus :aria-invalid="!!spaceErrors.fields.value.code" />
            <p v-if="spaceErrors.fields.value.code" class="vz-error" role="alert">{{ spaceErrors.fields.value.code }}</p>
          </div>
          <div>
            <label class="vz-label" for="space-form-type">Tipo</label>
            <select id="space-form-type" v-model="spaceForm.type" class="vz-input">
              <option v-for="type in spaceTypes" :key="type" :value="type">{{ spaceType[type].label }}</option>
            </select>
          </div>
          <div>
            <label class="vz-label" for="space-form-zone">Zona</label>
            <select id="space-form-zone" v-model="spaceForm.zone_id" class="vz-input" :aria-invalid="!!spaceErrors.fields.value.zone_id">
              <option :value="null">Sin zona</option>
              <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option>
            </select>
            <p v-if="spaceErrors.fields.value.zone_id" class="vz-error" role="alert">{{ spaceErrors.fields.value.zone_id }}</p>
          </div>
          <div>
            <label class="vz-label" for="space-form-floor">Piso / nivel</label>
            <input id="space-form-floor" v-model="spaceForm.floor" class="vz-input" type="text" maxlength="20" placeholder="Sótano 1" />
          </div>
          <label class="flex items-center gap-2 text-[13px]"><input v-model="spaceForm.is_reservable" class="size-4 accent-vz-primary" type="checkbox" /> Disponible para reservas</label>
          <label class="flex items-center gap-2 text-[13px]"><input v-model="spaceForm.is_assignable" class="size-4 accent-vz-primary" type="checkbox" /> Asignable a mensualidades</label>
          <p v-if="spaceErrors.general.value" class="text-[13px] text-vz-danger sm:col-span-2" role="alert">{{ spaceErrors.general.value }}</p>
        </div>
        <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
          <button class="vz-btn vz-btn-light" type="button" @click="editor = null">Cancelar</button>
          <button class="vz-btn vz-btn-primary" type="submit" :disabled="savingSpace">{{ savingSpace ? 'Guardando…' : 'Guardar' }}</button>
        </div>
      </form>
    </BaseModal>

    <BaseModal :open="bulkOpen" title="Crear espacios en bloque" @close="bulkOpen = false">
      <form novalidate @submit.prevent="onBulk">
        <div class="vz-card-body grid gap-4 sm:grid-cols-4">
          <div class="sm:col-span-2">
            <label class="vz-label" for="bulk-prefix">Prefijo</label>
            <input id="bulk-prefix" v-model="bulkForm.prefix" class="vz-input uppercase" type="text" maxlength="20" autofocus :aria-invalid="!!bulkErrors.fields.value.prefix" />
          </div>
          <div>
            <label class="vz-label" for="bulk-from">Desde</label>
            <input id="bulk-from" v-model.number="bulkForm.from" class="vz-input" type="number" min="0" required :aria-invalid="!!bulkErrors.fields.value.from" />
          </div>
          <div>
            <label class="vz-label" for="bulk-to">Hasta</label>
            <input id="bulk-to" v-model.number="bulkForm.to" class="vz-input" type="number" min="0" required :aria-invalid="!!bulkErrors.fields.value.to" />
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="bulk-padding">Dígitos</label>
            <select id="bulk-padding" v-model.number="bulkForm.padding" class="vz-input">
              <option :value="0">Sin ceros (1, 2, 3)</option>
              <option :value="2">2 dígitos (01, 02)</option>
              <option :value="3">3 dígitos (001, 002)</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="bulk-type">Tipo</label>
            <select id="bulk-type" v-model="bulkForm.type" class="vz-input">
              <option v-for="type in spaceTypes" :key="type" :value="type">{{ spaceType[type].label }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="bulk-zone">Zona</label>
            <select id="bulk-zone" v-model="bulkForm.zone_id" class="vz-input">
              <option :value="null">Sin zona</option>
              <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="bulk-floor">Piso / nivel</label>
            <input id="bulk-floor" v-model="bulkForm.floor" class="vz-input" type="text" maxlength="20" />
          </div>
          <label class="flex items-center gap-2 text-[13px] sm:col-span-2"><input v-model="bulkForm.is_reservable" class="size-4 accent-vz-primary" type="checkbox" /> Disponibles para reservas</label>
          <label class="flex items-center gap-2 text-[13px] sm:col-span-2"><input v-model="bulkForm.is_assignable" class="size-4 accent-vz-primary" type="checkbox" /> Asignables a mensualidades</label>
          <p v-if="preview" class="rounded-vz bg-vz-primary/15 px-3 py-2 text-[13px] text-vz-primary-text sm:col-span-4" aria-live="polite">
            <i class="ri-eye-line" aria-hidden="true" /> {{ preview }}
          </p>
          <div v-if="bulkErrors.general.value" class="text-[13px] text-vz-danger sm:col-span-4" role="alert">
            <p v-for="(message, field) in bulkErrors.fields.value" :key="field">{{ message }}</p>
            <p v-if="Object.keys(bulkErrors.fields.value).length === 0">{{ bulkErrors.general.value }}</p>
          </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
          <button class="vz-btn vz-btn-light" type="button" @click="bulkOpen = false">Cancelar</button>
          <button class="vz-btn vz-btn-primary" type="submit" :disabled="savingBulk">{{ savingBulk ? 'Creando…' : 'Crear espacios' }}</button>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
