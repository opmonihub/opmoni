import type { WorkTaskPriority, WorkTaskStatus } from '~/types/work'

export type StatusColor = 'info' | 'warning' | 'success' | 'neutral'
export type PriorityColor = 'neutral' | 'info' | 'warning' | 'error'

export function statusPresentation(status: WorkTaskStatus): { label: string, color: StatusColor } {
  switch (status) {
    case 'todo': return { label: 'A fazer', color: 'info' }
    case 'doing': return { label: 'Em progresso', color: 'warning' }
    case 'done': return { label: 'Concluída', color: 'success' }
    case 'dismissed': return { label: 'Dispensada', color: 'neutral' }
  }
}

export function priorityPresentation(priority: WorkTaskPriority): { label: string, color: PriorityColor } {
  switch (priority) {
    case 'low': return { label: 'Baixa', color: 'neutral' }
    case 'medium': return { label: 'Média', color: 'info' }
    case 'high': return { label: 'Alta', color: 'warning' }
    case 'urgent': return { label: 'Urgente', color: 'error' }
  }
}
