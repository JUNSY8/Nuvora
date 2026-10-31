<script setup lang="ts">
import BaseModal from '@/components/ui/BaseModal.vue'

withDefaults(defineProps<{
  open: boolean
  title: string
  message: string
  confirmLabel?: string
  busy?: boolean
  error?: string
}>(), {
  confirmLabel: 'Eliminar',
  busy: false,
  error: '',
})

const emit = defineEmits<{ close: []; confirm: [] }>()
</script>

<template>
  <BaseModal :open="open" :title="title" size="sm" @close="emit('close')">
    <div class="px-5 py-6 text-center">
      <span class="mx-auto flex size-16 items-center justify-center rounded-full bg-vz-danger/15 text-3xl text-vz-danger" aria-hidden="true">
        <i class="ri-delete-bin-line" />
      </span>
      <p class="mt-4 text-[13px] text-vz-text">{{ message }}</p>
      <p v-if="error" class="mt-3 text-[13px] text-vz-danger" role="alert">{{ error }}</p>
    </div>
    <div class="flex justify-end gap-2 border-t border-vz-border px-5 py-3">
      <button class="vz-btn vz-btn-light" type="button" @click="emit('close')">Cancelar</button>
      <button class="vz-btn vz-btn-danger" type="button" :disabled="busy" @click="emit('confirm')">
        <i v-if="busy" class="ri-loader-4-line animate-spin" aria-hidden="true" />
        {{ confirmLabel }}
      </button>
    </div>
  </BaseModal>
</template>
