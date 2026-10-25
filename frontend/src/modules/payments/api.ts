import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, PageMeta } from '@/types/api'

import type { Payment, PaymentFilters, PaymentInput, PaymentTotals, Receipt, Reconciliation, ReconciliationPreview } from '@/modules/payments/types'

const base = '/api/v1/payments'
const closings = '/api/v1/payment-reconciliations'

export async function listPayments(filters: PaymentFilters = {}, page = 1, perPage = 25): Promise<{ items: Payment[]; meta: PageMeta; totals: PaymentTotals }> {
  const response = await http.get<ApiSuccess<{ items: Payment[]; meta: PageMeta; totals: PaymentTotals }>>(base, {
    params: { ...filters, page, per_page: perPage },
  })

  return response.data.data
}

export async function getPayment(id: string): Promise<Payment> {
  const response = await http.get<ApiSuccess<{ payment: Payment }>>(`${base}/${id}`)

  return response.data.data.payment
}

export async function getReceipt(id: string): Promise<Receipt> {
  const response = await http.get<ApiSuccess<{ receipt: Receipt }>>(`${base}/${id}/receipt`)

  return response.data.data.receipt
}

// The same idempotency key must be sent again when retrying the same payment.
export async function collectPayment(input: PaymentInput, idempotencyKey: string): Promise<{ payment: Payment; replayed: boolean }> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ payment: Payment; replayed: boolean }>>(base, input, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })

  return response.data.data
}

export async function refundPayment(id: string, input: { amount: number | null; reason: string }, idempotencyKey: string): Promise<Payment> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ payment: Payment }>>(`${base}/${id}/refunds`, input, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })

  return response.data.data.payment
}

export async function previewReconciliation(cashierId?: string): Promise<ReconciliationPreview[]> {
  const response = await http.get<ApiSuccess<{ currencies: ReconciliationPreview[] }>>(`${closings}/preview`, {
    params: cashierId ? { cashier_id: cashierId } : {},
  })

  return response.data.data.currencies
}

export async function closeReconciliation(input: { cashier_id?: string; currency: string; counted_cash: number; notes: string | null }): Promise<Reconciliation> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ reconciliation: Reconciliation }>>(closings, input)

  return response.data.data.reconciliation
}

export async function listReconciliations(page = 1, perPage = 25): Promise<{ items: Reconciliation[]; meta: PageMeta }> {
  const response = await http.get<ApiSuccess<{ items: Reconciliation[]; meta: PageMeta }>>(closings, { params: { page, per_page: perPage } })

  return response.data.data
}
