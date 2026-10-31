import { computed } from 'vue'

import { useAuthStore } from '@/stores/auth'

export function usePermissions() {
  const auth = useAuthStore()
  const granted = computed(() => new Set(auth.currentTenant?.permissions ?? []))

  return {
    can: (permission: string): boolean => granted.value.has(permission),
    hasTenant: computed(() => auth.currentTenant !== null),
  }
}
