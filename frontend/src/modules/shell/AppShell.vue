<script setup lang="ts">
import { onClickOutside } from '@vueuse/core'
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'

import NuvoraLogo from '@/components/ui/NuvoraLogo.vue'
import { navigation, upcoming } from '@/modules/shell/navigation'
import { initials } from '@/services/format'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()

const sidebarOpen = ref(false)
const loggingOut = ref(false)
const switching = ref(false)
const resending = ref(false)
const tenantMenuOpen = ref(false)
const userMenuOpen = ref(false)
const tenantMenu = ref<HTMLElement | null>(null)
const userMenu = ref<HTMLElement | null>(null)
const year = new Date().getFullYear()

onClickOutside(tenantMenu, () => {
  tenantMenuOpen.value = false
})
onClickOutside(userMenu, () => {
  userMenuOpen.value = false
})

const sections = computed(() => {
  const permissions = new Set(auth.currentTenant?.permissions ?? [])

  return navigation
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => item.permission === undefined || permissions.has(item.permission)),
    }))
    .filter((section) => section.items.length > 0)
})

const activeSection = computed(() => {
  const name = String(route.name ?? '')

  return name === 'parking-lot' ? 'parking-lots' : name
})

const roleLabel = computed(() => {
  const roles = auth.currentTenant?.roles ?? []
  const names: Record<string, string> = {
    tenant_owner: 'Propietario',
    tenant_administrator: 'Administrador',
    supervisor: 'Supervisor',
    cashier: 'Cajero',
    parking_operator: 'Operador',
    accountant: 'Contador',
    report_viewer: 'Consulta',
  }

  return roles.length > 0 ? roles.map((role) => names[role] ?? role).join(', ') : 'Sin empresa'
})

watch(() => route.fullPath, () => {
  sidebarOpen.value = false
  userMenuOpen.value = false
})

async function onLogout(): Promise<void> {
  loggingOut.value = true

  try {
    await auth.logout()
    await router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
  }
}

async function onSwitchTenant(tenantId: string): Promise<void> {
  tenantMenuOpen.value = false

  if (tenantId === auth.currentTenant?.id) {
    return
  }

  switching.value = true

  try {
    await auth.selectTenant(tenantId)
    toasts.success(`Ahora operas ${auth.currentTenant?.name ?? 'la empresa seleccionada'}.`)
    await router.push({ name: 'dashboard' })
  } catch (error) {
    toasts.error(error instanceof Error ? error.message : 'No fue posible cambiar de empresa.')
  } finally {
    switching.value = false
  }
}

async function onResendVerification(): Promise<void> {
  resending.value = true

  try {
    await auth.resendVerification()
    toasts.success('Enviamos un nuevo enlace de verificación.')
  } catch (error) {
    toasts.error(error instanceof Error ? error.message : 'No fue posible reenviar la verificación.')
  } finally {
    resending.value = false
  }
}

function toggleFullscreen(): void {
  if (document.fullscreenElement) {
    void document.exitFullscreen()
  } else {
    void document.documentElement.requestFullscreen?.()
  }
}
</script>

