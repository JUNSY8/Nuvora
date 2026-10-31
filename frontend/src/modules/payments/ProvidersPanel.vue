<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import StateCard from '@/components/ui/StateCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { usePermissions } from '@/composables/usePermissions'
import { listProviderAccounts, providerOptions, reconcileProvider } from '@/modules/payments/api'
import { paymentNumber, paymentStatus, reconciliationIssue } from '@/modules/payments/labels'
import ProviderAccountModal from '@/modules/payments/ProviderAccountModal.vue'
import type { ProviderAccount, ProviderInfo, ProviderReconciliation } from '@/modules/payments/types'
import { formatDateTime, toLocalInput } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { formatMoney } from '@/services/money'
import { useToastStore } from '@/stores/toast'

// The tenant's provider accounts (payments.configure) and the check of
// online payments against each provider (payments.reconcile).
const props = defineProps<{ timezone: string }>()
const emit = defineEmits<{ reconciled: [] }>()
const { can } = usePermissions()
const toasts = useToastStore()

const state = ref<'loading' | 'ready' | 'error'>('loading')
const errorMessage = ref('')
const accounts = ref<Array<Pick<ProviderAccount, 'id' | 'name' | 'provider' | 'environment' | 'status'> & Partial<ProviderAccount>>>([])
const providers = ref<ProviderInfo[]>([])
const editing = ref<ProviderAccount | null>(null)
const formOpen = ref(false)
const copied = ref('')

const today = computed(() => toLocalInput(new Date(), props.timezone).slice(0, 10))
const reconcileAccount = ref('')
const fromDate = ref('')
const toDate = ref('')
const checking = ref(false)
const result = ref<ProviderReconciliation | null>(null)
const checkError = ref('')

async function load(): Promise<void> {
  state.value = 'loading'

  try {
    if (can('payments.configure')) {
      const data = await listProviderAccounts()
      accounts.value = data.items
      providers.value = data.providers
    } else {
      // Reconciling needs the accounts' names only.
      accounts.value = (await providerOptions()).map((option) => ({ id: option.id, name: option.name, provider: option.provider, environment: option.sandbox ? 'sandbox' : 'production', status: 'active' }))
    }

    reconcileAccount.value = accounts.value[0]?.id ?? ''
    state.value = 'ready'
  } catch (error) {
    errorMessage.value = apiErrorCode(error) === 'FORBIDDEN' ? 'Tu rol no permite ver las pasarelas.' : parseApiError(error, 'No fue posible consultar las pasarelas.')
    state.value = 'error'
  }
}

function open(account: ProviderAccount | null): void {
  editing.value = account
  formOpen.value = true
}

function onSaved(account: ProviderAccount): void {
  formOpen.value = false
  toasts.success(editing.value ? `Cuenta ${account.name} guardada.` : `Cuenta ${account.name} conectada. Registra la URL de notificaciones en la pasarela.`)
  void load()
}

async function copy(text: string, key: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = key
  } catch {
    copied.value = ''
  }
}

