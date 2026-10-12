<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const token = ref(typeof route.query.token === 'string' ? route.query.token : '')
const password = ref('')
const passwordConfirmation = ref('')
const errorMessage = ref('')
const submitting = ref(false)

async function onSubmit(): Promise<void> {
  errorMessage.value = ''
  submitting.value = true

  try {
    await auth.resetPassword(email.value, token.value, password.value, passwordConfirmation.value)
    await router.push({ name: 'login', query: { restablecida: '1' } })
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'No fue posible restablecer la contraseña.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-vz-body px-4 py-12">
    <div class="w-full max-w-[420px]">
      <p class="mb-5 text-center text-2xl font-semibold text-vz-heading">Nuvora</p>
      <section class="rounded-vz border border-vz-border bg-vz-card p-6 shadow-[0_1px_2px_rgba(56,65,74,0.15)]">
        <header class="text-center">
          <h1 class="text-base font-semibold text-vz-heading">Nueva contraseña</h1>
          <p class="mt-1 text-sm text-vz-muted">El enlace vence en 60 minutos.</p>
        </header>
        <form class="mt-6 space-y-4" @submit.prevent="onSubmit">
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="email">Correo</label>
            <input id="email" v-model="email" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="email" autocomplete="username" required />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="password">Nueva contraseña</label>
            <input id="password" v-model="password" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="password" autocomplete="new-password" required minlength="12" />
            <p class="mt-1 text-sm text-vz-muted">Mínimo 12 caracteres, con mayúsculas, minúsculas y números.</p>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="password_confirmation">Confirmar contraseña</label>
            <input id="password_confirmation" v-model="passwordConfirmation" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="password" autocomplete="new-password" required />
          </div>
          <p v-if="token === ''" class="text-sm text-vz-danger" role="alert">El enlace no incluye un token válido.</p>
          <p v-if="errorMessage" class="text-sm text-vz-danger" role="alert">{{ errorMessage }}</p>
          <button class="w-full rounded-vz bg-vz-primary px-4 py-2 text-sm font-medium text-white disabled:opacity-60" type="submit" :disabled="submitting || token === ''">
            {{ submitting ? 'Guardando…' : 'Guardar contraseña' }}
          </button>
        </form>
      </section>
      <p class="mt-4 text-center text-sm text-vz-muted">
        <RouterLink class="font-medium text-vz-text underline" to="/login">Volver al inicio de sesión</RouterLink>
      </p>
    </div>
  </main>
</template>
