import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, setToken, getToken } from '../api/client'
import type { Company } from '../types'

interface AuthUser {
  id: number
  name: string
  email: string
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const company = ref<Company | null>(null)
  const ready = ref(false)

  async function register(payload: { name: string; email: string; password: string; company_name: string; country?: string }) {
    const { data } = await api.post('/register', payload)
    setToken(data.token)
    user.value = data.user
    company.value = data.company
  }

  async function login(payload: { email: string; password: string }) {
    const { data } = await api.post('/login', payload)
    setToken(data.token)
    user.value = data.user
    await fetchMe()
  }

  async function fetchMe() {
    const { data } = await api.get('/me')
    user.value = data.user
    company.value = data.company
  }

  /** Restore a session on app boot if a token is stored. */
  async function bootstrap() {
    if (getToken()) {
      try {
        await fetchMe()
      } catch {
        setToken(null)
      }
    }
    ready.value = true
  }

  async function logout() {
    try {
      await api.post('/logout')
    } catch {
      /* ignore */
    }
    setToken(null)
    user.value = null
    company.value = null
  }

  function setCompany(c: Company) {
    company.value = c
  }

  return { user, company, ready, register, login, fetchMe, bootstrap, logout, setCompany }
})
