import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { ParkingLot } from '@/modules/parking/types'
import { createReservation, getAvailability } from '@/modules/reservations/api'
import ReservationFormModal from '@/modules/reservations/ReservationFormModal.vue'
import type { Availability, Reservation } from '@/modules/reservations/types'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/modules/reservations/api', () => ({ createReservation: vi.fn(), getAvailability: vi.fn() }))

const lot = { id: 'lot-1', name: 'Centro', timezone: 'America/Bogota', currency: 'COP' } as ParkingLot
const availability: Availability = {
  lot: { id: 'lot-1', timezone: 'America/Bogota' },
  policy: { enabled: true, buffer_minutes: 10, hold_minutes: 15, arrival_tolerance_minutes: 30, min_duration_minutes: 30, max_duration_minutes: 1440, max_advance_days: 60, within_operating_hours: true },
  opens_throughout: true,
  pool: 3,
  available: 2,
  zones: [{ id: 'z1', name: 'Norte', code: 'N', pool: 2, available: 1 }],
  spaces: [{ id: 's1', code: 'R-01', zone_id: 'z1', type: 'car', free: true }, { id: 's2', code: 'R-02', zone_id: 'z1', type: 'car', free: false }],
}
const created = { reservation: { id: 'r1', code: 'R-7K3M9Q', vehicle: { plate: 'ABC123' } } as Reservation, replayed: false }

describe('ReservationFormModal', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().currentTenant = { id: 't1', name: 'Demo', slug: 'demo', timezone: 'America/Bogota', roles: [], permissions: ['reservations.manage'] }
    vi.mocked(getAvailability).mockReset().mockResolvedValue(availability)
    vi.mocked(createReservation).mockReset()
  })

  it('shows availability, offers only free spaces and renews the key for another plate', async () => {
    vi.mocked(createReservation).mockResolvedValue(created)
    const wrapper = mount(ReservationFormModal, { props: { open: true, lot }, attachTo: document.body })
    await flushPromises()

    expect(document.body.textContent).toContain('2 de 3 espacios reservables libres')

    const plate = document.querySelector<HTMLInputElement>('#reservation-plate')!
    plate.value = 'abc-123'
    plate.dispatchEvent(new Event('input'))
    const space = document.querySelector<HTMLInputElement>('input[name="reservation-scope"][value="space"]')!
    space.checked = true
    space.dispatchEvent(new Event('change'))
    await flushPromises()

    const options = Array.from(document.querySelectorAll<HTMLOptionElement>('#reservation-space option')).map((option) => option.textContent?.trim())
    expect(options).toEqual(['Elige un espacio', 'R-01'])

    const select = document.querySelector<HTMLSelectElement>('#reservation-space')!
    select.value = 's1'
    select.dispatchEvent(new Event('change'))
    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()

    const [input, key] = vi.mocked(createReservation).mock.calls[0]!
    expect(input).toMatchObject({ lot_id: 'lot-1', plate: 'abc-123', space_id: 's1', zone_id: null, confirm: true })
    expect(input.starts_at).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:00$/)

    // Retrying the same plate keeps the key; another plate gets a new one.
    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()
    plate.value = 'XYZ987'
    plate.dispatchEvent(new Event('input'))
    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()

    const keys = vi.mocked(createReservation).mock.calls.map((call) => call[1])
    expect(keys[1]).toBe(key)
    expect(keys[2]).not.toBe(key)
    wrapper.unmount()
  })
})
