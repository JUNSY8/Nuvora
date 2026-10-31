import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, PageMeta } from '@/types/api'

import type { EntryInput, ExitInput, OperationsSummary, ParkingSession, SessionFilters, SessionResult } from '@/modules/sessions/types'
import type { Quote } from '@/modules/tariffs/types'

const base = '/api/v1/parking-sessions'

export async function listSessions(filters: SessionFilters = {}, page = 1, perPage = 25): Promise<{ items: ParkingSession[]; meta: PageMeta }> {
  const response = await http.get<ApiSuccess<{ items: ParkingSession[]; meta: PageMeta }>>(base, {
    params: { ...filters, page, per_page: perPage },
  })

  return response.data.data
}

export async function getOperationsSummary(lotId: string): Promise<OperationsSummary> {
  const response = await http.get<ApiSuccess<OperationsSummary>>(`${base}/summary`, { params: { lot_id: lotId } })

  return response.data.data
}

// The same idempotency key must be sent again when retrying the same entry.
export async function registerEntry(input: EntryInput, idempotencyKey: string): Promise<SessionResult> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<SessionResult>>(`${base}/entries`, input, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })

  return response.data.data
}

// What the stay would cost if it left now; nothing is stored. The quote token
// holds that price for a few minutes when sent with the exit.
export async function quoteSession(sessionId: string, discountId: string | null): Promise<Quote> {
  const response = await http.get<ApiSuccess<{ quote: Quote }>>(`${base}/${sessionId}/quote`, {
    params: discountId ? { discount_id: discountId } : {},
  })

  return response.data.data.quote
}

// A payment sent with the exit is collected in the same request; if it fails the vehicle stays inside.
export async function registerExit(sessionId: string, input: ExitInput, idempotencyKey: string): Promise<SessionResult> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<SessionResult>>(`${base}/${sessionId}/exit`, input, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })

  return response.data.data
}

export async function cancelSession(sessionId: string, reason: string): Promise<ParkingSession> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<SessionResult>>(`${base}/${sessionId}/cancel`, { reason })

  return response.data.data.session
}

export async function moveSession(sessionId: string, spaceId: string): Promise<ParkingSession> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<SessionResult>>(`${base}/${sessionId}/space`, { space_id: spaceId })

  return response.data.data.session
}
