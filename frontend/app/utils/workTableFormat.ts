/** Format a work due date (`YYYY-MM-DD`) for pt-BR table cells. */
export function formatWorkDueOn(value: string | null | undefined): string {
  if (!value) return '—'
  return new Date(`${value}T00:00:00`).toLocaleDateString('pt-BR')
}

/** Process completion ratio as a compact percentage badge label. */
export function workRatioLabel(ratio: number): string {
  return `${Math.round(ratio * 100)}%`
}

/** Count non-empty leaf tasks under a TanStack grouped row. */
export function countWorkLeafTasks(row: {
  getLeafRows: () => { original: { empty?: boolean } }[]
}): number {
  return row.getLeafRows().filter(leaf => !leaf.original.empty).length
}

/**
 * Badge label for a grouped row's leaf count. Wording is the pre-refactor one
 * (`N tarefa(s)`), so the merged Item column still reads like the old `#` +
 * `Tarefa` pair.
 */
export function workLeafCountLabel(row: {
  getLeafRows: () => { original: { empty?: boolean } }[]
}): string {
  return `${countWorkLeafTasks(row)} tarefa(s)`
}

export function workAssignMemberItems(
  memberOptions: readonly { label: string, value: number }[]
): { label: string, value: number | null }[] {
  return [
    { label: 'Sem responsável', value: null },
    ...memberOptions.map(option => ({ label: option.label, value: option.value }))
  ]
}
