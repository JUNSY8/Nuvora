import { describe, expect, it } from 'vitest'

import { formatDate, formatDuration, minutesSince, toLocalInput } from '@/services/format'
import { newIdempotencyKey } from '@/services/idempotency'

describe('formatDuration', () => {
  it('shows minutes, hours and days as operators read them', () => {
    expect(formatDuration(0)).toBe('0 min')
    expect(formatDuration(45)).toBe('45 min')
    expect(formatDuration(65)).toBe('1 h 05 min')
    expect(formatDuration(1440)).toBe('1 d')
    expect(formatDuration(1560)).toBe('1 d 2 h')
  })

  it('does not invent a duration for missing or invalid values', () => {
    expect(formatDuration(null)).toBe('—')
    expect(formatDuration(-3)).toBe('—')
    expect(formatDuration(Number.NaN)).toBe('—')
  })
})

describe('minutesSince', () => {
  it('counts a started minute as a full one, like the API', () => {
    const entry = '2026-11-04T15:00:00Z'
    const at = (seconds: number) => Date.parse(entry) + seconds * 1000

    expect(minutesSince(entry, at(0))).toBe(0)
    expect(minutesSince(entry, at(1))).toBe(1)
    expect(minutesSince(entry, at(60))).toBe(1)
    expect(minutesSince(entry, at(61))).toBe(2)
    expect(minutesSince(entry, at(-30))).toBe(0)
  })
})

describe('newIdempotencyKey', () => {
  it('creates distinct keys the API accepts', () => {
    const keys = new Set(Array.from({ length: 50 }, () => newIdempotencyKey()))

    expect(keys.size).toBe(50)
    for (const key of keys) {
      expect(key).toMatch(/^[A-Za-z0-9_\-:.]{8,80}$/)
    }
  })
})

describe('toLocalInput', () => {
  it('writes the wall clock of the lot, not of the browser', () => {
    const instant = new Date('2026-11-05T03:30:00Z')

    expect(toLocalInput(instant, 'America/Bogota')).toBe('2026-11-04T22:30')
    expect(toLocalInput(instant, 'UTC')).toBe('2026-11-05T03:30')
  })
})

describe('formatDate', () => {
  it('shows a local day without shifting it to another timezone', () => {
    // The exact month text depends on the ICU data; the day must not move.
    expect(formatDate('2026-11-01')).toMatch(/^1\D.*2026$/)
    expect(formatDate('2026-12-31')).toMatch(/^31\D.*2026$/)
    expect(formatDate(null)).toBe('—')
    expect(formatDate('2026-11-01T10:00:00Z')).toBe('—')
  })
})
