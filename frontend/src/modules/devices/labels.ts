import type { Tone } from '@/components/ui/tones'
import type { DeviceStatus, DeviceType } from '@/modules/devices/types'

export const deviceType: Record<DeviceType, { label: string; icon: string }> = {
  camera: { label: 'Cámara', icon: 'ri-camera-lens-line' },
  barrier: { label: 'Barrera', icon: 'ri-door-open-line' },
  ticket_printer: { label: 'Impresora de tiquetes', icon: 'ri-printer-line' },
  display: { label: 'Pantalla', icon: 'ri-tv-2-line' },
  sensor: { label: 'Sensor', icon: 'ri-sensor-line' },
  access_reader: { label: 'Lector de acceso', icon: 'ri-qr-scan-2-line' },
  payment_terminal: { label: 'Datáfono', icon: 'ri-bank-card-line' },
  kiosk: { label: 'Kiosco', icon: 'ri-macbook-line' },
}

export const deviceStatus: Record<DeviceStatus, { label: string; tone: Tone; icon: string }> = {
  online: { label: 'En línea', tone: 'success', icon: 'ri-wifi-line' },
  offline: { label: 'Sin conexión', tone: 'danger', icon: 'ri-wifi-off-line' },
  degraded: { label: 'Degradado', tone: 'warning', icon: 'ri-error-warning-line' },
  unknown: { label: 'Sin reporte', tone: 'muted', icon: 'ri-question-line' },
  maintenance: { label: 'Mantenimiento', tone: 'info', icon: 'ri-tools-line' },
}

export const deviceStatusOrder: DeviceStatus[] = ['online', 'degraded', 'offline', 'unknown', 'maintenance']
