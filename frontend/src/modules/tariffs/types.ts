import type { VehicleType } from '@/modules/vehicles/types'

export type TariffStatus = 'active' | 'inactive'
export type DiscountType = 'percentage' | 'fixed_amount' | 'free_minutes'
export type DiscountApplication = 'manual' | 'automatic'

export interface TimeWindow {
  days: number[]
  start: string
  end: string
}

// Amounts are integers in the minor unit of the currency (500000 = $5.000 COP).
export interface PriceBracket {
  up_to_minutes: number
  amount: number
}

interface TariffRules {
  code: string
  name: string
  priority: number
  vehicle_types: VehicleType[]
  zone: { id: string; name: string | null; code: string | null } | null
  windows: TimeWindow[]
  grace_minutes: number | null
  brackets: PriceBracket[]
  step_minutes: number
  step_amount: number
  daily_max_amount: number | null
}

export interface Tariff extends TariffRules {
  id: string
  parking_lot_id: string
  currency: string
  status: TariffStatus
  version: number
  effective_from: string
  created_at: string | null
}

export interface TariffVersion extends TariffRules {
  id: string
  version: number
  status: TariffStatus
  effective_from: string
  change_note: string | null
  author: { id: string; name: string } | null
}

export interface TariffInput {
  code: string
  name: string
  priority: number
  vehicle_types: VehicleType[]
  zone_id: string | null
  windows: TimeWindow[]
  grace_minutes: number | null
  brackets: PriceBracket[]
  step_minutes: number
  step_amount: number
  daily_max_amount: number | null
  change_note: string | null
}

export interface Discount {
  id: string
  parking_lot_id: string | null
  code: string
  name: string
  type: DiscountType
  value: number
  currency: string | null
  application: DiscountApplication
  status: TariffStatus
  vehicle_types: VehicleType[]
  days: number[]
  valid_from: string | null
  valid_until: string | null
}

export interface DiscountInput {
  parking_lot_id: string | null
  code: string
  name: string
  type: DiscountType
  value: number
  currency: string | null
  application: DiscountApplication
  vehicle_types: VehicleType[]
  days: number[]
  valid_from: string | null
  valid_until: string | null
}

export interface PriceLine {
  type: 'bracket' | 'step' | 'day' | 'daily_cap' | 'grace' | 'discount'
  label: string
  amount: number
  quantity: number | null
  unit_amount: number | null
}

// Answer of the pricing engine, for a quote, a simulation or a closed session.
export interface Quote {
  status: 'priced' | 'unpriced'
  currency: string
  tariff: { id: string; version_id: string; version: number; code: string; name: string } | null
  discount_rule: { id: string; code: string; name: string } | null
  stay_minutes: number
  charged_minutes: number | null
  grace_minutes: number | null
  grace_applied: boolean
  subtotal: number | null
  discount: number | null
  total: number | null
  lines: PriceLine[]
  entry_local: string
  exit_local: string
  zone_id: string | null
  reason: string | null
}

export interface SimulationInput {
  lot_id: string
  vehicle_type: VehicleType
  zone_id: string | null
  entry_at: string
  exit_at: string
  discount_id: string | null
}
