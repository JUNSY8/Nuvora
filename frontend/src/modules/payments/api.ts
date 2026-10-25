import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, PageMeta } from '@/types/api'

import type {
  PayableType,
  Payment,
  PaymentFilters,
  PaymentInput,
  PaymentMethod,
  PaymentTotals,
  ProviderAccount,
  ProviderAccountInput,
  ProviderInfo,
  ProviderOption,
  ProviderReconciliation,
  PublicCheckout,
  Receipt,
  Reconciliation,
  ReconciliationPreview,
} from '@/modules/payments/types'

const base = '/api/v1/payments'
const closings = '/api/v1/payment-reconciliations'
const providers = '/api/v1/payment-providers'

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

// Online payments go back through the provider unless channel is "manual" (cash or a transfer made outside Nuvora).
export async function refundPayment(id: string, input: { amount: number | null; reason: string; channel?: 'provider' | 'manual'; manual_method?: PaymentMethod }, idempotencyKey: string): Promise<Payment> {
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

// A pending online payment and the link the customer pays it with.
export async function createPaymentLink(input: { payable_type: PayableType; payable_id: string; account_id: string; amount: number | null }, idempotencyKey: string): Promise<Payment> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ payment: Payment }>>(`${base}/checkouts`, input, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })

  return response.data.data.payment
}

export async function cancelPaymentLink(id: string): Promise<Payment> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ payment: Payment }>>(`${base}/${id}/cancel`)

  return response.data.data.payment
}

// Asks the provider now instead of waiting for its notification.
export async function syncPayment(id: string): Promise<Payment> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ payment: Payment }>>(`${base}/${id}/sync`)

  return response.data.data.payment
}

export async function listProviderAccounts(): Promise<{ items: ProviderAccount[]; providers: ProviderInfo[] }> {
  const response = await http.get<ApiSuccess<{ items: ProviderAccount[]; providers: ProviderInfo[] }>>(providers)

  return response.data.data
}

export async function providerOptions(): Promise<ProviderOption[]> {
  const response = await http.get<ApiSuccess<{ items: ProviderOption[] }>>(`${providers}/options`)

  return response.data.data.items
}

export async function saveProviderAccount(id: string | null, input: ProviderAccountInput): Promise<ProviderAccount> {
  await ensureCsrfCookie()
  const response = id === null
    ? await http.post<ApiSuccess<{ account: ProviderAccount }>>(providers, input)
    : await http.patch<ApiSuccess<{ account: ProviderAccount }>>(`${providers}/${id}`, input)

  return response.data.data.account
}

export async function reconcileProvider(id: string, fromDate: string, toDate: string): Promise<ProviderReconciliation> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ reconciliation: ProviderReconciliation }>>(`${providers}/${id}/reconcile`, { from_date: fromDate, to_date: toDate })

  return response.data.data.reconciliation
}

export async function getPublicCheckout(token: string, returning: boolean): Promise<PublicCheckout> {
  const response = await http.get<ApiSuccess<{ checkout: PublicCheckout }>>(`/api/v1/public/checkouts/${token}`, {
    params: returning ? { volver: 1 } : {},
  })

  return response.data.data.checkout
}
