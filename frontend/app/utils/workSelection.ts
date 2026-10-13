export type WorkSelectableLeaf = { id: string, taskId: number | null, empty: boolean }

export function isWorkSelectableLeaf(leaf: WorkSelectableLeaf): boolean {
  return !leaf.empty && leaf.taskId != null
}

export function workSelectedIds(selection: Record<string, boolean>): string[] {
  return Object.entries(selection).filter(([, selected]) => selected).map(([id]) => id)
}

export function workSelectedCount(selection: Record<string, boolean>): number {
  return workSelectedIds(selection).length
}

export function workLeafSelectionState(selection: Record<string, boolean>, leafIds: readonly string[]): boolean | 'indeterminate' {
  if (!leafIds.length) return false
  let selected = 0
  for (const id of leafIds) {
    if (selection[id]) selected += 1
  }
  if (selected === 0) return false
  if (selected === leafIds.length) return true
  return 'indeterminate'
}

export function withWorkLeavesSelected(selection: Record<string, boolean>, leafIds: readonly string[], selected: boolean): Record<string, boolean> {
  if (selected) {
    const next = { ...selection }
    for (const id of leafIds) next[id] = true
    return next
  }
  const remove = new Set(leafIds)
  return Object.fromEntries(
    Object.entries(selection).filter(([id]) => !remove.has(id))
  )
}

export function workTaskIdsFromSelection(selection: Record<string, boolean>, leaves: readonly WorkSelectableLeaf[]): number[] {
  const selected = new Set(workSelectedIds(selection))
  const ids: number[] = []
  for (const leaf of leaves) {
    if (!selected.has(leaf.id) || !isWorkSelectableLeaf(leaf) || leaf.taskId == null) continue
    ids.push(leaf.taskId)
  }
  return ids
}
