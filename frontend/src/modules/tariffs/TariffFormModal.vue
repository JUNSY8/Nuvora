<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import type { ParkingLot, ParkingZone } from '@/modules/parking/types'
import { saveTariff } from '@/modules/tariffs/api'
import { describeMinutes, weekDays } from '@/modules/tariffs/labels'
import type { Tariff, TariffInput } from '@/modules/tariffs/types'
import { vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import type { VehicleType } from '@/modules/vehicles/types'
import { inputDecimals, toMajorInput, toMinorUnits } from '@/services/money'

const props = defineProps<{
  open: boolean
  lot: ParkingLot
  zones: ParkingZone[]
  tariff: Tariff | null
}>()

const emit = defineEmits<{ close: []; saved: [tariff: Tariff] }>()

interface BracketRow { minutes: number | null; amount: string }
interface WindowRow { days: number[]; start: string; end: string }
interface FormState {
  code: string
  name: string
  priority: number
  vehicle_types: VehicleType[]
  zone_id: string
  windows: WindowRow[]
  lot_grace: boolean
  grace_minutes: number | null
  brackets: BracketRow[]
  step_minutes: number | null
  step_amount: string
  daily_max: string
  change_note: string
}

// Starting points for the usual models; amounts are typed in major units.
const presets: Array<{ key: string; label: string; apply: Partial<FormState> }> = [
  { key: 'hour', label: 'Por hora', apply: { brackets: [], step_minutes: 60, step_amount: '3000', daily_max: '' } },
  { key: 'fraction', label: 'Por fracción de 15 min', apply: { brackets: [], step_minutes: 15, step_amount: '1000', daily_max: '30000' } },
  { key: 'progressive', label: 'Primera hora y adicionales', apply: { brackets: [{ minutes: 60, amount: '5000' }, { minutes: 120, amount: '9000' }], step_minutes: 60, step_amount: '3000', daily_max: '' } },
  { key: 'brackets', label: 'Tramos (15, 30, 60 min)', apply: { brackets: [{ minutes: 15, amount: '2000' }, { minutes: 30, amount: '3000' }, { minutes: 60, amount: '5000' }], step_minutes: 60, step_amount: '4000', daily_max: '' } },
  { key: 'daily', label: 'Día completo', apply: { brackets: [], step_minutes: 1440, step_amount: '20000', daily_max: '' } },
  { key: 'night', label: 'Noche plana', apply: { brackets: [{ minutes: 720, amount: '12000' }], step_minutes: 60, step_amount: '4000', daily_max: '', windows: [{ days: [1, 2, 3, 4, 5, 6, 7], start: '19:00', end: '06:00' }] } },
]

const form = ref<FormState>(blank())
const saving = ref(false)
const errors = useFieldErrors()
const isEdit = computed(() => props.tariff !== null)
const currency = computed(() => props.lot.currency)
const amountStep = computed(() => (inputDecimals(currency.value) === 0 ? '1' : '0.01'))

function blank(): FormState {
  return {
    code: '',
    name: '',
    priority: 0,
    vehicle_types: [],
    zone_id: '',
    windows: [],
    lot_grace: true,
    grace_minutes: null,
    brackets: [],
    step_minutes: 60,
    step_amount: '',
    daily_max: '',
    change_note: '',
  }
}

function reset(): void {
  const tariff = props.tariff
  errors.clear()

  if (!tariff) {
    form.value = blank()
    return
  }

  form.value = {
    code: tariff.code,
    name: tariff.name,
    priority: tariff.priority,
    vehicle_types: [...tariff.vehicle_types],
    zone_id: tariff.zone?.id ?? '',
    windows: tariff.windows.map((window) => ({ days: [...window.days], start: window.start, end: window.end })),
    lot_grace: tariff.grace_minutes === null,
    grace_minutes: tariff.grace_minutes,
    brackets: tariff.brackets.map((bracket) => ({ minutes: bracket.up_to_minutes, amount: toMajorInput(bracket.amount, currency.value) })),
    step_minutes: tariff.step_minutes,
    step_amount: toMajorInput(tariff.step_amount, currency.value),
    daily_max: toMajorInput(tariff.daily_max_amount, currency.value),
    change_note: '',
  }
}

function onPreset(event: Event): void {
  const select = event.target as HTMLSelectElement
  const preset = presets.find((item) => item.key === select.value)
  select.value = ''

  if (preset) {
    form.value = { ...form.value, windows: [], ...structuredClone(preset.apply) }
  }
}

function toggle<T>(list: T[], value: T): void {
  const index = list.indexOf(value)

  if (index === -1) {
    list.push(value)
  } else {
    list.splice(index, 1)
  }
}

function addBracket(): void {
  const last = form.value.brackets.at(-1)
  form.value.brackets.push({ minutes: (last?.minutes ?? 0) + 30, amount: '' })
}

function addWindow(): void {
  form.value.windows.push({ days: [1, 2, 3, 4, 5], start: '19:00', end: '06:00' })
}

// Money is converted here; every pricing rule is validated by the API.
function toInput(): TariffInput | null {
  const local: Record<string, string> = {}
  const money = (value: string, field: string, required: boolean): number | null => {
    if (value.trim() === '' && !required) {
      return null
    }

    const minor = toMinorUnits(value, currency.value)

    if (minor === null) {
      local[field] = inputDecimals(currency.value) === 0 ? `Escribe un valor en ${currency.value} sin decimales.` : `Escribe un valor válido en ${currency.value}.`
    }

    return minor
  }

  const brackets = form.value.brackets.map((row, index) => ({
    up_to_minutes: Number(row.minutes ?? 0),
    amount: money(row.amount, `brackets.${index}.amount`, true) ?? 0,
  }))
  const stepAmount = money(form.value.step_amount, 'step_amount', true)
  const dailyMax = money(form.value.daily_max, 'daily_max_amount', false)

  if (Object.keys(local).length > 0) {
    errors.fields.value = local
    errors.general.value = 'Revisa los campos marcados.'

    return null
  }

  return {
    code: form.value.code.trim(),
    name: form.value.name.trim(),
    priority: Number(form.value.priority) || 0,
    vehicle_types: vehicleTypeOrder.filter((type) => form.value.vehicle_types.includes(type)),
    zone_id: form.value.zone_id === '' ? null : form.value.zone_id,
    windows: form.value.windows.map((window) => ({ days: [...window.days].sort((a, b) => a - b), start: window.start, end: window.end })),
    grace_minutes: form.value.lot_grace ? null : Number(form.value.grace_minutes ?? 0),
    brackets,
    step_minutes: Number(form.value.step_minutes ?? 0),
    step_amount: stepAmount ?? 0,
    daily_max_amount: dailyMax,
    change_note: form.value.change_note.trim() === '' ? null : form.value.change_note.trim(),
  }
}

async function onSave(): Promise<void> {
  errors.clear()
  const input = toInput()

  if (!input || saving.value) {
    return
  }

  saving.value = true

  try {
    emit('saved', await saveTariff(props.tariff?.id ?? null, props.lot.id, input))
  } catch (error) {
    errors.capture(error, 'No fue posible guardar la tarifa.')
  } finally {
    saving.value = false
  }
}

watch(() => props.open, (open) => {
  if (open) {
    reset()
  }
}, { immediate: true })
</script>

<template>
  <BaseModal :open="open" :title="isEdit ? `Editar tarifa ${tariff?.code}` : 'Nueva tarifa'" size="lg" @close="emit('close')">
    <form novalidate @submit.prevent="onSave">
      <div class="vz-card-body max-h-[70vh] space-y-5 overflow-y-auto">
        <p v-if="isEdit" class="rounded-vz border border-vz-info/30 bg-vz-info/10 px-3 py-2 text-[13px] text-vz-info-text">
          <i class="ri-information-line" aria-hidden="true" />
          Guardar publica la versión {{ (tariff?.version ?? 0) + 1 }}. Los vehículos que ya están dentro conservan la versión vigente cuando entraron.
        </p>

        <section class="grid gap-4 sm:grid-cols-6">
          <div class="sm:col-span-3">
            <label class="vz-label" for="tariff-name">Nombre</label>
            <input id="tariff-name" v-model="form.name" class="vz-input" type="text" maxlength="80" required placeholder="Carros por fracción" :aria-invalid="!!errors.fields.value.name" />
            <p v-if="errors.fields.value.name" class="vz-error" role="alert">{{ errors.fields.value.name }}</p>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="tariff-code">Código</label>
            <input id="tariff-code" v-model="form.code" class="vz-input font-mono uppercase" type="text" maxlength="40" required placeholder="GENERAL" :aria-invalid="!!errors.fields.value.code" />
            <p v-if="errors.fields.value.code" class="vz-error" role="alert">{{ errors.fields.value.code }}</p>
          </div>
          <div class="sm:col-span-1">
            <label class="vz-label" for="tariff-priority">Prioridad</label>
            <input id="tariff-priority" v-model.number="form.priority" class="vz-input" type="number" min="-100" max="100" aria-describedby="tariff-priority-help" />
          </div>
          <p id="tariff-priority-help" class="vz-help -mt-2 sm:col-span-6">
            Si varias tarifas aplican, gana la de mayor prioridad; con la misma prioridad gana la más específica (zona, luego franja, luego tipo de vehículo).
          </p>
        </section>

        <fieldset class="space-y-3">
          <legend class="vz-label">¿A quién aplica?</legend>
          <div class="flex flex-wrap gap-2" role="group" aria-label="Tipos de vehículo">
            <button
              v-for="type in vehicleTypeOrder"
              :key="type"
              type="button"
              class="vz-btn vz-btn-sm"
              :class="form.vehicle_types.includes(type) ? 'vz-btn-primary' : 'vz-btn-light'"
              :aria-pressed="form.vehicle_types.includes(type)"
              @click="toggle(form.vehicle_types, type)"
            >
              <i :class="vehicleType[type].icon" aria-hidden="true" /> {{ vehicleType[type].label }}
            </button>
          </div>
          <p class="vz-help">{{ form.vehicle_types.length === 0 ? 'Sin selección aplica a todos los vehículos.' : 'Solo aplica a los tipos marcados.' }}</p>

          <div class="max-w-sm">
            <label class="vz-label" for="tariff-zone">Zona</label>
            <select id="tariff-zone" v-model="form.zone_id" class="vz-input" :aria-invalid="!!errors.fields.value.zone_id">
              <option value="">Todas las zonas</option>
              <option v-for="zone in zones" :key="zone.id" :value="zone.id">{{ zone.name }} ({{ zone.code }})</option>
            </select>
            <p v-if="errors.fields.value.zone_id" class="vz-error" role="alert">{{ errors.fields.value.zone_id }}</p>
          </div>

          <div>
            <div class="flex items-center justify-between gap-2">
              <p class="text-[13px] text-vz-heading">Franjas de entrada <span class="text-vz-muted">(opcional)</span></p>
              <button class="vz-btn vz-btn-sm vz-btn-soft-primary" type="button" @click="addWindow"><i class="ri-add-line" aria-hidden="true" /> Agregar franja</button>
            </div>
            <p class="vz-help">La hora de entrada decide la tarifa: una franja 19:00–06:00 cubre a quien entra de noche aunque salga de día.</p>
            <ul class="mt-2 space-y-2">
              <li v-for="(window, index) in form.windows" :key="index" class="flex flex-wrap items-center gap-2 rounded-vz border border-vz-border p-2">
                <div class="flex gap-1" role="group" :aria-label="`Días de la franja ${index + 1}`">
                  <button
                    v-for="day in weekDays"
                    :key="day.day"
                    type="button"
                    class="size-8 rounded-vz text-xs font-semibold"
                    :class="window.days.includes(day.day) ? 'bg-vz-primary text-white' : 'bg-vz-elevated text-vz-muted'"
                    :aria-pressed="window.days.includes(day.day)"
                    :aria-label="day.name"
                    @click="toggle(window.days, day.day)"
                  >
                    {{ day.short }}
                  </button>
                </div>
                <label class="sr-only" :for="`window-start-${index}`">Desde</label>
                <input :id="`window-start-${index}`" v-model="window.start" class="vz-input w-28" type="time" />
                <span class="text-vz-muted">a</span>
                <label class="sr-only" :for="`window-end-${index}`">Hasta</label>
                <input :id="`window-end-${index}`" v-model="window.end" class="vz-input w-28" type="time" />
                <button class="vz-btn-icon ml-auto size-8 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger" type="button" :aria-label="`Quitar franja ${index + 1}`" @click="form.windows.splice(index, 1)">
                  <i class="ri-close-line" aria-hidden="true" />
                </button>
              </li>
            </ul>
          </div>
        </fieldset>

        <fieldset class="space-y-3">
          <legend class="vz-label">Precio ({{ lot.currency }})</legend>
          <div v-if="!isEdit" class="flex items-center gap-2">
            <label class="text-xs text-vz-muted" for="tariff-preset">Partir de una plantilla</label>
            <select id="tariff-preset" class="vz-input w-auto py-1 text-xs" @change="onPreset">
              <option value="">Elegir…</option>
              <option v-for="preset in presets" :key="preset.key" :value="preset.key">{{ preset.label }}</option>
            </select>
          </div>

          <div>
            <div class="flex items-center justify-between gap-2">
              <p class="text-[13px] text-vz-heading">Tramos acumulados <span class="text-vz-muted">(opcional)</span></p>
              <button class="vz-btn vz-btn-sm vz-btn-soft-primary" type="button" :disabled="form.brackets.length >= 12" @click="addBracket"><i class="ri-add-line" aria-hidden="true" /> Agregar tramo</button>
            </div>
            <p class="vz-help">Precio total de la estancia hasta cierto tiempo. Ej.: hasta 60 min $5.000, hasta 120 min $9.000.</p>
            <ul class="mt-2 space-y-2">
              <li v-for="(bracket, index) in form.brackets" :key="index" class="flex flex-wrap items-center gap-2">
                <span class="text-[13px] text-vz-muted">Hasta</span>
                <label class="sr-only" :for="`bracket-minutes-${index}`">Minutos del tramo {{ index + 1 }}</label>
                <input :id="`bracket-minutes-${index}`" v-model.number="bracket.minutes" class="vz-input w-24" type="number" min="1" inputmode="numeric" />
                <span class="text-[13px] text-vz-muted">min{{ bracket.minutes ? ` (${describeMinutes(bracket.minutes)})` : '' }} →</span>
                <label class="sr-only" :for="`bracket-amount-${index}`">Valor del tramo {{ index + 1 }}</label>
                <input
                  :id="`bracket-amount-${index}`"
                  v-model="bracket.amount"
                  class="vz-input w-32"
                  type="text"
                  inputmode="decimal"
                  placeholder="5000"
                  :aria-invalid="!!errors.fields.value[`brackets.${index}.amount`]"
                />
                <button class="vz-btn-icon size-8 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger" type="button" :aria-label="`Quitar tramo ${index + 1}`" @click="form.brackets.splice(index, 1)">
                  <i class="ri-close-line" aria-hidden="true" />
                </button>
                <p v-if="errors.fields.value[`brackets.${index}.amount`]" class="vz-error basis-full" role="alert">{{ errors.fields.value[`brackets.${index}.amount`] }}</p>
              </li>
            </ul>
          </div>

          <div class="grid gap-4 sm:grid-cols-3">
            <div>
              <label class="vz-label" for="tariff-step-amount">{{ form.brackets.length > 0 ? 'Valor adicional' : 'Valor' }}</label>
              <input id="tariff-step-amount" v-model="form.step_amount" class="vz-input" type="text" inputmode="decimal" placeholder="3000" required :aria-invalid="!!errors.fields.value.step_amount" />
              <p v-if="errors.fields.value.step_amount" class="vz-error" role="alert">{{ errors.fields.value.step_amount }}</p>
            </div>
            <div>
              <label class="vz-label" for="tariff-step-minutes">Por cada (min)</label>
              <input id="tariff-step-minutes" v-model.number="form.step_minutes" class="vz-input" type="number" min="1" max="44640" inputmode="numeric" required :aria-invalid="!!errors.fields.value.step_minutes" />
              <p v-if="errors.fields.value.step_minutes" class="vz-error" role="alert">{{ errors.fields.value.step_minutes }}</p>
              <p v-else-if="form.step_minutes" class="vz-help">Fracción iniciada: {{ describeMinutes(form.step_minutes) }}</p>
            </div>
            <div>
              <label class="vz-label" for="tariff-daily-max">Tope por 24 h <span class="font-normal text-vz-muted">(opcional)</span></label>
              <input id="tariff-daily-max" v-model="form.daily_max" class="vz-input" type="text" inputmode="decimal" placeholder="38000" :aria-invalid="!!errors.fields.value.daily_max_amount" />
              <p v-if="errors.fields.value.daily_max_amount" class="vz-error" role="alert">{{ errors.fields.value.daily_max_amount }}</p>
            </div>
          </div>
          <p class="vz-help">Valores en {{ lot.currency }}{{ amountStep === '1' ? ', sin decimales' : '' }}.</p>
        </fieldset>

        <fieldset class="space-y-2">
          <legend class="vz-label">Tiempo de gracia</legend>
          <label class="flex items-center gap-2 text-[13px]">
            <input v-model="form.lot_grace" class="size-4 accent-vz-primary" type="checkbox" />
            Usar el del parqueadero ({{ lot.grace_period_minutes }} min sin cobro)
          </label>
          <div v-if="!form.lot_grace" class="max-w-40">
            <label class="vz-label" for="tariff-grace">Minutos sin cobro</label>
            <input id="tariff-grace" v-model.number="form.grace_minutes" class="vz-input" type="number" min="0" max="240" :aria-invalid="!!errors.fields.value.grace_minutes" />
            <p v-if="errors.fields.value.grace_minutes" class="vz-error" role="alert">{{ errors.fields.value.grace_minutes }}</p>
          </div>
        </fieldset>

        <div v-if="isEdit">
          <label class="vz-label" for="tariff-note">Motivo del cambio <span class="font-normal text-vz-muted">(queda en el historial)</span></label>
          <input id="tariff-note" v-model="form.change_note" class="vz-input" type="text" maxlength="255" placeholder="Ajuste de precios de enero" />
        </div>

        <p v-if="errors.general.value" class="text-[13px] text-vz-danger" role="alert">{{ errors.general.value }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : isEdit ? 'Publicar versión' : 'Crear tarifa' }}</button>
      </div>
    </form>
  </BaseModal>
</template>
