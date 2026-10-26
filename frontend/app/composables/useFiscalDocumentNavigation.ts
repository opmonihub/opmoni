import { fiscalQuery, parseFiscalFilters } from '~/utils/fiscalFilters'
import type { FiscalListFilters } from '~/types/fiscal'

const fields = ['model', 'client_id', 'issuer', 'recipient', 'kind', 'issued_from', 'issued_to', 'amount_min', 'amount_max', 'q', 'sort', 'direction', 'page', 'per_page'] as const
type QueryState = Record<(typeof fields)[number], string | string[] | null>

export function useFiscalDocumentNavigation() {
  const route = useRoute()
  const router = useRouter()
  const empty = () => Object.fromEntries(fields.map(key => [key, null])) as QueryState
  function normalize(value: unknown): QueryState {
    const input = value && typeof value === 'object' ? value as Record<string, unknown> : {}
    return { ...empty(), ...fiscalQuery(parseFiscalFilters(input)) }
  }
  let initial = true
  const state = useListNavigationState('fiscal-documents', {
    defaults: empty,
    normalize,
    fromQuery: () => {
      const query = route.query
      const explicit = fields.some(key => Object.hasOwn(query, key))
      const first = initial
      initial = false
      return explicit || !first ? normalize(query) : null
    }
  })
  const filters = computed(() => parseFiscalFilters(state.value))
  function queryFor(next: FiscalListFilters) {
    const unrelated = Object.fromEntries(Object.entries(route.query).filter(([key]) => !(fields as readonly string[]).includes(key)))
    return { ...unrelated, ...fiscalQuery(next) }
  }
  function update(next: FiscalListFilters) {
    state.value = normalize(fiscalQuery(next))
    return router.push({ query: queryFor(filters.value) })
  }
  // Restore before the first dataset reads. The URL mirrors the same criteria,
  // so finishing this replacement never starts a second logical query.
  const restored = fiscalQuery(filters.value)
  if (!fields.some(key => Object.hasOwn(route.query, key)) && Object.keys(restored).length)
    void router.replace({ query: { ...route.query, ...restored } })
  return { filters, update }
}
