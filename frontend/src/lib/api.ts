import axios from 'axios'

export const TOKEN_KEY = 'cordes_token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8001/api',
  headers: { Accept: 'application/json' },
})

// Attach the Bearer token to every request.
api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// On 401 the token is stale — drop it. Route protection (RequireAuth) handles
// the redirect on the React side; we must NOT hard-reload here or we break SPA
// navigation right after a successful login.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      setToken(null)
    }
    return Promise.reject(error)
  },
)

export default api
