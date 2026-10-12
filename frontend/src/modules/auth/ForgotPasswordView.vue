<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const email = ref('')
const errorMessage = ref('')
const successMessage = ref('')
const submitting = ref(false)

async function onSubmit(): Promise<void> {
  errorMessage.value = ''
  successMessage.value = ''
  submitting.value = true

  try {
    successMessage.value = await auth.forgotPassword(email.value)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'No fue posible enviar el enlace.'
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
          <h1 class="text-base font-semibold text-vz-heading">Recuperar contraseña</h1>
          <p class="mt-1 text-sm text-vz-muted">Te enviaremos un enlace si la cuenta existe.</p>
        </header>
        <form class="mt-6 space-y-4" @submit.prevent="onSubmit">
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="email">Correo</label>
            <input id="email" v-model="email" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="email" autocomplete="username" required :aria-invalid="errorMessage !== ''" />
          </div>
          <p v-if="errorMessage" class="text-sm text-vz-danger" role="alert">{{ errorMessage }}</p>
          <p v-if="successMessage" class="text-sm text-vz-success" role="status">{{ successMessage }}</p>
          <button class="w-full rounded-vz bg-vz-primary px-4 py-2 text-sm font-medium text-white disabled:opacity-60" type="submit" :disabled="submitting">
            {{ submitting ? 'Enviando…' : 'Enviar enlace' }}
          </button>
        </form>
      </section>
      <p class="mt-4 text-center text-sm text-vz-muted">
        <RouterLink class="font-medium text-vz-text underline" to="/login">Volver al inicio de sesión</RouterLink>
      </p>
    </div>
  </main>
</template>
