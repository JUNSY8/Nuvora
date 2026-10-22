import { ensureCsrfCookie, http } from '@/services/http'
import type { ApiSuccess, PageMeta } from '@/types/api'

import type { Membership, MembershipFilters, MembershipInput, MembershipPlan, MembershipVehicleInput, PlanInput, PlanStatus } from '@/modules/memberships/types'

const plans = '/api/v1/membership-plans'
const base = '/api/v1/memberships'

export async function listPlans(status?: PlanStatus): Promise<MembershipPlan[]> {
  const response = await http.get<ApiSuccess<{ items: MembershipPlan[] }>>(plans, { params: status ? { status } : {} })

  return response.data.data.items
}

export async function savePlan(id: string | null, input: PlanInput): Promise<MembershipPlan> {
  await ensureCsrfCookie()
  const response = id === null
    ? await http.post<ApiSuccess<{ plan: MembershipPlan }>>(plans, input)
    : await http.patch<ApiSuccess<{ plan: MembershipPlan }>>(`${plans}/${id}`, input)

  return response.data.data.plan
}

export async function changePlanStatus(id: string, status: PlanStatus): Promise<MembershipPlan> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ plan: MembershipPlan }>>(`${plans}/${id}/status`, { status })

  return response.data.data.plan
}

export async function listMemberships(filters: MembershipFilters = {}, page = 1, perPage = 25): Promise<{ items: Membership[]; meta: PageMeta }> {
  const response = await http.get<ApiSuccess<{ items: Membership[]; meta: PageMeta }>>(base, {
    params: { ...filters, page, per_page: perPage },
  })

  return response.data.data
}

// With its billing periods.
export async function getMembership(id: string): Promise<Membership> {
  const response = await http.get<ApiSuccess<{ membership: Membership }>>(`${base}/${id}`)

  return response.data.data.membership
}

export async function sellMembership(input: MembershipInput): Promise<Membership> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ membership: Membership }>>(base, input)

  return response.data.data.membership
}

async function act(path: string, body: Record<string, unknown> = {}): Promise<Membership> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ membership: Membership }>>(path, body)

  return response.data.data.membership
}

export function addMembershipVehicle(id: string, vehicle: MembershipVehicleInput): Promise<Membership> {
  return act(`${base}/${id}/vehicles`, { ...vehicle })
}

export async function removeMembershipVehicle(id: string, vehicleId: string): Promise<Membership> {
  await ensureCsrfCookie()
  const response = await http.delete<ApiSuccess<{ membership: Membership }>>(`${base}/${id}/vehicles/${vehicleId}`)

  return response.data.data.membership
}

// The same idempotency key must be sent again when retrying the same renewal.
export async function renewMembership(id: string, idempotencyKey: string): Promise<Membership> {
  await ensureCsrfCookie()
  const response = await http.post<ApiSuccess<{ membership: Membership }>>(`${base}/${id}/renew`, {}, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })

  return response.data.data.membership
}

export function suspendMembership(id: string, reason: string): Promise<Membership> {
  return act(`${base}/${id}/suspend`, { reason })
}

export function reactivateMembership(id: string): Promise<Membership> {
  return act(`${base}/${id}/reactivate`)
}

export function cancelMembership(id: string, reason: string): Promise<Membership> {
  return act(`${base}/${id}/cancel`, { reason })
}

export function setAutoRenew(id: string, autoRenew: boolean): Promise<Membership> {
  return act(`${base}/${id}/auto-renew`, { auto_renew: autoRenew })
}
