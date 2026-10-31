export type ParkingLotStatus = 'active' | 'inactive' | 'maintenance'
export type SpaceStatus = 'available' | 'occupied' | 'reserved' | 'blocked' | 'maintenance' | 'out_of_service'
export type SpaceType = 'car' | 'motorcycle' | 'truck' | 'van' | 'ev' | 'disabled' | 'vip' | 'reserved'
export type GateDirection = 'entry' | 'exit' | 'bidirectional'
export type GateStatus = 'active' | 'inactive' | 'maintenance'
export type OperatingDayMode = 'closed' | 'open_24h' | 'scheduled'

export interface Occupancy {
  total: number
  capacity: number
  available: number
  in_use: number
  rate: number
  by_status: Record<SpaceStatus, number>
}

export interface OperatingHour {
  day_of_week: number
  mode: OperatingDayMode
  opens_at: string | null
  closes_at: string | null
}

export interface ParkingLot {
  id: string
  name: string
  code: string
  description: string | null
  address: string | null
  city: string | null
  latitude: string | null
  longitude: string | null
  timezone: string
  currency: string
  grace_period_minutes: number
  status: ParkingLotStatus
  zones_count: number
  gates_count: number
  occupancy: Occupancy | null
  operating_hours?: OperatingHour[]
  created_at: string | null
  updated_at: string | null
}

export interface ParkingLotInput {
  name: string
  code: string
  description: string | null
  address: string | null
  city: string | null
  latitude: string | null
  longitude: string | null
  timezone: string
  currency: string
  grace_period_minutes: number
  status: ParkingLotStatus
}

export interface ParkingZone {
  id: string
  parking_lot_id: string
  name: string
  code: string
  description: string | null
  is_active: boolean
  sort_order: number
  spaces_count: number
}

export interface ParkingZoneInput {
  name: string
  code: string
  description: string | null
  is_active: boolean
  sort_order: number
}

export interface ParkingSpace {
  id: string
  parking_lot_id: string
  code: string
  type: SpaceType
  status: SpaceStatus
  floor: string | null
  is_reservable: boolean
  is_assignable: boolean
  zone: { id: string; name: string; code: string } | null
  status_changed_at: string | null
  manual_transitions: SpaceStatus[]
  removable: boolean
}

export interface ParkingSpaceAttributes {
  zone_id: string | null
  type: SpaceType
  floor: string | null
  is_reservable: boolean
  is_assignable: boolean
}

export interface ParkingSpaceInput extends ParkingSpaceAttributes {
  code: string
}

export interface ParkingSpaceRangeInput extends ParkingSpaceAttributes {
  prefix: string
  from: number
  to: number
  padding: number
}

export interface SpaceFilters {
  zone_id?: string
  status?: SpaceStatus
  type?: SpaceType
  search?: string
}

export interface ParkingGate {
  id: string
  parking_lot_id: string
  name: string
  code: string
  direction: GateDirection
  status: GateStatus
}

export interface ParkingGateInput {
  name: string
  code: string
  direction: GateDirection
  status: GateStatus
}
