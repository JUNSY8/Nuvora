<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { computed, ref, watch } from 'vue'

import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import { spaceType } from '@/modules/parking/labels'
import type { ParkingGate, ParkingSpace, SpaceType } from '@/modules/parking/types'
import { registerEntry } from '@/modules/sessions/api'
import { entryErrorHints } from '@/modules/sessions/labels'
import type { SessionResult } from '@/modules/sessions/types'
import { checkPlate } from '@/modules/vehicles/api'
import { plateCountries, vehicleStatus, vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import type { PlateCheck, VehicleType } from '@/modules/vehicles/types'
import { apiErrorCode } from '@/services/http'
import { newIdempotencyKey } from '@/services/idempotency'

const props = defineProps<{
  lotId: string
  gates: ParkingGate[]
  spaces: ParkingSpace[]
  disabled: boolean
}>()

const emit = defineEmits<{ entered: [result: SessionResult] }>()
const { can } = usePermissions()

type SpaceMode = 'auto' | 'manual' | 'none'

const plate = ref('')
const country = ref('CO')
const type = ref<VehicleType>('car')
const typeTouched = ref(false)
const spaceMode = ref<SpaceMode>('auto')
const spaceId = ref('')
const gateId = ref('')
const notes = ref('')
const saving = ref(false)
const hint = ref('')
const errors = useFieldErrors()
const plateInput = ref<HTMLInputElement | null>(null)

// One key per attempt: a retry of the same plate reuses it, a new plate gets a new one.
let attemptKey = newIdempotencyKey()

const check = ref<PlateCheck | null>(null)
const debounced = refDebounced(computed(() => `${country.value}|${plate.value}`), 350)
const known = computed(() => check.value?.existing ?? null)
const blocked = computed(() => known.value?.status === 'blocked')
const canCheck = computed(() => can('vehicles.view'))

const spacesByType = computed(() => {
  const groups = new Map<SpaceType, ParkingSpace[]>()

  for (const space of props.spaces) {
    groups.set(space.type, [...(groups.get(space.type) ?? []), space])
  }

  return [...groups.entries()]
})

async function runCheck(): Promise<void> {
  const value = plate.value.trim()

  if (!canCheck.value || value.replace(/[^a-z0-9]/gi, '').length < 2) {
    check.value = null
    return
  }

  try {
    check.value = await checkPlate(value, country.value)

    if (!typeTouched.value && check.value.suggested_type) {
      type.value = check.value.suggested_type
    }
  } catch {
    // The entry request validates the plate again and shows the error.
    check.value = null
  }
}

function reset(): void {
  plate.value = ''
  notes.value = ''
  typeTouched.value = false
  type.value = 'car'
  spaceId.value = ''
  check.value = null
  hint.value = ''
  errors.clear()
  attemptKey = newIdempotencyKey()
  plateInput.value?.focus()
}

async function onSubmit(): Promise<void> {
  if (saving.value || props.disabled) {
    return
  }

  saving.value = true
  hint.value = ''
  errors.clear()

  try {
    const result = await registerEntry({
      lot_id: props.lotId,
      plate: plate.value.trim(),
      country: country.value,
      vehicle_type: known.value ? null : type.value,
      space_id: spaceMode.value === 'manual' && spaceId.value !== '' ? spaceId.value : null,
      auto_assign: spaceMode.value === 'auto',
      gate_id: gateId.value === '' ? null : gateId.value,
      notes: notes.value.trim() === '' ? null : notes.value.trim(),
    }, attemptKey)

    emit('entered', result)
    reset()
  } catch (error) {
    errors.capture(error, 'No fue posible registrar la entrada.')
    hint.value = entryErrorHints[apiErrorCode(error) ?? ''] ?? ''
  } finally {
    saving.value = false
  }
}

watch(debounced, () => void runCheck())
watch([plate, country], () => {
  attemptKey = newIdempotencyKey()
})
watch(() => props.gates, (gates) => {
  if (!gates.some((gate) => gate.id === gateId.value)) {
    gateId.value = gates[0]?.id ?? ''
  }
}, { immediate: true })
watch(() => props.spaces, (spaces) => {
  if (spaceId.value !== '' && !spaces.some((space) => space.id === spaceId.value)) {
    spaceId.value = ''
  }
})
</script>

<template>
  <form class="vz-card" novalidate @submit.prevent="onSubmit">
    <div class="vz-card-header flex items-center gap-2">
      <i class="ri-login-box-line text-lg text-vz-success" aria-hidden="true" />
      <h2 class="vz-card-title">Registrar entrada</h2>
    </div>

    <div class="space-y-4 p-4">
      <div class="grid grid-cols-[1fr_auto] gap-2">
        <div>
          <label class="vz-label" for="entry-plate">Placa</label>
          <input
            id="entry-plate"
            ref="plateInput"
            v-model="plate"
            class="vz-input font-mono text-lg font-bold tracking-[0.15em] uppercase"
            type="text"
            autocomplete="off"
            spellcheck="false"
            maxlength="20"
            placeholder="ABC123"
            :aria-invalid="Boolean(errors.fields.value.plate)"
            :aria-describedby="errors.fields.value.plate ? 'entry-plate-error' : undefined"
            :disabled="disabled"
            autofocus
          />
        </div>
        <div>
          <label class="vz-label" for="entry-country">País</label>
          <select id="entry-country" v-model="country" class="vz-input" :disabled="disabled">
            <option v-for="item in plateCountries" :key="item.code" :value="item.code">{{ item.code }}</option>
          </select>
        </div>
      </div>
      <p v-if="errors.fields.value.plate" id="entry-plate-error" class="vz-error" role="alert">{{ errors.fields.value.plate }}</p>

      <div v-if="check" class="rounded-vz border p-3 text-[13px]" :class="blocked ? 'border-vz-danger/40 bg-vz-danger/10' : 'border-vz-border bg-vz-elevated/40'" aria-live="polite">
        <template v-if="known">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium text-vz-heading">Vehículo registrado</span>
            <StatusBadge :label="vehicleStatus[known.status].label" :tone="vehicleStatus[known.status].tone" :icon="vehicleStatus[known.status].icon" />
          </div>
          <p v-if="blocked" class="mt-1 text-vz-danger-text">No puede ingresar mientras esté bloqueado.</p>
        </template>
        <template v-else>
          <p class="font-medium text-vz-heading">Placa nueva</p>
          <p class="text-vz-muted">Se registrará como visitante{{ check.format ? ` (${check.format.label.toLowerCase()})` : '' }}.</p>
        </template>
      </div>

      <div v-if="!known">
        <label class="vz-label" for="entry-type">Tipo de vehículo</label>
        <select id="entry-type" v-model="type" class="vz-input" :disabled="disabled" @change="typeTouched = true">
          <option v-for="item in vehicleTypeOrder" :key="item" :value="item">{{ vehicleType[item].label }}</option>
        </select>
        <p v-if="errors.fields.value.vehicle_type" class="vz-error" role="alert">{{ errors.fields.value.vehicle_type }}</p>
      </div>

      <fieldset>
        <legend class="vz-label">Espacio</legend>
        <div class="grid grid-cols-3 gap-2">
          <label
            v-for="mode in ([['auto', 'Automático'], ['manual', 'Elegir'], ['none', 'Sin espacio']] as const)"
            :key="mode[0]"
            class="flex cursor-pointer items-center justify-center gap-1.5 rounded-vz border px-2 py-2 text-[13px] transition-colors"
            :class="spaceMode === mode[0] ? 'border-vz-secondary bg-vz-secondary/15 text-vz-heading' : 'border-vz-border text-vz-muted hover:bg-vz-elevated'"
          >
            <input v-model="spaceMode" class="sr-only" type="radio" name="space-mode" :value="mode[0]" :disabled="disabled" />
            {{ mode[1] }}
          </label>
        </div>
        <div v-if="spaceMode === 'manual'" class="mt-2">
          <label class="sr-only" for="entry-space">Espacio disponible</label>
          <select id="entry-space" v-model="spaceId" class="vz-input" :disabled="disabled || spaces.length === 0" required>
            <option value="" disabled>{{ spaces.length === 0 ? 'No hay espacios disponibles' : 'Selecciona un espacio' }}</option>
            <optgroup v-for="[group, items] in spacesByType" :key="group" :label="spaceType[group].label">
              <option v-for="space in items" :key="space.id" :value="space.id">{{ space.code }}{{ space.zone ? ` · ${space.zone.name}` : '' }}</option>
            </optgroup>
          </select>
        </div>
        <p v-else-if="spaceMode === 'none'" class="vz-help mt-2">Para parqueaderos sin espacios numerados o cuando el vehículo aún no tiene lugar.</p>
      </fieldset>

      <div v-if="gates.length > 0">
        <label class="vz-label" for="entry-gate">Puerta</label>
        <select id="entry-gate" v-model="gateId" class="vz-input" :disabled="disabled">
          <option value="">Sin puerta</option>
          <option v-for="gate in gates" :key="gate.id" :value="gate.id">{{ gate.name }} ({{ gate.code }})</option>
        </select>
        <p v-if="errors.fields.value.gate_id" class="vz-error" role="alert">{{ errors.fields.value.gate_id }}</p>
      </div>

      <div>
        <label class="vz-label" for="entry-notes">Observaciones <span class="font-normal text-vz-muted">(opcional)</span></label>
        <input id="entry-notes" v-model="notes" class="vz-input" type="text" maxlength="500" placeholder="Ej.: rayón en la puerta trasera" :disabled="disabled" />
      </div>

      <div v-if="errors.general.value" class="rounded-vz border border-vz-danger/40 bg-vz-danger/10 p-3 text-[13px]" role="alert">
        <p class="text-vz-danger-text">{{ errors.general.value }}</p>
        <p v-if="hint" class="mt-1 text-vz-muted">{{ hint }}</p>
      </div>
    </div>

    <div class="border-t border-vz-border p-4">
      <button class="vz-btn vz-btn-success w-full justify-center" type="submit" :disabled="disabled || saving || plate.trim() === '' || blocked || (spaceMode === 'manual' && spaceId === '')">
        <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-login-box-line'" aria-hidden="true" />
        Registrar entrada
      </button>
    </div>
  </form>
</template>
