import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface Toast {
  id: number
  kind: 'success' | 'error' | 'info'
  message: string
}

export const useToastStore = defineStore('toast', () => {
  const items = ref<Toast[]>([])
  let sequence = 0

  function dismiss(id: number): void {
    items.value = items.value.filter((toast) => toast.id !== id)
  }

  function push(kind: Toast['kind'], message: string, timeout = 4500): void {
    sequence += 1
    const id = sequence
    items.value = [...items.value.slice(-3), { id, kind, message }]

    if (timeout > 0) {
      window.setTimeout(() => dismiss(id), timeout)
    }
  }

  return {
    items,
    dismiss,
    success: (message: string) => push('success', message),
    error: (message: string) => push('error', message, 7000),
    info: (message: string) => push('info', message),
  }
})
