<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'

import AuthLayout from '@/modules/auth/AuthLayout.vue'
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
  <AuthLayout title="¿Olvidaste tu contraseña?" subtitle="Te enviaremos un enlace para restablecerla.">
    <div class="mb-5 flex items-center gap-3 rounded-vz border border-vz-warning/30 bg-vz-warning/10 px-3 py-2.5 text-[13px]">
      <i class="ri-mail-send-line text-xl text-vz-warning" aria-hidden="true" />
      Escribe el correo de tu cuenta y revisa tu bandeja de entrada.
    </div>

    <form class="space-y-4" @submit.prevent="onSubmit">
      <div>
        <label class="vz-label" for="email">Correo</label>
        <div class="relative">
          <i class="ri-mail-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
          <input id="email" v-model="email" class="vz-input ps-9" type="email" autocomplete="username" required :aria-invalid="errorMessage !== ''" />
        </div>
      </div>
      <p v-if="errorMessage" class="rounded-vz border border-vz-danger/40 bg-vz-danger/10 px-3 py-2.5 text-[13px] text-vz-danger-text" role="alert">{{ errorMessage }}</p>
      <p v-if="successMessage" class="rounded-vz border border-vz-success/40 bg-vz-success/10 px-3 py-2.5 text-[13px] text-vz-success-text" role="status">{{ successMessage }}</p>
      <button class="vz-btn vz-btn-success w-full py-2.5" type="submit" :disabled="submitting">
        {{ submitting ? 'Enviando…' : 'Enviar enlace' }}
      </button>
    </form>

    <p class="mt-6 text-center text-[13px] text-vz-muted">
      ¿La recordaste?
      <RouterLink class="font-semibold text-vz-secondary-text hover:underline" to="/login">Volver a iniciar sesión</RouterLink>
    </p>
  </AuthLayout>
</template>
