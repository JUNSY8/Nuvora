<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const remember = ref(false)
const showPassword = ref(false)
const errorMessage = ref('')
const submitting = ref(false)
const year = new Date().getFullYear()

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
  <div class="relative flex min-h-screen flex-col bg-vz-body">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-[380px] overflow-hidden" aria-hidden="true">
      <div class="absolute inset-0 bg-gradient-to-r from-[#364574] to-vz-primary opacity-90" />
      <svg class="absolute inset-x-0 bottom-0 h-24 w-full text-vz-body" viewBox="0 0 1440 120" preserveAspectRatio="none">
        <path fill="currentColor" d="M0 48C180 80 360 112 540 104C720 96 900 48 1080 40C1260 32 1350 56 1440 64V120H0Z" />
      </svg>
    </div>

    <div class="relative z-10 flex flex-1 flex-col px-4 pt-12">
      <div class="mb-4 text-center">
        <p class="text-xl font-semibold tracking-wide text-white">Nuvora</p>
        <p class="mt-3 text-[15px] font-medium text-white/60">Operación de parqueaderos</p>
        <p v-if="$route.query.restablecida === '1'" class="mt-3 text-sm text-white" role="status">La contraseña fue actualizada. Ya puedes iniciar sesión.</p>
      </div>

      <div class="mx-auto mt-28 w-full max-w-[480px]">
        <section class="rounded-vz border border-vz-border bg-vz-card p-6 shadow-[0_1px_2px_rgba(56,65,74,0.15)]">
          <header class="mt-2 text-center">
            <h1 class="text-base font-semibold text-vz-primary">Bienvenido</h1>
            <p class="mt-1 text-sm text-vz-muted">Inicia sesión para continuar en Nuvora.</p>
          </header>

          <form class="mt-6 space-y-4 px-2" @submit.prevent="onSubmit">
            <div>
              <label class="mb-2 block text-sm font-medium text-vz-text" for="email">Correo</label>
              <input
                id="email"
                v-model="email"
                class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 text-sm text-vz-text outline-none placeholder:text-vz-muted focus:border-vz-primary focus:ring-1 focus:ring-vz-primary"
                type="email"
                name="email"
                autocomplete="username"
                placeholder="Ingresa tu correo"
                required
                :aria-invalid="errorMessage !== ''"
              />
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-vz-text" for="password">Contraseña</label>
              <div class="relative">
                <input
                  id="password"
                  v-model="password"
                  class="w-full rounded-vz border border-vz-input-border bg-vz-input px-3 py-2 pe-10 text-sm text-vz-text outline-none placeholder:text-vz-muted focus:border-vz-primary focus:ring-1 focus:ring-vz-primary"
                  :type="showPassword ? 'text' : 'password'"
                  name="password"
                  autocomplete="current-password"
                  placeholder="Ingresa tu contraseña"
                  required
                  :aria-invalid="errorMessage !== ''"
                />
                <button
                  class="absolute inset-y-0 right-0 px-3 text-vz-muted"
                  type="button"
                  :aria-pressed="showPassword"
                  :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                  @click="showPassword = !showPassword"
                >
                  <svg v-if="showPassword" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M3 3l18 18" />
                    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
                    <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c5.5 0 9.5 4.5 10.5 7-0.4 1-1.2 2.4-2.4 3.7" />
                    <path d="M6.1 6.1C4.2 7.5 2.8 9.4 1.5 12c1 2.5 5 7 10.5 7 1.3 0 2.5-.3 3.6-.8" />
                  </svg>
                  <svg v-else class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                </button>
              </div>
            </div>

            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2">
                <input id="remember" v-model="remember" class="size-4 accent-vz-primary" type="checkbox" name="remember" />
                <label class="text-sm text-vz-text" for="remember">Recordarme</label>
              </div>
              <RouterLink class="text-sm font-medium text-vz-text underline" to="/recuperar-contrasena">¿Olvidaste tu contraseña?</RouterLink>
            </div>

            <p v-if="errorMessage" class="text-sm text-vz-danger" role="alert">{{ errorMessage }}</p>

            <button
              class="mt-2 w-full rounded-vz bg-vz-success px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
              type="submit"
              :disabled="submitting"
            >
              {{ submitting ? 'Ingresando…' : 'Iniciar sesión' }}
            </button>
          </form>
        </section>

        <p class="mt-4 text-center text-sm text-vz-muted">
          ¿Aún no tienes cuenta?
          <RouterLink class="font-semibold text-vz-primary underline" to="/registro">Crear cuenta</RouterLink>
        </p>
      </div>

      <footer class="mt-auto py-6 text-center text-sm text-vz-muted">
        © {{ year }} Nuvora
      </footer>
    </div>
  </div>
</template>
