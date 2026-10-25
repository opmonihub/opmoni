import type { ComputedRef, Ref } from 'vue'
import type { ContextToken, OfficeErpCommandResult, OfficeErpFailure } from '#shared/types/officeErpCommercial'

type PendingOfficeCommand = {
  key: string
  contextStamp: string
  durableStamp: string
}

const pendingStorageKey = 'opmoni:office-erp-commercial:pending:v1'
const validOperationKey = /^[0-9a-f-]{36}$/i

function tokenStamp(token: ContextToken): string {
  return JSON.stringify([token.key, token.generation])
}

function statusOf(error: unknown): number | undefined {
  if (!error || typeof error !== 'object') return undefined
  const candidate = error as { statusCode?: unknown, status?: unknown, response?: { status?: unknown } }
  const value = candidate.statusCode ?? candidate.status ?? candidate.response?.status
  return Number.isInteger(value) ? Number(value) : undefined
}

function failureOf(error: unknown, status: number): OfficeErpFailure {
  const candidate = error && typeof error === 'object' ? error as { data?: unknown, response?: { _data?: unknown } } : {}
  const envelope = (candidate.data ?? candidate.response?._data) as Partial<OfficeErpFailure> | null | undefined
  const failure: OfficeErpFailure = {
    code: typeof envelope?.code === 'string' ? envelope.code : 'office_erp_request_failed',
    message: typeof envelope?.message === 'string' ? envelope.message : 'A solicitação comercial não foi aceita.',
    http_status: status
  }
  if (envelope?.errors && typeof envelope.errors === 'object' && !Array.isArray(envelope.errors))
    failure.errors = envelope.errors as Record<string, string[]>
  if (envelope?.data && typeof envelope.data === 'object' && !Array.isArray(envelope.data))
    failure.data = envelope.data as OfficeErpFailure['data']
  return failure
}

function freezePayload(body: Record<string, unknown>): Record<string, unknown> {
  const copy = JSON.parse(JSON.stringify(body)) as unknown
  if (!copy || typeof copy !== 'object' || Array.isArray(copy))
    throw new TypeError('Office ERP command body must be a JSON object')
  function freeze(value: unknown): void {
    if (!value || typeof value !== 'object' || Object.isFrozen(value)) return
    for (const child of Object.values(value)) freeze(child)
    Object.freeze(value)
  }
  freeze(copy)
  return copy as Record<string, unknown>
}

function dataEnvelope<T>(response: unknown): { valid: true, data: T } | { valid: false } {
  if (!response || typeof response !== 'object' || !Object.hasOwn(response, 'data')) return { valid: false }
  return { valid: true, data: (response as { data: T }).data }
}

