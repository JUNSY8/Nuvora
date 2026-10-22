import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { sellMembership } from '@/modules/memberships/api'
import MembershipFormModal from '@/modules/memberships/MembershipFormModal.vue'
import type { Membership, MembershipPlan } from '@/modules/memberships/types'
import { listSpaces } from '@/modules/parking/api'
import type { ParkingLot, ParkingSpace, ParkingZone } from '@/modules/parking/types'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/modules/memberships/api', () => ({ sellMembership: vi.fn() }))
vi.mock('@/modules/parking/api', () => ({ listSpaces: vi.fn() }))
vi.mock('@/modules/customers/api', () => ({ listCustomers: vi.fn().mockResolvedValue({ items: [], meta: {} }) }))

const lots = [
  { id: 'l1', name: 'Centro', timezone: 'America/Bogota', currency: 'COP' },
  { id: 'l2', name: 'Norte', timezone: 'America/Bogota', currency: 'COP' },
] as ParkingLot[]
const zones = [{ id: 'z1', parking_lot_id: 'l1', name: 'Cubierta', code: 'C' }] as ParkingZone[]
const plan = {
  id: 'p1', code: 'CORP', name: 'Corporativo', category: 'corporate', billing_period: 'monthly', price_amount: 30000000, currency: 'COP',
  grace_days: 3, max_vehicles: 2, capacity: null, sold: 0, parking_lot_ids: ['l1'], zone_ids: ['z1'], vehicle_types: [], windows: [], status: 'active', description: null,
} as MembershipPlan
const space = (id: string, code: string, assignable: boolean, zone: string | null): ParkingSpace => ({
  id, code, is_assignable: assignable, zone: zone ? { id: zone, name: 'Cubierta', code: 'C' } : null,
} as ParkingSpace)

describe('MembershipFormModal', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().currentTenant = { id: 't1', name: 'Demo', slug: 'demo', timezone: 'America/Bogota', roles: [], permissions: ['memberships.manage'] }
    vi.mocked(sellMembership).mockReset().mockResolvedValue({ id: 'm1', code: 'M-1' } as Membership)
    vi.mocked(listSpaces).mockReset().mockResolvedValue({
      items: [space('s1', 'M-01', true, 'z1'), space('s2', 'A-01', false, 'z1'), space('s3', 'M-02', true, null)],
      meta: {} as never,
    })
  })

  it('limits vehicles to the plan, offers only assignable spaces inside the plan and sends the sale', async () => {
    const wrapper = mount(MembershipFormModal, { props: { open: true, plans: [plan, { ...plan, id: 'p2', status: 'inactive' }], lots, zones, today: '2026-11-04' }, attachTo: document.body })
    await flushPromises()

    // A single plan for sale is preselected; inactive plans are not offered.
    expect(Array.from(document.querySelectorAll('#membership-plan option')).map((option) => option.textContent?.trim().split(' · ')[0])).toEqual(['Elige un plan', 'Corporativo'])
    expect(document.querySelector<HTMLSelectElement>('#membership-plan')!.value).toBe('p1')

    const add = Array.from(document.querySelectorAll('button')).find((button) => button.textContent?.includes('Agregar vehículo'))!
    add.click()
    await flushPromises()
    expect(add.disabled).toBe(true)

    const plates = document.querySelectorAll<HTMLInputElement>('input[id^="membership-plate-"]')
    plates[0]!.value = 'abc123'
    plates[0]!.dispatchEvent(new Event('input'))
    plates[1]!.value = 'xyz987'
    plates[1]!.dispatchEvent(new Event('input'))

    // Only the plan's lots can hold the assigned space.
    expect(Array.from(document.querySelectorAll('#membership-lot option')).map((option) => option.textContent?.trim())).toEqual(['Sin espacio fijo', 'Centro'])
    const lot = document.querySelector<HTMLSelectElement>('#membership-lot')!
    lot.value = 'l1'
    lot.dispatchEvent(new Event('change'))
    await flushPromises()

    expect(Array.from(document.querySelectorAll('#membership-space option')).map((option) => option.textContent?.trim())).toEqual(['Elige un espacio', 'M-01 · Cubierta'])
    const select = document.querySelector<HTMLSelectElement>('#membership-space')!
    select.value = 's1'
    select.dispatchEvent(new Event('change'))

    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(vi.mocked(sellMembership).mock.calls[0]![0]).toEqual({
      plan_id: 'p1',
      customer_id: '',
      starts_on: '2026-11-04',
      vehicles: [{ plate: 'abc123', country: 'CO', vehicle_type: 'car' }, { plate: 'xyz987', country: 'CO', vehicle_type: 'car' }],
      assigned_lot_id: 'l1',
      assigned_space_id: 's1',
      auto_renew: true,
      notes: null,
    })
    expect(wrapper.emitted('saved')).toHaveLength(1)
    wrapper.unmount()
  })
})