<template>
  <div class="min-h-screen bg-vz-body text-vz-text">
    <a href="#contenido" class="sr-only z-[80] rounded bg-vz-primary px-3 py-2 text-white focus:not-sr-only focus:fixed focus:top-2 focus:left-2">Saltar al contenido</a>

    <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/50 md:hidden" aria-hidden="true" @click="sidebarOpen = false" />

    <aside
      id="sidebar"
      class="fixed inset-y-0 left-0 z-40 flex w-[250px] flex-col border-r border-vz-border/60 bg-vz-sidebar transition-transform md:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <RouterLink :to="{ name: 'dashboard' }" class="flex h-[70px] shrink-0 items-center gap-2.5 px-6" aria-label="Nuvora, ir al panel">
        <NuvoraLogo class="size-9 shrink-0" />
        <span class="leading-tight">
          <span class="block text-lg font-semibold tracking-wide text-white">Nuvora</span>
          <span class="block text-[10px] tracking-[0.18em] text-vz-sidebar-title uppercase">Parking Cloud</span>
        </span>
      </RouterLink>

      <nav class="flex-1 overflow-y-auto pb-6" aria-label="Principal">
        <div v-for="section in sections" :key="section.title" class="mt-2">
          <p class="px-6 pt-4 pb-2 text-[11px] font-semibold tracking-[0.08em] text-vz-sidebar-title uppercase">{{ section.title }}</p>
          <ul class="space-y-0.5 px-3">
            <li v-for="item in section.items" :key="item.name">
              <RouterLink
                :to="{ name: item.name }"
                class="flex items-center gap-3 rounded px-3 py-2.5 text-[14px] transition-colors hover:text-white"
                :class="activeSection === item.name ? 'bg-white/15 text-white' : 'text-vz-sidebar-item'"
                :aria-current="activeSection === item.name ? 'page' : undefined"
              >
                <i :class="item.icon" class="text-lg" aria-hidden="true" />
                {{ item.label }}
              </RouterLink>
            </li>
          </ul>
        </div>

        <div class="mt-2">
          <p class="px-6 pt-4 pb-2 text-[11px] font-semibold tracking-[0.08em] text-vz-sidebar-title uppercase">Próximamente</p>
          <ul class="space-y-0.5 px-3">
            <li v-for="item in upcoming" :key="item.name" class="flex items-center gap-3 rounded px-3 py-2.5 text-[14px] text-vz-sidebar-title">
              <i :class="item.icon" class="text-lg" aria-hidden="true" />
              <span class="flex-1">{{ item.label }}</span>
              <span class="rounded bg-white/5 px-1.5 py-0.5 text-[10px] uppercase">Pronto</span>
            </li>
          </ul>
        </div>
      </nav>
    </aside>

    <div class="md:ps-[250px]">
      <header class="fixed inset-x-0 top-0 z-20 flex h-[70px] items-center justify-between gap-3 bg-vz-topbar px-4 shadow-[0_1px_2px_rgba(0,0,0,0.25)] md:left-[250px] md:px-6">
        <div class="flex min-w-0 items-center gap-3">
          <button
            class="vz-btn-icon bg-vz-topbar-button text-vz-topbar-item md:hidden"
            type="button"
            aria-controls="sidebar"
            :aria-expanded="sidebarOpen"
            :aria-label="sidebarOpen ? 'Cerrar menú' : 'Abrir menú'"
            @click="sidebarOpen = !sidebarOpen"
          >
            <i :class="sidebarOpen ? 'ri-close-line' : 'ri-menu-2-line'" aria-hidden="true" />
          </button>

          <div v-if="auth.tenants.length > 0" ref="tenantMenu" class="relative">
            <button
              class="flex max-w-[16rem] items-center gap-2 rounded bg-vz-topbar-search px-3 py-2 text-left text-[13px] text-vz-topbar-item hover:bg-vz-topbar-button disabled:opacity-60"
              type="button"
              aria-haspopup="menu"
              :aria-expanded="tenantMenuOpen"
              :disabled="switching || auth.tenants.length < 2"
              @click="tenantMenuOpen = !tenantMenuOpen"
            >
              <i class="ri-building-4-line text-base text-vz-muted" aria-hidden="true" />
              <span class="truncate">{{ auth.currentTenant?.name ?? 'Selecciona una empresa' }}</span>
              <i v-if="auth.tenants.length > 1" class="ri-arrow-down-s-line text-vz-muted" aria-hidden="true" />
            </button>
            <div v-if="tenantMenuOpen" class="vz-dropdown left-0 w-64" role="menu" aria-label="Empresas">
              <p class="px-4 pt-1 pb-2 text-[11px] font-semibold tracking-wide text-vz-muted uppercase">Cambiar de empresa</p>
              <button
                v-for="tenant in auth.tenants"
                :key="tenant.id"
                class="vz-dropdown-item"
                type="button"
                role="menuitem"
                @click="onSwitchTenant(tenant.id)"
              >
                <i class="ri-building-line" aria-hidden="true" />
                <span class="flex-1 truncate">{{ tenant.name }}</span>
                <i v-if="tenant.id === auth.currentTenant?.id" class="ri-check-line text-vz-success" aria-label="Activa" />
              </button>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-1 sm:gap-2">
          <button class="vz-btn-icon hidden text-vz-topbar-item hover:bg-vz-topbar-button sm:inline-flex" type="button" aria-label="Pantalla completa" @click="toggleFullscreen">
            <i class="ri-fullscreen-line" aria-hidden="true" />
          </button>

          <div ref="userMenu" class="relative">
            <button
              class="flex items-center gap-2.5 rounded px-2 py-1.5 hover:bg-vz-topbar-button"
              type="button"
              aria-haspopup="menu"
              :aria-expanded="userMenuOpen"
              @click="userMenuOpen = !userMenuOpen"
            >
              <span class="flex size-8 items-center justify-center rounded-full bg-vz-primary/30 text-xs font-semibold text-white" aria-hidden="true">
                {{ initials(auth.user?.name) }}
              </span>
              <span class="hidden text-left leading-tight sm:block">
                <span class="block text-[13px] font-medium text-vz-topbar-item">{{ auth.user?.name }}</span>
                <span class="block text-xs text-vz-muted">{{ roleLabel }}</span>
              </span>
              <i class="ri-arrow-down-s-line hidden text-vz-muted sm:block" aria-hidden="true" />
            </button>
            <div v-if="userMenuOpen" class="vz-dropdown right-0" role="menu" aria-label="Cuenta">
              <p class="px-4 pt-1 pb-2 text-xs text-vz-muted">{{ auth.user?.email }}</p>
              <RouterLink class="vz-dropdown-item" role="menuitem" :to="{ name: 'tenant' }">
                <i class="ri-building-line" aria-hidden="true" /> Mi empresa
              </RouterLink>
              <div class="my-1 border-t border-vz-border" />
              <button class="vz-dropdown-item" type="button" role="menuitem" :disabled="loggingOut" @click="onLogout">
                <i class="ri-logout-box-r-line" aria-hidden="true" /> {{ loggingOut ? 'Cerrando…' : 'Cerrar sesión' }}
              </button>
            </div>
          </div>
        </div>
      </header>

      <main id="contenido" class="min-h-screen px-4 pt-[94px] pb-[84px] md:px-6" tabindex="-1">
        <div
          v-if="auth.user && !auth.user.email_verified_at"
          class="mb-5 flex flex-wrap items-center gap-3 rounded-vz border border-vz-warning/40 bg-vz-warning/15 px-4 py-3 text-[13px]"
          role="status"
        >
          <i class="ri-mail-unread-line text-lg text-vz-warning" aria-hidden="true" />
          <p class="flex-1">Verifica tu correo para crear o administrar una empresa.</p>
          <button class="vz-btn vz-btn-soft-warning vz-btn-sm" type="button" :disabled="resending" @click="onResendVerification">
            {{ resending ? 'Enviando…' : 'Reenviar verificación' }}
          </button>
        </div>
        <p v-if="route.query.verificado === '1'" class="mb-5 rounded-vz border border-vz-success/40 bg-vz-success/15 px-4 py-3 text-[13px]" role="status">
          <i class="ri-checkbox-circle-line me-1 text-vz-success" aria-hidden="true" /> Correo verificado. Ya puedes continuar.
        </p>
        <RouterView />
      </main>

      <footer class="fixed inset-x-0 bottom-0 z-10 flex h-[60px] items-center justify-between border-t border-vz-border/60 bg-vz-footer px-6 text-[13px] text-vz-muted md:left-[250px]">
        <span>© {{ year }} Nuvora</span>
        <span class="hidden sm:inline">Gestión de parqueaderos</span>
      </footer>
    </div>
  </div>
</template>
