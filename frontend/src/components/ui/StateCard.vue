<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  variant: 'loading' | 'empty' | 'error' | 'denied' | 'no-tenant'
  title?: string
  message?: string
  compact?: boolean
}>(), {
  title: undefined,
  message: undefined,
  compact: false,
})

const defaults = {
  loading: { icon: 'ri-loader-4-line animate-spin', title: 'Cargando…', tone: 'text-vz-muted' },
  empty: { icon: 'ri-inbox-2-line', title: 'Sin registros', tone: 'text-vz-muted' },
  error: { icon: 'ri-error-warning-line', title: 'Algo salió mal', tone: 'text-vz-danger' },
  denied: { icon: 'ri-lock-2-line', title: 'Sin permiso', tone: 'text-vz-warning' },
  'no-tenant': { icon: 'ri-building-line', title: 'Sin empresa activa', tone: 'text-vz-info' },
} as const

const preset = computed(() => defaults[props.variant])
const role = computed(() => (props.variant === 'error' || props.variant === 'denied' ? 'alert' : 'status'))
</script>

<template>
  <section class="vz-card flex flex-col items-center text-center" :class="compact ? 'px-5 py-8' : 'px-6 py-14'" :role="role" :aria-busy="variant === 'loading'">
    <span class="flex size-14 items-center justify-center rounded-full bg-vz-elevated text-[28px]" :class="preset.tone" aria-hidden="true">
      <i :class="preset.icon" />
    </span>
    <h2 class="mt-4 text-[15px] font-semibold text-vz-heading">{{ title ?? preset.title }}</h2>
    <p v-if="message" class="mt-1.5 max-w-md text-[13px] text-vz-muted">{{ message }}</p>
    <div v-if="$slots.default" class="mt-5 flex flex-wrap justify-center gap-2">
      <slot />
    </div>
  </section>
</template>
