export type ExplicitClientSelection = {
  mode: 'explicit'
  ids: number[]
}

export type MatchingClientSelection = {
  mode: 'all_matching'
  id: string
  total: number
  excluded: number[]
  included: number[]
}

export type ClientSelection = ExplicitClientSelection | MatchingClientSelection

export function emptyClientSelection(): ExplicitClientSelection {
  return { mode: 'explicit', ids: [] }
}

export function clientSelectionCount(selection: ClientSelection): number {
  if (selection.mode === 'explicit') return selection.ids.length
  return Math.max(0, selection.total - selection.excluded.length + selection.included.length)
}

export function isClientInSelection(selection: ClientSelection, id: number, inSnapshot: boolean): boolean {
  if (selection.mode === 'explicit') return selection.ids.includes(id)
  if (selection.included.includes(id)) return true
  if (selection.excluded.includes(id)) return false
  return inSnapshot
}

export function withClientSelected(
  selection: ClientSelection,
  id: number,
  selected: boolean,
  inSnapshot: boolean
): ClientSelection {
  if (selection.mode === 'explicit') {
    const ids = new Set(selection.ids)
    if (selected) ids.add(id)
    else ids.delete(id)
    return { mode: 'explicit', ids: [...ids] }
  }

  const excluded = new Set(selection.excluded)
  const included = new Set(selection.included)
  if (inSnapshot) {
    if (selected) excluded.delete(id)
    else excluded.add(id)
    included.delete(id)
  } else if (selected) included.add(id)
  else included.delete(id)

  const next: MatchingClientSelection = { ...selection, excluded: [...excluded], included: [...included] }
  return clientSelectionCount(next) === 0 ? emptyClientSelection() : next
}

export function withVisibleClientsSelected(
  selection: ClientSelection,
  visibleIds: number[],
  selected: boolean,
  inSnapshot: (id: number) => boolean
): ClientSelection {
  return visibleIds.reduce((current, id) => {
    if (isClientInSelection(current, id, inSnapshot(id)) === selected) return current
    return withClientSelected(current, id, selected, inSnapshot(id))
  }, selection)
}

export function headerCheckboxState(selection: ClientSelection, matchingTotal: number): boolean | 'indeterminate' {
  const count = clientSelectionCount(selection)
  if (count <= 0 || matchingTotal <= 0) return false
  if (count >= matchingTotal) return true
  return 'indeterminate'
}
