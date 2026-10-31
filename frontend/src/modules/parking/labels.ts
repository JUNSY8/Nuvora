import type { Tone } from '@/components/ui/tones'
import type { GateDirection, GateStatus, OperatingDayMode, ParkingLotStatus, SpaceStatus, SpaceType } from '@/modules/parking/types'

interface Presentation {
  label: string
  tone: Tone
  icon: string
}

export const lotStatus: Record<ParkingLotStatus, Presentation> = {
  active: { label: 'Activo', tone: 'success', icon: 'ri-checkbox-circle-line' },
  inactive: { label: 'Inactivo', tone: 'muted', icon: 'ri-pause-circle-line' },
  maintenance: { label: 'Mantenimiento', tone: 'warning', icon: 'ri-tools-line' },
}

export const spaceStatus: Record<SpaceStatus, Presentation> = {
  available: { label: 'Disponible', tone: 'success', icon: 'ri-checkbox-circle-line' },
  occupied: { label: 'Ocupado', tone: 'danger', icon: 'ri-car-fill' },
  reserved: { label: 'Reservado', tone: 'info', icon: 'ri-bookmark-line' },
  blocked: { label: 'Bloqueado', tone: 'primary', icon: 'ri-forbid-2-line' },
  maintenance: { label: 'Mantenimiento', tone: 'warning', icon: 'ri-tools-line' },
  out_of_service: { label: 'Fuera de servicio', tone: 'muted', icon: 'ri-close-circle-line' },
}

export const spaceStatusOrder: SpaceStatus[] = ['available', 'occupied', 'reserved', 'blocked', 'maintenance', 'out_of_service']

export const spaceType: Record<SpaceType, { label: string; icon: string }> = {
  car: { label: 'Carro', icon: 'ri-car-line' },
  motorcycle: { label: 'Moto', icon: 'ri-motorbike-line' },
  truck: { label: 'Camión', icon: 'ri-truck-line' },
  van: { label: 'Camioneta', icon: 'ri-bus-line' },
  ev: { label: 'Eléctrico', icon: 'ri-charging-pile-2-line' },
  disabled: { label: 'Movilidad reducida', icon: 'ri-wheelchair-line' },
  vip: { label: 'VIP', icon: 'ri-vip-crown-2-line' },
  reserved: { label: 'Reservado', icon: 'ri-bookmark-line' },
}

export const gateDirection: Record<GateDirection, { label: string; icon: string }> = {
  entry: { label: 'Entrada', icon: 'ri-login-box-line' },
  exit: { label: 'Salida', icon: 'ri-logout-box-r-line' },
  bidirectional: { label: 'Entrada y salida', icon: 'ri-arrow-left-right-line' },
}

export const gateStatus: Record<GateStatus, Presentation> = {
  active: { label: 'Activa', tone: 'success', icon: 'ri-checkbox-circle-line' },
  inactive: { label: 'Inactiva', tone: 'muted', icon: 'ri-pause-circle-line' },
  maintenance: { label: 'Mantenimiento', tone: 'warning', icon: 'ri-tools-line' },
}

export const dayMode: Record<OperatingDayMode, string> = {
  open_24h: '24 horas',
  scheduled: 'Con horario',
  closed: 'Cerrado',
}

export const weekdays = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']
