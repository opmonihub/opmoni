import { computed } from 'vue'
import { tenantPermissions } from '#shared/utils/business'
import type { Identity } from '#shared/types/access'
import { accessDestination, apiFailure } from '#shared/utils/access'

export function useAuth() {
  const nuxtApp = useNuxtApp()
  const user = useState<Identity | null>('access-user', () => null)
  const resolved = useState('access-resolved', () => false)
  const generation = useState('access-generation', () => 0)
  const sessionGeneration = useState('access-session-generation', () => 0)
  const { api, csrf, refreshCsrf } = useAccessApi()
  function clearAccountData() {
    nuxtApp.runWithContext(() => {
      clearNuxtData()
      clearNuxtState(key => !key.startsWith('access-') && key !== '__nuxt_devtools__' && key !== 'color-mode')
    })
  }
  function clearSession() {
    ++generation.value
    ++sessionGeneration.value
    user.value = null
    resolved.value = true
    csrf.value = null
    clearAccountData()
  }
  async function rotateCsrf() {
    csrf.value = null
    try {
      await refreshCsrf()
    } catch {
      csrf.value = null
    }
  }
  async function refreshWithStatus() {
    const version = ++generation.value
    try {
      const identity = await api<Identity>('/me')
      if (version === generation.value) {
        user.value = identity
        resolved.value = true
        return { identity: user.value, committed: true }
      }
      return { identity: user.value, committed: false }
    } catch (error) {
      if (apiFailure(error).status === 401) {
        if (version === generation.value) {
          clearSession()
        }
        return { identity: null, committed: false }
      }
      throw error
    }
  }
  async function refresh() {
    return (await refreshWithStatus()).identity
  }
  async function authenticate(path: '/login' | '/register' | '/first-access', body: Record<string, unknown>) {
    const identity = await api<Identity>(path, { method: 'POST', body })
    ++generation.value
    ++sessionGeneration.value
    user.value = identity
    resolved.value = true
    csrf.value = null
    clearNuxtData()
    await rotateCsrf()
    try {
      const recoveryContinuation = await api<{ state: string }>('/password-recovery/current')
      if (['valid', 'identity_mismatch'].includes(recoveryContinuation.state))
        return navigateTo('/redefinir-senha', { replace: true })
    } catch { /* Ordinary authentication keeps its usual destination without a password recovery continuation. */ }
    try {
      const memberContinuation = await api<{ state: string }>('/erp-member-invitations/current')
      if (['valid', 'ready', 'accepted', 'identity_mismatch', 'login_required'].includes(memberContinuation.state))
        return navigateTo('/erp/convite', { replace: true })
    } catch { /* Ordinary login keeps its usual destination when no member invitation is pending. */ }
    try {
      const continuation = await api<{ state: string }>('/erp-invitations/current')
      if (['valid', 'ready', 'accepted', 'identity_mismatch', 'login_required'].includes(continuation.state))
        return navigateTo('/erp/ativar', { replace: true })
    } catch { /* Ordinary authentication keeps its existing destination when no continuation is available. */ }
    return navigateTo(accessDestination(identity), { replace: true })
  }
  async function switchAccount(id: number) {
    const identity = await api<Identity>('/account/switch', { method: 'POST', body: { account_id: id } })
    ++generation.value
    ++sessionGeneration.value
    user.value = identity
    csrf.value = null
    clearAccountData()
    await rotateCsrf()
    if (import.meta.client)
      window.location.assign(accessDestination(identity))
  }
  async function switchCompany(id: number) {
    const identity = await api<Identity>('/erp/company/switch', { method: 'POST', body: { company_id: id } })
    ++generation.value
    ++sessionGeneration.value
    user.value = identity
    csrf.value = null
    clearAccountData()
    await rotateCsrf()
    if (import.meta.client)
      window.location.assign('/erp')
  }
  async function logout() {
    const version = sessionGeneration.value
    try {
      await api('/logout', { method: 'POST' })
    } catch (error) {
      if (apiFailure(error).status !== 401)
        throw error
    }
    if (version !== sessionGeneration.value)
      return
    clearSession()
    if (import.meta.client)
      window.location.assign('/login')
  }
  const isSuperAdmin = computed(() => user.value?.is_super_admin ?? false)
  const accounts = computed(() => user.value?.accounts ?? [])
  const currentAccount = computed(() => user.value?.current_account ?? null)
  const companies = computed(() => user.value?.companies ?? [])
  const currentCompany = computed(() => user.value?.current_company ?? null)
  const permissions = computed(() => tenantPermissions(user.value))
  const currentRole = computed(() => permissions.value.role)
  const can = (roles: string[]) => roles.includes(currentRole.value ?? '')
  const canManageClients = computed(() => permissions.value.canManageClients)
  const canManageWork = computed(() => permissions.value.canManageWork)
  const canManageDepartments = computed(() => permissions.value.canManageDepartments)
  const canManageMembers = computed(() => permissions.value.canManageMembers)
  async function registrationAvailable() {
    return (await api<{ registration_available: boolean }>('/registration-status')).registration_available
  }
  return { user, resolved, refresh, refreshWithStatus, fetchMe: refresh, authenticate, switchAccount, switchCompany, logout, invalidateSession: clearSession,
    isSuperAdmin, accounts, currentAccount, companies, currentCompany, currentRole, can, canManageClients,
    canManageWork, canManageDepartments, canManageMembers, registrationAvailable }
}
