<script setup lang="ts">
import { useToastStore } from '@/stores/toast'

const toasts = useToastStore()

const styles = {
  success: { icon: 'ri-checkbox-circle-fill', classes: 'border-vz-success/40 text-vz-success-text' },
  error: { icon: 'ri-error-warning-fill', classes: 'border-vz-danger/40 text-vz-danger-text' },
  info: { icon: 'ri-information-fill', classes: 'border-vz-info/40 text-vz-info-text' },
} as const
</script>

<template>
  <div class="pointer-events-none fixed right-4 bottom-16 z-[70] flex w-[min(360px,calc(100vw-2rem))] flex-col gap-2" aria-live="polite">
    <div
      v-for="toast in toasts.items"
      :key="toast.id"
      class="pointer-events-auto flex items-start gap-3 rounded-vz border bg-vz-dropdown px-4 py-3 shadow-lg"
      :class="styles[toast.kind].classes"
      :role="toast.kind === 'error' ? 'alert' : 'status'"
    >
      <i class="mt-0.5 text-lg" :class="styles[toast.kind].icon" aria-hidden="true" />
      <p class="flex-1 text-[13px] text-vz-text">{{ toast.message }}</p>
      <button class="text-vz-muted hover:text-vz-topbar-item" type="button" aria-label="Cerrar aviso" @click="toasts.dismiss(toast.id)">
        <i class="ri-close-line" aria-hidden="true" />
      </button>
    </div>
  </div>
</template>
