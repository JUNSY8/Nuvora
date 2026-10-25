import type { Tone } from '@/components/ui/tones'
import type { PaymentBalance, PaymentDraft, PaymentMethod, PaymentStatus } from '@/modules/payments/types'
import { toMinorUnits } from '@/services/money'

export const paymentMethods: PaymentMethod[] = ['cash', 'card', 'transfer', 'other']

export const paymentMethod: Record<PaymentMethod, { label: string; icon: string; needsReference: boolean }> = {
  cash: { label: 'Efectivo', icon: 'ri-money-dollar-box-line', needsReference: false },
  card: { label: 'Tarjeta (datáfono)', icon: 'ri-bank-card-line', needsReference: true },
  transfer: { label: 'Transferencia', icon: 'ri-exchange-funds-line', needsReference: true },
  other: { label: 'Otro', icon: 'ri-wallet-3-line', needsReference: true },
}

export const paymentStatus: Record<PaymentStatus, { label: string; tone: Tone }> = {
  pending: { label: 'Pendiente', tone: 'warning' },
  completed: { label: 'Recibido', tone: 'success' },
  failed: { label: 'Fallido', tone: 'danger' },
  partially_refunded: { label: 'Reembolso parcial', tone: 'info' },
  refunded: { label: 'Reembolsado', tone: 'muted' },
}

export const balanceStatus: Record<PaymentBalance['status'], { label: string; tone: Tone }> = {
  not_required: { label: 'Sin cobro', tone: 'muted' },
  pending: { label: 'Por cobrar', tone: 'warning' },
  partial: { label: 'Pago parcial', tone: 'info' },
  paid: { label: 'Pagado', tone: 'success' },
  overpaid: { label: 'Pagado de más', tone: 'danger' },
}

export const providerName: Record<string, string> = {
  offline: 'En caja',
  wompi: 'Wompi',
  payu: 'PayU',
}

export const refundStatus: Record<'pending' | 'completed' | 'failed', { label: string; tone: Tone }> = {
  pending: { label: 'En proceso', tone: 'warning' },
  completed: { label: 'Devuelto', tone: 'success' },
  failed: { label: 'Rechazado', tone: 'danger' },
}

export const reconciliationIssue: Record<string, string> = {
  not_found: 'La pasarela no tiene esta transacción',
  status_mismatch: 'Recibido aquí, no aprobado en la pasarela',
  amount_mismatch: 'La pasarela aprobó otro valor',
  overpaid: 'El cobro quedó pagado de más: reembolsa la diferencia',
  unreachable: 'La pasarela no respondió',
}

// Names of the credential fields each provider asks for.
export const credentialLabel: Record<string, string> = {
  public_key: 'Llave pública',
  private_key: 'Llave privada',
  events_secret: 'Secreto de eventos',
  integrity_secret: 'Secreto de integridad',
  merchant_id: 'ID del comercio (merchantId)',
  account_id: 'ID de la cuenta (accountId)',
  api_key: 'API Key',
  api_login: 'API Login',
}

// The receipt number, or what a payment without one is.
export function paymentNumber(payment: { number: string | null; status: PaymentStatus }): string {
  if (payment.number) {
    return payment.number
  }

  return payment.status === 'pending' ? 'Enlace pendiente' : 'Sin recibo'
}

export function newPaymentDraft(): PaymentDraft {
  return { method: 'cash', tendered: '', reference: '' }
}

// Can the draft be sent for this amount? Cash must cover it when typed.
export function draftTender(draft: PaymentDraft, amount: number, currency: string): { ok: boolean; tendered: number | null; reference: string | null } {
  const reference = draft.reference.trim() === '' ? null : draft.reference.trim()

  if (draft.method !== 'cash') {
    return { ok: true, tendered: null, reference }
  }

  if (draft.tendered.trim() === '') {
    return { ok: true, tendered: null, reference: null }
  }

  const tendered = toMinorUnits(draft.tendered, currency)

  return { ok: tendered !== null && tendered >= amount, tendered, reference: null }
}

// Change for cash handed over; null when it does not cover the charge yet.
export function changeFor(amount: number, tendered: number | null): number | null {
  if (tendered === null) {
    return 0
  }

  return tendered >= amount ? tendered - amount : null
}

// Colombian bills, in minor units ($2.000 to $100.000).
const copBills = [200000, 500000, 1000000, 2000000, 5000000, 10000000]

// Quick amounts a cashier is likely to receive: the exact value and the next
// round bills above it. Only pesos have a known set of bills.
export function suggestedTenders(amount: number, currency: string): number[] {
  if (amount <= 0 || currency.toUpperCase() !== 'COP') {
    return []
  }

  const options = [amount, ...copBills.map((bill) => Math.ceil(amount / bill) * bill)]

  return [...new Set(options)].sort((a, b) => a - b).slice(0, 4)
}
