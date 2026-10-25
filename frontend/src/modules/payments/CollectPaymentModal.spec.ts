import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { collectPayment, createPaymentLink, providerOptions } from '@/modules/payments/api'
import CollectPaymentModal from '@/modules/payments/CollectPaymentModal.vue'
import type { PayableSummary, Payment } from '@/modules/payments/types'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/modules/payments/api', () => ({ collectPayment: vi.fn(), createPaymentLink: vi.fn(), providerOptions: vi.fn() }))

const payable: PayableSummary = { type: 'parking_session', id: 's1', title: 'Estancia ABC123', subtitle: 'Centro', currency: 'COP', balance: 600000 }

describe('CollectPaymentModal', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().currentTenant = { id: 't1', name: 'Demo', slug: 'demo', timezone: 'America/Bogota', roles: [], permissions: ['payments.create'] }
    vi.mocked(collectPayment).mockReset()
    vi.mocked(createPaymentLink).mockReset()
    vi.mocked(providerOptions).mockReset()
  })

  it('creates a payment link through the chosen provider and shows it to copy', async () => {
    vi.mocked(providerOptions).mockResolvedValue([{ id: 'acc1', name: 'Wompi principal', provider: 'wompi', provider_label: 'Wompi', sandbox: true }])
    vi.mocked(createPaymentLink).mockResolvedValue({ id: 'p1', amount: 600000, currency: 'COP', checkout_url: 'http://localhost:5173/pagar/abc', expires_at: '2026-12-01T15:00:00Z' } as Payment)

    const wrapper = mount(CollectPaymentModal, { props: { payable: null }, attachTo: document.body })
    await wrapper.setProps({ payable })
    await flushPromises()

    Array.from(document.querySelectorAll<HTMLButtonElement>('[role="radio"]')).find((button) => button.textContent?.includes('Enlace de pago'))!.click()
    await flushPromises()
    expect(document.querySelector('#collect-account')).not.toBeNull()

    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(vi.mocked(createPaymentLink).mock.calls[0]![0]).toEqual({ payable_type: 'parking_session', payable_id: 's1', account_id: 'acc1', amount: 600000 })
    expect(collectPayment).not.toHaveBeenCalled()
    expect(document.querySelector<HTMLInputElement>('input[aria-label="Enlace de pago"]')!.value).toBe('http://localhost:5173/pagar/abc')
    expect(wrapper.emitted('linked')).toHaveLength(1)
    wrapper.unmount()
  })

  it('collects at the desk only when no provider is connected', async () => {
    vi.mocked(providerOptions).mockResolvedValue([])

    const wrapper = mount(CollectPaymentModal, { props: { payable: null }, attachTo: document.body })
    await wrapper.setProps({ payable })
    await flushPromises()

    expect(document.querySelectorAll('[role="radio"]')).toHaveLength(0)
    expect(document.body.textContent).toContain('Medio de pago')
    wrapper.unmount()
  })
})
