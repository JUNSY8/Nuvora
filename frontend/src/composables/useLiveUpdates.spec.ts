import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

import { useLiveUpdates } from '@/composables/useLiveUpdates'
import { http } from '@/services/http'

function versions(lot: string | null, tenant = 't1') {
  return { data: { success: true, data: { tenant, lot }, request_id: 'r' } }
}

function host(lotId: { value: string | null }, onChange: () => void, enabled = () => true) {
  return mount(defineComponent({
    setup() {
      const { status } = useLiveUpdates({ lotId: () => lotId.value, onChange, enabled, intervalMs: 1000, backstopMs: 60000 })

      return () => h('span', status.value)
    },
  }))
}

describe('useLiveUpdates', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.useRealTimers()
  })

  it('reloads only when the watched version moves', async () => {
    const get = vi.spyOn(http, 'get')
      .mockResolvedValueOnce(versions('v1'))
      .mockResolvedValueOnce(versions('v1'))
      .mockResolvedValueOnce(versions('v2'))
      .mockResolvedValue(versions('v2'))
    const onChange = vi.fn()
    const wrapper = host(ref('lot-1'), onChange)

    await vi.advanceTimersByTimeAsync(0)
    expect(get).toHaveBeenCalledWith('/api/v1/live', { params: { lot_id: 'lot-1' } })
    expect(onChange).not.toHaveBeenCalled()
    expect(wrapper.text()).toBe('live')

    await vi.advanceTimersByTimeAsync(1000)
    expect(onChange).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1000)
    expect(onChange).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(3000)
    expect(onChange).toHaveBeenCalledTimes(1)

    wrapper.unmount()
  })

  it('reports offline, backs off and recovers', async () => {
    const get = vi.spyOn(http, 'get')
      .mockRejectedValueOnce(new Error('down'))
      .mockRejectedValueOnce(new Error('down'))
      .mockResolvedValue(versions('v1'))
    const wrapper = host(ref('lot-1'), vi.fn())

    await vi.advanceTimersByTimeAsync(0)
    expect(wrapper.text()).toBe('offline')

    // First retry after 2 s, the second after 4 s.
    await vi.advanceTimersByTimeAsync(1999)
    expect(get).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(get).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(4000)
    expect(get).toHaveBeenCalledTimes(3)
    expect(wrapper.text()).toBe('live')

    wrapper.unmount()
  })

  it('does not poll while disabled and stops when unmounted', async () => {
    const get = vi.spyOn(http, 'get').mockResolvedValue(versions('v1'))
    const enabled = ref(false)
    const wrapper = host(ref(null), vi.fn(), () => enabled.value)

    await vi.advanceTimersByTimeAsync(3000)
    expect(get).not.toHaveBeenCalled()

    enabled.value = true
    await vi.advanceTimersByTimeAsync(1000)
    expect(get).toHaveBeenCalledWith('/api/v1/live', { params: {} })

    wrapper.unmount()
    const calls = get.mock.calls.length
    await vi.advanceTimersByTimeAsync(5000)
    expect(get).toHaveBeenCalledTimes(calls)
  })
})
