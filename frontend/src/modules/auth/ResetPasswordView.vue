<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AuthLayout from '@/modules/auth/AuthLayout.vue'
import PasswordInput from '@/modules/auth/PasswordInput.vue'
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
  <AuthLayout title="Nueva contraseña" subtitle="Elige una contraseña segura para tu cuenta.">
    <form class="space-y-4" @submit.prevent="onSubmit">
      <div>
        <label class="vz-label" for="email">Correo</label>
        <input id="email" v-model="email" class="vz-input" type="email" autocomplete="username" required />
      </div>
      <div>
        <label class="vz-label" for="password">Nueva contraseña</label>
        <PasswordInput id="password" v-model="password" autocomplete="new-password" :minlength="12" :invalid="errorMessage !== ''" />
        <p class="vz-help">Mínimo 12 caracteres, con mayúsculas, minúsculas y números.</p>
      </div>
      <div>
        <label class="vz-label" for="password_confirmation">Confirmar contraseña</label>
        <PasswordInput id="password_confirmation" v-model="passwordConfirmation" autocomplete="new-password" :invalid="errorMessage !== ''" />
      </div>
      <p v-if="token === ''" class="text-[13px] text-vz-danger" role="alert">El enlace no incluye un token válido.</p>
      <p v-if="errorMessage" class="rounded-vz border border-vz-danger/40 bg-vz-danger/10 px-3 py-2.5 text-[13px] text-vz-danger-text" role="alert">{{ errorMessage }}</p>
      <button class="vz-btn vz-btn-success w-full py-2.5" type="submit" :disabled="submitting || token === ''">
        {{ submitting ? 'Guardando…' : 'Restablecer contraseña' }}
      </button>
    </form>
    <p class="mt-6 text-center text-[13px] text-vz-muted">
      <RouterLink class="font-semibold text-vz-secondary-text hover:underline" to="/login">Volver a iniciar sesión</RouterLink>
    </p>
  </AuthLayout>
</template>
