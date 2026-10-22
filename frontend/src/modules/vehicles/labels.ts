import type { Tone } from '@/components/ui/tones'
import type { VehicleEvent, VehicleEventType, VehicleRelationship, VehicleStatus, VehicleType } from '@/modules/vehicles/types'
import { formatDuration } from '@/services/format'
import { formatMoney } from '@/services/money'

export const vehicleType: Record<VehicleType, { label: string; icon: string }> = {
  car: { label: 'Automóvil', icon: 'ri-car-line' },
  motorcycle: { label: 'Motocicleta', icon: 'ri-motorbike-line' },
  bicycle: { label: 'Bicicleta', icon: 'ri-riding-line' },
  van: { label: 'Camioneta / van', icon: 'ri-bus-2-line' },
  truck: { label: 'Camión', icon: 'ri-truck-line' },
  bus: { label: 'Bus', icon: 'ri-bus-line' },
  other: { label: 'Otro', icon: 'ri-steering-2-line' },
}

export const vehicleTypeOrder: VehicleType[] = ['car', 'motorcycle', 'van', 'truck', 'bus', 'bicycle', 'other']

export const vehicleStatus: Record<VehicleStatus, { label: string; tone: Tone; icon: string }> = {
  active: { label: 'Activo', tone: 'success', icon: 'ri-checkbox-circle-line' },
  inactive: { label: 'Inactivo', tone: 'muted', icon: 'ri-pause-circle-line' },
  blocked: { label: 'Bloqueado', tone: 'danger', icon: 'ri-forbid-line' },
}

export const vehicleStatusOrder: VehicleStatus[] = ['active', 'inactive', 'blocked']

export const relationship: Record<VehicleRelationship, { label: string; tone: Tone; icon: string; help: string }> = {
  owner: { label: 'Propietario', tone: 'primary', icon: 'ri-key-2-line', help: 'Responsable del vehículo. Solo puede haber uno activo.' },
  driver: { label: 'Conductor', tone: 'info', icon: 'ri-steering-2-line', help: 'Lo conduce habitualmente, por ejemplo un empleado de la empresa.' },
  authorized: { label: 'Autorizado', tone: 'secondary', icon: 'ri-user-follow-line', help: 'Puede ingresarlo o retirarlo de forma ocasional.' },
}

export const relationshipOrder: VehicleRelationship[] = ['owner', 'driver', 'authorized']

// ISO 3166-1 alpha-2 codes offered in forms; the API accepts any code.
export const plateCountries = [
  { code: 'CO', name: 'Colombia' },
  { code: 'EC', name: 'Ecuador' },
  { code: 'PE', name: 'Perú' },
  { code: 'VE', name: 'Venezuela' },
  { code: 'PA', name: 'Panamá' },
  { code: 'MX', name: 'México' },
  { code: 'CL', name: 'Chile' },
  { code: 'AR', name: 'Argentina' },
  { code: 'BR', name: 'Brasil' },
  { code: 'US', name: 'Estados Unidos' },
]

export const eventMeta: Record<VehicleEventType, { label: string; icon: string; tone: Tone }> = {
  registered: { label: 'Vehículo registrado', icon: 'ri-add-circle-line', tone: 'success' },
  updated: { label: 'Datos actualizados', icon: 'ri-edit-2-line', tone: 'info' },
  plate_changed: { label: 'Placa corregida', icon: 'ri-text', tone: 'warning' },
  status_changed: { label: 'Cambio de estado', icon: 'ri-shield-flash-line', tone: 'warning' },
  customer_linked: { label: 'Cliente vinculado', icon: 'ri-link', tone: 'primary' },
  relationship_changed: { label: 'Relación modificada', icon: 'ri-arrow-left-right-line', tone: 'secondary' },
  customer_unlinked: { label: 'Cliente desvinculado', icon: 'ri-link-unlink', tone: 'muted' },
  entered: { label: 'Entrada', icon: 'ri-login-box-line', tone: 'success' },
  exited: { label: 'Salida', icon: 'ri-logout-box-r-line', tone: 'info' },
  session_cancelled: { label: 'Sesión anulada', icon: 'ri-close-circle-line', tone: 'muted' },
  reserved: { label: 'Reserva', icon: 'ri-calendar-check-line', tone: 'primary' },
}

const fieldLabels: Record<string, string> = {
  type: 'tipo',
  brand: 'marca',
  model: 'línea',
  color: 'color',
  year: 'año',
  is_electric: 'eléctrico',
  notes: 'notas',
}

function text(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function relationshipLabel(value: unknown): string {
  return relationship[value as VehicleRelationship]?.label.toLowerCase() ?? text(value)
}

function statusLabel(value: unknown): string {
  return vehicleStatus[value as VehicleStatus]?.label ?? text(value)
}

export function describeEvent(event: VehicleEvent): string {
  const data = event.data

  switch (event.type) {
    case 'registered':
      return `Placa ${text(data.plate)} (${text(data.country)}) como ${vehicleType[data.type as VehicleType]?.label.toLowerCase() ?? text(data.type)}${data.source === 'entry' ? ', al ingresar como visitante.' : data.source === 'reservation' ? ', al reservar.' : '.'}`
    case 'updated': {
      const changes = (data.changes ?? {}) as Record<string, unknown>

      return `Cambió ${Object.keys(changes).map((field) => fieldLabels[field] ?? field).join(', ')}.`
    }
    case 'plate_changed':
      return `De ${text(data.from)} (${text(data.from_country)}) a ${text(data.to)} (${text(data.to_country)}).`
    case 'status_changed':
      return `${statusLabel(data.from)} → ${statusLabel(data.to)}${data.reason ? `: ${text(data.reason)}` : '.'}`
    case 'customer_linked':
      return `${text(data.customer_name)} como ${relationshipLabel(data.relationship)}.`
    case 'relationship_changed':
      return `${text(data.customer_name)} pasó de ${relationshipLabel(data.from)} a ${relationshipLabel(data.to)}.`
    case 'customer_unlinked':
      return `${text(data.customer_name)} dejó de ser ${relationshipLabel(data.relationship)}${data.reason === 'ownership_transferred' ? ' por transferencia de propiedad.' : '.'}`
    case 'entered': {
      const reservation = data.reservation_code ? ` con la reserva ${text(data.reservation_code)}` : ''

      return data.space_code ? `Ingresó al espacio ${text(data.space_code)}${reservation}.` : `Ingresó sin espacio asignado${reservation}.`
    }
    case 'exited': {
      const charged = typeof data.total_amount === 'number' && typeof data.currency === 'string' ? ` Valor: ${formatMoney(data.total_amount, data.currency)}.` : ''

      return `Salió${data.space_code ? ` del espacio ${text(data.space_code)}` : ''} tras ${formatDuration(typeof data.duration_minutes === 'number' ? data.duration_minutes : null)}.${charged}`
    }
    case 'session_cancelled':
      return `Sesión anulada: ${text(data.reason)}`
    case 'reserved':
      return `Reserva ${text(data.code)} para el ${text(data.starts_local).replace(' ', ' a las ')}.`
  }
}
