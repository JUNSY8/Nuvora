import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { http } from '@/services/http'
import type { ApiSuccess } from '@/types/api'

interface LiveVersions {
  tenant: string
  lot: string | null
}

export type LiveStatus = 'connecting' | 'live' | 'offline'

interface Options {
  // Lot to watch; null watches the whole tenant.
  lotId: () => string | null
  onChange: () => void | Promise<void>
  // Polling is skipped while this returns false (for example without a tenant).
  enabled?: () => boolean
  intervalMs?: number
  // Reload anyway after this long: the change marker lives in a cache and may be lost.
  backstopMs?: number
}

/**
 * Polls the change markers of GET /api/v1/live and calls onChange when the
 * watched channel moves. The screen then reloads from the regular API, which
 * stays the source of truth. Polling pauses while the tab is hidden and
 * slows down after errors. See ADR-015.
 */
export function useLiveUpdates(options: Options) {
  const status = ref<LiveStatus>('connecting')
  const interval = options.intervalMs ?? 4000
  const backstop = options.backstopMs ?? 60000

  let baseline: string | null = null
  let timer: ReturnType<typeof setTimeout> | null = null
  let failures = 0
  let lastReload = Date.now()
  let stopped = false

  function schedule(delay: number): void {
    if (timer !== null) {
      clearTimeout(timer)
    }

    timer = stopped ? null : setTimeout(() => void tick(), delay)
  }

  async function reload(): Promise<void> {
    lastReload = Date.now()
    await options.onChange()
  }

  async function tick(): Promise<void> {
    if (stopped) {
      return
    }

    if ((typeof document !== 'undefined' && document.visibilityState === 'hidden') || options.enabled?.() === false) {
      schedule(interval)
      return
    }

    const lotId = options.lotId()

    try {
      const response = await http.get<ApiSuccess<LiveVersions>>('/api/v1/live', { params: lotId ? { lot_id: lotId } : {} })
      const version = lotId ? response.data.data.lot : response.data.data.tenant
      failures = 0
      status.value = 'live'

      if (baseline !== null && version !== baseline) {
        baseline = version
        await reload()
      } else {
        baseline = version

        if (Date.now() - lastReload >= backstop) {
          await reload()
        }
      }
    } catch {
      failures += 1
      status.value = 'offline'
    }

    schedule(failures === 0 ? interval : Math.min(30000, interval * 2 ** failures))
  }

  function onVisibility(): void {
    if (document.visibilityState === 'visible') {
      schedule(0)
    }
  }

  // A new lot starts a new baseline; the screen loads it on its own.
  watch(options.lotId, () => {
    baseline = null
    lastReload = Date.now()
    schedule(0)
  })

  onMounted(() => {
    stopped = false
    document.addEventListener('visibilitychange', onVisibility)
    schedule(0)
  })

  onBeforeUnmount(() => {
    stopped = true
    document.removeEventListener('visibilitychange', onVisibility)

    if (timer !== null) {
      clearTimeout(timer)
    }
  })

  return { status }
}
