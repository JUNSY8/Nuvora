import type { Tone } from '@/components/ui/tones'
import type { CustomerStatus, CustomerType } from '@/modules/customers/types'

export const customerType: Record<CustomerType, { label: string; plural: string; icon: string; tone: Tone }> = {
  individual: { label: 'Persona', plural: 'Personas', icon: 'ri-user-3-line', tone: 'info' },
  business: { label: 'Empresa', plural: 'Empresas', icon: 'ri-building-2-line', tone: 'primary' },
  corporate: { label: 'Corporativo', plural: 'Corporativos', icon: 'ri-government-line', tone: 'warning' },
}

export const customerTypeOrder: CustomerType[] = ['individual', 'business', 'corporate']

export const customerStatus: Record<CustomerStatus, { label: string; tone: Tone }> = {
  active: { label: 'Activo', tone: 'success' },
  inactive: { label: 'Inactivo', tone: 'muted' },
}

// Suggestions only: tenants can type any document code.
export const documentTypes = [
  { code: 'CC', label: 'Cédula de ciudadanía' },
  { code: 'CE', label: 'Cédula de extranjería' },
  { code: 'NIT', label: 'NIT' },
  { code: 'PAS', label: 'Pasaporte' },
  { code: 'TI', label: 'Tarjeta de identidad' },
  { code: 'PPT', label: 'Permiso de protección temporal' },
]
