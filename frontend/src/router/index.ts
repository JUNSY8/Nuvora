import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    guest?: boolean
    requiresAuth?: boolean
    title?: string
  }
}

export const router = createRouter({
  history: createWebHistory(),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/modules/auth/LoginView.vue'),
      meta: { guest: true, title: 'Iniciar sesión' },
    },
    {
      path: '/registro',
      name: 'register',
      component: () => import('@/modules/auth/RegisterView.vue'),
      meta: { guest: true, title: 'Crear cuenta' },
    },
    {
      path: '/recuperar-contrasena',
      name: 'forgot-password',
      component: () => import('@/modules/auth/ForgotPasswordView.vue'),
      meta: { guest: true, title: 'Recuperar contraseña' },
    },
    {
      path: '/restablecer-contrasena',
      name: 'reset-password',
      component: () => import('@/modules/auth/ResetPasswordView.vue'),
      meta: { title: 'Restablecer contraseña' },
    },
    {
      // Payment link for the customer: public, the token in the URL is the access.
      path: '/pagar/:token',
      name: 'public-checkout',
      component: () => import('@/modules/payments/PublicCheckoutView.vue'),
      props: true,
      meta: { title: 'Pagar' },
    },
    {
      // Printable receipt, outside the app frame so only the receipt prints.
      path: '/pagos/:paymentId/recibo',
      name: 'payment-receipt',
      component: () => import('@/modules/payments/ReceiptView.vue'),
      props: true,
      meta: { requiresAuth: true, title: 'Recibo' },
    },
    {
      path: '/',
      component: () => import('@/modules/shell/AppShell.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/modules/dashboard/DashboardView.vue'),
          meta: { title: 'Panel de operación' },
        },
        {
          path: 'operacion',
          name: 'operations',
          component: () => import('@/modules/sessions/OperationsView.vue'),
          meta: { title: 'Entradas y salidas' },
        },
        {
          path: 'sesiones',
          name: 'sessions',
          component: () => import('@/modules/sessions/SessionsHistoryView.vue'),
          meta: { title: 'Historial de sesiones' },
        },
        {
          path: 'reservas',
          name: 'reservations',
          component: () => import('@/modules/reservations/ReservationsView.vue'),
          meta: { title: 'Reservas' },
        },
        {
          path: 'mensualidades',
          name: 'memberships',
          component: () => import('@/modules/memberships/MembershipsView.vue'),
          meta: { title: 'Mensualidades' },
        },
        {
          path: 'pagos',
          name: 'payments',
          component: () => import('@/modules/payments/PaymentsView.vue'),
          meta: { title: 'Pagos' },
        },
        {
          path: 'tarifas',
          name: 'tariffs',
          component: () => import('@/modules/tariffs/TariffsView.vue'),
          meta: { title: 'Tarifas' },
        },
        {
          path: 'parqueaderos',
          name: 'parking-lots',
          component: () => import('@/modules/parking/ParkingLotsView.vue'),
          meta: { title: 'Parqueaderos' },
        },
        {
          path: 'parqueaderos/:lotId',
          name: 'parking-lot',
          component: () => import('@/modules/parking/ParkingLotDetailView.vue'),
          props: true,
          meta: { title: 'Parqueadero' },
        },
        {
          path: 'dispositivos',
          name: 'devices',
          component: () => import('@/modules/devices/DevicesView.vue'),
          meta: { title: 'Dispositivos' },
        },
        {
          path: 'clientes',
          name: 'customers',
          component: () => import('@/modules/customers/CustomersView.vue'),
          meta: { title: 'Clientes' },
        },
        {
          path: 'clientes/:customerId',
          name: 'customer',
          component: () => import('@/modules/customers/CustomerDetailView.vue'),
          props: true,
          meta: { title: 'Cliente' },
        },
        {
          path: 'vehiculos',
          name: 'vehicles',
          component: () => import('@/modules/vehicles/VehiclesView.vue'),
          meta: { title: 'Vehículos' },
        },
        {
          path: 'vehiculos/:vehicleId',
          name: 'vehicle',
          component: () => import('@/modules/vehicles/VehicleDetailView.vue'),
          props: true,
          meta: { title: 'Vehículo' },
        },
        {
          path: 'empresa',
          name: 'tenant',
          component: () => import('@/modules/tenancy/TenantView.vue'),
          meta: { title: 'Empresa' },
        },
        {
          path: 'miembros',
          name: 'members',
          component: () => import('@/modules/tenancy/MembersView.vue'),
          meta: { title: 'Miembros' },
        },
        {
          path: 'roles',
          name: 'roles',
          component: () => import('@/modules/tenancy/RolesView.vue'),
          meta: { title: 'Roles y permisos' },
        },
        {
          path: 'auditoria',
          name: 'audit',
          component: () => import('@/modules/audit/AuditView.vue'),
          meta: { title: 'Auditoría' },
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: { name: 'dashboard' },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.status === 'loading') {
    await auth.fetchCurrentUser()
  }

  const requiresAuth = to.matched.some((record) => record.meta.requiresAuth === true)
  const guestOnly = to.matched.some((record) => record.meta.guest === true)

  if (requiresAuth && auth.status !== 'authenticated') {
    return { name: 'login' }
  }

  if (guestOnly && auth.status === 'authenticated') {
    return { name: 'dashboard' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · Nuvora` : 'Nuvora'
})
