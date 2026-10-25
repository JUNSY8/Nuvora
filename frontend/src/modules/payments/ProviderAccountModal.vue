<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'
import { useFieldErrors } from '@/composables/useFieldErrors'
import { saveProviderAccount } from '@/modules/payments/api'
import { credentialLabel } from '@/modules/payments/labels'
import type { ProviderAccount, ProviderAccountInput, ProviderInfo, ProviderKey } from '@/modules/payments/types'

// Credentials are write-only: an account being edited shows their last
// characters as placeholders, and an empty field keeps the stored value.
const props = defineProps<{ open: boolean; account: ProviderAccount | null; providers: ProviderInfo[] }>()
const emit = defineEmits<{ close: []; saved: [account: ProviderAccount] }>()

const form = ref<ProviderAccountInput>({ provider: 'wompi', name: '', environment: 'sandbox', status: 'active', credentials: {} })
const saving = ref(false)
const errors = useFieldErrors()

const provider = computed(() => props.providers.find((item) => item.key === form.value.provider) ?? null)
const editing = computed(() => props.account !== null)

watch(() => props.open, (open) => {
  if (!open) {
    return
  }

  errors.clear()
  const account = props.account
  form.value = account
    ? { provider: account.provider, name: account.name, environment: account.environment, status: account.status, credentials: {} }
    : { provider: (props.providers[0]?.key ?? 'wompi') as ProviderKey, name: '', environment: 'sandbox', status: 'active', credentials: {} }
})

async function onSave(): Promise<void> {
  if (saving.value) {
    return
  }

  saving.value = true
  errors.clear()

  try {
    const credentials: Record<string, string> = {}

    for (const key of provider.value?.credential_keys ?? []) {
      credentials[key] = (form.value.credentials[key] ?? '').trim()
    }

    const saved = await saveProviderAccount(props.account?.id ?? null, { ...form.value, name: form.value.name.trim(), credentials })
    emit('saved', saved)
  } catch (error) {
    errors.capture(error, 'No fue posible guardar la cuenta.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :open="open" :title="editing ? 'Editar pasarela' : 'Conectar pasarela'" size="md" @close="emit('close')">
    <form novalidate @submit.prevent="onSave">
      <div class="space-y-4 px-5 py-5">
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="vz-label" for="provider-key">Pasarela</label>
            <select id="provider-key" v-model="form.provider" class="vz-input" :disabled="editing">
              <option v-for="item in providers" :key="item.key" :value="item.key">{{ item.label }}</option>
            </select>
          </div>
          <div>
            <label class="vz-label" for="provider-name">Nombre</label>
            <input id="provider-name" v-model="form.name" class="vz-input" type="text" maxlength="80" placeholder="Wompi principal" :aria-invalid="!!errors.fields.value.name" />
            <p v-if="errors.fields.value.name" class="vz-error" role="alert">{{ errors.fields.value.name }}</p>
          </div>
          <div>
            <label class="vz-label" for="provider-environment">Ambiente</label>
            <select id="provider-environment" v-model="form.environment" class="vz-input">
              <option value="sandbox">Pruebas (sandbox)</option>
              <option value="production">Producción</option>
            </select>
          </div>
          <div>
            <label class="vz-label" for="provider-status">Estado</label>
            <select id="provider-status" v-model="form.status" class="vz-input">
              <option value="active">Activa: se pueden enviar enlaces</option>
              <option value="inactive">Inactiva</option>
            </select>
          </div>
        </div>

        <fieldset class="space-y-3 rounded-vz border border-vz-border p-3">
          <legend class="px-1 text-[13px] font-semibold text-vz-heading">Credenciales de {{ provider?.label }}</legend>
          <p class="text-xs text-vz-muted">Se guardan cifradas y nunca se vuelven a mostrar completas.<template v-if="editing"> Deja un campo vacío para conservar el valor actual.</template></p>
          <div v-for="key in provider?.credential_keys ?? []" :key="key">
            <label class="vz-label" :for="`credential-${key}`">{{ credentialLabel[key] ?? key }}</label>
            <input
              :id="`credential-${key}`"
              v-model="form.credentials[key]"
              class="vz-input font-mono text-xs"
              :type="key.includes('secret') || key.includes('private') || key === 'api_key' ? 'password' : 'text'"
              autocomplete="off"
              maxlength="255"
              :placeholder="account?.credentials[key] ?? ''"
              :aria-invalid="!!errors.fields.value[`credentials.${key}`]"
            />
            <p v-if="errors.fields.value[`credentials.${key}`]" class="vz-error" role="alert">{{ errors.fields.value[`credentials.${key}`] }}</p>
          </div>
        </fieldset>

        <p v-if="errors.general.value" class="vz-error" role="alert">{{ errors.general.value }}</p>
      </div>

      <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
        <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
        <button class="vz-btn vz-btn-primary" type="submit" :disabled="saving">
          <i :class="saving ? 'ri-loader-4-line animate-spin' : 'ri-save-line'" aria-hidden="true" /> Guardar
        </button>
      </div>
    </form>
  </BaseModal>
</template>
