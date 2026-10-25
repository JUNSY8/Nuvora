<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { usePermissions } from '@/composables/usePermissions'
import {
  addMembershipVehicle,
  cancelMembership,
  getMembership,
  reactivateMembership,
  removeMembershipVehicle,
  renewMembership,
  setAutoRenew,
  suspendMembership,
} from '@/modules/memberships/api'
import { billingPeriod, coverageHint, describePrice, membershipStatus, periodOrigin, periodStatus } from '@/modules/memberships/labels'
import type { Membership, MembershipPeriod, MembershipPlan } from '@/modules/memberships/types'
import CollectPaymentModal from '@/modules/payments/CollectPaymentModal.vue'
import type { PayableSummary, Payment } from '@/modules/payments/types'
import PlateChip from '@/modules/vehicles/PlateChip.vue'
import { plateCountries, vehicleType, vehicleTypeOrder } from '@/modules/vehicles/labels'
import type { VehicleType } from '@/modules/vehicles/types'
import { formatDate } from '@/services/format'
import { parseApiError } from '@/services/http'
import { newIdempotencyKey } from '@/services/idempotency'
import { formatMoney } from '@/services/money'

const props = defineProps<{
  membershipId: string | null
  today: string
  plans: MembershipPlan[]
}>()

const emit = defineEmits<{ close: []; changed: [membership: Membership, message: string] }>()
const { can } = usePermissions()

type Step = 'view' | 'renew' | 'suspend' | 'cancel'

const membership = ref<Membership | null>(null)
const loading = ref(false)
const loadError = ref('')
const step = ref<Step>('view')
const busy = ref(false)
const reason = ref('')
const errors = useFieldErrors()
const vehicle = ref<{ plate: string; country: string; vehicle_type: VehicleType }>({ plate: '', country: 'CO', vehicle_type: 'car' })
const vehicleErrors = useFieldErrors()
const removing = ref<string | null>(null)
const collecting = ref<PayableSummary | null>(null)
// One key per renewal attempt; retries of that attempt reuse it.
let renewKey = newIdempotencyKey()

function collect(period: MembershipPeriod): void {
  const current = membership.value

  if (!current) {
    return
  }

  collecting.value = {
    type: 'membership_period',
    id: period.id,
    title: `${current.code} · ${current.plan.name} · periodo ${period.sequence}`,
    subtitle: `${current.customer?.name ?? 'Cliente'} · ${formatDate(period.starts_on)} a ${formatDate(period.ends_on)}`,
    currency: period.currency,
    balance: period.balance,
  }
}

async function onPaid(payment: Payment): Promise<void> {
  collecting.value = null
  const id = membership.value?.id

  if (!id) {
    return
  }

  membership.value = await getMembership(id)
  emit('changed', membership.value, `Pago ${payment.number} registrado: ${formatMoney(payment.amount, payment.currency)}.`)
}

const manage = computed(() => can('memberships.manage') && membership.value !== null && membership.value.status !== 'cancelled')
const hint = computed(() => (membership.value ? coverageHint(membership.value, props.today) : null))
const maxVehicles = computed(() => props.plans.find((plan) => plan.id === membership.value?.plan.id)?.max_vehicles ?? null)
const roomForVehicles = computed(() => membership.value !== null && (maxVehicles.value === null || membership.value.vehicles.length < maxVehicles.value))
const periods = computed(() => [...(membership.value?.periods ?? [])].sort((a, b) => b.sequence - a.sequence))
// The API renews at most one period ahead of the one in course.
const renewedAhead = computed(() => periods.value[0] !== undefined && periods.value[0].starts_on > props.today)
const title = computed(() => (membership.value ? `Mensualidad ${membership.value.code}` : 'Mensualidad'))

async function load(id: string): Promise<void> {
  loading.value = true
  loadError.value = ''

  try {
    membership.value = await getMembership(id)
  } catch (error) {
    loadError.value = parseApiError(error, 'No fue posible consultar la mensualidad.')
  } finally {
    loading.value = false
  }
}

function open(next: Step): void {
  reason.value = ''
  renewKey = newIdempotencyKey()
  errors.clear()
  step.value = next
}

