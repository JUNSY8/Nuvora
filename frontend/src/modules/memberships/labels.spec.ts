import { describe, expect, it } from 'vitest'

import { coverageHint, daysUntil, describeAccess, describePrice } from '@/modules/memberships/labels'
import type { Membership, MembershipPlan } from '@/modules/memberships/types'

const plan: MembershipPlan = {
  id: 'p1',
  code: 'CARRO-DIA',
  name: 'Carro diurno',
  category: 'monthly',
  billing_period: 'monthly',
  price_amount: 12000000,
  currency: 'COP',
  grace_days: 3,
  max_vehicles: 1,
  capacity: 20,
  sold: 4,
  parking_lot_ids: ['l1'],
  zone_ids: ['z1'],
  vehicle_types: ['car'],
  windows: [{ days: [1, 2, 3, 4, 5], start: '06:00', end: '20:00' }],
  status: 'active',
  description: null,
}

function membership(status: Membership['status'], periodEnd: string, coveredUntil: string): Membership {
  return { status, current_period_end: periodEnd, covered_until: coveredUntil } as Membership
}

describe('membership labels', () => {
  it('describes the price per period and every access restriction', () => {
    expect(describePrice(plan).replace(/\s/g, ' ')).toBe('$ 120.000 / mes')
    expect(describeAccess(plan, () => 'Centro', () => 'Cubierta')).toEqual([
      'Centro',
      'Zonas: Cubierta',
      'Automóvil',
      'Lunes a viernes, 06:00 a 20:00',
    ])
    expect(describeAccess({ ...plan, parking_lot_ids: [], zone_ids: [], vehicle_types: [], windows: [] }, () => '', () => ''))
      .toEqual(['Todos los parqueaderos', 'Todos los vehículos', 'A cualquier hora'])
  })

  it('counts local days without timezone drift', () => {
    expect(daysUntil('2026-11-05', '2026-11-01')).toBe(4)
    expect(daysUntil('2026-10-31', '2026-11-01')).toBe(-1)
  })

  it('warns when the period is ending or running on grace days', () => {
    const today = '2026-11-10'

    expect(coverageHint(membership('active', '2026-11-30', '2026-12-03'), today)).toBeNull()
    expect(coverageHint(membership('active', '2026-11-13', '2026-11-16'), today)).toEqual({ text: 'Termina en 3 día(s)', tone: 'warning' })
    expect(coverageHint(membership('active', '2026-11-10', '2026-11-13'), today)?.text).toBe('Termina hoy')
    expect(coverageHint(membership('active', '2026-11-08', '2026-11-11'), today)).toEqual({ text: 'En gracia: 1 día(s)', tone: 'danger' })
    expect(coverageHint(membership('active', '2026-11-05', '2026-11-08'), today)?.text).toBe('Sin cobertura')
    expect(coverageHint(membership('suspended', '2026-11-08', '2026-11-11'), today)).toBeNull()
  })
})
