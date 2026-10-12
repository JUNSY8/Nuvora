import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/modules/auth/LoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/registro',
      name: 'register',
      component: () => import('@/modules/auth/RegisterView.vue'),
      meta: { guest: true },
    },
    {
      path: '/recuperar-contrasena',
      name: 'forgot-password',
      component: () => import('@/modules/auth/ForgotPasswordView.vue'),
      meta: { guest: true },
    },
    {
      path: '/restablecer-contrasena',
      name: 'reset-password',
      component: () => import('@/modules/auth/ResetPasswordView.vue'),
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
        },
        {
          path: 'empresa',
          name: 'tenant',
          component: () => import('@/modules/tenancy/TenantView.vue'),
        },
        {
          path: 'miembros',
          name: 'members',
          component: () => import('@/modules/tenancy/MembersView.vue'),
        },
        {
          path: 'auditoria',
          name: 'audit',
          component: () => import('@/modules/audit/AuditView.vue'),
        },
      ],
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
