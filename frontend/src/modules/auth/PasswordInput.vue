<script setup lang="ts">
import { ref } from 'vue'

defineProps<{
  id: string
  autocomplete: string
  invalid?: boolean
  minlength?: number
  placeholder?: string
}>()

const model = defineModel<string>({ required: true })
const visible = ref(false)
</script>

<template>
  <div class="relative">
    <i class="ri-lock-password-line pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-vz-muted" aria-hidden="true" />
    <input
      :id="id"
      v-model="model"
      class="vz-input ps-9 pe-10"
      :type="visible ? 'text' : 'password'"
      :name="id"
      :autocomplete="autocomplete"
      :minlength="minlength"
      :placeholder="placeholder"
      required
      :aria-invalid="invalid ?? false"
    />
    <button
      class="absolute inset-y-0 right-0 px-3 text-vz-muted hover:text-vz-topbar-item"
      type="button"
      :aria-pressed="visible"
      :aria-label="visible ? 'Ocultar contraseña' : 'Mostrar contraseña'"
      @click="visible = !visible"
    >
      <i :class="visible ? 'ri-eye-off-line' : 'ri-eye-line'" aria-hidden="true" />
    </button>
  </div>
</template>
