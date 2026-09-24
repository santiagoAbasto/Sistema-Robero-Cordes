import { createContext, useContext, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import api, { getToken, setToken } from './api'
import type { User } from '../types'

interface AuthContextValue {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  // Restore the session on first load if a token is present.
  useEffect(() => {
    let active = true
    async function bootstrap() {
      // No token yet → no point probing /me; go straight to the login screen.
      if (!getToken()) {
        if (active) setLoading(false)
        return
      }
      try {
        const { data } = await api.get<User>('/me')
        if (active) setUser(data)
      } catch {
        if (active) setUser(null)
      } finally {
        if (active) setLoading(false)
      }
    }
    bootstrap()
    return () => {
      active = false
    }
  }, [])

  async function login(email: string, password: string) {
    const { data } = await api.post<{ token: string; user: User }>('/login', {
      email,
      password,
    })
    setToken(data.token)
    setUser(data.user)
  }

  async function logout() {
    try {
      await api.post('/logout')
    } catch {
      // ignore network errors on logout
    }
    setToken(null)
    setUser(null)
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within <AuthProvider>')
  return ctx
}
