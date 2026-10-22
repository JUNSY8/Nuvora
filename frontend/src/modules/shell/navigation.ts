export interface NavItem {
  name: string
  label: string
  icon: string
  permission?: string
}

export interface NavSection {
  title: string
  items: NavItem[]
}

export const navigation: NavSection[] = [
  {
    title: 'Operación',
    items: [
      { name: 'dashboard', label: 'Panel', icon: 'ri-dashboard-2-line' },
      { name: 'operations', label: 'Entradas/salidas', icon: 'ri-arrow-left-right-line', permission: 'sessions.view' },
      { name: 'sessions', label: 'Historial', icon: 'ri-history-line', permission: 'sessions.view' },
      { name: 'tariffs', label: 'Tarifas', icon: 'ri-price-tag-3-line', permission: 'tariffs.view' },
      { name: 'parking-lots', label: 'Parqueaderos', icon: 'ri-parking-box-line', permission: 'parking.view' },
      { name: 'devices', label: 'Dispositivos', icon: 'ri-router-line', permission: 'devices.view' },
    ],
  },
  {
    title: 'Clientes',
    items: [
      { name: 'customers', label: 'Clientes', icon: 'ri-contacts-book-line', permission: 'customers.view' },
      { name: 'vehicles', label: 'Vehículos', icon: 'ri-car-line', permission: 'vehicles.view' },
    ],
  },
  {
    title: 'Administración',
    items: [
      { name: 'tenant', label: 'Empresa', icon: 'ri-building-line' },
      { name: 'members', label: 'Miembros', icon: 'ri-team-line', permission: 'users.view' },
      { name: 'roles', label: 'Roles', icon: 'ri-shield-user-line', permission: 'roles.view' },
      { name: 'audit', label: 'Auditoría', icon: 'ri-shield-check-line', permission: 'audit.view' },
    ],
  },
]

// Modules of the roadmap shown as upcoming so operators know what is coming.
export const upcoming: NavItem[] = [
  { name: 'reservations', label: 'Reservas', icon: 'ri-calendar-event-line' },
  { name: 'memberships', label: 'Mensualidades', icon: 'ri-calendar-check-line' },
]
