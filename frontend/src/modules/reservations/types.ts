import type { Quote } from '@/modules/tariffs/types'
import type { VehicleType } from '@/modules/vehicles/types'

export type ReservationStatus = 'pending' | 'confirmed' | 'active' | 'completed' | 'cancelled' | 'expired' | 'no_show'
export type ReservationScope = 'lot' | 'zone' | 'space'

interface Reference {
  id: string
  name: string | null
  code: string | null
}

export interface Reservation {
  id: string
  code: string
  status: ReservationStatus
  parking_lot: Reference
  vehicle: { id: string; plate: string; country: string; type: VehicleType }
  customer: { id: string; name: string; type: string } | null
  scope: ReservationScope
  zone: Reference | null
  space: { id: string; code: string | null } | null
  starts_at: string
  ends_at: string
  arrival_from: string
  no_show_after: string
  hold_expires_at: string | null
  confirmed_at: string | null
  checked_in_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  expired_at: string | null
  cancel_reason: string | null
  currency: string
  // Estimate in minor units with the tariffs in force at the start.
  quoted_amount: number | null
  pricing: Quote | null
  notes: string | null
  created_by: { id: string; name: string } | null
  created_at: string | null
}

export interface ReservationInput {
  lot_id: string
  plate: string
  country: string
  vehicle_type: VehicleType | null
  customer_id: string | null
  zone_id: string | null
  space_id: string | null
  starts_at: string
  ends_at: string
  confirm: boolean
  notes: string | null
}

export interface ReservationFilters {
  lot_id?: string
  status?: ReservationStatus[]
  search?: string
  from_date?: string
  to_date?: string
  vehicle_id?: string
}

export interface ReservationPolicy {
  enabled: boolean
  buffer_minutes: number
  hold_minutes: number
  arrival_tolerance_minutes: number
  min_duration_minutes: number
  max_duration_minutes: number
  max_advance_days: number
  within_operating_hours: boolean
}

export interface Availability {
  lot: { id: string; timezone: string }
  policy: ReservationPolicy
  opens_throughout: boolean
  pool: number
  available: number
  zones: Array<{ id: string; name: string | null; code: string | null; pool: number; available: number }>
  spaces: Array<{ id: string; code: string; zone_id: string | null; type: string; free: boolean }>
}
