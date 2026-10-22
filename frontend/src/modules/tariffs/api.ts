import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess } from '@/types/api'

import type { Discount, DiscountApplication, DiscountInput, Quote, SimulationInput, Tariff, TariffInput, TariffStatus, TariffVersion } from '@/modules/tariffs/types'

export async function listTariffs(lotId: string): Promise<Tariff[]> {
  const response = await http.get<ApiSuccess<{ items: Tariff[] }>>('/api/v1/tariffs', { params: { lot_id: lotId } })

  return response.data.data.items
}

export async function saveTariff(tariffId: string | null, lotId: string, input: TariffInput): Promise<Tariff> {
  await ensureCsrfCookie()
  const response = tariffId === null
    ? await http.post<ApiSuccess<{ tariff: Tariff }>>('/api/v1/tariffs', { parking_lot_id: lotId, ...input })
    : await http.patch<ApiSuccess<{ tariff: Tariff }>>(`/api/v1/tariffs/${tariffId}`, input)

  return response.data.data.tariff
}

export async function changeTariffStatus(tariffId: string, status: TariffStatus, note: string | null): Promise<Tariff> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ tariff: Tariff }>>(`/api/v1/tariffs/${tariffId}/status`, { status, change_note: note })

  return response.data.data.tariff
}

export async function listTariffVersions(tariffId: string): Promise<TariffVersion[]> {
  const response = await http.get<ApiSuccess<{ items: TariffVersion[] }>>(`/api/v1/tariffs/${tariffId}/versions`)

  return response.data.data.items
}

export async function simulatePricing(input: SimulationInput): Promise<Quote> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ quote: Quote }>>('/api/v1/tariffs/simulate', input)

  return response.data.data.quote
}

export async function listDiscounts(filters: { lot_id?: string; application?: DiscountApplication; status?: TariffStatus } = {}): Promise<Discount[]> {
  const response = await http.get<ApiSuccess<{ items: Discount[] }>>('/api/v1/discounts', { params: filters })

  return response.data.data.items
}

export async function saveDiscount(discountId: string | null, input: DiscountInput): Promise<Discount> {
  await ensureCsrfCookie()
  const response = discountId === null
    ? await http.post<ApiSuccess<{ discount: Discount }>>('/api/v1/discounts', input)
    : await http.patch<ApiSuccess<{ discount: Discount }>>(`/api/v1/discounts/${discountId}`, input)

  return response.data.data.discount
}

export async function changeDiscountStatus(discountId: string, status: TariffStatus): Promise<Discount> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ discount: Discount }>>(`/api/v1/discounts/${discountId}/status`, { status })

  return response.data.data.discount
}
