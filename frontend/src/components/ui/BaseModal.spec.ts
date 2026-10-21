import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { defineComponent, nextTick, ref } from 'vue'

import BaseModal from '@/components/ui/BaseModal.vue'

describe('BaseModal', () => {
  it('closes only the topmost dialog on Escape, even when focus is on the body', async () => {
    const Host = defineComponent({
      components: { BaseModal },
      setup() {
        const outer = ref(true)
        const inner = ref(true)

        return { outer, inner }
      },
      template: `
        <BaseModal :open="outer" title="Espacio" @close="outer = false"><p>Detalle</p></BaseModal>
        <BaseModal :open="inner" title="Confirmar" @close="inner = false"><p>¿Seguro?</p></BaseModal>
      `,
    })

    const wrapper = mount(Host, { attachTo: document.body })
    await nextTick()
    await nextTick()
    ;(document.activeElement as HTMLElement | null)?.blur()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await nextTick()

    expect(wrapper.vm.inner).toBe(false)
    expect(wrapper.vm.outer).toBe(true)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await nextTick()

    expect(wrapper.vm.outer).toBe(false)
    wrapper.unmount()
  })
})
