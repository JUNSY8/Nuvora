<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import { getPublicCheckout } from '@/modules/payments/api'
import type { PublicCheckout } from '@/modules/payments/types'
import { formatDateTime } from '@/services/format'
import { apiErrorCode, parseApiError } from '@/services/http'
import { formatMoney } from '@/services/money'

// Public page of a payment link: the customer opens it without an account
// and goes on to the provider's hosted checkout. Back from the provider it
// asks for the result for a while, since the confirmation may take seconds.
const props = defineProps<{ token: string }>()
const route = useRoute()

const checkout = ref<PublicCheckout | null>(null)
const error = ref('')
const form = ref<HTMLFormElement | null>(null)
const returning = computed(() => route.query.volver === '1')
let timer: ReturnType<typeof setTimeout> | null = null
let attempts = 0

const outcome = computed(() => {
  switch (checkout.value?.status) {
    case 'completed':
    case 'partially_refunded':
    case 'refunded':
      return { icon: 'ri-checkbox-circle-line', tone: 'text-vz-success', title: 'Pago recibido', text: checkout.value.number ? `Recibo ${checkout.value.number}. Ya puedes cerrar esta página.` : 'Ya puedes cerrar esta página.' }
    case 'failed':
      return { icon: 'ri-close-circle-line', tone: 'text-vz-danger', title: 'El pago no se completó', text: 'La pasarela no aprobó el pago o el enlace se anuló. Pide uno nuevo en el parqueadero.' }
    case 'expired':
      return { icon: 'ri-time-line', tone: 'text-vz-warning', title: 'Enlace vencido', text: 'Este enlace ya no se puede pagar. Pide uno nuevo en el parqueadero.' }
    default:
      return returning.value
        ? { icon: 'ri-loader-4-line animate-spin', tone: 'text-vz-info', title: 'Confirmando el pago…', text: 'La pasarela nos está avisando el resultado. No cierres esta página.' }
        : null
  }
})

async function load(): Promise<void> {
  try {
    checkout.value = await getPublicCheckout(props.token, returning.value)
    error.value = ''

    // Back from the provider and still pending: ask again, for up to about two minutes.
    if (returning.value && checkout.value.status === 'pending' && attempts < 24) {
      attempts++
      timer = setTimeout(() => void load(), 5000)
    }
  } catch (failure) {
    error.value = apiErrorCode(failure) === 'NOT_FOUND' ? 'El enlace de pago no existe.' : parseApiError(failure, 'No fue posible cargar el pago.')
  }
}

function pay(): void {
  const target = checkout.value?.checkout

  if (!target) {
    return
  }

  if (target.method === 'GET') {
    const url = new URL(target.url)

    for (const [key, value] of Object.entries(target.fields)) {
      url.searchParams.set(key, value)
    }

    window.location.assign(url.toString())

    return
  }

  form.value?.submit()
}

onMounted(load)
onBeforeUnmount(() => {
  if (timer) {
    clearTimeout(timer)
  }
})
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-vz-body px-4 py-10">
    <section class="vz-card w-full max-w-md p-6" aria-labelledby="checkout-title">
      <p v-if="error" class="text-center text-vz-danger" role="alert">{{ error }}</p>
      <p v-else-if="!checkout" class="text-center text-vz-muted" role="status"><i class="ri-loader-4-line animate-spin" aria-hidden="true" /> Cargando…</p>

      <template v-else>
        <p class="text-center text-xs font-medium tracking-wide text-vz-muted uppercase">{{ checkout.tenant.name }}</p>
        <h1 id="checkout-title" class="mt-2 text-center text-lg font-semibold text-vz-heading">{{ checkout.description }}</h1>
        <p class="mt-4 text-center text-3xl font-semibold text-vz-heading">{{ formatMoney(checkout.amount, checkout.currency) }}</p>

        <div v-if="outcome" class="mt-6 flex flex-col items-center gap-2 text-center" role="status" aria-live="polite">
          <i :class="[outcome.icon, outcome.tone]" class="text-4xl" aria-hidden="true" />
          <p class="font-semibold text-vz-heading">{{ outcome.title }}</p>
          <p class="text-[13px] text-vz-muted">{{ outcome.text }}</p>
        </div>

        <div v-if="checkout.checkout && !(returning && checkout.status === 'pending')" class="mt-6 space-y-3">
          <button class="vz-btn vz-btn-primary w-full justify-center py-2.5" type="button" @click="pay">
            <i class="ri-secure-payment-line" aria-hidden="true" /> Pagar con {{ checkout.provider }}
          </button>
          <p class="text-center text-xs text-vz-muted">Pagas en la página segura de {{ checkout.provider }} con tarjeta, PSE u otro medio. Nuvora no ve los datos de tu tarjeta.</p>
          <p v-if="checkout.expires_at" class="text-center text-xs text-vz-muted">Válido hasta {{ formatDateTime(checkout.expires_at, 'America/Bogota') }}.</p>
          <form v-if="checkout.checkout.method === 'POST'" ref="form" class="hidden" method="post" :action="checkout.checkout.url">
            <input v-for="(value, key) in checkout.checkout.fields" :key="key" type="hidden" :name="String(key)" :value="value" />
          </form>
        </div>
      </template>
    </section>
  </main>
</template>
