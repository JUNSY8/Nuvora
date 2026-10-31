import { describe, expect, it } from 'vitest'

import { formatMoney, inputDecimals, toMajorInput, toMinorUnits } from '@/services/money'

// Intl uses non-breaking spaces between the symbol and the amount.
const plain = (value: string): string => value.replace(/\s/g, ' ')

describe('money', () => {
  it('formats minor units as whole pesos and dollars with cents', () => {
    expect(plain(formatMoney(500000, 'COP'))).toBe('$ 5.000')
    expect(plain(formatMoney(0, 'COP'))).toBe('$ 0')
    expect(plain(formatMoney(1250, 'USD'))).toBe('US$ 12,50')
    expect(formatMoney(null, 'COP')).toBe('—')
  })

  it('parses what a cashier types into chargeable minor units', () => {
    expect(inputDecimals('COP')).toBe(0)
    expect(inputDecimals('USD')).toBe(2)
    expect(toMinorUnits('5.000', 'COP')).toBe(500000)
    expect(toMinorUnits('$ 12,000', 'COP')).toBe(1200000)
    expect(toMinorUnits('12,50', 'USD')).toBe(1250)
    expect(toMinorUnits('1.234,5', 'USD')).toBe(123450)
    expect(toMinorUnits('1,234.50', 'USD')).toBe(123450)
    expect(toMinorUnits('', 'COP')).toBeNull()
    expect(toMinorUnits('-300', 'COP')).toBeNull()
    expect(toMinorUnits('abc', 'COP')).toBeNull()
  })

  it('round-trips amounts for form fields', () => {
    expect(toMajorInput(380000, 'COP')).toBe('3800')
    expect(toMajorInput(1250, 'USD')).toBe('12.50')
    expect(toMajorInput(null, 'COP')).toBe('')
    expect(toMinorUnits(toMajorInput(1250, 'USD'), 'USD')).toBe(1250)
  })
})
