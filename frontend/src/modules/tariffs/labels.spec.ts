import { describe, expect, it } from 'vitest'

import { describeDays, describeDiscountValue, describeMinutes, describeRates, describeWindow } from '@/modules/tariffs/labels'

const plain = (value: string): string => value.replace(/\s/g, ' ')

describe('tariff labels', () => {
  it('describes durations, days and windows in Spanish', () => {
    expect(describeMinutes(15)).toBe('15 min')
    expect(describeMinutes(60)).toBe('1 h')
    expect(describeMinutes(90)).toBe('1 h 30 min')
    expect(describeMinutes(2880)).toBe('2 días')
    expect(describeDays([5, 4, 3, 2, 1])).toBe('Lunes a viernes')
    expect(describeDays([2, 4])).toBe('martes, jueves')
    expect(describeWindow({ days: [1, 2, 3, 4, 5, 6, 7], start: '19:00', end: '06:00' })).toBe('Todos los días, 19:00 a 06:00')
    expect(describeWindow({ days: [6, 7], start: '00:00', end: '00:00' })).toBe('Fines de semana, todo el día')
  })

  it('summarizes the price in the order the engine applies it', () => {
    const lines = describeRates({ brackets: [{ up_to_minutes: 30, amount: 300000 }], step_minutes: 15, step_amount: 120000, daily_max_amount: 3800000 }, 'COP').map(plain)

    expect(lines).toEqual(['Hasta 30 min: $ 3.000', 'Luego $ 1.200 por cada 15 min adicional', 'Tope por 24 h: $ 38.000'])
    expect(describeRates({ brackets: [], step_minutes: 60, step_amount: 300000, daily_max_amount: null }, 'COP').map(plain)).toEqual(['$ 3.000 por cada 1 h'])
  })

  it('describes each kind of discount', () => {
    expect(describeDiscountValue({ type: 'percentage', value: 1250, currency: null })).toBe('12,5 %')
    expect(plain(describeDiscountValue({ type: 'fixed_amount', value: 200000, currency: 'COP' }))).toBe('$ 2.000')
    expect(describeDiscountValue({ type: 'free_minutes', value: 120, currency: null })).toBe('2 h gratis')
  })
})
