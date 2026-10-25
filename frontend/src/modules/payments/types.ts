// Amounts are integers in the currency's minor unit, like the rest of the API.
export type PaymentMethod = 'cash' | 'card' | 'transfer' | 'other'
export type PaymentStatus = 'pending' | 'completed' | 'failed' | 'partially_refunded' | 'refunded'
export type PayableType = 'parking_session' | 'membership_period'

// What a payable owes; "not_required" when it charges nothing (grace, membership, no tariff).
export interface PaymentBalance {
  status: 'not_required' | 'pending' | 'partial' | 'paid' | 'overpaid'
  due: number
  paid: number
  balance: number
}

interface Person {
  id: string
  name: string
}

export interface Payment {
  id: string
  // Online payments get their number when the provider approves them.
  number: string | null
  status: PaymentStatus
  payable: { type: PayableType; id: string }
  parking_lot: { id: string; name: string | null; code: string | null } | null
  customer_id: string | null
  description: string
  plate: string | null
  method: PaymentMethod
  provider: string
  amount: number
  refunded_amount: number
  net_amount: number
  currency: string
  tendered_amount: number | null
  change_amount: number | null
  reference: string | null
  notes: string | null
  received_at: string
  received_by: Person | null
  reconciled: boolean
  provider_account: { id: string; name: string | null } | null
  provider_reference: string | null
  provider_method: string | null
  failure_reason: string | null
  expires_at: string | null
  details?: Record<string, unknown>
  requested_by?: Person | null
  // Only for pending links, so staff can copy them again.
  checkout_url?: string | null
  refunds?: PaymentRefund[]
  reconciliation?: { id: string; number: string } | null
}

export interface PaymentRefund {
  id: string
  number: string
  amount: number
  currency: string
  method: PaymentMethod
  reason: string
  refunded_at: string
  refunded_by: Person | null
  status: 'pending' | 'completed' | 'failed'
  provider: string
  failure_reason: string | null
}

export type ProviderKey = 'wompi' | 'payu'

export interface ProviderAccount {
  id: string
  provider: ProviderKey
  name: string
  environment: 'sandbox' | 'production'
  status: 'active' | 'inactive'
  // Last characters only; credentials are write-only.
  credentials: Record<string, string>
  notification_url: string
  updated_at: string | null
}

export interface ProviderInfo {
  key: ProviderKey
  label: string
  credential_keys: string[]
  partial_refunds: boolean
}

export interface ProviderAccountInput {
  provider: ProviderKey
  name: string
  environment: 'sandbox' | 'production'
  status: 'active' | 'inactive'
  credentials: Record<string, string>
}

export interface ProviderOption {
  id: string
  name: string
  provider: ProviderKey
  provider_label: string
  sandbox: boolean
}

export interface ProviderReconciliationItem {
  payment_id: string
  number: string | null
  description: string
  amount: number
  currency: string
  status_before: PaymentStatus
  status: PaymentStatus
  provider_status: string | null
  issue: 'not_found' | 'status_mismatch' | 'amount_mismatch' | 'overpaid' | 'unreachable' | null
  fixed: boolean
}

export interface ProviderReconciliation {
  checked: number
  fixed: number
  issues: number
  truncated: boolean
  items: ProviderReconciliationItem[]
}

// What the public payment page shows; no session is needed.
export interface PublicCheckout {
  tenant: { name: string }
  description: string
  amount: number
  currency: string
  status: PaymentStatus | 'expired'
  number: string | null
  provider: string | null
  expires_at: string | null
  checkout: { url: string; method: 'GET' | 'POST'; fields: Record<string, string> } | null
}

export interface MethodTotals {
  count: number
  collected: number
  refunded: number
  net: number
}

export interface PaymentTotals {
  count: number
  collected: number
  refunded: number
  net: number
  currencies: string[]
  by_method: Record<PaymentMethod, MethodTotals>
}

export interface PaymentFilters {
  lot_id?: string
  method?: PaymentMethod
  status?: PaymentStatus
  payable_type?: PayableType
  payable_id?: string
  received_by?: string
  reconciled?: 0 | 1
  from_date?: string
  to_date?: string
  search?: string
}

export interface PaymentInput {
  payable_type: PayableType
  payable_id: string
  method: PaymentMethod
  amount: number | null
  tendered_amount: number | null
  reference: string | null
  notes: string | null
}

// What the cashier is typing: the tendered cash stays as text until sent.
export interface PaymentDraft {
  method: PaymentMethod
  tendered: string
  reference: string
}

// What the person collecting sees about the charge before confirming.
export interface PayableSummary {
  type: PayableType
  id: string
  title: string
  subtitle: string
  currency: string
  balance: number
}

export interface Receipt {
  number: string
  issued_at: string
  timezone: string
  tenant: { name: string }
  parking_lot: { id: string; name: string; code: string } | null
  description: string
  plate: string | null
  details: Record<string, unknown>
  method: PaymentMethod
  method_label: string
  status: PaymentStatus
  currency: string
  amount: number
  tendered: number | null
  change: number | null
  reference: string | null
  refunded: number
  cashier: string | null
  refunds: Array<{ number: string; amount: number; reason: string; refunded_at: string; refunded_by: string | null }>
}

export interface ReconciliationPreview {
  currency: string
  payments_count: number
  refunds_count: number
  first_movement_at: string | null
  by_method: Record<PaymentMethod, MethodTotals>
  expected_cash: number
}

export interface Reconciliation {
  id: string
  number: string
  cashier: Person | null
  closed_by: Person | null
  currency: string
  first_movement_at: string | null
  closed_at: string
  payments_count: number
  refunds_count: number
  by_method: Record<PaymentMethod, MethodTotals>
  expected_cash: number
  counted_cash: number
  difference: number
  notes: string | null
}
