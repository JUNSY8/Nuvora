<script setup lang="ts">
import { computed } from 'vue'

import { solidFill, type Tone } from '@/components/ui/tones'

const props = defineProps<{ value: number; label: string }>()

const clamped = computed(() => Math.min(100, Math.max(0, props.value)))
// Occupancy thresholds are presentation only: they color the bar.
const tone = computed<Tone>(() => (clamped.value >= 90 ? 'danger' : clamped.value >= 70 ? 'warning' : 'success'))
</script>

<template>
  <div class="h-1.5 w-full overflow-hidden rounded-full bg-vz-elevated" role="progressbar" :aria-valuenow="clamped" aria-valuemin="0" aria-valuemax="100" :aria-label="label">
    <div class="h-full rounded-full transition-[width] duration-500" :class="solidFill[tone]" :style="{ width: `${clamped}%` }" />
  </div>
</template>
