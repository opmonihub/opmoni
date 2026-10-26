import type { ComputedRef } from 'vue'
import type { ContextToken, OfficeErpCapabilities } from '#shared/types/officeErpCommercial'

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
  const { user, currentAccount } = useAuth()
  const sessionGeneration = useState('access-session-generation', () => 0)
  const { api } = useAccessApi()
  const storedCapabilities = useState<OfficeErpCapabilities | null>('office-erp-context-capabilities', () => null)
  const capabilitiesOwner = useState<string | null>('office-erp-context-capabilities-owner', () => null)
  const generation = useState('office-erp-context-generation', () => 0)
  const requestGeneration = useState('office-erp-context-request-generation', () => 0)

  const authorityKey = computed(() => JSON.stringify([
    user.value?.id ?? null,
    sessionGeneration.value,
    currentAccount.value?.id ?? null,
    currentAccount.value?.status ?? null,
    currentAccount.value?.role ?? null
  ]))
  const capabilities = computed(() => capabilitiesOwner.value === authorityKey.value ? storedCapabilities.value : null)
  const contextKey = computed(() => JSON.stringify([
    authorityKey.value,
    capabilities.value?.capability_key ?? null
  ]))

  function clearCapabilities() {
    storedCapabilities.value = null
    capabilitiesOwner.value = null
  }

  function invalidate() {
    generation.value++
    requestGeneration.value++
    clearCapabilities()
  }

  watch(authorityKey, () => {
    invalidate()
  }, { flush: 'sync' })

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
    const request = ++requestGeneration.value
    try {
      const response = await api<{ data?: unknown }>('/erp-commercial/context')
      if (request !== requestGeneration.value || !isCurrent(token)) return
      if (!response || !isCapabilities(response.data)) {
        if (capabilities.value) generation.value++
        clearCapabilities()
        return
      }

      storedCapabilities.value = response.data
      capabilitiesOwner.value = sourceKey
    } catch {
      if (request === requestGeneration.value && isCurrent(token)) {
        if (capabilities.value) generation.value++
        clearCapabilities()
      }
    }
  }

  return {
    capabilities: capabilities as ComputedRef<OfficeErpCapabilities | null>,
    capture,
    isCurrent,
    invalidate,
    refresh
  }
}
