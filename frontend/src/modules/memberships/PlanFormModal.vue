<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { savePlan } from '@/modules/memberships/api'
import { billingPeriod, billingPeriodOrder, planCategory, planCategoryOrder } from '@/modules/memberships/labels'
import type { BillingPeriod, MembershipPlan, PlanCategory } from '@/modules/memberships/types'
import type { ParkingLot, ParkingZone } from '@/modules/parking/types'
import { weekDays } from '@/modules/tariffs/labels'
import { vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import type { VehicleType } from '@/modules/vehicles/types'
import { inputDecimals, toMajorInput, toMinorUnits } from '@/services/money'

const props = defineProps<{
  open: boolean
  plan: MembershipPlan | null
  lots: ParkingLot[]
  zones: ParkingZone[]
  currency: string
}>()

const emit = defineEmits<{ close: []; saved: [plan: MembershipPlan] }>()

interface WindowRow { days: number[]; start: string; end: string }
interface FormState {
  code: string
  name: string
  category: PlanCategory
  billing_period: BillingPeriod
  price: string
  grace_days: number
  max_vehicles: number
  capacity: number | null
  lot_ids: string[]
  zone_ids: string[]
  vehicle_types: VehicleType[]
  windows: WindowRow[]
  description: string
}

const form = ref<FormState>(blank())
const saving = ref(false)
const errors = useFieldErrors()
const isEdit = computed(() => props.plan !== null)
const currency = computed(() => props.plan?.currency ?? props.currency)
// Zones offered belong to the chosen lots (or to every lot when none is chosen).
const offeredZones = computed(() => props.zones.filter((zone) => form.value.lot_ids.length === 0 || form.value.lot_ids.includes(zone.parking_lot_id)))
const lotName = (id: string): string => props.lots.find((lot) => lot.id === id)?.name ?? ''

function blank(): FormState {
  return {
    code: '',
    name: '',
    category: 'monthly',
    billing_period: 'monthly',
    price: '',
    grace_days: 3,
    max_vehicles: 1,
    capacity: null,
    lot_ids: [],
    zone_ids: [],
    vehicle_types: [],
    windows: [],
    description: '',
  }
}

function reset(): void {
  const plan = props.plan
  errors.clear()

  if (!plan) {
    form.value = blank()
    return
  }

  form.value = {
    code: plan.code,
    name: plan.name,
    category: plan.category,
    billing_period: plan.billing_period,
    price: toMajorInput(plan.price_amount, plan.currency),
    grace_days: plan.grace_days,
    max_vehicles: plan.max_vehicles,
    capacity: plan.capacity,
    lot_ids: [...plan.parking_lot_ids],
    zone_ids: [...plan.zone_ids],
    vehicle_types: [...plan.vehicle_types],
    windows: plan.windows.map((window) => ({ days: [...window.days], start: window.start, end: window.end })),
    description: plan.description ?? '',
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

function addWindow(): void {
  form.value.windows.push({ days: [1, 2, 3, 4, 5], start: '06:00', end: '20:00' })
}

async function onSave(): Promise<void> {
  errors.clear()
  const price = toMinorUnits(form.value.price, currency.value)

  if (price === null) {
    errors.fields.value = { price_amount: inputDecimals(currency.value) === 0 ? `Escribe el precio en ${currency.value} sin decimales.` : `Escribe un precio válido en ${currency.value}.` }
    errors.general.value = 'Revisa los campos marcados.'
    return
  }

  if (saving.value) {
    return
  }

  saving.value = true

  try {
    const zones = new Set(offeredZones.value.map((zone) => zone.id))
    emit('saved', await savePlan(props.plan?.id ?? null, {
      code: form.value.code.trim(),
      name: form.value.name.trim(),
      category: form.value.category,
      billing_period: form.value.billing_period,
      price_amount: price,
      currency: currency.value,
      grace_days: Number(form.value.grace_days) || 0,
      max_vehicles: Number(form.value.max_vehicles) || 1,
      capacity: form.value.capacity === null || String(form.value.capacity) === '' ? null : Number(form.value.capacity),
      parking_lot_ids: form.value.lot_ids,
      // A zone of a lot that is no longer chosen is dropped.
      zone_ids: form.value.zone_ids.filter((id) => zones.has(id)),
      vehicle_types: vehicleTypeOrder.filter((type) => form.value.vehicle_types.includes(type)),
      windows: form.value.windows.map((window) => ({ days: [...window.days].sort((a, b) => a - b), start: window.start, end: window.end })),
      description: form.value.description.trim() === '' ? null : form.value.description.trim(),
    }))
  } catch (error) {
    errors.capture(error, 'No fue posible guardar el plan.')
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
  <BaseModal :open="open" :title="isEdit ? `Editar plan ${plan?.code}` : 'Nuevo plan de mensualidad'" size="lg" @close="emit('close')">
    <form novalidate @submit.prevent="onSave">
      <div class="vz-card-body max-h-[70vh] space-y-5 overflow-y-auto">
        <p v-if="isEdit" class="rounded-vz border border-vz-info/30 bg-vz-info/10 px-3 py-2 text-[13px] text-vz-info-text">
          <i class="ri-information-line" aria-hidden="true" />
          El precio y el periodo nuevos aplican a las ventas nuevas; las mensualidades vendidas conservan los suyos. Las reglas de acceso aplican a todas.
        </p>

        <section class="grid gap-4 sm:grid-cols-6">
          <div class="sm:col-span-3">
            <label class="vz-label" for="plan-name">Nombre</label>
            <input id="plan-name" v-model="form.name" class="vz-input" type="text" maxlength="80" required placeholder="Mensualidad carro diurna" :aria-invalid="!!errors.fields.value.name" />
            <p v-if="errors.fields.value.name" class="vz-error" role="alert">{{ errors.fields.value.name }}</p>
          </div>
          <div class="sm:col-span-3">
            <label class="vz-label" for="plan-code">Código</label>
            <input id="plan-code" v-model="form.code" class="vz-input font-mono uppercase" type="text" maxlength="40" required placeholder="CARRO-DIA" :aria-invalid="!!errors.fields.value.code" />
            <p v-if="errors.fields.value.code" class="vz-error" role="alert">{{ errors.fields.value.code }}</p>
          </div>
          <div class="sm:col-span-3">
            <label class="vz-label" for="plan-category">Tipo de plan</label>
            <select id="plan-category" v-model="form.category" class="vz-input">
              <option v-for="category in planCategoryOrder" :key="category" :value="category">{{ planCategory[category].label }}</option>
            </select>
          </div>
          <div class="sm:col-span-3">
            <label class="vz-label" for="plan-period">Periodo de cobro</label>
            <select id="plan-period" v-model="form.billing_period" class="vz-input">
              <option v-for="period in billingPeriodOrder" :key="period" :value="period">{{ billingPeriod[period].label }}</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="plan-price">Precio por {{ billingPeriod[form.billing_period].per }} ({{ currency }})</label>
            <input id="plan-price" v-model="form.price" class="vz-input" type="text" inputmode="decimal" required placeholder="120000" :aria-invalid="!!errors.fields.value.price_amount" />
            <p v-if="errors.fields.value.price_amount" class="vz-error" role="alert">{{ errors.fields.value.price_amount }}</p>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="plan-grace">Días de gracia</label>
            <input id="plan-grace" v-model.number="form.grace_days" class="vz-input" type="number" min="0" max="31" aria-describedby="plan-grace-help" :aria-invalid="!!errors.fields.value.grace_days" />
            <p v-if="errors.fields.value.grace_days" class="vz-error" role="alert">{{ errors.fields.value.grace_days }}</p>
            <p v-else id="plan-grace-help" class="vz-help">Siguen entrando cubiertos tras el fin del periodo.</p>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="plan-vehicles">Vehículos por mensualidad</label>
            <input id="plan-vehicles" v-model.number="form.max_vehicles" class="vz-input" type="number" min="1" max="500" aria-describedby="plan-vehicles-help" :aria-invalid="!!errors.fields.value.max_vehicles" />
            <p v-if="errors.fields.value.max_vehicles" class="vz-error" role="alert">{{ errors.fields.value.max_vehicles }}</p>
            <p v-else id="plan-vehicles-help" class="vz-help">Más de uno para planes corporativos o familiares.</p>
          </div>
          <div class="sm:col-span-2">
            <label class="vz-label" for="plan-capacity">Cupos a la venta <span class="font-normal text-vz-muted">(opcional)</span></label>
            <input id="plan-capacity" v-model.number="form.capacity" class="vz-input" type="number" min="1" placeholder="Sin límite" :aria-invalid="!!errors.fields.value.capacity" />
            <p v-if="errors.fields.value.capacity" class="vz-error" role="alert">{{ errors.fields.value.capacity }}</p>
          </div>
        </section>

        <fieldset class="space-y-3">
          <legend class="vz-label">¿Dónde y cuándo cubre?</legend>

          <div>
            <p class="text-[13px] text-vz-heading">Parqueaderos</p>
            <div class="mt-1 flex flex-wrap gap-2" role="group" aria-label="Parqueaderos del plan">
              <button
                v-for="lot in lots"
                :key="lot.id"
                type="button"
                class="vz-btn vz-btn-sm"
                :class="form.lot_ids.includes(lot.id) ? 'vz-btn-primary' : 'vz-btn-light'"
                :aria-pressed="form.lot_ids.includes(lot.id)"
                @click="toggle(form.lot_ids, lot.id)"
              >
                <i class="ri-parking-box-line" aria-hidden="true" /> {{ lot.name }}
              </button>
            </div>
            <p class="vz-help">{{ form.lot_ids.length === 0 ? 'Sin selección cubre todos los parqueaderos.' : 'Solo cubre los parqueaderos marcados.' }}</p>
          </div>

          <div v-if="offeredZones.length > 0">
            <p class="text-[13px] text-vz-heading">Zonas</p>
            <div class="mt-1 flex flex-wrap gap-2" role="group" aria-label="Zonas del plan">
              <button
                v-for="zone in offeredZones"
                :key="zone.id"
                type="button"
                class="vz-btn vz-btn-sm"
                :class="form.zone_ids.includes(zone.id) ? 'vz-btn-primary' : 'vz-btn-light'"
                :aria-pressed="form.zone_ids.includes(zone.id)"
                @click="toggle(form.zone_ids, zone.id)"
              >
                {{ zone.name }}<span v-if="form.lot_ids.length !== 1" class="text-xs opacity-70"> · {{ lotName(zone.parking_lot_id) }}</span>
              </button>
            </div>
            <p class="vz-help">{{ form.zone_ids.length === 0 ? 'Sin selección cubre cualquier zona.' : 'El vehículo debe quedar en una de estas zonas; si no, paga como visitante.' }}</p>
          </div>

          <div>
            <p class="text-[13px] text-vz-heading">Tipos de vehículo</p>
            <div class="mt-1 flex flex-wrap gap-2" role="group" aria-label="Tipos de vehículo del plan">
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
            <p class="vz-help">{{ form.vehicle_types.length === 0 ? 'Sin selección cubre todos los vehículos.' : 'Solo cubre los tipos marcados.' }}</p>
          </div>

          <div>
            <div class="flex items-center justify-between gap-2">
              <p class="text-[13px] text-vz-heading">Horario de acceso <span class="text-vz-muted">(opcional)</span></p>
              <button class="vz-btn vz-btn-sm vz-btn-soft-primary" type="button" :disabled="form.windows.length >= 14" @click="addWindow"><i class="ri-add-line" aria-hidden="true" /> Agregar franja</button>
            </div>
            <p class="vz-help">La hora de llegada decide: quien llega fuera de las franjas paga como visitante. Sin franjas cubre a cualquier hora.</p>
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
                <label class="sr-only" :for="`plan-window-start-${index}`">Desde</label>
                <input :id="`plan-window-start-${index}`" v-model="window.start" class="vz-input w-28" type="time" />
                <span class="text-vz-muted">a</span>
                <label class="sr-only" :for="`plan-window-end-${index}`">Hasta</label>
                <input :id="`plan-window-end-${index}`" v-model="window.end" class="vz-input w-28" type="time" />
                <button class="vz-btn-icon ml-auto size-8 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger" type="button" :aria-label="`Quitar franja ${index + 1}`" @click="form.windows.splice(index, 1)">
                  <i class="ri-close-line" aria-hidden="true" />
                </button>
                <p v-if="errors.fields.value[`windows.${index}.days`]" class="vz-error basis-full" role="alert">{{ errors.fields.value[`windows.${index}.days`] }}</p>
              </li>
            </ul>
          </div>
        </fieldset>

        <div>
          <label class="vz-label" for="plan-description">Descripción <span class="font-normal text-vz-muted">(opcional)</span></label>
          <input id="plan-description" v-model="form.description" class="vz-input" type="text" maxlength="500" placeholder="Lunes a sábado, un carro, zona cubierta" />
        </div>

        <p v-if="errors.general.value" class="text-[13px] text-vz-danger" role="alert">{{ errors.general.value }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : isEdit ? 'Guardar plan' : 'Crear plan' }}</button>
      </div>
    </form>
  </BaseModal>
</template>
