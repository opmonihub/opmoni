export interface AuthAccountLink {
  id: number
  name: string
  role?: string
  /** false quando super_admin pode trocar para a conta sem vínculo de membro */
  is_member?: boolean
}

export interface AuthCurrentAccount {
  id: number
  name: string
}

export interface AuthUser {
  id: number
  name: string
  email: string
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  company: string
  size: string
}

interface MeResponse {
  id: number
  name: string
  email: string
  is_super_admin: boolean
  accounts: AuthAccountLink[]
  current_account: AuthCurrentAccount | null
}

export function useAuth() {
  const user = useState<AuthUser | null>('auth.user', () => null)
  const isSuperAdmin = useState<boolean>('auth.is-super-admin', () => false)
  const accounts = useState<AuthAccountLink[]>('auth.accounts', () => [])
  const currentAccount = useState<AuthCurrentAccount | null>('auth.current-account', () => null)
  const currentRole = computed(() => {
    const link = accounts.value.find(account => account.id === currentAccount.value?.id)
    if (!link || link.is_member === false) {
      return null
    }
    return link.role ?? null
  })
  const can = (roles: string[]): boolean => isSuperAdmin.value || roles.includes(currentRole.value ?? '')
  const canManageClients = computed(() => can(['admin', 'operador']))
  // Escrita em Work (tasks/templates/processes) e em Departamentos exige papel
  // admin|operador na conta (super_admin atua como admin) — espelha
  // TaskPolicy/ProcessTemplatePolicy/DepartmentPolicy no backend.
  const canManageWork = computed(() => can(['admin', 'operador']))
  const canManageDepartments = computed(() => can(['admin', 'operador']))
  // Gestão de membros (convite/papel/remoção) exige admin na conta —
  // espelha AccountPolicy::manageMembers (super_admin em suporte atua como admin).
  const canManageMembers = computed(() => can(['admin']))

  function applyMe(me: MeResponse) {
    user.value = { id: me.id, name: me.name, email: me.email }
    isSuperAdmin.value = me.is_super_admin
    accounts.value = me.accounts ?? []
    currentAccount.value = me.current_account
  }

  function clearAuth() {
    user.value = null
    isSuperAdmin.value = false
    accounts.value = []
    currentAccount.value = null
  }

  async function ensureCsrf() {
    await $fetch(`${apiOrigin()}/sanctum/csrf-cookie`, { credentials: 'include' })
    refreshCookie('XSRF-TOKEN')
  }

  async function fetchMe() {
    const { $api } = useNuxtApp()
    const me = await $api<MeResponse>('/me')
    applyMe(me)
    return me
  }

  async function registrationAvailable(): Promise<boolean> {
    const { $api } = useNuxtApp()
    const status = await $api<{ registration_available: boolean }>('/registration-status')
    return status.registration_available
  }

  async function login(email: string, password: string) {
    const { $api } = useNuxtApp()
    await ensureCsrf()
    await $api('/login', { method: 'POST', body: { email, password } })
    return fetchMe()
  }

  async function register(payload: RegisterPayload) {
    const { $api } = useNuxtApp()
    await ensureCsrf()
    await $api('/register', { method: 'POST', body: payload })
    return fetchMe()
  }

  /**
   * Always drops local state, even when `/logout` fails (419 stale CSRF, 401
   * already-expired session, 500). Without `finally` a failed call kept
   * `auth.user` truthy, and the resulting loop was: plugin bounces to /login →
   * fetchMe() 401 → user still set → navigateTo('/') → auth middleware returns
   * early on `if (user.value)` → every API call 401s again. The error still
   * propagates so the caller can toast the failure.
   */
  async function logout() {
    const { $api } = useNuxtApp()
    try {
      await $api('/logout', { method: 'POST' })
    } finally {
      clearAuth()
    }
  }

  async function switchAccount(accountId: number) {
    const { $api } = useNuxtApp()
    await $api('/account/switch', { method: 'POST', body: { account_id: accountId } })
    return fetchMe()
  }

  async function enterSupport(accountId: number) {
    const { $api } = useNuxtApp()
    await $api(`/support/accounts/${accountId}/enter`, { method: 'POST' })
    return fetchMe()
  }

  async function exitSupport() {
    const { $api } = useNuxtApp()
    await $api('/support/exit', { method: 'POST' })
    return fetchMe()
  }

  return { user, isSuperAdmin, accounts, currentAccount, currentRole, can, canManageClients, canManageWork, canManageDepartments, canManageMembers, fetchMe, clearAuth, ensureCsrf, registrationAvailable, login, register, logout, switchAccount, enterSupport, exitSupport }
}
