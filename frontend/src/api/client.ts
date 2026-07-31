import axios from 'axios'

const TOKEN_KEY = 'transoria_token'

export const api = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
})

// Attach the bearer token to every request if present.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

export function setToken(token: string | null) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_KEY)
  }
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

/** Extract a human-readable message from an axios error. */
export function apiError(err: unknown): string {
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    if (data?.errors) {
      return Object.values(data.errors).flat()[0] ?? 'Request failed.'
    }
    return data?.message ?? err.message
  }
  return 'Something went wrong.'
}