export function useOfficeErpCommand() {
  const { api, refreshCsrf } = useAccessApi()
  const { user, currentAccount } = useAuth()
  const sessionGeneration = useState('access-session-generation', () => 0)
  const context = useOfficeErpContext()
  const operation = useState<PendingOfficeCommand | null>('office-erp-command-operation', () => null)
  const inFlight = useState('office-erp-command-in-flight', () => false)
  const reservation = useState<string | null>('office-erp-command-reservation', () => null)
  const reservationAuthority = useState<string | null>('office-erp-command-reservation-authority', () => null)
  const pending = computed(() => inFlight.value || operation.value !== null)
  const recoveryKey = computed(() => operation.value?.key ?? null)
  const storageScope = computed(() => JSON.stringify([user.value?.id ?? null, currentAccount.value?.id ?? null]))
  let previousStorageKey = `${pendingStorageKey}:${storageScope.value}`

  function authorityKey(): string {
    return JSON.stringify([
      user.value?.id ?? null,
      sessionGeneration.value,
      currentAccount.value?.id ?? null,
      currentAccount.value?.status ?? null,
      currentAccount.value?.role ?? null
    ])
  }

  function durableStamp(): string {
    return JSON.stringify([
      user.value?.id ?? null,
      currentAccount.value?.id ?? null,
      currentAccount.value?.status ?? null,
      currentAccount.value?.role ?? null,
      context.capabilities.value?.capability_key ?? null
    ])
  }

  function removeStored(key = previousStorageKey) {
    if (typeof sessionStorage === 'undefined') return
    try {
      sessionStorage.removeItem(key)
    } catch { /* Storage can be disabled. */ }
  }

  function clearOperation(key?: string) {
    if (key && operation.value?.key !== key) return
    operation.value = null
    removeStored()
  }

  function saveOperation(value: PendingOfficeCommand): boolean {
    if (typeof sessionStorage === 'undefined') return false
    try {
      sessionStorage.setItem(previousStorageKey, JSON.stringify({ version: 1, key: value.key, durableStamp: value.durableStamp }))
      return true
    } catch {
      return false
    }
  }

  function restoreOperation(stamp: string): PendingOfficeCommand | null {
    if (typeof sessionStorage === 'undefined') return null
    try {
      const raw = sessionStorage.getItem(previousStorageKey)
      const stored = raw ? JSON.parse(raw) as { version?: unknown, key?: unknown, durableStamp?: unknown } : null
      if (!raw) return null
      if (stored?.version !== 1 || typeof stored.key !== 'string' || !validOperationKey.test(stored.key) || stored.durableStamp !== durableStamp()) {
        sessionStorage.removeItem(previousStorageKey)
        return null
      }
      return { key: stored.key, contextStamp: stamp, durableStamp: durableStamp() }
    } catch {
      return null
    }
  }

  const contextStamp = computed(() => tokenStamp(context.capture()))
  watch(storageScope, (next) => {
    const oldKey = previousStorageKey
    previousStorageKey = `${pendingStorageKey}:${next}`
    removeStored(oldKey)
    if (reservation.value && reservationAuthority.value !== authorityKey()) {
      if (operation.value?.key === reservation.value) operation.value = null
      reservation.value = null
      reservationAuthority.value = null
      inFlight.value = false
    }
  })
  watch(contextStamp, (stamp, previous) => {
    if (previous !== undefined && previous !== stamp && !context.capabilities.value)
      removeStored()
    if (operation.value && operation.value.durableStamp !== durableStamp()) {
      const staleKey = operation.value.key
      clearOperation(staleKey)
      if (reservation.value === staleKey) {
        reservation.value = null
        reservationAuthority.value = null
        inFlight.value = false
      }
    }
    if (reservation.value && reservationAuthority.value !== authorityKey()) {
      reservation.value = null
      reservationAuthority.value = null
      inFlight.value = false
    }
    if (!operation.value && !inFlight.value && context.capabilities.value)
      operation.value = restoreOperation(stamp)
  }, { immediate: true })

  function unknown(key: string): OfficeErpCommandResult<never> {
    return { status: 'unknown', key }
  }

  function invalid(message: string): OfficeErpCommandResult<never> {
    return { status: 'failed', failure: { code: 'office_erp_invalid_command', message, http_status: 422 } }
  }

  async function execute<T>(action: string, path: string, body: Record<string, unknown>, method: 'POST' | 'PATCH' = 'POST'): Promise<OfficeErpCommandResult<T>> {
    if (operation.value) return unknown(operation.value.key)
    if (inFlight.value && reservation.value) return unknown(reservation.value)
    if (!action.trim() || !path.startsWith('/erp-commercial/') || path.includes('?') || path.includes('#'))
      return invalid('Ação ou caminho comercial inválido.')

    let payload: Record<string, unknown>
    try {
      payload = freezePayload(body)
    } catch {
      return invalid('O corpo da operação não é um objeto JSON válido.')
    }
    const key = crypto.randomUUID()
    reservation.value = key
    reservationAuthority.value = authorityKey()
    inFlight.value = true

    let token: ContextToken | null = null
    let dispatched = false
    try {
      const initialToken = context.capture()
      const initialAuthority = authorityKey()
      const hadCapabilities = !!context.capabilities.value
      await context.refresh()
      if (reservation.value !== key) return { status: 'stale' }
      if (authorityKey() !== initialAuthority || context.capture().generation !== initialToken.generation
        || (hadCapabilities && context.capture().key !== initialToken.key)) {
        inFlight.value = false
        reservation.value = null
        reservationAuthority.value = null
        return { status: 'stale' }
      }
      if (reservation.value !== key) return { status: 'stale' }
      if (!context.capabilities.value) {
        inFlight.value = false
        reservation.value = null
        reservationAuthority.value = null
        return { status: 'failed', failure: { code: 'office_erp_context_unavailable', message: 'Não foi possível confirmar o acesso comercial do escritório.' } }
      }
      token = context.capture()
      const stamp = tokenStamp(token)
      if (!operation.value) operation.value = restoreOperation(stamp)
      if (operation.value) {
        const priorKey = operation.value.key
        inFlight.value = false
        reservation.value = null
        reservationAuthority.value = null
        return unknown(priorKey)
      }
      const command: PendingOfficeCommand = { key, contextStamp: stamp, durableStamp: durableStamp() }
      operation.value = command

      await refreshCsrf()
      if (!context.isCurrent(token) || reservation.value !== key) return { status: 'stale' }
      if (!saveOperation(command)) {
        clearOperation(key)
        return { status: 'failed', failure: { code: 'office_erp_recovery_unavailable', message: 'Não foi possível guardar a identificação da operação para recuperação.' } }
      }

      dispatched = true
      const response = await api<unknown>(path, { method, body: payload, headers: { 'Idempotency-Key': key } })
      if (!context.isCurrent(token) || reservation.value !== key) return { status: 'stale' }
      const envelope = dataEnvelope<T>(response)
      if (!envelope.valid) {
        operation.value = { key, contextStamp: stamp, durableStamp: durableStamp() }
        return unknown(key)
      }

      clearOperation(key)
      return { status: 'confirmed', data: envelope.data }
    } catch (error) {
      if (token && !context.isCurrent(token)) return { status: 'stale' }
      if (reservation.value !== key) return { status: 'stale' }
      const status = statusOf(error)
      if (!dispatched) {
        clearOperation(key)
        return status !== undefined
          ? { status: 'failed', failure: failureOf(error, status) }
          : { status: 'failed', failure: { code: 'office_erp_preflight_failed', message: 'Não foi possível preparar a solicitação comercial.' } }
      }
      if (status !== undefined && status >= 400 && status < 500) {
        clearOperation(key)
        return { status: 'failed', failure: failureOf(error, status) }
      }
      if (operation.value?.key === key)
        operation.value = { key, contextStamp: operation.value.contextStamp, durableStamp: operation.value.durableStamp }
      return unknown(key)
    } finally {
      if ((!token || context.isCurrent(token)) && reservation.value === key) {
        inFlight.value = false
        reservation.value = null
        reservationAuthority.value = null
      }
    }
  }

  async function recover<T>(key: string): Promise<OfficeErpCommandResult<T>> {
    if (!validOperationKey.test(key)) return invalid('A identificação da operação é inválida.')
    if (inFlight.value) return unknown(reservation.value ?? key)

    await context.refresh()
    if (!context.capabilities.value) return { status: 'failed', failure: { code: 'office_erp_context_unavailable', message: 'Não foi possível confirmar o acesso comercial do escritório.' } }
    const token = context.capture()
    const stamp = tokenStamp(token)
    if (!operation.value) operation.value = restoreOperation(stamp)
    if (!operation.value || operation.value.key !== key || operation.value.durableStamp !== durableStamp())
      return { status: 'stale' }

    reservation.value = key
    reservationAuthority.value = authorityKey()
    inFlight.value = true
    try {
      const response = await api<unknown>(`/erp-commercial/operations/${encodeURIComponent(key)}`)
      if (!context.isCurrent(token) || reservation.value !== key) return { status: 'stale' }
      const envelope = dataEnvelope<T>(response)
      if (!envelope.valid) return unknown(key)
      clearOperation(key)
      return { status: 'confirmed', data: envelope.data }
    } catch (error) {
      if (!context.isCurrent(token) || reservation.value !== key) return { status: 'stale' }
      const status = statusOf(error)
      if (status === 404 || status === undefined || status >= 500) return unknown(key)
      return { status: 'failed', failure: failureOf(error, status) }
    } finally {
      if (context.isCurrent(token) && reservation.value === key) {
        inFlight.value = false
        reservation.value = null
        reservationAuthority.value = null
      }
    }
  }

  return {
    pending: pending as ComputedRef<boolean>,
    recoveryKey: recoveryKey as Readonly<Ref<string | null>>,
    execute,
    recover
  }
}
