import type { CustomerType } from '@/modules/customers/types'
import type { TimeWindow } from '@/modules/tariffs/types'
import type { VehicleType } from '@/modules/vehicles/types'

export type PlanCategory = 'monthly' | 'weekly' | 'corporate' | 'vip' | 'employee' | 'resident' | 'student' | 'other'
export type BillingPeriod = 'weekly' | 'monthly' | 'quarterly' | 'yearly'
export type PlanStatus = 'active' | 'inactive'
export type MembershipStatus = 'active' | 'suspended' | 'expired' | 'cancelled'

// Empty lists of lots, zones, vehicle types or windows mean no restriction.
// The price is an integer in the minor unit of the currency.
export interface MembershipPlan {
  id: string
  code: string
  name: string
  category: PlanCategory
  billing_period: BillingPeriod
  price_amount: number
  currency: string
  grace_days: number
  max_vehicles: number
  capacity: number | null
  sold: number | null
  parking_lot_ids: string[]
  zone_ids: string[]
  vehicle_types: VehicleType[]
  windows: TimeWindow[]
  status: PlanStatus
  description: string | null
}

export interface PlanInput {
  code: string
  name: string
  category: PlanCategory
  billing_period: BillingPeriod
  price_amount: number
  currency: string
  grace_days: number
  max_vehicles: number
  capacity: number | null
  parking_lot_ids: string[]
  zone_ids: string[]
  vehicle_types: VehicleType[]
  windows: TimeWindow[]
  description: string | null
}

export interface MembershipPeriod {
  id: string
  sequence: number
  starts_on: string
  ends_on: string
  amount: number
  currency: string
  status: string
  origin: string
  // Collected so far and still owed, refunds subtracted.
  paid: number
  balance: number
}

// Dates are local days of the tenant (Y-m-d); the period end is inclusive.
export interface Membership {
  id: string
  code: string
  status: MembershipStatus
  status_reason: string | null
  plan: { id: string; code: string; name: string; category: PlanCategory }
  customer: { id: string; name: string; type: CustomerType } | null
  billing_period: BillingPeriod
  price_amount: number
  currency: string
  grace_days: number
  starts_on: string
  current_period_start: string
  current_period_end: string
  covered_until: string
  ended_on: string | null
  auto_renew: boolean
  assigned_space: { id: string; code: string; parking_lot_id: string } | null
  vehicles: Array<{ id: string; plate: string; country: string | null; type: VehicleType | null; added_at: string }>
  notes: string | null
  created_at: string | null
  periods?: MembershipPeriod[]
  // What all its periods still owe; only in the detail.
  outstanding?: number
}

export interface MembershipVehicleInput {
  plate: string
  country: string
  vehicle_type: VehicleType | null
}

export interface MembershipInput {
  plan_id: string
  customer_id: string
  starts_on: string
  vehicles: MembershipVehicleInput[]
  assigned_lot_id: string | null
  assigned_space_id: string | null
  auto_renew: boolean
  notes: string | null
}

export interface MembershipFilters {
  status?: MembershipStatus
  plan_id?: string
  customer_id?: string
  search?: string
}
