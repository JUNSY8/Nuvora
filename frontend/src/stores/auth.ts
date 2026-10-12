import { defineStore } from 'pinia'
import { ref } from 'vue'

import { ensureCsrfCookie, http, parseApiError, resetCsrfCookie } from '@/services/http'
import type { ApiSuccess, AuthProfile, AuthUser, TenantAccess } from '@/types/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const tenants = ref<TenantAccess[]>([])
  const currentTenant = ref<TenantAccess | null>(null)
  const status = ref<'loading' | 'authenticated' | 'anonymous'>('loading')

  function applyProfile(profile: AuthProfile): void {
    user.value = profile.user
    tenants.value = profile.tenants
    currentTenant.value = profile.current_tenant
    status.value = 'authenticated'

    if (profile.current_tenant) {
      http.defaults.headers.common['X-Tenant-Id'] = profile.current_tenant.id
    } else {
      delete http.defaults.headers.common['X-Tenant-Id']
    }
  }

  function clearSession(): void {
    user.value = null
    tenants.value = []
    currentTenant.value = null
    status.value = 'anonymous'
    delete http.defaults.headers.common['X-Tenant-Id']
  }

  async function fetchCurrentUser(): Promise<void> {
    status.value = 'loading'

    try {
      const response = await http.get<ApiSuccess<AuthProfile>>('/api/v1/auth/me')
      applyProfile(response.data.data)
    } catch {
      clearSession()
    }
  }

  async function login(email: string, password: string, remember = false): Promise<void> {
    delete http.defaults.headers.common['X-Tenant-Id']

    try {
      await ensureCsrfCookie()
      const response = await http.post<ApiSuccess<AuthProfile>>('/api/v1/auth/login', {
        email,
        password,
        remember,
      })
      applyProfile(response.data.data)
    } catch (error) {
      clearSession()
      throw new Error(parseApiError(error, 'No fue posible iniciar sesión.'))
    }
  }

  async function register(name: string, email: string, password: string, passwordConfirmation: string): Promise<void> {
    delete http.defaults.headers.common['X-Tenant-Id']

    try {
      await ensureCsrfCookie()
      const response = await http.post<ApiSuccess<AuthProfile>>('/api/v1/auth/register', {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      })
      applyProfile(response.data.data)
    } catch (error) {
      clearSession()
      throw new Error(parseApiError(error, 'No fue posible crear la cuenta.'))
    }
  }

  async function logout(): Promise<void> {
    await ensureCsrfCookie()
    await http.post('/api/v1/auth/logout')
    clearSession()
    resetCsrfCookie()
  }

  async function forgotPassword(email: string): Promise<string> {
    try {
      await ensureCsrfCookie()
      const response = await http.post<ApiSuccess<{ message: string }>>('/api/v1/auth/forgot-password', { email })

      return response.data.data.message
    } catch (error) {
      throw new Error(parseApiError(error, 'No fue posible enviar el enlace.'))
    }
  }

  async function resetPassword(email: string, token: string, password: string, passwordConfirmation: string): Promise<string> {
    try {
      await ensureCsrfCookie()
      const response = await http.post<ApiSuccess<{ message: string }>>('/api/v1/auth/reset-password', {
        email,
        token,
        password,
        password_confirmation: passwordConfirmation,
      })

      return response.data.data.message
    } catch (error) {
      throw new Error(parseApiError(error, 'No fue posible restablecer la contraseña.'))
    }
  }

  async function resendVerification(): Promise<void> {
    try {
      await ensureCsrfCookie()
      await http.post('/api/v1/auth/email/verification-notification')
    } catch (error) {
      throw new Error(parseApiError(error, 'No fue posible reenviar la verificación.'))
    }
  }

  async function createTenant(name: string, timezone: string): Promise<void> {
    try {
      await ensureCsrfCookie()
      const response = await http.post<ApiSuccess<AuthProfile>>('/api/v1/tenants', { name, timezone })
      applyProfile(response.data.data)
    } catch (error) {
      throw new Error(parseApiError(error, 'No fue posible crear la empresa.'))
    }
  }

  async function selectTenant(tenantId: string): Promise<void> {
    try {
      await ensureCsrfCookie()
      const response = await http.post<ApiSuccess<AuthProfile>>('/api/v1/tenant-context', { tenant_id: tenantId })
      applyProfile(response.data.data)
    } catch (error) {
      throw new Error(parseApiError(error, 'No fue posible cambiar de empresa.'))
    }
  }

  async function updateTenant(name: string, timezone: string): Promise<void> {
    try {
      await ensureCsrfCookie()
      const response = await http.patch<ApiSuccess<{ profile: AuthProfile }>>('/api/v1/tenant', { name, timezone })
      applyProfile(response.data.data.profile)
    } catch (error) {
      throw new Error(parseApiError(error, 'No fue posible actualizar la empresa.'))
    }
  }

  return {
    user,
    tenants,
    currentTenant,
    status,
    fetchCurrentUser,
    login,
    register,
    logout,
    forgotPassword,
    resetPassword,
    resendVerification,
    createTenant,
    selectTenant,
    updateTenant,
  }
})
