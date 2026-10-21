<script setup lang="ts">
import { computed } from 'vue'

import { softBadge, type Tone } from '@/components/ui/tones'

const props = defineProps<{
  label: string
  tone: Tone
  icon?: string
}>()

const classes = computed(() => softBadge[props.tone])
</script>

<template>
  <span class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-semibold tracking-wide whitespace-nowrap uppercase" :class="classes">
    <i v-if="icon" :class="icon" aria-hidden="true" />
    {{ label }}
  </span>
</template>
