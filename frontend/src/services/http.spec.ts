import { AxiosError } from 'axios'
import { describe, expect, it } from 'vitest'

import { parseApiError } from '@/services/http'

describe('parseApiError', () => {
  it('reads the message from the Nuvora error contract', () => {
    const error = new AxiosError('Request failed')
    error.response = {
      data: {
        success: false,
        error: {
          code: 'INVALID_CREDENTIALS',
          message: 'Las credenciales no son válidas.',
          details: {},
        },
        request_id: '11111111-1111-4111-8111-111111111111',
      },
      status: 401,
      statusText: 'Unauthorized',
      headers: {},
      config: { headers: {} } as never,
    }

    expect(parseApiError(error, 'No fue posible iniciar sesión.')).toBe('Las credenciales no son válidas.')
  })

  it('prefers the first validation detail', () => {
    const error = new AxiosError('Request failed')
    error.response = {
      data: {
        success: false,
        error: {
          code: 'VALIDATION_FAILED',
          message: 'Los datos enviados no son válidos.',
          details: { email: ['El correo es obligatorio.'] },
        },
        request_id: '11111111-1111-4111-8111-111111111111',
      },
      status: 422,
      statusText: 'Unprocessable',
      headers: {},
      config: { headers: {} } as never,
    }

    expect(parseApiError(error, 'No fue posible crear la cuenta.')).toBe('El correo es obligatorio.')
  })

  it('falls back when the payload is not an API error', () => {
    expect(parseApiError(new Error('network'), 'No fue posible iniciar sesión.')).toBe('No fue posible iniciar sesión.')
  })
})
