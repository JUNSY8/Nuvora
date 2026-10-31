import { describe, expect, it } from 'vitest'

import { changeFor, suggestedTenders } from '@/modules/payments/labels'

describe('payment labels', () => {
  it('computes the change only when the cash covers the charge', () => {
    expect(changeFor(600000, 1000000)).toBe(400000)
    expect(changeFor(600000, 600000)).toBe(0)
    expect(changeFor(600000, 500000)).toBeNull()
    expect(changeFor(600000, null)).toBe(0)
  })

  it('suggests the exact amount and the next round bills for pesos', () => {
    expect(suggestedTenders(650000, 'COP')).toEqual([650000, 800000, 1000000, 2000000])
    expect(suggestedTenders(5000000, 'COP')).toEqual([5000000, 6000000, 10000000])
    expect(suggestedTenders(0, 'COP')).toEqual([])
    expect(suggestedTenders(1500, 'USD')).toEqual([])
  })
})
