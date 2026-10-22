import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, PageMeta } from '@/types/api'

import type { Availability, Reservation, ReservationFilters, ReservationInput, ReservationPolicy } from '@/modules/reservations/types'
import type { VehicleType } from '@/modules/vehicles/types'

const base = '/api/v1/reservations'

export async function listReservations(filters: ReservationFilters = {}, page = 1, perPage = 25): Promise<{ items: Reservation[]; meta: PageMeta }> {
  const response = await http.get<ApiSuccess<{ items: Reservation[]; meta: PageMeta }>>(base, {
    params: { ...filters, page, per_page: perPage },
  })

  return response.data.data
}

// The same idempotency key must be sent again when retrying the same booking.
export async function createReservation(input: ReservationInput, idempotencyKey: string): Promise<{ reservation: Reservation; replayed: boolean }> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ reservation: Reservation; replayed: boolean }>>(base, input, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })

  return response.data.data
}

export async function confirmReservation(id: string): Promise<Reservation> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ reservation: Reservation }>>(`${base}/${id}/confirm`)

  return response.data.data.reservation
}

export async function cancelReservation(id: string, reason: string): Promise<Reservation> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ reservation: Reservation }>>(`${base}/${id}/cancel`, { reason })

  return response.data.data.reservation
}

export async function getAvailability(params: { lot_id: string; vehicle_type: VehicleType; starts_at: string; ends_at: string }): Promise<Availability> {
  const response = await http.get<ApiSuccess<Availability>>(`${base}/availability`, { params })

  return response.data.data
}

export async function getReservationPolicy(lotId: string): Promise<ReservationPolicy> {
  const response = await http.get<ApiSuccess<{ policy: ReservationPolicy }>>(`${base}/policy`, { params: { lot_id: lotId } })

  return response.data.data.policy
}

export async function updateReservationPolicy(lotId: string, policy: ReservationPolicy): Promise<ReservationPolicy> {
  await ensureCsrfCookie()
  const response = await http.put<ApiSuccess<{ policy: ReservationPolicy }>>(`${base}/policy`, { lot_id: lotId, ...policy })

  return response.data.data.policy
}
