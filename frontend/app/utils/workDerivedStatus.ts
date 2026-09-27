import type { WorkTaskStatus } from '~/types/work'

export type DerivedProcessStatus = 'empty' | 'open' | 'in_progress' | 'done'
export type DerivedProcessStatusColor = 'info' | 'warning' | 'success' | 'neutral'

export function derivedProcessStatus(
  statuses: Array<WorkTaskStatus | null | undefined>
): DerivedProcessStatus {
  const present = statuses.filter((status): status is WorkTaskStatus => status != null)
  if (present.length === 0) return 'empty'
  if (present.every(status => status === 'done' || status === 'dismissed')) return 'done'
  if (present.every(status => status === 'todo')) return 'open'
  return 'in_progress'
}

export function derivedProcessStatusForGroup<T extends { status: WorkTaskStatus | null }>(
  rows: T[],
  belongsToGroup: (row: T) => boolean
): DerivedProcessStatus {
  return derivedProcessStatus(rows.filter(belongsToGroup).map(row => row.status))
}

export function derivedProcessStatusPresentation(status: DerivedProcessStatus): {
  label: string
  color: DerivedProcessStatusColor
} {
  switch (status) {
    case 'empty': return { label: 'Sem tarefas', color: 'neutral' }
    case 'open': return { label: 'A fazer', color: 'info' }
    case 'in_progress': return { label: 'Em progresso', color: 'warning' }
    case 'done': return { label: 'Concluído', color: 'success' }
  }
}

export function isCascadeAdvanceLocked(
  cascade: boolean,
  taskOrder: number,
  siblings: Array<{ order: number, status: WorkTaskStatus }>
): boolean {
  if (!cascade) return false
  return siblings.some(sibling =>
    sibling.order < taskOrder
    && sibling.status !== 'done'
    && sibling.status !== 'dismissed'
  )
}

export function isCascadeAdvanceLockedInProcess<T extends { processId: number, order: number, status: WorkTaskStatus | null }>(
  cascade: boolean,
  processId: number,
  taskOrder: number,
  rows: T[],
  serverLocked?: boolean
): boolean {
  if (serverLocked !== undefined) return cascade && serverLocked
  return isCascadeAdvanceLocked(
    cascade,
    taskOrder,
    rows.filter((row): row is T & { status: WorkTaskStatus } =>
      row.processId === processId && row.status !== null)
  )
}
