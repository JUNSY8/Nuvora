<script setup lang="ts">
import { useId } from 'vue'

// "icon": gradient tile for the sidebar and headers. "mark": bare symbol in currentColor for coloured surfaces.
withDefaults(defineProps<{ variant?: 'icon' | 'mark' }>(), { variant: 'icon' })

// Four parking bays turning around the vehicle (the teal dot) at the centre.
const arms = [0, 90, 180, 270]
const gradientId = `nuvora-logo-${useId()}`
</script>

<template>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    :viewBox="variant === 'icon' ? '0 0 512 512' : '96 96 320 320'"
    aria-hidden="true"
    focusable="false"
  >
    <template v-if="variant === 'icon'">
      <defs>
        <linearGradient :id="gradientId" x1="0" y1="0" x2="512" y2="512" gradientUnits="userSpaceOnUse">
          <stop offset="0" stop-color="#405189" />
          <stop offset="1" stop-color="#3577f1" />
        </linearGradient>
      </defs>
      <rect width="512" height="512" rx="120" :fill="`url(#${gradientId})`" />
    </template>
    <g
      fill="none"
      :stroke="variant === 'icon' ? '#fff' : 'currentColor'"
      stroke-width="46"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path v-for="angle in arms" :key="angle" d="M214 206 V132 H318" :transform="`rotate(${angle} 256 256)`" />
    </g>
    <circle cx="256" cy="256" r="30" fill="#0ab39c" />
  </svg>
</template>