async function onReconcile(): Promise<void> {
  if (checking.value || reconcileAccount.value === '') {
    return
  }

  checking.value = true
  checkError.value = ''

  try {
    result.value = await reconcileProvider(reconcileAccount.value, fromDate.value || today.value, toDate.value || today.value)
    emit('reconciled')
  } catch (error) {
    checkError.value = parseApiError(error, 'No fue posible verificar con la pasarela.')
  } finally {
    checking.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-5 p-4">
    <StateCard v-if="state === 'loading'" variant="loading" compact />
    <StateCard v-else-if="state === 'error'" variant="error" :message="errorMessage" compact>
      <button class="vz-btn vz-btn-primary" type="button" @click="load">Reintentar</button>
    </StateCard>

    <template v-else>
      <section v-if="can('payments.configure')" aria-labelledby="providers-title">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h3 id="providers-title" class="text-[14px] font-semibold text-vz-heading">Cuentas conectadas</h3>
          <button class="vz-btn vz-btn-primary vz-btn-sm" type="button" @click="open(null)"><i class="ri-add-line" aria-hidden="true" /> Conectar pasarela</button>
        </div>
        <StateCard v-if="accounts.length === 0" variant="empty" title="Sin pasarelas" message="Conecta una cuenta de Wompi o PayU para enviar enlaces de pago con tarjeta, PSE o Nequi." compact />
        <ul v-else class="grid gap-4 lg:grid-cols-2">
          <li v-for="account in accounts" :key="account.id" class="space-y-3 rounded-vz border border-vz-border p-4" :class="account.status === 'inactive' ? 'opacity-70' : ''">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate font-semibold text-vz-heading">{{ account.name }}</p>
                <p class="text-xs text-vz-muted">{{ providers.find((item) => item.key === account.provider)?.label ?? account.provider }} · {{ account.environment === 'sandbox' ? 'Pruebas' : 'Producción' }}</p>
              </div>
              <StatusBadge :label="account.status === 'active' ? 'Activa' : 'Inactiva'" :tone="account.status === 'active' ? 'success' : 'muted'" />
            </div>
            <div v-if="account.notification_url">
              <p class="vz-label">URL de notificaciones (webhook)</p>
              <div class="flex gap-2">
                <input class="vz-input font-mono text-xs" :value="account.notification_url" readonly :aria-label="`URL de notificaciones de ${account.name}`" />
                <button class="vz-btn vz-btn-light shrink-0" type="button" @click="copy(account.notification_url ?? '', account.id)">
                  <i :class="copied === account.id ? 'ri-check-line' : 'ri-file-copy-line'" aria-hidden="true" /> {{ copied === account.id ? 'Copiada' : 'Copiar' }}
                </button>
              </div>
              <p class="vz-help">Regístrala en el panel de la pasarela para que confirme los pagos al instante.</p>
            </div>
            <div class="flex items-center justify-between gap-2">
              <p v-if="account.updated_at" class="text-xs text-vz-muted">Actualizada {{ formatDateTime(account.updated_at, timezone) }}</p>
              <button class="vz-btn vz-btn-sm vz-btn-soft-primary ms-auto" type="button" @click="open(account as ProviderAccount)"><i class="ri-edit-2-line" aria-hidden="true" /> Editar</button>
            </div>
          </li>
        </ul>
      </section>

      <section v-if="can('payments.reconcile') && accounts.length > 0" class="rounded-vz border border-vz-border" aria-labelledby="provider-check-title">
        <header class="border-b border-vz-border px-4 py-3">
          <h3 id="provider-check-title" class="text-[14px] font-semibold text-vz-heading">Verificar con la pasarela</h3>
          <p class="text-xs text-vz-muted">Compara los pagos en línea con la pasarela. Lo que se puede resolver solo (un pago aprobado sin notificar, un vencido) se aplica; el resto queda listado.</p>
        </header>
        <form class="grid gap-3 p-4 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end" novalidate @submit.prevent="onReconcile">
          <div>
            <label class="vz-label" for="check-account">Cuenta</label>
            <select id="check-account" v-model="reconcileAccount" class="vz-input">
              <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }}</option>
            </select>
          </div>
          <div>
            <label class="vz-label" for="check-from">Desde</label>
            <input id="check-from" v-model="fromDate" class="vz-input" type="date" :max="toDate || today" :placeholder="today" />
          </div>
          <div>
            <label class="vz-label" for="check-to">Hasta</label>
            <input id="check-to" v-model="toDate" class="vz-input" type="date" :min="fromDate || undefined" :max="today" />
          </div>
          <button class="vz-btn vz-btn-primary" type="submit" :disabled="checking">
            <i :class="checking ? 'ri-loader-4-line animate-spin' : 'ri-search-eye-line'" aria-hidden="true" /> Verificar
          </button>
        </form>
        <p v-if="checkError" class="vz-error px-4 pb-4" role="alert">{{ checkError }}</p>

        <div v-if="result" class="border-t border-vz-border">
          <p class="px-4 py-3 text-[13px] text-vz-text" role="status">
            {{ result.checked }} pago(s) revisado(s): {{ result.fixed }} actualizado(s) y {{ result.issues }} por revisar.<template v-if="result.truncated"> Hay más pagos en el rango; acórtalo para revisarlos todos.</template>
          </p>
          <div v-if="result.items.some((item) => item.issue || item.fixed)" class="overflow-x-auto">
            <table class="vz-table">
              <thead>
                <tr>
                  <th scope="col">Pago</th>
                  <th scope="col" class="text-right">Valor</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Resultado</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in result.items.filter((row) => row.issue || row.fixed)" :key="item.payment_id">
                  <td>
                    <code class="text-xs">{{ paymentNumber(item) }}</code>
                    <p class="text-xs text-vz-muted">{{ item.description }}</p>
                  </td>
                  <td class="text-right whitespace-nowrap">{{ formatMoney(item.amount, item.currency) }}</td>
                  <td><StatusBadge :label="paymentStatus[item.status].label" :tone="paymentStatus[item.status].tone" /></td>
                  <td class="text-[13px]">
                    <span v-if="item.issue" class="text-vz-danger-text">{{ reconciliationIssue[item.issue] }}</span>
                    <span v-else class="text-vz-success-text">Actualizado: antes {{ paymentStatus[item.status_before].label.toLowerCase() }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </template>

    <ProviderAccountModal :open="formOpen" :account="editing" :providers="providers" @close="formOpen = false" @saved="onSaved" />
  </div>
</template>
