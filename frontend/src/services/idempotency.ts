/**
 * Key sent as Idempotency-Key with entries and exits. Create one per
 * attempt and reuse it when retrying the same attempt, so a request that
 * timed out cannot register the vehicle twice.
 */
export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  const random = Array.from({ length: 4 }, () => Math.floor(Math.random() * 0x100000000).toString(16).padStart(8, '0')).join('')

  return `k-${Date.now().toString(16)}-${random}`
}
