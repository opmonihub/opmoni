export interface ClientFilterState {
  status: string
  regime: string
  deadline: string
}

export type ClientFilterKey = keyof ClientFilterState

export const defaultClientFilters: ClientFilterState = {
  status: 'all',
  regime: 'all',
  deadline: 'all'
}

export function countActiveFilters(state: ClientFilterState): number {
  return (Object.keys(defaultClientFilters) as ClientFilterKey[])
    .filter(key => state[key] !== 'all')
    .length
}

export function resetClientFilters(): ClientFilterState {
  return { ...defaultClientFilters }
}

export function applyClientFilters(draft: ClientFilterState): ClientFilterState {
  return { ...draft }
}

export function removeClientFilter(state: ClientFilterState, key: ClientFilterKey): ClientFilterState {
  return { ...state, [key]: 'all' }
}

export function activeFilterChips(
  state: ClientFilterState,
  labels: Record<ClientFilterKey, Record<string, string>>
): { key: ClientFilterKey, label: string }[] {
  return (Object.keys(defaultClientFilters) as ClientFilterKey[])
    .filter(key => state[key] !== 'all')
    .map(key => ({ key, label: labels[key][state[key]] ?? state[key] }))
}
