import type { ComputedRef } from 'vue'
import type { ContextToken, OfficeErpCapabilities } from '#shared/types/officeErpCommercial'

interface ContextFlight {
  authority: string
  request: number
  generation: number
  promise: Promise<void>
}

const pendingContext = new WeakMap<object, ContextFlight>()

function isCapabilities(value: unknown): value is OfficeErpCapabilities {
  if (!value || typeof value !== 'object') return false
  const candidate = value as Partial<OfficeErpCapabilities>
  const allowance = candidate.allowance
  const usage = candidate.usage
  const capabilityReasons = candidate.capability_reasons
  const hasCapabilityReasons = !!capabilityReasons
    && typeof capabilityReasons === 'object'
    && !Array.isArray(capabilityReasons)
    && ['can_configure', 'can_sell_new', 'can_provision', 'can_start_trial', 'can_manage_trial_link'].every((key) => {
      const reasons = capabilityReasons[key]

      return Array.isArray(reasons) && reasons.every(reason => typeof reason === 'string')
    })
  return typeof candidate.can_read === 'boolean'
    && typeof candidate.can_maintain === 'boolean'
    && typeof candidate.can_configure === 'boolean'
    && typeof candidate.can_sell_new === 'boolean'
    && typeof candidate.can_provision === 'boolean'
    && typeof candidate.can_start_trial === 'boolean'
    && typeof candidate.can_manage_trial_link === 'boolean'
    && typeof candidate.capability_key === 'string' && candidate.capability_key.length > 0
    && !!candidate.reasons && typeof candidate.reasons === 'object' && !Array.isArray(candidate.reasons)
    && hasCapabilityReasons
    && !!allowance && typeof allowance.enabled === 'boolean'
    && (allowance.licence_limit === null || (Number.isSafeInteger(allowance.licence_limit) && allowance.licence_limit >= 0))
    && Array.isArray(allowance.module_keys) && allowance.module_keys.every(key => typeof key === 'string')
    && !!usage && Number.isSafeInteger(usage.reserved) && usage.reserved >= 0
    && Number.isSafeInteger(usage.occupied) && usage.occupied >= 0
}

export function useOfficeErpContext() {
  const nuxtApp = useNuxtApp()
  const { user, currentAccount } = useAuth()
  const sessionGeneration = useState('access-session-generation', () => 0)
  const { api } = useAccessApi()
  const confirmed = useState<{ owner: string, capabilities: OfficeErpCapabilities | null } | null>('office-erp-context-confirmed', () => null)
  const generation = useState('office-erp-context-generation', () => 0)
  const requestGeneration = useState('office-erp-context-request-generation', () => 0)

  const authorityKey = computed(() => JSON.stringify([
    user.value?.id ?? null,
    sessionGeneration.value,
    currentAccount.value?.id ?? null,
    currentAccount.value?.status ?? null,
    currentAccount.value?.role ?? null
  ]))
  const authorityOwner = useState('office-erp-context-authority-owner', () => authorityKey.value)
  const confirmation = computed(() => {
    const value = confirmed.value
    if (!value || value.owner !== authorityKey.value) return 'unknown'
    return value.capabilities ? 'confirmed' : 'failed'
  })
  const capabilities = computed(() => confirmed.value?.owner === authorityKey.value ? confirmed.value.capabilities : null)
  const contextKey = computed(() => JSON.stringify([
    authorityKey.value,
    capabilities.value?.capability_key ?? null
  ]))

  function clearCapabilities() {
    confirmed.value = null
  }

  function invalidate() {
    generation.value++
    requestGeneration.value++
    pendingContext.delete(nuxtApp)
    clearCapabilities()
  }

  watch(authorityKey, (owner) => {
    if (authorityOwner.value === owner) return
    authorityOwner.value = owner
    invalidate()
  }, { immediate: true, flush: 'sync' })

  function capture(): ContextToken {
    return { key: contextKey.value, generation: generation.value }
  }

  function isCurrent(token: ContextToken): boolean {
    return token.key === contextKey.value && token.generation === generation.value
  }

  async function refresh(): Promise<void> {
    const account = currentAccount.value
    if (!account || account.status !== 'active' || !user.value?.id) {
      invalidate()
      return
    }

    const token = capture()
    const sourceKey = authorityKey.value
    const existing = pendingContext.get(nuxtApp)
    if (existing?.authority === sourceKey && existing.request === requestGeneration.value && existing.generation === generation.value) return existing.promise
    const request = ++requestGeneration.value
    let complete!: () => void
    const flight: ContextFlight = { authority: sourceKey, request, generation: token.generation, promise: new Promise<void>((resolve) => {
      complete = resolve
    }) }
    pendingContext.set(nuxtApp, flight)
    void confirm().finally(() => {
      if (pendingContext.get(nuxtApp) === flight) pendingContext.delete(nuxtApp)
      complete()
    })
    return flight.promise

    async function confirm() {
      try {
        const response = await api<{ data?: unknown }>('/erp-commercial/context')
        if (request !== requestGeneration.value || !isCurrent(token)) return
        if (!response || !isCapabilities(response.data)) {
          if (capabilities.value) generation.value++
          confirmed.value = { owner: sourceKey, capabilities: null }
          return
        }

        confirmed.value = { owner: sourceKey, capabilities: response.data }
      } catch {
        if (request === requestGeneration.value && isCurrent(token)) {
          if (capabilities.value) generation.value++
          confirmed.value = { owner: sourceKey, capabilities: null }
        }
      }
    }
  }

  return {
    confirmation,
    capabilities: capabilities as ComputedRef<OfficeErpCapabilities | null>,
    capture,
    isCurrent,
    invalidate,
    refresh
  }
}
