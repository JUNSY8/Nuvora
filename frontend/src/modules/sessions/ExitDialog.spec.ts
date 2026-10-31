import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { quoteSession, registerExit } from '@/modules/sessions/api'
import ExitDialog from '@/modules/sessions/ExitDialog.vue'
import type { ParkingSession, SessionResult } from '@/modules/sessions/types'
import { listDiscounts } from '@/modules/tariffs/api'
import type { Discount, Quote } from '@/modules/tariffs/types'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/modules/sessions/api', () => ({ quoteSession: vi.fn(), registerExit: vi.fn() }))
vi.mock('@/modules/tariffs/api', () => ({ listDiscounts: vi.fn() }))

const session = {
  id: 's1',
  parking_lot: { id: 'lot-1', name: 'Centro', code: 'CENTRO' },
  vehicle: { id: 'v1', plate: 'ABC123', country: 'CO', type: 'car' },
  customer: null,
  space: { id: 'sp1', code: 'A-01' },
  entry_at: '2026-11-04T13:00:00+00:00',
} as unknown as ParkingSession

function quote(total: number, discount: Quote['discount_rule'] = null): Quote {
  return {
    status: 'priced',
    currency: 'COP',
    tariff: { id: 't1', version_id: 'tv1', version: 2, code: 'GENERAL', name: 'General' },
    discount_rule: discount,
    stay_minutes: 95,
    charged_minutes: 95,
    grace_minutes: 15,
    grace_applied: false,
    subtotal: 800000,
    discount: 800000 - total,
    total,
    lines: [{ type: 'bracket', label: 'Hasta 1 hora', amount: 500000, quantity: null, unit_amount: null }],
    entry_local: '',
    exit_local: '',
    zone_id: null,
    reason: null,
  }
}

const agreement = { id: 'd1', name: 'Convenio cine' } as Discount

