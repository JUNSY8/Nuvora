export type CustomerType = 'individual' | 'business' | 'corporate'
export type CustomerStatus = 'active' | 'inactive'

export interface CustomerSummary {
  id: string
  name: string
  type: CustomerType
  status: CustomerStatus
}

export interface Customer {
  id: string
  type: CustomerType
  name: string
  document_type: string | null
  document_number: string | null
  email: string | null
  phone: string | null
  business: { id: string; name: string } | null
  members_count: number
  notes: string | null
  status: CustomerStatus
  created_at: string | null
}

export interface CustomerInput {
  type: CustomerType
  name: string
  document_type: string | null
  document_number: string | null
  email: string | null
  phone: string | null
  business_id: string | null
  notes: string | null
}

export interface CustomerFilters {
  type?: CustomerType
  status?: CustomerStatus
  business_id?: string
  organizations?: 1
  search?: string
}
