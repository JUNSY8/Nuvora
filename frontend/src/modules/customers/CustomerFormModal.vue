<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { saveCustomer } from '@/modules/customers/api'
import CustomerPicker from '@/modules/customers/CustomerPicker.vue'
import { customerType, customerTypeOrder, documentTypes } from '@/modules/customers/labels'
import type { Customer, CustomerInput, CustomerSummary } from '@/modules/customers/types'

const props = withDefaults(defineProps<{
  open: boolean
  customer?: Customer | null
}>(), {
  customer: null,
})

const emit = defineEmits<{ close: []; saved: [customer: Customer] }>()

const form = ref<CustomerInput>(blank())
const business = ref<CustomerSummary | null>(null)
const saving = ref(false)
const errors = useFieldErrors()

const isEdit = computed(() => props.customer !== null)
const isOrganization = computed(() => form.value.type !== 'individual')

function blank(): CustomerInput {
  return { type: 'individual', name: '', document_type: 'CC', document_number: null, email: null, phone: null, business_id: null, notes: null }
}

function reset(): void {
  const customer = props.customer
  form.value = customer
    ? {
        type: customer.type,
        name: customer.name,
        document_type: customer.document_type,
        document_number: customer.document_number,
        email: customer.email,
        phone: customer.phone,
        business_id: customer.business?.id ?? null,
        notes: customer.notes,
      }
    : blank()
  business.value = customer?.business ? { id: customer.business.id, name: customer.business.name, type: 'business', status: 'active' } : null
  errors.clear()
}

function selectType(type: CustomerInput['type']): void {
  const wasOrganization = isOrganization.value
  form.value.type = type

  // Suggest the usual document for the kind of customer, without overriding a custom one.
  if (!isEdit.value && (form.value.document_type === 'CC' || form.value.document_type === 'NIT')) {
    form.value.document_type = type === 'individual' ? 'CC' : 'NIT'
  }

  if (!wasOrganization && isOrganization.value) {
    business.value = null
  }
}

