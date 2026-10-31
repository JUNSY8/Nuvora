import axios from 'axios'
import { ref } from 'vue'

import { parseApiError } from '@/services/http'
import type { ApiFailure } from '@/types/api'

/**
 * Keeps per-field validation messages from the API error contract so forms
 * can show them next to each input.
 */
export function useFieldErrors() {
  const fields = ref<Record<string, string>>({})
  const general = ref('')

  function clear(): void {
    fields.value = {}
    general.value = ''
  }

  function capture(error: unknown, fallback: string): void {
    clear()

    if (axios.isAxiosError(error)) {
      const details = (error.response?.data as ApiFailure | undefined)?.error?.details

      if (details && typeof details === 'object') {
        for (const [key, value] of Object.entries(details)) {
          if (Array.isArray(value) && typeof value[0] === 'string') {
            fields.value[key] = value[0]
          }
        }
      }
    }

    general.value = Object.keys(fields.value).length > 0 ? 'Revisa los campos marcados.' : parseApiError(error, fallback)
  }

  return { fields, general, clear, capture }
}
