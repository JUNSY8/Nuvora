import type { Tone } from '@/components/ui/tones'
import type { SessionStatus } from '@/modules/sessions/types'

export const sessionStatus: Record<SessionStatus, { label: string; tone: Tone; icon: string }> = {
  open: { label: 'En curso', tone: 'info', icon: 'ri-time-line' },
  closed: { label: 'Cerrada', tone: 'success', icon: 'ri-checkbox-circle-line' },
  cancelled: { label: 'Anulada', tone: 'muted', icon: 'ri-close-circle-line' },
}

export const sessionStatusOrder: SessionStatus[] = ['open', 'closed', 'cancelled']

// Presentation only: highlights stays that usually need a look from the supervisor.
export const LONG_STAY_MINUTES = 12 * 60

// Error codes of the sessions API that the operator screen explains next to the form.
export const entryErrorHints: Record<string, string> = {
  NO_SPACE_AVAILABLE: 'Puedes elegir un espacio especial a mano o registrar la entrada sin espacio.',
  VEHICLE_ALREADY_INSIDE: 'Búscalo en la lista de vehículos dentro para registrar su salida.',
  ENTRY_CONFLICT: 'Otro operador hizo un cambio al mismo tiempo. La lista ya se actualizó.',
}
