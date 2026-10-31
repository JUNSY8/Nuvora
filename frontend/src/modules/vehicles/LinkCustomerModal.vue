<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import CustomerPicker from '@/modules/customers/CustomerPicker.vue'
import type { CustomerSummary } from '@/modules/customers/types'
import { linkCustomer } from '@/modules/vehicles/api'
import { relationship, relationshipOrder } from '@/modules/vehicles/labels'
import type { Vehicle, VehicleRelationship } from '@/modules/vehicles/types'
import { apiErrorCode } from '@/services/http'

const props = defineProps<{ open: boolean; vehicle: Vehicle }>()
const emit = defineEmits<{ close: []; linked: [] }>()

const customer = ref<CustomerSummary | null>(null)
const kind = ref<VehicleRelationship>('driver')
const transfer = ref(false)
const saving = ref(false)
const errors = useFieldErrors()

const currentOwner = computed(() => props.vehicle.links.find((link) => link.relationship === 'owner') ?? null)
const needsTransfer = computed(() => kind.value === 'owner' && currentOwner.value !== null && currentOwner.value.customer?.id !== customer.value?.id)

async function onSubmit(): Promise<void> {
  if (!customer.value) {
    errors.fields.value = { customer_id: 'Selecciona el cliente.' }
    return
  }

  saving.value = true
  errors.clear()

  try {
    await linkCustomer(props.vehicle.id, customer.value.id, kind.value, needsTransfer.value && transfer.value)
    emit('linked')
  } catch (error) {
    errors.capture(error, 'No fue posible vincular el cliente.')

    if (apiErrorCode(error) === 'VEHICLE_HAS_OWNER') {
      transfer.value = false
    }
  } finally {
    saving.value = false
  }
}

watch(() => props.open, (open) => {
  if (open) {
    customer.value = null
    kind.value = currentOwner.value ? 'driver' : 'owner'
    transfer.value = false
    errors.clear()
  }
})
</script>

<template>
  <BaseModal :open="open" title="Vincular cliente" @close="emit('close')">
    <form novalidate @submit.prevent="onSubmit">
      <div class="vz-card-body space-y-4">
        <div>
          <CustomerPicker v-model="customer" label="Cliente" :invalid="!!errors.fields.value.customer_id" />
          <p v-if="errors.fields.value.customer_id" class="vz-error" role="alert">{{ errors.fields.value.customer_id }}</p>
        </div>
        <fieldset>
          <legend class="vz-label">Relación con el vehículo</legend>
          <div class="grid gap-2">
            <label
              v-for="key in relationshipOrder"
              :key="key"
              class="flex cursor-pointer items-start gap-3 rounded-vz border p-3 transition-colors"
              :class="kind === key ? 'border-vz-secondary bg-vz-secondary/10' : 'border-vz-border hover:bg-vz-elevated'"
            >
              <input v-model="kind" class="mt-0.5 size-4 accent-vz-secondary" type="radio" name="relationship" :value="key" />
              <span>
                <span class="flex items-center gap-1.5 text-[13px] font-medium text-vz-heading"><i :class="relationship[key].icon" aria-hidden="true" /> {{ relationship[key].label }}</span>
                <span class="block text-xs text-vz-muted">{{ relationship[key].help }}</span>
              </span>
            </label>
          </div>
        </fieldset>
        <label v-if="needsTransfer" class="flex items-start gap-2 rounded-vz border border-vz-warning/40 bg-vz-warning/10 p-3 text-[13px]">
          <input v-model="transfer" class="mt-0.5 size-4 accent-vz-warning" type="checkbox" />
          <span>Transferir la propiedad. <span class="font-medium text-vz-heading">{{ currentOwner?.customer?.name ?? 'El propietario actual' }}</span> dejará de ser propietario; el cambio queda en el historial.</span>
        </label>
        <p v-if="errors.general.value && !errors.fields.value.customer_id" class="text-[13px] text-vz-danger" role="alert">{{ errors.general.value }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving || (needsTransfer && !transfer)">{{ saving ? 'Vinculando…' : 'Vincular' }}</button>
      </div>
    </form>
  </BaseModal>
</template>
