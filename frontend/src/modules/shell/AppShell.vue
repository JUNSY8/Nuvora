<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const sidebarOpen = ref(false)
const loggingOut = ref(false)
const switching = ref(false)
const resending = ref(false)
const bannerMessage = ref('')
const bannerError = ref('')
const year = new Date().getFullYear()

const titles: Record<string, string> = {
  dashboard: 'Inicio',
  tenant: 'Empresa',
  members: 'Miembros',
  audit: 'Auditoría',
}

const pageTitle = computed(() => titles[String(route.name)] ?? 'Nuvora')

const navigation = computed(() => {
  const permissions = new Set(auth.currentTenant?.permissions ?? [])

  return [
    { name: 'dashboard', label: 'Inicio', visible: true },
    { name: 'tenant', label: 'Empresa', visible: true },
    { name: 'members', label: 'Miembros', visible: permissions.has('users.view') },
    { name: 'audit', label: 'Auditoría', visible: permissions.has('audit.view') },
  ].filter((item) => item.visible)
})

function closeSidebar(): void {
  sidebarOpen.value = false
}

async function onLogout(): Promise<void> {
  loggingOut.value = true

  try {
    await auth.logout()
    await router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
  }
}

async function onSwitchTenant(event: Event): Promise<void> {
  const tenantId = (event.target as HTMLSelectElement).value

  if (tenantId === '' || tenantId === auth.currentTenant?.id) {
    return
  }

  switching.value = true
  bannerError.value = ''

  try {
    await auth.selectTenant(tenantId)
  } catch (error) {
    bannerError.value = error instanceof Error ? error.message : 'No fue posible cambiar de empresa.'
  } finally {
    switching.value = false
  }
}

async function onResendVerification(): Promise<void> {
  resending.value = true
  bannerMessage.value = ''
  bannerError.value = ''

  try {
    await auth.resendVerification()
    bannerMessage.value = 'Enviamos un nuevo enlace de verificación.'
  } catch (error) {
    bannerError.value = error instanceof Error ? error.message : 'No fue posible reenviar la verificación.'
  } finally {
    resending.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-vz-body text-vz-text">
    <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/50 md:hidden" @click="closeSidebar" />

    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-[250px] flex-col bg-vz-sidebar transition-transform md:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-[70px] items-center px-6">
        <p class="text-lg font-semibold text-white">Nuvora</p>
      </div>
      <p class="px-6 pb-2 text-xs font-medium tracking-wide text-vz-sidebar-title uppercase">Operación</p>
      <nav class="flex-1 space-y-1 px-4" aria-label="Principal">
        <RouterLink
          v-for="item in navigation"
          :key="item.name"
          class="block rounded px-3 py-2 text-sm text-vz-sidebar-item hover:bg-white/15 hover:text-white"
          :class="route.name === item.name ? 'bg-white/15 text-white' : ''"
          :to="{ name: item.name }"
          @click="closeSidebar"
        >
          {{ item.label }}
        </RouterLink>
      </nav>
    </aside>

    <div class="md:ps-[250px]">
      <header class="fixed inset-x-0 top-0 z-20 flex h-[70px] items-center justify-between gap-4 bg-vz-topbar px-4 md:left-[250px]">
        <div class="flex items-center gap-3">
          <button
            class="rounded bg-vz-topbar-button px-3 py-2 text-sm text-vz-topbar-item md:hidden"
            type="button"
            :aria-expanded="sidebarOpen"
            :aria-label="sidebarOpen ? 'Cerrar menú' : 'Abrir menú'"
            @click="sidebarOpen = !sidebarOpen"
          >
            Menú
          </button>
          <p class="text-sm font-medium text-vz-topbar-item">{{ pageTitle }}</p>
        </div>

        <div class="flex items-center gap-3">
          <label v-if="auth.tenants.length > 1" class="sr-only" for="tenant-switch">Empresa activa</label>
          <select
            v-if="auth.tenants.length > 1"
            id="tenant-switch"
            class="max-w-48 rounded-vz border border-vz-input-border bg-vz-topbar-search px-2 py-2 text-sm text-vz-topbar-item"
            :value="auth.currentTenant?.id ?? ''"
            :disabled="switching"
            @change="onSwitchTenant"
          >
            <option v-if="!auth.currentTenant" value="" disabled>Selecciona una empresa</option>
            <option v-for="tenant in auth.tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
          </select>
          <p v-else-if="auth.currentTenant" class="hidden text-sm text-vz-topbar-item sm:block">{{ auth.currentTenant.name }}</p>
          <p class="hidden text-sm text-vz-topbar-item sm:block">{{ auth.user?.name }}</p>
          <button
            class="rounded bg-vz-topbar-button px-3 py-2 text-sm text-vz-topbar-item"
            type="button"
            :disabled="loggingOut"
            @click="onLogout"
          >
            Cerrar sesión
          </button>
        </div>
      </header>

      <main class="min-h-screen px-6 pt-[94px] pb-[60px]">
        <div v-if="auth.user && !auth.user.email_verified_at" class="mb-4 rounded-vz border border-vz-warning/40 bg-vz-warning/15 px-4 py-3 text-sm text-vz-text" role="status">
          <p>Verifica tu correo para crear o administrar una empresa.</p>
          <button class="mt-2 text-sm font-medium text-vz-topbar-item underline" type="button" :disabled="resending" @click="onResendVerification">
            {{ resending ? 'Enviando…' : 'Reenviar verificación' }}
          </button>
        </div>
        <p v-if="bannerMessage" class="mb-4 text-sm text-vz-success" role="status">{{ bannerMessage }}</p>
        <p v-if="bannerError" class="mb-4 text-sm text-vz-danger" role="alert">{{ bannerError }}</p>
        <p v-if="route.query.verificado === '1'" class="mb-4 text-sm text-vz-success" role="status">Correo verificado. Ya puedes continuar.</p>
        <RouterView />
      </main>

      <footer class="fixed inset-x-0 bottom-0 z-10 bg-vz-footer px-6 py-4 text-sm text-vz-muted md:left-[250px]">
        © {{ year }} Nuvora
      </footer>
    </div>
  </div>
</template>
