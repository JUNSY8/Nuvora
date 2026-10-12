import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import ForgotPasswordView from '@/modules/auth/ForgotPasswordView.vue'
import { useAuthStore } from '@/stores/auth'

describe('ForgotPasswordView', () => {
  it('shows the API error and stays on the form', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'dashboard', component: { template: '<div>Inicio</div>' } },
        { path: '/recuperar-contrasena', name: 'forgot-password', component: ForgotPasswordView },
        { path: '/login', name: 'login', component: { template: '<div>Login</div>' } },
      ],
    })

    const pinia = createPinia()
    setActivePinia(pinia)

    const auth = useAuthStore()
    auth.forgotPassword = vi.fn().mockRejectedValue(new Error('No fue posible enviar el enlace.'))

    const wrapper = mount(ForgotPasswordView, {
      global: {
        plugins: [pinia, router],
      },
    })

    await wrapper.get('#email').setValue('ana@nuvora.test')
    await wrapper.get('form').trigger('submit.prevent')

    expect(wrapper.get('[role="alert"]').text()).toBe('No fue posible enviar el enlace.')
    expect(wrapper.get('#email').attributes('aria-invalid')).toBe('true')
  })
})
