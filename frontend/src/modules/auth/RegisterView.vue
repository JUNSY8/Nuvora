<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const errorMessage = ref('')
const submitting = ref(false)

async function onSubmit(): Promise<void> {
  errorMessage.value = ''
  submitting.value = true

  try {
    await auth.register(name.value, email.value, password.value, passwordConfirmation.value)
    await router.push({ name: 'dashboard' })
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'No fue posible crear la cuenta.'
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
          <h1 class="text-base font-semibold text-vz-heading">Crear cuenta</h1>
          <p class="mt-1 text-sm text-vz-muted">Después de verificar el correo podrás crear tu empresa.</p>
        </header>

        <form class="mt-6 space-y-4" @submit.prevent="onSubmit">
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="name">Nombre</label>
            <input id="name" v-model="name" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="text" autocomplete="name" required />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="email">Correo</label>
            <input id="email" v-model="email" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="email" autocomplete="email" required />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="password">Contraseña</label>
            <input id="password" v-model="password" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="password" autocomplete="new-password" required minlength="12" />
            <p class="mt-1 text-sm text-vz-muted">Mínimo 12 caracteres, con mayúsculas, minúsculas y números.</p>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-vz-text" for="password_confirmation">Confirmar contraseña</label>
            <input id="password_confirmation" v-model="passwordConfirmation" class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none focus:border-vz-primary focus:ring-1 focus:ring-vz-primary" type="password" autocomplete="new-password" required />
          </div>

          <p v-if="errorMessage" class="text-sm text-vz-danger" role="alert">{{ errorMessage }}</p>

          <button class="w-full rounded-vz bg-vz-primary px-4 py-2 text-sm font-medium text-white disabled:opacity-60" type="submit" :disabled="submitting">
            {{ submitting ? 'Creando cuenta…' : 'Crear cuenta' }}
          </button>
        </form>
      </section>

      <p class="mt-4 text-center text-sm text-vz-muted">
        ¿Ya tienes cuenta?
        <RouterLink class="font-medium text-vz-text underline" to="/login">Iniciar sesión</RouterLink>
      </p>
    </div>
  </main>
</template>
