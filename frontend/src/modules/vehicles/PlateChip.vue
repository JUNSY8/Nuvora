<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  plate: string
  country: string
  size?: 'sm' | 'lg'
}>(), {
  size: 'sm',
})

// Colombian private plates are yellow; other countries use a neutral plate.
const surface = computed(() => (props.country === 'CO' ? 'bg-[#f6c544] text-[#1b1b1b] border-[#1b1b1b]/70' : 'bg-[#eef1f5] text-[#1b1b1b] border-[#1b1b1b]/50'))
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded border font-mono font-bold tracking-[0.12em] whitespace-nowrap"
    :class="[surface, size === 'lg' ? 'px-3 py-1 text-xl' : 'px-2 py-0.5 text-[13px]']"
    :aria-label="`Placa ${plate}, ${country}`"
  >
    {{ plate }}
    <span class="font-sans text-[9px] font-semibold tracking-normal opacity-70" aria-hidden="true">{{ country }}</span>
  </span>
</template>
