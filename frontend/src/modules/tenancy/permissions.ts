// Labels for the permission catalog defined in the Tenancy domain.
export const permissionGroups: Array<{ title: string; icon: string; permissions: Array<{ key: string; label: string }> }> = [
  {
    title: 'Empresa y equipo',
    icon: 'ri-building-line',
    permissions: [
      { key: 'tenants.view', label: 'Ver empresa' },
      { key: 'tenants.update', label: 'Editar empresa' },
      { key: 'users.view', label: 'Ver miembros' },
      { key: 'users.manage', label: 'Administrar miembros' },
      { key: 'roles.view', label: 'Ver roles' },
      { key: 'roles.manage', label: 'Administrar roles' },
      { key: 'audit.view', label: 'Ver auditoría' },
    ],
  },
  {
    title: 'Parqueaderos y dispositivos',
    icon: 'ri-parking-box-line',
    permissions: [
      { key: 'parking.view', label: 'Ver parqueaderos' },
      { key: 'parking.create', label: 'Crear infraestructura' },
      { key: 'parking.update', label: 'Editar infraestructura' },
      { key: 'parking.delete', label: 'Eliminar infraestructura' },
      { key: 'devices.view', label: 'Ver dispositivos' },
      { key: 'devices.manage', label: 'Administrar dispositivos' },
    ],
  },
  {
    title: 'Clientes y vehículos',
    icon: 'ri-contacts-book-line',
    permissions: [
      { key: 'customers.view', label: 'Ver clientes' },
      { key: 'customers.create', label: 'Crear clientes' },
      { key: 'customers.update', label: 'Editar clientes' },
      { key: 'vehicles.view', label: 'Ver vehículos' },
      { key: 'vehicles.create', label: 'Registrar vehículos' },
      { key: 'vehicles.update', label: 'Editar y vincular vehículos' },
    ],
  },
  {
    title: 'Operación y caja',
    icon: 'ri-arrow-left-right-line',
    permissions: [
      { key: 'sessions.view', label: 'Ver sesiones y ocupación' },
      { key: 'sessions.create', label: 'Registrar entradas y mover espacios' },
      { key: 'sessions.close', label: 'Registrar salidas' },
      { key: 'sessions.cancel', label: 'Anular sesiones' },
      { key: 'sessions.discount', label: 'Aplicar descuentos en la salida' },
      { key: 'tariffs.view', label: 'Ver tarifas y simular cobros' },
      { key: 'tariffs.manage', label: 'Configurar tarifas y descuentos' },
      { key: 'reservations.view', label: 'Ver reservas y disponibilidad' },
      { key: 'reservations.manage', label: 'Crear, confirmar y cancelar reservas' },
      { key: 'memberships.view', label: 'Ver mensualidades y planes' },
      { key: 'memberships.manage', label: 'Vender, renovar y cancelar mensualidades' },
      { key: 'payments.view', label: 'Ver pagos' },
      { key: 'payments.create', label: 'Cobrar y cerrar su propia caja' },
      { key: 'payments.refund', label: 'Reembolsar' },
      { key: 'payments.reconcile', label: 'Cerrar la caja de otros cajeros y conciliar pasarelas' },
      { key: 'payments.configure', label: 'Configurar pasarelas de pago' },
      { key: 'reports.view', label: 'Ver reportes' },
    ],
  },
]

const labels = new Map(permissionGroups.flatMap((group) => group.permissions.map((item) => [item.key, item.label])))

export function permissionLabel(key: string): string {
  return labels.get(key) ?? key
}
