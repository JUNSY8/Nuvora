import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, PageMeta } from '@/types/api'

import type {
  OperatingHour,
  ParkingGate,
  ParkingGateInput,
  ParkingLot,
  ParkingLotInput,
  ParkingSpace,
  ParkingSpaceInput,
  ParkingSpaceRangeInput,
  ParkingZone,
  ParkingZoneInput,
  SpaceFilters,
  SpaceStatus,
} from '@/modules/parking/types'

export interface Page<T> {
  items: T[]
  meta: PageMeta
}

const base = '/api/v1/parking-lots'

export async function listParkingLots(params: { page?: number; per_page?: number; search?: string } = {}): Promise<Page<ParkingLot>> {
  const response = await http.get<ApiSuccess<Page<ParkingLot>>>(base, { params })

  return response.data.data
}

export async function getParkingLot(id: string): Promise<ParkingLot> {
  const response = await http.get<ApiSuccess<{ lot: ParkingLot }>>(`${base}/${id}`)

  return response.data.data.lot
}

export async function createParkingLot(input: ParkingLotInput): Promise<ParkingLot> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ lot: ParkingLot }>>(base, input)

  return response.data.data.lot
}

export async function updateParkingLot(id: string, input: ParkingLotInput): Promise<ParkingLot> {
  await ensureCsrfCookie()
  const response = await http.patch<ApiSuccess<{ lot: ParkingLot }>>(`${base}/${id}`, input)

  return response.data.data.lot
}

export async function deleteParkingLot(id: string): Promise<void> {
  await ensureCsrfCookie()
  await http.delete(`${base}/${id}`)
}

export async function replaceOperatingHours(id: string, days: OperatingHour[]): Promise<OperatingHour[]> {
  await ensureCsrfCookie()
  const response = await http.put<ApiSuccess<{ operating_hours: OperatingHour[] }>>(`${base}/${id}/operating-hours`, { days })

  return response.data.data.operating_hours
}

export async function listZones(lotId: string): Promise<ParkingZone[]> {
  const response = await http.get<ApiSuccess<{ zones: ParkingZone[] }>>(`${base}/${lotId}/zones`)

  return response.data.data.zones
}

export async function saveZone(lotId: string, zoneId: string | null, input: ParkingZoneInput): Promise<ParkingZone> {
  await ensureCsrfCookie()
  const response = zoneId === null
    ? await http.post<ApiSuccess<{ zone: ParkingZone }>>(`${base}/${lotId}/zones`, input)
    : await http.patch<ApiSuccess<{ zone: ParkingZone }>>(`${base}/${lotId}/zones/${zoneId}`, input)

  return response.data.data.zone
}

export async function deleteZone(lotId: string, zoneId: string): Promise<void> {
  await ensureCsrfCookie()
  await http.delete(`${base}/${lotId}/zones/${zoneId}`)
}

export async function listSpaces(lotId: string, filters: SpaceFilters, page = 1, perPage = 500): Promise<Page<ParkingSpace>> {
  const response = await http.get<ApiSuccess<Page<ParkingSpace>>>(`${base}/${lotId}/spaces`, {
    params: { ...filters, page, per_page: perPage },
  })

  return response.data.data
}

export async function saveSpace(lotId: string, spaceId: string | null, input: ParkingSpaceInput): Promise<ParkingSpace> {
  await ensureCsrfCookie()
  const response = spaceId === null
    ? await http.post<ApiSuccess<{ space: ParkingSpace }>>(`${base}/${lotId}/spaces`, input)
    : await http.patch<ApiSuccess<{ space: ParkingSpace }>>(`${base}/${lotId}/spaces/${spaceId}`, input)

  return response.data.data.space
}

export async function createSpaceRange(lotId: string, input: ParkingSpaceRangeInput): Promise<number> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ created: number }>>(`${base}/${lotId}/spaces/bulk`, input)

  return response.data.data.created
}

export async function changeSpaceStatus(lotId: string, spaceId: string, status: SpaceStatus, reason: string | null): Promise<ParkingSpace> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ space: ParkingSpace }>>(`${base}/${lotId}/spaces/${spaceId}/status`, { status, reason })

  return response.data.data.space
}

export async function deleteSpace(lotId: string, spaceId: string): Promise<void> {
  await ensureCsrfCookie()
  await http.delete(`${base}/${lotId}/spaces/${spaceId}`)
}

export async function listGates(lotId: string): Promise<ParkingGate[]> {
  const response = await http.get<ApiSuccess<{ gates: ParkingGate[] }>>(`${base}/${lotId}/gates`)

  return response.data.data.gates
}

export async function saveGate(lotId: string, gateId: string | null, input: ParkingGateInput): Promise<ParkingGate> {
  await ensureCsrfCookie()
  const response = gateId === null
    ? await http.post<ApiSuccess<{ gate: ParkingGate }>>(`${base}/${lotId}/gates`, input)
    : await http.patch<ApiSuccess<{ gate: ParkingGate }>>(`${base}/${lotId}/gates/${gateId}`, input)

  return response.data.data.gate
}

export async function deleteGate(lotId: string, gateId: string): Promise<void> {
  await ensureCsrfCookie()
  await http.delete(`${base}/${lotId}/gates/${gateId}`)
}
