<script setup lang="ts">
import type { PageMeta } from '@/types/api'

defineProps<{ meta: PageMeta }>()
const emit = defineEmits<{ change: [page: number] }>()
</script>

<template>
  <div v-if="meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-vz-border px-4 py-3 text-[13px]">
    <p class="text-vz-muted">
      Página <span class="font-semibold text-vz-text">{{ meta.current_page }}</span> de {{ meta.last_page }} · {{ meta.total }} registros
    </p>
    <div class="flex gap-1">
      <button class="vz-btn vz-btn-light vz-btn-sm" type="button" :disabled="meta.current_page <= 1" @click="emit('change', meta.current_page - 1)">
        <i class="ri-arrow-left-s-line" aria-hidden="true" /> Anterior
      </button>
      <button class="vz-btn vz-btn-light vz-btn-sm" type="button" :disabled="meta.current_page >= meta.last_page" @click="emit('change', meta.current_page + 1)">
        Siguiente <i class="ri-arrow-right-s-line" aria-hidden="true" />
      </button>
    </div>
  </div>
</template>