async function onSave(): Promise<void> {
  saving.value = true
  errors.clear()
  const clean = (value: string | null) => (value === null || value.trim() === '' ? null : value.trim())
  const documentNumber = clean(form.value.document_number)

  try {
    const saved = await saveCustomer(props.customer?.id ?? null, {
      ...form.value,
      document_type: documentNumber === null ? null : clean(form.value.document_type),
      document_number: documentNumber,
      email: clean(form.value.email),
      phone: clean(form.value.phone),
      notes: clean(form.value.notes),
      business_id: isOrganization.value ? null : business.value?.id ?? null,
    })
    emit('saved', saved)
  } catch (error) {
    errors.capture(error, 'No fue posible guardar el cliente.')
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
  <BaseModal :open="open" :title="isEdit ? 'Editar cliente' : 'Nuevo cliente'" size="lg" @close="emit('close')">
    <form novalidate @submit.prevent="onSave">
      <div class="vz-card-body grid gap-4 sm:grid-cols-2">
        <fieldset class="sm:col-span-2">
          <legend class="vz-label">Tipo de cliente</legend>
          <div class="grid gap-2 sm:grid-cols-3">
            <label
              v-for="type in customerTypeOrder"
              :key="type"
              class="flex cursor-pointer items-center gap-3 rounded-vz border p-3 transition-colors"
              :class="form.type === type ? 'border-vz-secondary bg-vz-secondary/10' : 'border-vz-border hover:bg-vz-elevated'"
            >
              <input class="sr-only" type="radio" name="customer-type" :value="type" :checked="form.type === type" @change="selectType(type)" />
              <i :class="customerType[type].icon" class="text-xl" :aria-hidden="true" />
              <span class="text-[13px] font-medium text-vz-heading">{{ customerType[type].label }}</span>
              <i v-if="form.type === type" class="ri-checkbox-circle-fill ms-auto text-vz-secondary" aria-hidden="true" />
            </label>
          </div>
          <p v-if="errors.fields.value.type" class="vz-error" role="alert">{{ errors.fields.value.type }}</p>
        </fieldset>

        <div class="sm:col-span-2">
          <label class="vz-label" for="customer-name">{{ isOrganization ? 'Razón social' : 'Nombre completo' }}</label>
          <input id="customer-name" v-model="form.name" class="vz-input" type="text" maxlength="120" required autofocus :aria-invalid="!!errors.fields.value.name" />
          <p v-if="errors.fields.value.name" class="vz-error" role="alert">{{ errors.fields.value.name }}</p>
        </div>

        <div>
          <label class="vz-label" for="customer-document-type">Tipo de documento</label>
          <input id="customer-document-type" v-model="form.document_type" class="vz-input uppercase" type="text" maxlength="20" list="customer-document-types" :aria-invalid="!!errors.fields.value.document_type" />
          <datalist id="customer-document-types">
            <option v-for="item in documentTypes" :key="item.code" :value="item.code">{{ item.label }}</option>
          </datalist>
          <p v-if="errors.fields.value.document_type" class="vz-error" role="alert">{{ errors.fields.value.document_type }}</p>
        </div>
        <div>
          <label class="vz-label" for="customer-document-number">Número de documento</label>
          <input id="customer-document-number" v-model="form.document_number" class="vz-input" type="text" maxlength="40" :placeholder="isOrganization ? '900.123.456-7' : '1.020.304.050'" :aria-invalid="!!errors.fields.value.document_number" aria-describedby="customer-document-help" />
          <p v-if="errors.fields.value.document_number" class="vz-error" role="alert">{{ errors.fields.value.document_number }}</p>
          <p v-else id="customer-document-help" class="vz-help">Puntos y guiones no cuentan para detectar duplicados.</p>
        </div>

        <div>
          <label class="vz-label" for="customer-email">Correo</label>
          <input id="customer-email" v-model="form.email" class="vz-input" type="email" maxlength="160" autocomplete="off" :aria-invalid="!!errors.fields.value.email" />
          <p v-if="errors.fields.value.email" class="vz-error" role="alert">{{ errors.fields.value.email }}</p>
        </div>
        <div>
          <label class="vz-label" for="customer-phone">Teléfono</label>
          <input id="customer-phone" v-model="form.phone" class="vz-input" type="tel" maxlength="40" autocomplete="off" placeholder="+57 300 000 0000" :aria-invalid="!!errors.fields.value.phone" />
          <p v-if="errors.fields.value.phone" class="vz-error" role="alert">{{ errors.fields.value.phone }}</p>
        </div>

        <div v-if="!isOrganization" class="sm:col-span-2">
          <CustomerPicker v-model="business" label="Empresa a la que pertenece (opcional)" organizations-only :exclude-id="customer?.id ?? null" placeholder="Busca la empresa o corporativo" :invalid="!!errors.fields.value.business_id" />
          <p v-if="errors.fields.value.business_id" class="vz-error" role="alert">{{ errors.fields.value.business_id }}</p>
          <p v-else class="vz-help">Para empleados o socios de un cliente empresarial.</p>
        </div>

        <div class="sm:col-span-2">
          <label class="vz-label" for="customer-notes">Notas internas</label>
          <textarea id="customer-notes" v-model="form.notes" class="vz-input min-h-16" maxlength="1000" rows="2" />
        </div>

        <p class="rounded-vz border border-vz-info/30 bg-vz-info/10 px-3 py-2.5 text-xs sm:col-span-2">
          <i class="ri-shield-user-line text-vz-info" aria-hidden="true" /> Son datos personales: la auditoría registra qué campos cambian, no su contenido.
        </p>
        <p v-if="errors.general.value && Object.keys(errors.fields.value).length === 0" class="text-[13px] text-vz-danger sm:col-span-2" role="alert">{{ errors.general.value }}</p>
      </div>
      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Crear cliente' }}</button>
      </div>
    </form>
  </BaseModal>
</template>
