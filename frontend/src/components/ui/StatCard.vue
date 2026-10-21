<script setup lang="ts">
import { computed } from 'vue'

import { softBadge, type Tone } from '@/components/ui/tones'

const props = defineProps<{
  label: string
  value: string | number
  icon: string
  tone: Tone
  hint?: string
  loading?: boolean
}>()

const iconClasses = computed(() => softBadge[props.tone])
</script>

<template>
  <article class="vz-card p-5">
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <p class="truncate text-xs font-medium tracking-wide text-vz-muted uppercase">{{ label }}</p>
        <p class="mt-4 text-[22px] font-semibold text-vz-heading">
          <span v-if="loading" class="inline-block h-6 w-16 animate-pulse rounded bg-vz-elevated" aria-label="Cargando" />
          <template v-else>{{ value }}</template>
        </p>
        <p v-if="hint" class="mt-2 truncate text-xs text-vz-muted">{{ hint }}</p>
      </div>
      <span class="flex size-12 shrink-0 items-center justify-center rounded text-2xl" :class="iconClasses" aria-hidden="true">
        <i :class="icon" />
      </span>
    </div>
  </article>
</template>
