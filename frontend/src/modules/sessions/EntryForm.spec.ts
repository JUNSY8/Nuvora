import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { registerEntry } from '@/modules/sessions/api'
import EntryForm from '@/modules/sessions/EntryForm.vue'
import type { SessionResult } from '@/modules/sessions/types'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/modules/sessions/api', () => ({ registerEntry: vi.fn() }))
vi.mock('@/modules/vehicles/api', () => ({ checkPlate: vi.fn().mockResolvedValue(null) }))

const result = { session: { id: 's1', vehicle: { plate: 'ABC123' } }, replayed: false } as unknown as SessionResult

function apiError(status: number, code: string, message: string): AxiosError {
  const error = new AxiosError('Request failed')
  error.response = {
    data: { success: false, error: { code, message, details: {} }, request_id: 'r' },
    status,
    statusText: '',
    headers: {},
    config: { headers: {} } as never,
  }

  return error
}

function mountForm() {
  return mount(EntryForm, {
    props: { lotId: 'lot-1', gates: [], spaces: [], disabled: false },
    attachTo: document.body,
  })
}

describe('EntryForm', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().currentTenant = { id: 't1', name: 'Demo', slug: 'demo', timezone: 'America/Bogota', roles: [], permissions: ['sessions.create'] }
    vi.mocked(registerEntry).mockReset()
  })

  it('reuses the idempotency key when retrying the same plate and renews it for another plate', async () => {
    vi.mocked(registerEntry)
      .mockRejectedValueOnce(new AxiosError('Network Error'))
      .mockResolvedValueOnce(result)
      .mockResolvedValueOnce(result)
    const wrapper = mountForm()

    await wrapper.get('#entry-plate').setValue('abc-123')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const [first, retry] = vi.mocked(registerEntry).mock.calls
    expect(first![1]).toBe(retry![1])
    expect(first![0]).toMatchObject({ lot_id: 'lot-1', plate: 'abc-123', country: 'CO', vehicle_type: 'car', auto_assign: true, space_id: null })
    expect(wrapper.emitted('entered')).toHaveLength(1)
    // The form is cleared for the next vehicle.
    expect((wrapper.get('#entry-plate').element as HTMLInputElement).value).toBe('')

    await wrapper.get('#entry-plate').setValue('XYZ789')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(registerEntry).mock.calls[2]![1]).not.toBe(first![1])
    wrapper.unmount()
  })

  it('explains a full lot and offers the alternatives', async () => {
    vi.mocked(registerEntry).mockRejectedValueOnce(apiError(422, 'NO_SPACE_AVAILABLE', 'No hay espacios disponibles para este tipo de vehículo.'))
    const wrapper = mountForm()

    await wrapper.get('#entry-plate').setValue('ABC123')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const alert = wrapper.get('[role="alert"]').text()
    expect(alert).toContain('No hay espacios disponibles')
    expect(alert).toContain('sin espacio')
    expect(wrapper.emitted('entered')).toBeUndefined()
    wrapper.unmount()
  })

  it('sends a session without space when the operator chooses so', async () => {
    vi.mocked(registerEntry).mockResolvedValueOnce(result)
    const wrapper = mountForm()

    await wrapper.get('#entry-plate').setValue('ABC123')
    await wrapper.get('input[type="radio"][value="none"]').setValue(true)
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(vi.mocked(registerEntry).mock.calls[0]![0]).toMatchObject({ auto_assign: false, space_id: null })
    wrapper.unmount()
  })
})
