<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router'

defineProps<{
  title: string
  subtitle?: string
  breadcrumbs?: Array<{ label: string; to?: RouteLocationRaw }>
}>()
</script>

<template>
  <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="min-w-0">
      <nav v-if="breadcrumbs && breadcrumbs.length > 0" aria-label="Ruta" class="mb-1">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-vz-muted">
          <li v-for="(crumb, index) in breadcrumbs" :key="crumb.label" class="flex items-center gap-1">
            <i v-if="index > 0" class="ri-arrow-right-s-line" aria-hidden="true" />
            <RouterLink v-if="crumb.to" :to="crumb.to" class="hover:text-vz-topbar-item">{{ crumb.label }}</RouterLink>
            <span v-else aria-current="page">{{ crumb.label }}</span>
          </li>
        </ol>
      </nav>
      <h1 class="truncate text-lg font-semibold tracking-wide text-vz-heading uppercase">{{ title }}</h1>
      <p v-if="subtitle" class="mt-1 text-[13px] text-vz-muted">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </header>
</template>
