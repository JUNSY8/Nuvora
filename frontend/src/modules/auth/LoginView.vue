<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import AuthLayout from '@/modules/auth/AuthLayout.vue'
import PasswordInput from '@/modules/auth/PasswordInput.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const remember = ref(false)
const errorMessage = ref('')
const submitting = ref(false)

async function onSubmit(): Promise<void> {
  errorMessage.value = ''
  submitting.value = true

  try {
    await auth.login(email.value, password.value, remember.value)
    await router.push({ name: 'dashboard' })
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'No fue posible iniciar sesión.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthLayout title="Bienvenido de nuevo" subtitle="Inicia sesión para continuar en Nuvora.">
    <p v-if="$route.query.restablecida === '1'" class="mb-4 rounded-vz border border-vz-success/40 bg-vz-success/15 px-3 py-2.5 text-[13px]" role="status">
      La contraseña fue actualizada. Ya puedes iniciar sesión.
    </p>

    <form class="space-y-4" @submit.prevent="onSubmit">
      <div>
        <label class="vz-label" for="email">Correo</label>
        <div class="relative">
          <i class="ri-mail-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
          <input
            id="email"
            v-model="email"
            class="vz-input ps-9"
            type="email"
            name="email"
            autocomplete="username"
            placeholder="tu@empresa.com"
            required
            :aria-invalid="errorMessage !== ''"
          />
        </div>
      </div>

      <div>
        <div class="mb-1.5 flex items-center justify-between">
          <label class="text-[13px] font-medium text-vz-text" for="password">Contraseña</label>
          <RouterLink class="text-xs text-vz-muted hover:text-vz-topbar-item" to="/recuperar-contrasena">¿Olvidaste tu contraseña?</RouterLink>
        </div>
        <PasswordInput id="password" v-model="password" autocomplete="current-password" placeholder="Tu contraseña" :invalid="errorMessage !== ''" />
      </div>

      <label class="flex items-center gap-2 text-[13px]">
        <input id="remember" v-model="remember" class="size-4 accent-vz-primary" type="checkbox" name="remember" />
        Recordarme en este equipo
      </label>

      <p v-if="errorMessage" class="rounded-vz border border-vz-danger/40 bg-vz-danger/10 px-3 py-2.5 text-[13px] text-vz-danger-text" role="alert">{{ errorMessage }}</p>

      <button class="vz-btn vz-btn-success w-full py-2.5" type="submit" :disabled="submitting">
        <i :class="submitting ? 'ri-loader-4-line animate-spin' : 'ri-login-box-line'" aria-hidden="true" />
        {{ submitting ? 'Ingresando…' : 'Iniciar sesión' }}
      </button>
    </form>

    <p class="mt-6 text-center text-[13px] text-vz-muted">
      ¿Aún no tienes cuenta?
      <RouterLink class="font-semibold text-vz-secondary-text hover:underline" to="/registro">Crear cuenta</RouterLink>
    </p>
  </AuthLayout>
</template>
