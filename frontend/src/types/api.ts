export interface AuthUser {
  id: string
  name: string
  email: string
  email_verified_at: string | null
}

export interface TenantAccess {
  id: string
  name: string
  slug: string
  timezone: string
  roles: string[]
  permissions: string[]
}

export interface AuthProfile {
  user: AuthUser
  tenants: TenantAccess[]
  current_tenant: TenantAccess | null
}

export interface TenantMember {
  id: string
  status: 'active' | 'suspended'
  user: {
    id: string
    name: string
    email: string
  }
  roles: Array<{ key: string; name: string }>
}

export interface TenantRole {
  id: string
  key: string
  name: string
  is_system: boolean
  permissions: string[]
}

export interface AuditEvent {
  id: string
  action: string
  resource_type: string
  resource_id: string | null
  actor_user_id: string | null
  created_at: string | null
}

export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ApiSuccess<T> {
  success: true
  data: T
  request_id: string
}

export interface ApiFailure {
  success: false
  error: {
    code: string
    message: string
    details: Record<string, string[]> | Record<string, never>
  }
  request_id: string
}

export interface ReadinessData {
  status: 'ok' | 'degraded'
  checks: Record<string, 'ok' | 'failed'>
}
