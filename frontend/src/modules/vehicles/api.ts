import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, PageMeta } from '@/types/api'

import type { PlateCheck, Vehicle, VehicleEvent, VehicleFilters, VehicleInput, VehicleLink, VehicleRelationship, VehicleStatus } from '@/modules/vehicles/types'

export interface VehiclePage {
  items: Vehicle[]
  meta: PageMeta
  status_counts: Record<VehicleStatus, number>
}

export async function listVehicles(filters: VehicleFilters = {}, page = 1, perPage = 25): Promise<VehiclePage> {
  const response = await http.get<ApiSuccess<VehiclePage>>('/api/v1/vehicles', {
    params: { ...filters, page, per_page: perPage },
  })

  return response.data.data
}

export async function getVehicle(vehicleId: string): Promise<Vehicle> {
  const response = await http.get<ApiSuccess<{ vehicle: Vehicle }>>(`/api/v1/vehicles/${vehicleId}`)

  return response.data.data.vehicle
}

export async function checkPlate(plate: string, country: string): Promise<PlateCheck> {
  const response = await http.get<ApiSuccess<PlateCheck>>('/api/v1/vehicles/plate-check', { params: { plate, country } })

  return response.data.data
}

export async function saveVehicle(vehicleId: string | null, input: VehicleInput): Promise<Vehicle> {
  await ensureCsrfCookie()
  const response = vehicleId === null
    ? await http.post<ApiSuccess<{ vehicle: Vehicle }>>('/api/v1/vehicles', input)
    : await http.patch<ApiSuccess<{ vehicle: Vehicle }>>(`/api/v1/vehicles/${vehicleId}`, input)

  return response.data.data.vehicle
}

export async function changeVehicleStatus(vehicleId: string, status: VehicleStatus, reason: string | null): Promise<Vehicle> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ vehicle: Vehicle }>>(`/api/v1/vehicles/${vehicleId}/status`, { status, reason })

  return response.data.data.vehicle
}

export async function linkCustomer(vehicleId: string, customerId: string, relationship: VehicleRelationship, transferOwnership: boolean): Promise<VehicleLink> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ link: VehicleLink }>>(`/api/v1/vehicles/${vehicleId}/customers`, {
    customer_id: customerId,
    relationship,
    transfer_ownership: transferOwnership,
  })

  return response.data.data.link
}

export async function unlinkCustomer(vehicleId: string, linkId: string): Promise<void> {
  await ensureCsrfCookie()
  await http.delete(`/api/v1/vehicles/${vehicleId}/customers/${linkId}`)
}

export async function listVehicleHistory(vehicleId: string, page = 1): Promise<{ items: VehicleEvent[]; meta: PageMeta }> {
  const response = await http.get<ApiSuccess<{ items: VehicleEvent[]; meta: PageMeta }>>(`/api/v1/vehicles/${vehicleId}/history`, {
    params: { page, per_page: 20 },
  })

  return response.data.data
}
