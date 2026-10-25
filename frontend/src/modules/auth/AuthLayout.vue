<script setup lang="ts">
import NuvoraLogo from '@/components/ui/NuvoraLogo.vue'

defineProps<{ title: string; subtitle: string }>()

const year = new Date().getFullYear()

const highlights = [
  { icon: 'ri-layout-grid-line', text: 'Mapa de espacios y ocupación por sede en tiempo real.' },
  { icon: 'ri-camera-lens-line', text: 'Cámaras LPR, barreras y lectores de cualquier fabricante.' },
  { icon: 'ri-shield-check-line', text: 'Roles, permisos y auditoría por empresa.' },
]
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-vz-body px-4 py-10">
    <div class="vz-card grid w-full max-w-[1000px] overflow-hidden lg:grid-cols-2">
      <aside class="relative hidden overflow-hidden bg-gradient-to-br from-[#364574] via-vz-primary to-[#2a3a6e] p-10 text-white lg:flex lg:flex-col" aria-hidden="true">
        <div class="pointer-events-none absolute -right-24 -bottom-24 size-80 rounded-full border-[40px] border-white/5" />
        <div class="pointer-events-none absolute -top-16 -left-16 size-56 rounded-full bg-white/5" />
        <div class="relative flex items-center gap-2.5">
          <span class="flex size-10 items-center justify-center rounded bg-white/15"><NuvoraLogo variant="mark" class="size-7 text-white" /></span>
          <span class="text-xl font-semibold tracking-wide">Nuvora</span>
        </div>
        <div class="relative mt-auto">
          <i class="ri-double-quotes-l text-4xl text-vz-success" />
          <p class="mt-2 text-lg leading-relaxed font-medium">La operación de tus parqueaderos, en una sola plataforma.</p>
          <ul class="mt-8 space-y-4">
            <li v-for="item in highlights" :key="item.text" class="flex items-start gap-3 text-[13px] text-white/80">
              <span class="flex size-8 shrink-0 items-center justify-center rounded bg-white/10 text-base"><i :class="item.icon" /></span>
              <span class="pt-1.5">{{ item.text }}</span>
            </li>
          </ul>
        </div>
      </aside>

      <main class="flex flex-col p-6 sm:p-10">
        <div class="mb-8 flex items-center gap-2.5 lg:hidden">
          <NuvoraLogo class="size-9 shrink-0" />
          <span class="text-lg font-semibold tracking-wide text-white">Nuvora</span>
        </div>
        <header>
          <h1 class="text-lg font-semibold text-vz-primary-text">{{ title }}</h1>
          <p class="mt-1 text-[13px] text-vz-muted">{{ subtitle }}</p>
        </header>
        <div class="mt-6 flex-1">
          <slot />
        </div>
        <footer class="mt-8 text-center text-xs text-vz-muted">© {{ year }} Nuvora · Gestión de parqueaderos</footer>
      </main>
    </div>
  </div>
</template>