// Runs one change and keeps the dialog open on the updated membership.
async function run(action: () => Promise<Membership>, message: (updated: Membership) => string, fallback: string): Promise<boolean> {
  if (busy.value) {
    return false
  }

  busy.value = true
  errors.clear()

  try {
    const updated = await action()
    membership.value = updated
    step.value = 'view'
    emit('changed', updated, message(updated))

    return true
  } catch (error) {
    errors.capture(error, fallback)

    return false
  } finally {
    busy.value = false
  }
}

function onRenew(): void {
  const id = membership.value!.id
  void run(() => renewMembership(id, renewKey), (updated) => `Mensualidad ${updated.code} renovada hasta el ${formatDate(updated.current_period_end)}.`, 'No fue posible renovar la mensualidad.')
}

function onSuspend(): void {
  const id = membership.value!.id
  void run(() => suspendMembership(id, reason.value.trim()), (updated) => `Mensualidad ${updated.code} suspendida.`, 'No fue posible suspender la mensualidad.')
}

function onReactivate(): void {
  const id = membership.value!.id
  void run(() => reactivateMembership(id), (updated) => `Mensualidad ${updated.code} reactivada.`, 'No fue posible reactivar la mensualidad.')
}

function onCancel(): void {
  const id = membership.value!.id
  void run(() => cancelMembership(id, reason.value.trim()), (updated) => `Mensualidad ${updated.code} cancelada.`, 'No fue posible cancelar la mensualidad.')
}

function onAutoRenew(event: Event): void {
  const input = event.target as HTMLInputElement
  const id = membership.value!.id
  const value = input.checked
  void run(() => setAutoRenew(id, value), (updated) => (value ? `Renovación automática activada en ${updated.code}.` : `Renovación automática desactivada en ${updated.code}.`), 'No fue posible cambiar la renovación automática.')
    .then((done) => {
      if (!done) {
        input.checked = !value
      }
    })
}

async function onAddVehicle(): Promise<void> {
  if (!membership.value || busy.value) {
    return
  }

  busy.value = true
  vehicleErrors.clear()

  try {
    const updated = await addMembershipVehicle(membership.value.id, { ...vehicle.value, plate: vehicle.value.plate.trim() })
    membership.value = updated
    emit('changed', updated, `Vehículo ${vehicle.value.plate.trim().toUpperCase()} agregado a ${updated.code}.`)
    vehicle.value = { plate: '', country: vehicle.value.country, vehicle_type: vehicle.value.vehicle_type }
  } catch (error) {
    vehicleErrors.capture(error, 'No fue posible agregar el vehículo.')
  } finally {
    busy.value = false
  }
}

async function onRemoveVehicle(vehicleId: string, plate: string): Promise<void> {
  if (!membership.value || busy.value) {
    return
  }

  busy.value = true
  removing.value = vehicleId
  vehicleErrors.clear()

  try {
    const updated = await removeMembershipVehicle(membership.value.id, vehicleId)
    membership.value = updated
    emit('changed', updated, `Vehículo ${plate} retirado de ${updated.code}.`)
  } catch (error) {
    vehicleErrors.capture(error, 'No fue posible retirar el vehículo.')
  } finally {
    busy.value = false
    removing.value = null
  }
}

watch(() => props.membershipId, (id) => {
  membership.value = null
  step.value = 'view'
  errors.clear()
  vehicleErrors.clear()

  if (id) {
    void load(id)
  }
}, { immediate: true })
</script>

