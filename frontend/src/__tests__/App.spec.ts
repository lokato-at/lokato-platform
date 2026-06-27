import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHashHistory } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import App from '../App.vue'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
    { path: '/admin/home', component: { template: '<div>Admin</div>' } },
    { path: '/login', component: { template: '<div>Login</div>' } },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
  ],
})

describe('App', () => {
  it('renders the app shell', async () => {
    setActivePinia(createPinia())

    router.push('/dashboard')
    await router.isReady()

    const wrapper = mount(App, {
      global: {
        plugins: [router, createPinia()],
      },
    })

    expect(wrapper.text()).toContain('Lokato')
    expect(wrapper.text()).toContain('Dashboard')
    expect(wrapper.text()).toContain('Admin')
  })
})
