import { defineStore } from 'pinia'
import api from '../api/axios'

const TOKEN_STORAGE_KEY = 'lokato.auth.token'
const USER_STORAGE_KEY = 'lokato.auth.user'

export interface AuthUser {
  id: number
  name: string
  email: string
}

interface LoginResponse {
  token: string
  user: AuthUser
}

function loadStoredToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_STORAGE_KEY)
  } catch {
    return null
  }
}

function loadStoredUser(): AuthUser | null {
  try {
    const raw = localStorage.getItem(USER_STORAGE_KEY)
    return raw ? (JSON.parse(raw) as AuthUser) : null
  } catch {
    return null
  }
}

export const useAuthStore = defineStore('authStore', {
  state: () => ({
    token: loadStoredToken(),
    user: loadStoredUser(),
    loading: false as boolean,
    error: null as string | null,
  }),

  getters: {
    isAuthenticated(state): boolean {
      return state.token !== null && state.user !== null
    },
  },

  actions: {
    async login(email: string, password: string, deviceName?: string): Promise<void> {
      this.loading = true
      this.error = null

      try {
        const response = await api.post<LoginResponse>('/auth/login', {
          email,
          password,
          device_name: deviceName ?? navigator.userAgent.slice(0, 60),
        })

        this.token = response.data.token
        this.user = response.data.user
        localStorage.setItem(TOKEN_STORAGE_KEY, this.token)
        localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(this.user))
      } catch (err: unknown) {
        const message = extractAxiosMessage(err) ?? 'Anmeldung fehlgeschlagen.'
        this.error = message
        throw err
      } finally {
        this.loading = false
      }
    },

    async logout(): Promise<void> {
      if (this.token) {
        try {
          await api.post('/auth/logout')
        } catch {
          // Token is dropped client-side regardless
        }
      }
      this.token = null
      this.user = null
      localStorage.removeItem(TOKEN_STORAGE_KEY)
      localStorage.removeItem(USER_STORAGE_KEY)
    },

    async refreshUser(): Promise<void> {
      if (!this.token) return

      try {
        const response = await api.get<AuthUser>('/auth/me')
        this.user = response.data
        localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(this.user))
      } catch {
        // Token might be expired/revoked — drop it
        await this.logout()
      }
    },
  },
})

function extractAxiosMessage(err: unknown): string | null {
  if (typeof err !== 'object' || err === null) return null
  const maybeAxios = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  const data = maybeAxios.response?.data
  if (!data) return null
  if (typeof data.message === 'string') return data.message
  if (data.errors) {
    const firstField = Object.values(data.errors)[0]
    if (Array.isArray(firstField) && typeof firstField[0] === 'string') {
      return firstField[0]
    }
  }
  return null
}
