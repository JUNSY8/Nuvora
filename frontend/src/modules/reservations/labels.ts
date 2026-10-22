import type { Tone } from '@/components/ui/tones'
import type { Reservation, ReservationScope, ReservationStatus } from '@/modules/reservations/types'

export const reservationStatus: Record<ReservationStatus, { label: string; tone: Tone; icon: string }> = {
  pending: { label: 'Por confirmar', tone: 'warning', icon: 'ri-time-line' },
  confirmed: { label: 'Confirmada', tone: 'info', icon: 'ri-calendar-check-line' },
  active: { label: 'En curso', tone: 'success', icon: 'ri-car-line' },
  completed: { label: 'Cumplida', tone: 'muted', icon: 'ri-checkbox-circle-line' },
  cancelled: { label: 'Cancelada', tone: 'muted', icon: 'ri-close-circle-line' },
  expired: { label: 'Vencida', tone: 'muted', icon: 'ri-timer-flash-line' },
  no_show: { label: 'No llegó', tone: 'danger', icon: 'ri-user-unfollow-line' },
}

export const reservationStatusOrder: ReservationStatus[] = ['pending', 'confirmed', 'active', 'completed', 'cancelled', 'expired', 'no_show']

export const reservationScope: Record<ReservationScope, { label: string; help: string }> = {
  lot: { label: 'Cualquier espacio', help: 'Se asigna un espacio reservable del parqueadero al llegar.' },
  zone: { label: 'Una zona', help: 'Se asigna un espacio de la zona al llegar.' },
  space: { label: 'Un espacio', help: 'El espacio queda retenido desde el inicio de la ventana.' },
}

export function scopeLabel(reservation: Reservation): string {
  if (reservation.space) {
    return `Espacio ${reservation.space.code ?? ''}`
  }

  if (reservation.zone) {
    return `Zona ${reservation.zone.name ?? reservation.zone.code ?? ''}`
  }

  return 'Cualquier espacio'
}

// Reservations that still need the operator: arriving, pending or inside.
export function isOpen(status: ReservationStatus): boolean {
  return status === 'pending' || status === 'confirmed' || status === 'active'
}
