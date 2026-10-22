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

  it('describes parking stays', () => {
    expect(describeEvent(event('entered', { space_code: 'A-03' }))).toBe('Ingresó al espacio A-03.')
    expect(describeEvent(event('entered', { space_code: null }))).toBe('Ingresó sin espacio asignado.')
    expect(describeEvent(event('exited', { space_code: 'A-03', duration_minutes: 95 }))).toBe('Salió del espacio A-03 tras 1 h 35 min.')
    expect(describeEvent(event('exited', { duration_minutes: 95, total_amount: 800000, currency: 'COP' })).replace(/\s/g, ' ')).toBe('Salió tras 1 h 35 min. Valor: $ 8.000.')
    expect(describeEvent(event('session_cancelled', { reason: 'Placa errada' }))).toBe('Sesión anulada: Placa errada')
    expect(describeEvent(event('reserved', { code: 'R-7K3M9Q', starts_local: '2026-11-04 10:00' }))).toBe('Reserva R-7K3M9Q para el 2026-11-04 a las 10:00.')
    expect(describeEvent(event('entered', { space_code: 'R-01', reservation_code: 'R-7K3M9Q' }))).toBe('Ingresó al espacio R-01 con la reserva R-7K3M9Q.')
    expect(describeEvent(event('registered', { plate: 'ABC123', country: 'CO', type: 'car', source: 'entry' })))
      .toBe('Placa ABC123 (CO) como automóvil, al ingresar como visitante.')
  })

  it('describes memberships and covered stays', () => {
    expect(describeEvent(event('membership_added', { code: 'M-7K3M9Q', plan: 'Mensual carro' }))).toBe('Incluido en la mensualidad M-7K3M9Q (Mensual carro).')
    expect(describeEvent(event('membership_removed', { code: 'M-7K3M9Q' }))).toBe('Retirado de la mensualidad M-7K3M9Q.')
    expect(describeEvent(event('entered', { space_code: 'M-01', membership_code: 'M-7K3M9Q' }))).toBe('Ingresó al espacio M-01 con la mensualidad M-7K3M9Q.')
    expect(describeEvent(event('exited', { duration_minutes: 30, total_amount: 0, currency: 'COP', charge_status: 'covered' }))).toBe('Salió tras 30 min. Cubierta por la mensualidad.')
    expect(describeEvent(event('registered', { plate: 'ABC123', country: 'CO', type: 'car', source: 'membership' })))
      .toBe('Placa ABC123 (CO) como automóvil, al agregarlo a una mensualidad.')
  })
})
