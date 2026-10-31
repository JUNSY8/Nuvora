import type { Tone } from '@/components/ui/tones'
import type { BillingPeriod, Membership, MembershipPlan, MembershipStatus, PlanCategory } from '@/modules/memberships/types'
import { describeVehicles, describeWindow } from '@/modules/tariffs/labels'
import { formatMoney } from '@/services/money'

export const membershipStatus: Record<MembershipStatus, { label: string; tone: Tone; icon: string }> = {
  active: { label: 'Vigente', tone: 'success', icon: 'ri-checkbox-circle-line' },
  suspended: { label: 'Suspendida', tone: 'warning', icon: 'ri-pause-circle-line' },
  expired: { label: 'Vencida', tone: 'muted', icon: 'ri-timer-flash-line' },
  cancelled: { label: 'Cancelada', tone: 'muted', icon: 'ri-close-circle-line' },
}

export const membershipStatusOrder: MembershipStatus[] = ['active', 'suspended', 'expired', 'cancelled']

export const planCategory: Record<PlanCategory, { label: string; icon: string }> = {
  monthly: { label: 'Mensual', icon: 'ri-calendar-check-line' },
  weekly: { label: 'Semanal', icon: 'ri-calendar-2-line' },
  corporate: { label: 'Corporativo', icon: 'ri-building-2-line' },
  vip: { label: 'VIP', icon: 'ri-vip-crown-line' },
  employee: { label: 'Empleados', icon: 'ri-user-star-line' },
  resident: { label: 'Residentes', icon: 'ri-home-4-line' },
  student: { label: 'Estudiantes', icon: 'ri-graduation-cap-line' },
  other: { label: 'Otro', icon: 'ri-price-tag-3-line' },
}

export const planCategoryOrder: PlanCategory[] = ['monthly', 'weekly', 'corporate', 'vip', 'employee', 'resident', 'student', 'other']

export const billingPeriod: Record<BillingPeriod, { label: string; per: string }> = {
  weekly: { label: 'Semanal', per: 'semana' },
  monthly: { label: 'Mensual', per: 'mes' },
  quarterly: { label: 'Trimestral', per: 'trimestre' },
  yearly: { label: 'Anual', per: 'año' },
}

export const billingPeriodOrder: BillingPeriod[] = ['weekly', 'monthly', 'quarterly', 'yearly']

export const periodOrigin: Record<string, string> = {
  subscription: 'Venta',
  renewal: 'Renovación manual',
  automatic: 'Renovación automática',
}

export const periodStatus: Record<string, { label: string; tone: Tone }> = {
  due: { label: 'Por cobrar', tone: 'warning' },
  paid: { label: 'Pagado', tone: 'success' },
  void: { label: 'Anulado', tone: 'muted' },
}

// "$120.000 / mes".
export function describePrice(item: Pick<MembershipPlan, 'price_amount' | 'currency' | 'billing_period'>): string {
  return `${formatMoney(item.price_amount, item.currency)} / ${billingPeriod[item.billing_period].per}`
}

// One line per restriction of the plan, for cards and the sale form.
export function describeAccess(plan: MembershipPlan, lotName: (id: string) => string, zoneName: (id: string) => string): string[] {
  return [
    plan.parking_lot_ids.length === 0 ? 'Todos los parqueaderos' : plan.parking_lot_ids.map(lotName).join(', '),
    ...(plan.zone_ids.length === 0 ? [] : [`Zonas: ${plan.zone_ids.map(zoneName).join(', ')}`]),
    describeVehicles(plan.vehicle_types),
    plan.windows.length === 0 ? 'A cualquier hora' : plan.windows.map(describeWindow).join(' · '),
  ]
}

// Days from the local day `today` until `day`, both Y-m-d (negative when past).
export function daysUntil(day: string, today: string): number {
  return Math.round((Date.parse(`${day}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / 86400000)
}

// What the operator should notice about a membership's coverage today.
export function coverageHint(membership: Membership, today: string): { text: string; tone: Tone } | null {
  if (membership.status !== 'active') {
    return null
  }

  const left = daysUntil(membership.current_period_end, today)

  if (left < 0) {
    const grace = daysUntil(membership.covered_until, today)

    if (grace < 0) {
      return { text: 'Sin cobertura', tone: 'danger' }
    }

    return { text: grace === 0 ? 'En gracia: último día' : `En gracia: ${grace} día(s)`, tone: 'danger' }
  }

  if (left <= 5) {
    return { text: left === 0 ? 'Termina hoy' : `Termina en ${left} día(s)`, tone: 'warning' }
  }

  return null
}
