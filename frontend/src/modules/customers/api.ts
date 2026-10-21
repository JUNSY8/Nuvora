import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, PageMeta } from '@/types/api'

import type { Customer, CustomerFilters, CustomerInput, CustomerStatus, CustomerType } from '@/modules/customers/types'

export interface CustomerPage {
  items: Customer[]
  meta: PageMeta
  type_counts: Record<CustomerType, number>
}

export async function listCustomers(filters: CustomerFilters = {}, page = 1, perPage = 25): Promise<CustomerPage> {
  const response = await http.get<ApiSuccess<CustomerPage>>('/api/v1/customers', {
    params: { ...filters, page, per_page: perPage },
  })

  return response.data.data
}

export async function getCustomer(customerId: string): Promise<Customer> {
  const response = await http.get<ApiSuccess<{ customer: Customer }>>(`/api/v1/customers/${customerId}`)

  return response.data.data.customer
}

export async function saveCustomer(customerId: string | null, input: CustomerInput): Promise<Customer> {
  await ensureCsrfCookie()
  const response = customerId === null
    ? await http.post<ApiSuccess<{ customer: Customer }>>('/api/v1/customers', input)
    : await http.patch<ApiSuccess<{ customer: Customer }>>(`/api/v1/customers/${customerId}`, input)

  return response.data.data.customer
}

export async function changeCustomerStatus(customerId: string, status: CustomerStatus): Promise<Customer> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ customer: Customer }>>(`/api/v1/customers/${customerId}/status`, { status })

  return response.data.data.customer
}
