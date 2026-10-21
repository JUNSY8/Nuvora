import { describe, expect, it } from 'vitest'

import { describeEvent } from '@/modules/vehicles/labels'
import type { VehicleEvent } from '@/modules/vehicles/types'

function event(type: VehicleEvent['type'], data: Record<string, unknown>): VehicleEvent {
  return { id: '1', type, data, actor: null, occurred_at: '2026-10-20T12:00:00Z' }
}

describe('describeEvent', () => {
  it('explains ownership transfers and relationship changes', () => {
    expect(describeEvent(event('customer_unlinked', { customer_name: 'Laura', relationship: 'owner', reason: 'ownership_transferred' })))
      .toBe('Laura dejó de ser propietario por transferencia de propiedad.')
    expect(describeEvent(event('relationship_changed', { customer_name: 'Pedro', from: 'driver', to: 'owner' })))
      .toBe('Pedro pasó de conductor a propietario.')
  })

  it('lists changed fields and status reasons', () => {
    expect(describeEvent(event('updated', { changes: { color: { from: 'Rojo', to: 'Gris' }, notes: null } }))).toBe('Cambió color, notas.')
    expect(describeEvent(event('status_changed', { from: 'active', to: 'blocked', reason: 'Deuda' }))).toBe('Activo → Bloqueado: Deuda')
    expect(describeEvent(event('plate_changed', { from: 'ABC123', to: 'ABC124', from_country: 'CO', to_country: 'CO' }))).toBe('De ABC123 (CO) a ABC124 (CO).')
  })
})
