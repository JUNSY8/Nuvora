import type { Tone } from '@/components/ui/tones'
import type { Discount, DiscountApplication, DiscountType, PriceBracket, TariffStatus, TimeWindow } from '@/modules/tariffs/types'
import { vehicleType } from '@/modules/vehicles/labels'
import type { VehicleType } from '@/modules/vehicles/types'
import { formatMoney } from '@/services/money'

export const tariffStatus: Record<TariffStatus, { label: string; tone: Tone; icon: string }> = {
  active: { label: 'Activa', tone: 'success', icon: 'ri-checkbox-circle-line' },
  inactive: { label: 'Inactiva', tone: 'muted', icon: 'ri-pause-circle-line' },
}

export const discountType: Record<DiscountType, { label: string; icon: string }> = {
  percentage: { label: 'Porcentaje', icon: 'ri-percent-line' },
  fixed_amount: { label: 'Valor fijo', icon: 'ri-money-dollar-circle-line' },
  free_minutes: { label: 'Minutos gratis', icon: 'ri-timer-line' },
}

export const discountApplication: Record<DiscountApplication, { label: string; tone: Tone; help: string }> = {
  manual: { label: 'Manual', tone: 'info', help: 'El cajero lo elige al registrar la salida (convenios, cortesías).' },
  automatic: { label: 'Promoción', tone: 'primary', help: 'Se aplica sola cuando está vigente. No se acumula con otros descuentos.' },
}

// ISO days of the week, Monday first.
export const weekDays: Array<{ day: number; short: string; name: string }> = [
  { day: 1, short: 'L', name: 'lunes' },
  { day: 2, short: 'M', name: 'martes' },
  { day: 3, short: 'X', name: 'miércoles' },
  { day: 4, short: 'J', name: 'jueves' },
  { day: 5, short: 'V', name: 'viernes' },
  { day: 6, short: 'S', name: 'sábado' },
  { day: 7, short: 'D', name: 'domingo' },
]

// "15 min", "1 h", "1 h 30 min", "1 día", "2 días".
export function describeMinutes(minutes: number): string {
  if (minutes > 0 && minutes % 1440 === 0) {
    const days = minutes / 1440

    return days === 1 ? '1 día' : `${days} días`
  }

  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60

  if (hours === 0) {
    return `${rest} min`
  }

  return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`
}

export function describeDays(days: number[]): string {
  const sorted = [...new Set(days)].sort((a, b) => a - b)

  if (sorted.length === 7) {
    return 'Todos los días'
  }

  if (sorted.join() === '1,2,3,4,5') {
    return 'Lunes a viernes'
  }

  if (sorted.join() === '6,7') {
    return 'Fines de semana'
  }

  return sorted.map((day) => weekDays.find((item) => item.day === day)?.name ?? String(day)).join(', ')
}

export function describeWindow(window: TimeWindow): string {
  const hours = window.start === window.end ? 'todo el día' : `${window.start} a ${window.end}`

  return `${describeDays(window.days)}, ${hours}`
}

export function describeVehicles(types: VehicleType[]): string {
  return types.length === 0 ? 'Todos los vehículos' : types.map((type) => vehicleType[type].label).join(', ')
}

// One short line per part of the price, in the order the engine applies them.
export function describeRates(rules: { brackets: PriceBracket[]; step_minutes: number; step_amount: number; daily_max_amount: number | null }, currency: string): string[] {
  const parts = rules.brackets.map((bracket) => `Hasta ${describeMinutes(bracket.up_to_minutes)}: ${formatMoney(bracket.amount, currency)}`)
  const step = `${formatMoney(rules.step_amount, currency)} por cada ${describeMinutes(rules.step_minutes)}`

  parts.push(rules.brackets.length === 0 ? step : `Luego ${step} adicional`)

  if (rules.daily_max_amount !== null) {
    parts.push(`Tope por 24 h: ${formatMoney(rules.daily_max_amount, currency)}`)
  }

  return parts
}

export function describeDiscountValue(discount: Pick<Discount, 'type' | 'value' | 'currency'>): string {
  switch (discount.type) {
    case 'percentage':
      return `${new Intl.NumberFormat('es-CO', { maximumFractionDigits: 2 }).format(discount.value / 100)} %`
    case 'fixed_amount':
      return formatMoney(discount.value, discount.currency ?? 'COP')
    case 'free_minutes':
      return `${describeMinutes(discount.value)} gratis`
  }
}
