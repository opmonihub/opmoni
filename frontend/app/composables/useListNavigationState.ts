import { computed, watch, type Ref } from 'vue'
import type { Identity } from '#shared/types/access'

type NavigationPrimitive = string | number | boolean | null
export type ListNavigationValue = NavigationPrimitive | NavigationPrimitive[]
interface ListEntry {
  authority: string | null
  value: Record<string, ListNavigationValue>
}
interface ListRegistry {
  authority: string
  lists: Record<string, ListEntry>
}
interface ListNavigationOptions<T> {
  defaults: () => T
  /** Rebuild only supported criteria with the existing list codec. */
  normalize: (value: unknown) => T
  /** Return null for a canonical link without supported query keys. */
  fromQuery?: () => T | null
  /** Null represents first confirmation pending; definitive refusals use a distinct stamp. */
  authority?: () => string | null
}

/** The plugin owns this watcher even while every list page is unmounted. */
export function useListNavigationRegistry() {
  const identity = useState<Identity | null>('access-user', () => null)
  const generation = useState('access-session-generation', () => 0)
  const authority = computed(() => {
    const user = identity.value
    const account = user?.current_account
    const company = user?.current_company
    const effectiveAccount = user?.accounts?.find(value => value.id === account?.id)
    const effectiveCompany = user?.companies?.find(value => value.id === company?.id)
    const accountStamp = (value: typeof account) => value ? [value.id, value.status, value.role] : null
    const companyStamp = (value: typeof company) => value ? [value.id, value.status, value.role, value.access_kind, value.delegated_office_id, [...value.permissions].sort()] : null
    return JSON.stringify([user?.id ?? null, generation.value, user?.is_super_admin ?? false,
      accountStamp(account), accountStamp(effectiveAccount), companyStamp(company), companyStamp(effectiveCompany)])
  })
  const registry = useState<ListRegistry | undefined>('list-navigation-state', () => undefined)
  watch(authority, (value) => {
    if (registry.value?.authority !== value)
      registry.value = { authority: value, lists: {} }
  }, { immediate: true, flush: 'sync' })
  function current() {
    // clearNuxtState in the installed Nuxt version can set an existing ref to undefined.
    if (registry.value?.authority !== authority.value)
      registry.value = { authority: authority.value, lists: {} }
    return registry.value
  }
  return { registry, current }
}

/** Session-memory criteria only: never put records, selections or mutation drafts here. */
export function useListNavigationState<T extends { [K in keyof T]: ListNavigationValue }>(
  key: string,
  options: ListNavigationOptions<T>
): Ref<T> {
  const { current } = useListNavigationRegistry()
  const normalize = (value: unknown): T => {
    const normalized = options.normalize(value)
    const result: Record<string, ListNavigationValue> = {}
    // Defaults define the allowlist; the codec validates the supported values.
    for (const field of Object.keys(options.defaults())) {
      const value = normalized[field as keyof T]
      const primitive = (entry: unknown): entry is NavigationPrimitive => entry === null || typeof entry === 'string' || typeof entry === 'boolean' || (typeof entry === 'number' && Number.isFinite(entry))
      if (primitive(value)) result[field] = value
      else if (Array.isArray(value) && value.every(primitive)) result[field] = [...value]
    }
    return result as T
  }
  function entry() {
    const registry = current()
    if (!Object.hasOwn(registry.lists, key))
      registry.lists[key] = { authority: options.authority?.() ?? null, value: normalize(options.defaults()) }
    return registry.lists[key]!
  }
  const remembered = entry()
  remembered.value = normalize(remembered.value)
  const state = computed<T>({
    get: () => entry().value as T,
    set: (value) => { entry().value = normalize(value) }
  })
  watch(state, (value) => {
    const safe = normalize(value)
    if (JSON.stringify(value) !== JSON.stringify(safe)) state.value = safe
  }, { deep: true, flush: 'sync' })
  if (options.authority) {
    watch(options.authority, (stamp) => {
      if (stamp === null) return
      const stored = entry()
      if (stored.authority !== null && stored.authority !== stamp) {
        current().lists = {}
        state.value = options.defaults()
      }
      entry().authority = stamp
    }, { immediate: true, flush: 'sync' })
  }
  if (options.fromQuery) {
    watch(options.fromQuery, (value) => {
      if (value !== null) state.value = value
    }, { immediate: true, deep: true, flush: 'sync' })
  }
  return state
}

/** Stable field lens: unlike toRefs(state.value), it survives registry replacement. */
export function listNavigationField<T, K extends keyof T>(state: Ref<T>, key: K) {
  return computed({ get: () => state.value[key], set: (value: T[K]) => {
    state.value = { ...state.value, [key]: value }
  } })
}
