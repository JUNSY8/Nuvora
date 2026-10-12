import axios from 'axios'

import type { ApiFailure } from '@/types/api'

export const http = axios.create({
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

let csrfReady = false

export async function ensureCsrfCookie(): Promise<void> {
  if (csrfReady) {
    return
  }

  await http.get('/sanctum/csrf-cookie')
  csrfReady = true
}

export function resetCsrfCookie(): void {
  csrfReady = false
}

export function apiErrorCode(error: unknown): string | null {
  if (!axios.isAxiosError(error)) {
    return null
  }

  const code = (error.response?.data as ApiFailure | undefined)?.error?.code

  return typeof code === 'string' ? code : null
}

export function parseApiError(error: unknown, fallback: string): string {
  if (!axios.isAxiosError(error)) {
    return fallback
  }

  const payload = error.response?.data as ApiFailure | undefined
  const details = payload?.error?.details

  if (details && typeof details === 'object') {
    for (const value of Object.values(details)) {
      if (Array.isArray(value) && typeof value[0] === 'string' && value[0] !== '') {
        return value[0]
      }
    }
  }

  const message = payload?.error?.message

  if (typeof message === 'string' && message !== '') {
    return message
  }

  return fallback
}
