import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, AuditEvent, PageMeta, TenantMember, TenantRole } from '@/types/api'

export async function listMembers(): Promise<TenantMember[]> {
  const response = await http.get<ApiSuccess<{ members: TenantMember[] }>>('/api/v1/tenant/members')

  return response.data.data.members
}

export async function addMember(email: string, role: string): Promise<void> {
  await ensureCsrfCookie()
  await http.post('/api/v1/tenant/members', { email, roles: [role] })
}

export async function suspendMember(membershipId: string): Promise<void> {
  await ensureCsrfCookie()
  await http.patch(`/api/v1/tenant/members/${membershipId}`, { status: 'suspended' })
}

export async function listRoles(): Promise<TenantRole[]> {
  const response = await http.get<ApiSuccess<{ roles: TenantRole[] }>>('/api/v1/tenant/roles')

  return response.data.data.roles
}

export async function listAudit(page: number): Promise<{ items: AuditEvent[]; meta: PageMeta }> {
  const response = await http.get<ApiSuccess<{ items: AuditEvent[]; meta: PageMeta }>>('/api/v1/tenant/audit', {
    params: { page },
  })

  return response.data.data
}

export async function syncMemberRoles(membershipId: string, roles: string[]): Promise<void> {
  await ensureCsrfCookie()
  await http.put(`/api/v1/tenant/members/${membershipId}/roles`, { roles })
}

export async function createRole(name: string, permissions: string[]): Promise<TenantRole> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ role: TenantRole }>>('/api/v1/tenant/roles', { name, permissions })

  return response.data.data.role
}
