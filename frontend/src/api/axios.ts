import axios, { type InternalAxiosRequestConfig } from 'axios'
import { apiBaseUrl } from '@/utils/api'

const TOKEN_STORAGE_KEY = 'lokato.auth.token'

const api = axios.create({
  baseURL: apiBaseUrl,
  timeout: 180000,
  headers: {
    Accept: 'application/json',
  },
})

// Inject the Sanctum bearer token on every outgoing request. Reading from
// localStorage directly (instead of importing the store) avoids a circular
// dependency between this module and the auth store.
api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  let token: string | null = null
  try {
    token = localStorage.getItem(TOKEN_STORAGE_KEY)
  } catch {
    token = null
  }
  if (token) {
    config.headers.set('Authorization', `Bearer ${token}`)
  }
  return config
})

// 401 → wipe the token locally; the auth store + router-guard will redirect
// to /login on the next navigation tick.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 401) {
      try {
        localStorage.removeItem(TOKEN_STORAGE_KEY)
        localStorage.removeItem('lokato.auth.user')
      } catch {
        // ignore
      }
    }
    return Promise.reject(error)
  },
)

export default api
