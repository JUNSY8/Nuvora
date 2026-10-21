import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, PageMeta } from '@/types/api'

import type { Device, DeviceFilters, DeviceInput, DeviceStatus } from '@/modules/devices/types'

export interface DevicePage {
  items: Device[]
  meta: PageMeta
  status_counts: Record<DeviceStatus, number>
}

export async function listDevices(filters: DeviceFilters = {}, page = 1, perPage = 25): Promise<DevicePage> {
  const response = await http.get<ApiSuccess<DevicePage>>('/api/v1/devices', {
    params: { ...filters, page, per_page: perPage },
  })

  return response.data.data
}

export async function saveDevice(deviceId: string | null, input: DeviceInput): Promise<Device> {
  await ensureCsrfCookie()
  const response = deviceId === null
    ? await http.post<ApiSuccess<{ device: Device }>>('/api/v1/devices', input)
    : await http.patch<ApiSuccess<{ device: Device }>>(`/api/v1/devices/${deviceId}`, input)

  return response.data.data.device
}

export async function changeDeviceStatus(deviceId: string, status: DeviceStatus): Promise<Device> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ device: Device }>>(`/api/v1/devices/${deviceId}/status`, { status })

  return response.data.data.device
}

export async function deleteDevice(deviceId: string): Promise<void> {
  await ensureCsrfCookie()
  await http.delete(`/api/v1/devices/${deviceId}`)
}
