<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import AuthLayout from '@/modules/auth/AuthLayout.vue'
import PasswordInput from '@/modules/auth/PasswordInput.vue'
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
  <AuthLayout title="Crea tu cuenta" subtitle="Empieza a gestionar tus parqueaderos con Nuvora.">
    <form class="space-y-4" @submit.prevent="onSubmit">
      <div>
        <label class="vz-label" for="name">Nombre</label>
        <div class="relative">
          <i class="ri-user-3-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
          <input id="name" v-model="name" class="vz-input ps-9" type="text" autocomplete="name" required :aria-invalid="errorMessage !== ''" />
        </div>
      </div>
      <div>
        <label class="vz-label" for="email">Correo</label>
        <div class="relative">
          <i class="ri-mail-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
          <input id="email" v-model="email" class="vz-input ps-9" type="email" autocomplete="email" required :aria-invalid="errorMessage !== ''" />
        </div>
      </div>
      <div>
        <label class="vz-label" for="password">Contraseña</label>
        <PasswordInput id="password" v-model="password" autocomplete="new-password" :minlength="12" :invalid="errorMessage !== ''" />
        <p class="vz-help">Mínimo 12 caracteres, con mayúsculas, minúsculas y números.</p>
      </div>
      <div>
        <label class="vz-label" for="password_confirmation">Confirmar contraseña</label>
        <PasswordInput id="password_confirmation" v-model="passwordConfirmation" autocomplete="new-password" :invalid="errorMessage !== ''" />
      </div>

      <p v-if="errorMessage" class="rounded-vz border border-vz-danger/40 bg-vz-danger/10 px-3 py-2.5 text-[13px] text-vz-danger-text" role="alert">{{ errorMessage }}</p>

      <button class="vz-btn vz-btn-success w-full py-2.5" type="submit" :disabled="submitting">
        <i :class="submitting ? 'ri-loader-4-line animate-spin' : 'ri-user-add-line'" aria-hidden="true" />
        {{ submitting ? 'Creando…' : 'Crear cuenta' }}
      </button>
    </form>

    <p class="mt-6 text-center text-[13px] text-vz-muted">
      ¿Ya tienes cuenta?
      <RouterLink class="font-semibold text-vz-secondary-text hover:underline" to="/login">Iniciar sesión</RouterLink>
    </p>
  </AuthLayout>
</template>