describe('ExitDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(quoteSession).mockReset()
    vi.mocked(registerExit).mockReset()
    vi.mocked(listDiscounts).mockReset().mockResolvedValue([agreement])
  })

  it('shows the price and sends the discount the cashier chose', async () => {
    useAuthStore().currentTenant = { id: 't1', name: 'Demo', slug: 'demo', timezone: 'America/Bogota', roles: [], permissions: ['sessions.close', 'sessions.discount', 'tariffs.view'] }
    vi.mocked(quoteSession).mockResolvedValueOnce(quote(800000)).mockResolvedValueOnce(quote(300000, { id: 'd1', code: 'CINE', name: 'Convenio cine' }))
    vi.mocked(registerExit).mockResolvedValue({ session, replayed: false } as SessionResult)

    const wrapper = mount(ExitDialog, { props: { session: null, gates: [], timezone: 'America/Bogota', now: 0 }, attachTo: document.body })
    await wrapper.setProps({ session })
    await flushPromises()

    expect(document.body.textContent?.replace(/\s/g, ' ')).toContain('$ 8.000')
    expect(listDiscounts).toHaveBeenCalledWith({ lot_id: 'lot-1', application: 'manual', status: 'active' })

    const select = document.querySelector<HTMLSelectElement>('#exit-discount')!
    select.value = 'd1'
    select.dispatchEvent(new Event('change'))
    await flushPromises()

    expect(quoteSession).toHaveBeenLastCalledWith('s1', 'd1')
    expect(document.body.textContent?.replace(/\s/g, ' ')).toContain('$ 3.000')

    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(vi.mocked(registerExit).mock.calls[0]![1]).toMatchObject({ discount_id: 'd1' })
    expect(wrapper.emitted('exited')).toHaveLength(1)
    wrapper.unmount()
  })

  it('hides discounts without permission and still lets the vehicle leave when no tariff applies', async () => {
    useAuthStore().currentTenant = { id: 't1', name: 'Demo', slug: 'demo', timezone: 'America/Bogota', roles: [], permissions: ['sessions.close'] }
    vi.mocked(quoteSession).mockResolvedValue({ ...quote(0), status: 'unpriced', total: null, reason: 'Ninguna tarifa activa aplica.' })
    vi.mocked(registerExit).mockResolvedValue({ session, replayed: false } as SessionResult)

    const wrapper = mount(ExitDialog, { props: { session: null, gates: [], timezone: 'America/Bogota', now: 0 }, attachTo: document.body })
    await wrapper.setProps({ session })
    await flushPromises()

    expect(listDiscounts).not.toHaveBeenCalled()
    expect(document.querySelector('#exit-discount')).toBeNull()
    expect(document.body.textContent).toContain('La salida se registra sin cobro')

    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(vi.mocked(registerExit).mock.calls[0]![1]).toMatchObject({ discount_id: null })
    wrapper.unmount()
  })

  it('collects cash with the exit, shows the change and holds the quoted price', async () => {
    useAuthStore().currentTenant = { id: 't1', name: 'Demo', slug: 'demo', timezone: 'America/Bogota', roles: [], permissions: ['sessions.close', 'payments.create'] }
    vi.mocked(quoteSession).mockResolvedValue({ ...quote(650000), quote_token: 'hold-1', quote_expires_at: '2999-01-01T00:00:00Z' })
    vi.mocked(registerExit).mockResolvedValue({ session, replayed: false } as SessionResult)

    const wrapper = mount(ExitDialog, { props: { session: null, gates: [], timezone: 'America/Bogota', now: 0 }, attachTo: document.body })
    await wrapper.setProps({ session })
    await flushPromises()

    // A quick bill fills the cash received.
    const bill = Array.from(document.querySelectorAll<HTMLButtonElement>('button')).find((button) => button.textContent?.replace(/\s/g, ' ').includes('$ 10.000'))!
    bill.click()
    await flushPromises()
    expect(document.body.textContent?.replace(/\s/g, ' ')).toContain('Vueltas: $ 3.500')

    // The 30 s tick does not quote again while the price is held.
    await wrapper.setProps({ now: 30_000 })
    await flushPromises()
    expect(quoteSession).toHaveBeenCalledTimes(1)

    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(vi.mocked(registerExit).mock.calls[0]![1]).toMatchObject({
      quote_token: 'hold-1',
      payment: { method: 'cash', tendered_amount: 1000000, reference: null },
    })
    wrapper.unmount()
  })

  it('leaves the charge pending when the role does not collect', async () => {
    useAuthStore().currentTenant = { id: 't1', name: 'Demo', slug: 'demo', timezone: 'America/Bogota', roles: [], permissions: ['sessions.close'] }
    vi.mocked(quoteSession).mockResolvedValue(quote(650000))
    vi.mocked(registerExit).mockResolvedValue({ session, replayed: false } as SessionResult)

    const wrapper = mount(ExitDialog, { props: { session: null, gates: [], timezone: 'America/Bogota', now: 0 }, attachTo: document.body })
    await wrapper.setProps({ session })
    await flushPromises()

    expect(document.body.textContent).toContain('el cobro queda pendiente para caja')

    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(vi.mocked(registerExit).mock.calls[0]![1]).toMatchObject({ payment: null })
    wrapper.unmount()
  })

  it('shows a stay covered by a membership at zero and offers no discount', async () => {
    useAuthStore().currentTenant = { id: 't1', name: 'Demo', slug: 'demo', timezone: 'America/Bogota', roles: [], permissions: ['sessions.close', 'sessions.discount', 'tariffs.view'] }
    const member = { ...session, membership: { id: 'm1', code: 'M-7K3M9Q', plan: 'Mensual carro' } } as ParkingSession
    vi.mocked(quoteSession).mockResolvedValue({
      ...quote(0),
      status: 'covered',
      subtotal: 800000,
      discount: 800000,
      total: 0,
      membership: member.membership,
      lines: [...quote(0).lines, { type: 'membership', label: 'Mensualidad M-7K3M9Q (Mensual carro)', amount: -800000, quantity: null, unit_amount: null }],
    })

    const wrapper = mount(ExitDialog, { props: { session: null, gates: [], timezone: 'America/Bogota', now: 0 }, attachTo: document.body })
    await wrapper.setProps({ session: member })
    await flushPromises()

    const text = document.body.textContent?.replace(/\s/g, ' ') ?? ''
    expect(text).toContain('Cubierta por la mensualidad M-7K3M9Q')
    expect(text).toContain('A cobrar (mensualidad)')
    expect(text).toContain('$ 0')
    expect(listDiscounts).not.toHaveBeenCalled()
    expect(document.querySelector('#exit-discount')).toBeNull()
    wrapper.unmount()
  })
})
