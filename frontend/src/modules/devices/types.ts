export type DeviceType = 'camera' | 'barrier' | 'ticket_printer' | 'display' | 'sensor' | 'access_reader' | 'payment_terminal' | 'kiosk'
export type DeviceStatus = 'online' | 'offline' | 'degraded' | 'unknown' | 'maintenance'

export interface ResourceRef {
  id: string
  name: string
  code: string
}

export interface Device {
  id: string
  name: string
  code: string
  type: DeviceType
  vendor: string | null
  model: string | null
  serial_number: string | null
  ip_address: string | null
  firmware_version: string | null
  status: DeviceStatus
  last_seen_at: string | null
  parking_lot: ResourceRef | null
  gate: ResourceRef | null
  manual_transitions: DeviceStatus[]
  created_at: string | null
}

export interface DeviceInput {
  parking_lot_id: string
  gate_id: string | null
  name: string
  code: string
  type: DeviceType
  vendor: string | null
  model: string | null
  serial_number: string | null
  ip_address: string | null
  firmware_version: string | null
}

export interface DeviceFilters {
  parking_lot_id?: string
  status?: DeviceStatus
  type?: DeviceType
  search?: string
}
