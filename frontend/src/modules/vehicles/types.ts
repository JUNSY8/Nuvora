import type { CustomerSummary } from '@/modules/customers/types'

export type VehicleType = 'car' | 'motorcycle' | 'bicycle' | 'van' | 'truck' | 'bus' | 'other'
export type VehicleStatus = 'active' | 'inactive' | 'blocked'
export type VehicleRelationship = 'owner' | 'driver' | 'authorized'
export type VehicleEventType =
  | 'registered'
  | 'updated'
  | 'plate_changed'
  | 'status_changed'
  | 'customer_linked'
  | 'relationship_changed'
  | 'customer_unlinked'
  | 'entered'
  | 'exited'
  | 'session_cancelled'
  | 'reserved'
  | 'membership_added'
  | 'membership_removed'

export interface VehicleLink {
  id: string
  relationship: VehicleRelationship
  started_at: string
  customer: CustomerSummary | null
}

export interface Vehicle {
  id: string
  plate: string
  country: string
  plate_format: { key: string; label: string } | null
  type: VehicleType
  brand: string | null
  model: string | null
  color: string | null
  year: number | null
  is_electric: boolean
  status: VehicleStatus
  status_reason: string | null
  status_changed_at: string | null
  notes: string | null
  links: VehicleLink[]
  created_at: string | null
}

export interface VehicleInput {
  plate: string
  country: string
  type: VehicleType
  brand: string | null
  model: string | null
  color: string | null
  year: number | null
  is_electric: boolean
  notes: string | null
  customer_id?: string | null
  relationship?: VehicleRelationship
}

export interface VehicleFilters {
  status?: VehicleStatus
  type?: VehicleType
  customer_id?: string
  search?: string
}

export interface PlateCheck {
  plate: string
  country: string
  format: { key: string; label: string } | null
  rules_available: boolean
  suggested_type: VehicleType | null
  existing: { id: string; plate: string; status: VehicleStatus } | null
}

export interface VehicleEvent {
  id: string
  type: VehicleEventType
  data: Record<string, unknown>
  actor: { id: string; name: string } | null
  occurred_at: string
}
