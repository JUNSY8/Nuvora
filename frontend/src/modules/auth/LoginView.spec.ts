import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import LoginView from '@/modules/auth/LoginView.vue'
import { useAuthStore } from '@/stores/auth'

describe('LoginView', () => {
  it('shows the API error and keeps the operator on the form', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/login', name: 'login', component: LoginView },
        { path: '/registro', name: 'register', component: { template: '<div>Registro</div>' } },
        { path: '/recuperar-contrasena', name: 'forgot-password', component: { template: '<div>Recuperar</div>' } },
        { path: '/', name: 'dashboard', component: { template: '<div>Inicio</div>' } },
      ],
    })

    const pinia = createPinia()
    setActivePinia(pinia)

    const auth = useAuthStore()
    auth.login = vi.fn().mockRejectedValue(new Error('Las credenciales no son válidas.'))

    const wrapper = mount(LoginView, {
      global: {
        plugins: [pinia, router],
      },
    })

    await wrapper.get('#email').setValue('ana@nuvora.test')
    await wrapper.get('#password').setValue('Password12345')
    await wrapper.get('form').trigger('submit.prevent')

    expect(wrapper.get('[role="alert"]').text()).toBe('Las credenciales no son válidas.')
    expect(wrapper.get('#email').attributes('aria-invalid')).toBe('true')
  })
})