<template>
  <BaseModal :open="membershipId !== null" :title="title" size="lg" @close="emit('close')">
    <div v-if="loading" class="flex items-center gap-2 px-5 py-8 text-[13px] text-vz-muted" role="status">
      <i class="ri-loader-4-line animate-spin" aria-hidden="true" /> Cargando…
    </div>
    <p v-else-if="loadError" class="px-5 py-8 text-[13px] text-vz-danger" role="alert">{{ loadError }}</p>

    <template v-else-if="membership">
      <div v-if="step === 'view'" class="vz-card-body max-h-[70vh] space-y-5 overflow-y-auto text-[13px]">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-base font-semibold text-vz-heading">{{ membership.plan.name }}</p>
            <p class="text-vz-muted">
              {{ membership.customer?.name ?? 'Cliente no disponible' }} · {{ describePrice(membership) }}
            </p>
          </div>
          <div class="flex flex-col items-end gap-1">
            <StatusBadge :label="membershipStatus[membership.status].label" :tone="membershipStatus[membership.status].tone" :icon="membershipStatus[membership.status].icon" />
            <StatusBadge v-if="hint" :label="hint.text" :tone="hint.tone" />
          </div>
        </div>

        <p v-if="membership.status_reason" class="rounded-vz border border-vz-warning/30 bg-vz-warning/10 px-3 py-2 text-vz-warning-text">
          <i class="ri-information-line" aria-hidden="true" /> {{ membership.status === 'suspended' ? 'Suspendida' : 'Motivo' }}: {{ membership.status_reason }}
        </p>

        <dl class="grid grid-cols-2 gap-3 rounded-vz bg-vz-elevated/50 p-3 sm:grid-cols-4">
          <div>
            <dt class="text-vz-muted">Periodo actual</dt>
            <dd class="font-medium text-vz-heading">{{ formatDate(membership.current_period_start) }} – {{ formatDate(membership.current_period_end) }}</dd>
          </div>
          <div>
            <dt class="text-vz-muted">Cubre hasta</dt>
            <dd class="font-medium text-vz-heading">{{ formatDate(membership.covered_until) }}</dd>
            <dd v-if="membership.grace_days > 0" class="text-xs text-vz-muted">con {{ membership.grace_days }} día(s) de gracia</dd>
          </div>
          <div>
            <dt class="text-vz-muted">Cliente desde</dt>
            <dd class="font-medium text-vz-heading">{{ formatDate(membership.starts_on) }}</dd>
            <dd v-if="membership.ended_on" class="text-xs text-vz-muted">terminó el {{ formatDate(membership.ended_on) }}</dd>
          </div>
          <div>
            <dt class="text-vz-muted">Espacio</dt>
            <dd class="font-medium text-vz-heading">{{ membership.assigned_space?.code ?? 'Sin espacio fijo' }}</dd>
          </div>
        </dl>

        <label v-if="manage" class="flex items-center gap-2">
          <input class="size-4 accent-vz-primary" type="checkbox" :checked="membership.auto_renew" :disabled="busy" @change="onAutoRenew" />
          Renovar automáticamente cada periodo {{ billingPeriod[membership.billing_period].label.toLowerCase() }}
        </label>
        <p v-else class="text-vz-muted">{{ membership.auto_renew ? 'Se renueva automáticamente.' : 'Sin renovación automática.' }}</p>

        <section>
          <h3 class="mb-2 font-semibold text-vz-heading">Vehículos ({{ membership.vehicles.length }}{{ maxVehicles ? ` de ${maxVehicles}` : '' }})</h3>
          <ul class="flex flex-wrap gap-2">
            <li v-for="item in membership.vehicles" :key="item.id" class="flex items-center gap-1 rounded-vz border border-vz-border py-1 ps-1 pe-1">
              <PlateChip :plate="item.plate" :country="item.country ?? 'CO'" />
              <button
                v-if="manage && membership.vehicles.length > 1"
                class="vz-btn-icon size-7 text-vz-muted hover:bg-vz-danger/15 hover:text-vz-danger"
                type="button"
                :disabled="busy"
                :aria-label="`Retirar ${item.plate} de la mensualidad`"
                @click="onRemoveVehicle(item.id, item.plate)"
              >
                <i :class="removing === item.id ? 'ri-loader-4-line animate-spin' : 'ri-close-line'" aria-hidden="true" />
              </button>
            </li>
          </ul>
          <form v-if="manage && roomForVehicles" class="mt-3 flex flex-wrap items-center gap-2" novalidate @submit.prevent="onAddVehicle">
            <label class="sr-only" for="membership-add-country">País de la placa</label>
            <select id="membership-add-country" v-model="vehicle.country" class="vz-input w-32">
              <option v-for="country in plateCountries" :key="country.code" :value="country.code">{{ country.name }}</option>
            </select>
            <label class="sr-only" for="membership-add-plate">Placa</label>
            <input id="membership-add-plate" v-model="vehicle.plate" class="vz-input w-32 font-mono tracking-widest uppercase" type="text" maxlength="20" autocomplete="off" placeholder="ABC123" />
            <label class="sr-only" for="membership-add-type">Tipo de vehículo</label>
            <select id="membership-add-type" v-model="vehicle.vehicle_type" class="vz-input w-40">
              <option v-for="type in vehicleTypeOrder" :key="type" :value="type">{{ vehicleType[type].label }}</option>
            </select>
            <button class="vz-btn vz-btn-sm vz-btn-soft-primary" type="submit" :disabled="busy || vehicle.plate.trim() === ''"><i class="ri-add-line" aria-hidden="true" /> Agregar</button>
          </form>
          <p v-if="vehicleErrors.fields.value.plate" class="vz-error" role="alert">{{ vehicleErrors.fields.value.plate }}</p>
          <p v-else-if="vehicleErrors.general.value" class="vz-error" role="alert">{{ vehicleErrors.general.value }}</p>
        </section>

        <section>
          <h3 class="mb-2 font-semibold text-vz-heading">Periodos</h3>
          <div class="overflow-x-auto">
            <table class="vz-table">
              <thead>
                <tr>
                  <th scope="col">#</th>
                  <th scope="col">Desde</th>
                  <th scope="col">Hasta</th>
                  <th scope="col">Origen</th>
                  <th scope="col" class="text-right">Valor</th>
                  <th scope="col">Cobro</th>
                  <th scope="col"><span class="sr-only">Acciones</span></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="period in periods" :key="period.sequence">
                  <td class="text-vz-muted">{{ period.sequence }}</td>
                  <td class="whitespace-nowrap">{{ formatDate(period.starts_on) }}</td>
                  <td class="whitespace-nowrap">{{ formatDate(period.ends_on) }}</td>
                  <td>{{ periodOrigin[period.origin] ?? period.origin }}</td>
                  <td class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(period.amount, period.currency) }}</td>
                  <td>
                    <StatusBadge :label="periodStatus[period.status]?.label ?? period.status" :tone="periodStatus[period.status]?.tone ?? 'muted'" />
                    <p v-if="period.paid > 0 && period.balance > 0" class="mt-0.5 text-xs text-vz-muted">Abonado {{ formatMoney(period.paid, period.currency) }}</p>
                  </td>
                  <td class="text-right">
                    <button
                      v-if="can('payments.create') && period.balance > 0"
                      class="vz-btn vz-btn-sm vz-btn-soft-success"
                      type="button"
                      :aria-label="`Cobrar el periodo ${period.sequence}`"
                      @click="collect(period)"
                    >
                      <i class="ri-money-dollar-circle-line" aria-hidden="true" /> Cobrar
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="mt-2 text-xs text-vz-muted">
            <template v-if="(membership.outstanding ?? 0) > 0">Saldo por cobrar: <strong class="text-vz-warning-text">{{ formatMoney(membership.outstanding ?? 0, membership.currency) }}</strong>. La cobertura no depende del pago; los días de gracia son el margen para cobrar.</template>
            <template v-else>Sin saldo pendiente.</template>
          </p>
        </section>

        <p v-if="membership.notes" class="text-vz-muted"><i class="ri-sticky-note-line" aria-hidden="true" /> {{ membership.notes }}</p>
        <p v-if="errors.general.value" class="text-vz-danger" role="alert">{{ errors.general.value }}</p>
      </div>

      <div v-else-if="step === 'renew'" class="space-y-3 px-5 py-5 text-[13px]">
        <p>
          Se abre el siguiente periodo de <strong class="text-vz-heading">{{ membership.code }}</strong> por
          <strong class="text-vz-heading">{{ formatMoney(membership.price_amount, membership.currency) }}</strong>, que queda por cobrar.
        </p>
        <p class="text-vz-muted">
          {{ membership.status === 'expired' ? 'Como está vencida, el nuevo periodo empieza hoy.' : 'Los días ya pagados siguen cubiertos; el periodo nuevo empieza al terminar el actual.' }}
          <template v-if="membership.status === 'suspended'"> Renovar no levanta la suspensión.</template>
        </p>
        <p v-if="errors.general.value" class="vz-error" role="alert">{{ errors.general.value }}</p>
      </div>

      <form v-else id="membership-reason-form" class="space-y-3 px-5 py-5 text-[13px]" novalidate @submit.prevent="step === 'suspend' ? onSuspend() : onCancel()">
        <p v-if="step === 'suspend'">
          Mientras esté suspendida, los vehículos de <strong class="text-vz-heading">{{ membership.code }}</strong> pagan como visitantes. El periodo sigue corriendo.
        </p>
        <p v-else>
          Cancelar <strong class="text-vz-heading">{{ membership.code }}</strong> es definitivo: libera sus vehículos, su espacio y el cupo del plan. La acción queda en la auditoría.
        </p>
        <div>
          <label class="vz-label" for="membership-reason">Motivo</label>
          <textarea id="membership-reason" v-model="reason" class="vz-input min-h-20" maxlength="255" :placeholder="step === 'suspend' ? 'Ej.: pago pendiente del periodo' : 'Ej.: el cliente no renovará el contrato'" :aria-invalid="!!errors.fields.value.reason" />
          <p v-if="errors.fields.value.reason" class="vz-error" role="alert">{{ errors.fields.value.reason }}</p>
          <p v-else-if="errors.general.value" class="vz-error" role="alert">{{ errors.general.value }}</p>
        </div>
      </form>
    </template>

    <div v-if="membership && !loading" class="flex flex-wrap justify-end gap-2 border-t border-vz-border px-5 py-3">
      <template v-if="step === 'view'">
        <template v-if="manage">
          <button v-if="membership.status === 'active'" class="vz-btn vz-btn-soft-warning" type="button" :disabled="busy" @click="open('suspend')">
            <i class="ri-pause-circle-line" aria-hidden="true" /> Suspender
          </button>
          <button v-if="membership.status === 'suspended'" class="vz-btn vz-btn-soft-success" type="button" :disabled="busy" @click="onReactivate">
            <i class="ri-play-circle-line" aria-hidden="true" /> Reactivar
          </button>
          <button class="vz-btn vz-btn-soft-danger" type="button" :disabled="busy || membership.status === 'expired'" @click="open('cancel')">
            <i class="ri-close-circle-line" aria-hidden="true" /> Cancelar
          </button>
          <button
            class="vz-btn vz-btn-primary"
            type="button"
            :disabled="busy || renewedAhead"
            :title="renewedAhead ? `Ya está renovada hasta el ${formatDate(membership.current_period_end)}` : undefined"
            @click="open('renew')"
          >
            <i class="ri-refresh-line" aria-hidden="true" /> Renovar
          </button>
        </template>
        <button v-else class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cerrar</button>
      </template>
      <template v-else>
        <button class="vz-btn vz-btn-light" type="button" :disabled="busy" @click="step = 'view'">Volver</button>
        <button v-if="step === 'renew'" class="vz-btn vz-btn-primary" type="button" :disabled="busy" @click="onRenew">
          <i :class="busy ? 'ri-loader-4-line animate-spin' : 'ri-refresh-line'" aria-hidden="true" /> Renovar un periodo
        </button>
        <button v-else-if="step === 'suspend'" class="vz-btn vz-btn-warning" type="submit" form="membership-reason-form" :disabled="busy || reason.trim().length < 3">
          <i :class="busy ? 'ri-loader-4-line animate-spin' : 'ri-pause-circle-line'" aria-hidden="true" /> Suspender
        </button>
        <button v-else class="vz-btn vz-btn-danger" type="submit" form="membership-reason-form" :disabled="busy || reason.trim().length < 3">
          <i :class="busy ? 'ri-loader-4-line animate-spin' : 'ri-close-circle-line'" aria-hidden="true" /> Cancelar mensualidad
        </button>
      </template>
    </div>
  </BaseModal>

  <CollectPaymentModal :payable="collecting" @close="collecting = null" @paid="onPaid" />
</template>
