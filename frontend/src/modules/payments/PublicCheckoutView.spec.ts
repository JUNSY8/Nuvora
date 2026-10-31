import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { getPublicCheckout } from '@/modules/payments/api'
import PublicCheckoutView from '@/modules/payments/PublicCheckoutView.vue'
import type { PublicCheckout } from '@/modules/payments/types'

vi.mock('@/modules/payments/api', () => ({ getPublicCheckout: vi.fn() }))

const query: Record<string, string> = {}
vi.mock('vue-router', () => ({ useRoute: () => ({ query }) }))

function checkout(overrides: Partial<PublicCheckout> = {}): PublicCheckout {
  return {
    tenant: { name: 'Nuvora Demo' },
    description: 'Estancia ABC123 · Sede Centro',
    amount: 600000,
    currency: 'COP',
    status: 'pending',
    number: null,
    provider: 'Wompi',
    expires_at: '2026-12-01T15:00:00Z',
    checkout: { url: 'https://checkout.wompi.co/p/', method: 'GET', fields: { reference: 'p1' } },
    ...overrides,
  }
}

describe('PublicCheckoutView', () => {
  beforeEach(() => {
    vi.mocked(getPublicCheckout).mockReset()
    delete query.volver
  })

  it('offers to pay a pending link with the provider', async () => {
    vi.mocked(getPublicCheckout).mockResolvedValue(checkout())

    const wrapper = mount(PublicCheckoutView, { props: { token: 'tok' } })
    await flushPromises()

    expect(getPublicCheckout).toHaveBeenCalledWith('tok', false)
    expect(wrapper.text().replace(/\s/g, ' ')).toContain('$ 6.000')
    expect(wrapper.text()).toContain('Pagar con Wompi')
  })

  it('shows the result after returning from the provider', async () => {
    query.volver = '1'
    vi.mocked(getPublicCheckout).mockResolvedValue(checkout({ status: 'completed', number: 'RC-000007', checkout: null }))

    const wrapper = mount(PublicCheckoutView, { props: { token: 'tok' } })
    await flushPromises()

    expect(getPublicCheckout).toHaveBeenCalledWith('tok', true)
    expect(wrapper.text()).toContain('Pago recibido')
    expect(wrapper.text()).toContain('RC-000007')
    expect(wrapper.text()).not.toContain('Pagar con')
  })

  it('tells the customer an expired link cannot be paid', async () => {
    vi.mocked(getPublicCheckout).mockResolvedValue(checkout({ status: 'expired', checkout: null }))

    const wrapper = mount(PublicCheckoutView, { props: { token: 'tok' } })
    await flushPromises()

    expect(wrapper.text()).toContain('Enlace vencido')
  })
})
