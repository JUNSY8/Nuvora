import type { Occupancy, ParkingLotStatus } from '@/modules/parking/types'
import type { PaymentBalance, PaymentMethod } from '@/modules/payments/types'
import type { PriceLine, Quote } from '@/modules/tariffs/types'
import type { VehicleType } from '@/modules/vehicles/types'

export type SessionStatus = 'open' | 'closed' | 'cancelled'
export type SessionSource = 'manual'

interface Reference {
  id: string
  name: string
  code: string
}

export interface ParkingSession {
  id: string
  status: SessionStatus
  parking_lot: Reference
  vehicle: { id: string; plate: string; country: string; type: VehicleType }
  customer: { id: string; name: string; type: string } | null
  space: { id: string; code: string } | null
  entry_at: string
  exit_at: string | null
  cancelled_at: string | null
  duration_minutes: number | null
  entry_source: SessionSource
  exit_source: SessionSource | null
  entry_gate: Reference | null
  exit_gate: Reference | null
  opened_by: { id: string; name: string } | null
  closed_by: { id: string; name: string } | null
  cancel_reason: string | null
  notes: string | null
  charge: SessionCharge | null
  // What the stay owes once closed; null while open or when cancelled.
  payment: PaymentBalance | null
  reservation: { id: string; code: string; status: string } | null
  membership: { id: string; code: string; plan: string } | null
}

// Charge stored at the exit; amounts in minor units of currency.
export interface SessionCharge {
  status: 'priced' | 'unpriced' | 'covered'
  currency: string | null
  subtotal: number | null
  discount: number | null
  total: number | null
  charged_minutes: number | null
  grace_applied: boolean
  tariff: Quote['tariff']
  discount_rule: Quote['discount_rule']
  membership: Quote['membership']
  lines: PriceLine[]
  reason: string | null
}

// What the operator should know about the entry (membership coverage...).
export interface SessionNotice {
  type: 'info' | 'warning'
  message: string
}

// Payment collected together with an exit.
export interface ExitPaymentResult {
  id: string
  number: string
  method: PaymentMethod
  amount: number
  currency: string
  tendered_amount: number | null
  change_amount: number | null
}

export interface SessionResult {
  session: ParkingSession
  replayed: boolean
  notices?: SessionNotice[]
  payment?: ExitPaymentResult | null
}

export interface ExitInput {
  gate_id: string | null
  notes: string | null
  discount_id: string | null
  quote_token: string | null
  payment: { method: PaymentMethod; tendered_amount: number | null; reference: string | null } | null
}

export interface OperationsSummary {
  lot: { id: string; name: string; code: string; timezone: string; status: ParkingLotStatus }
  occupancy: Occupancy
  open: number
  open_without_space: number
  entries_today: number
  exits_today: number
  charged_today: number
  collected_today: number
  unpaid_today: number
  unpriced_today: number
  covered_today: number
  currency: string
  cancelled_today: number
  average_stay_minutes_today: number | null
  oldest_open_entry_at: string | null
  day_starts_at: string
  version: string
}

export interface EntryInput {
  lot_id: string
  plate: string
  country: string
  vehicle_type: VehicleType | null
  space_id: string | null
  auto_assign: boolean
  gate_id: string | null
  notes: string | null
}

export interface SessionFilters {
  lot_id?: string
  status?: SessionStatus
  search?: string
  vehicle_id?: string
  from_date?: string
  to_date?: string
  unpaid?: 0 | 1
}
