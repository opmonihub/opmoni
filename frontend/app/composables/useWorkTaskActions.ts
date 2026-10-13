import type { Ref } from 'vue'
import { apiMessage, apiStatus } from '~/composables/useApiError'
import type { WorkTaskStatus } from '~/types/work'

export type WorkTaskPatchBody = {
  status?: string
  dismissal_reason?: string
  assignee_member_id?: number | null
}

export type WorkActionLeaf = {
  taskId: number | null
  status: WorkTaskStatus | null
}

export const workStatusSuccessTitles: Record<Exclude<WorkTaskStatus, 'dismissed'>, string> = {
  todo: 'Tarefa marcada como A fazer',
  doing: 'Tarefa em progresso',
  done: 'Tarefa concluída'
}

type UseWorkTaskActionsOptions<TLeaf extends WorkActionLeaf> = {
  updateTask: (id: number, body: WorkTaskPatchBody) => Promise<unknown>
  refresh: () => Promise<unknown>
  clearSelection: () => void
  leaves: Ref<readonly TLeaf[]> | (() => readonly TLeaf[])
  selectedTaskIds: Ref<readonly number[]> | (() => readonly number[])
  selectedCount?: Ref<number> | (() => number)
}

function readRefOrGetter<T>(source: Ref<T> | (() => T)): T {
  return typeof source === 'function' ? source() : source.value
}

/**
 * Shared lock / single-status / bulk / dismiss flow for work grouped tables
 * (processos + clientes). Tarefas keeps a flatter shape and reuses the titles
 * + lock helpers only.
 */
export function useWorkTaskActions<TLeaf extends WorkActionLeaf>(
  options: UseWorkTaskActionsOptions<TLeaf>
) {
  const toast = useToast()

  const bulkBusy = ref(false)
  const lockedIds = ref<Set<number>>(new Set())
  const dismissOpen = ref(false)
  const dismissReason = ref('')
  const dismissTargetIds = ref<number[] | null>(null)

  function currentLeaves(): readonly TLeaf[] {
    return readRefOrGetter(options.leaves)
  }

  function currentSelectedIds(): readonly number[] {
    return readRefOrGetter(options.selectedTaskIds)
  }

  function markLocked(taskId: number) {
    lockedIds.value = new Set(lockedIds.value).add(taskId)
  }

  function clearLocked(taskId: number) {
    const next = new Set(lockedIds.value)
    next.delete(taskId)
    lockedIds.value = next
  }

  function resetLocked() {
    lockedIds.value = new Set()
  }

  function isTaskIdLocked(taskId: number): boolean {
    return lockedIds.value.has(taskId)
  }

  async function setTaskStatus(
    taskId: number,
    current: WorkTaskStatus,
    next: Exclude<WorkTaskStatus, 'dismissed'>
  ) {
    if (current === next) return
    try {
      await options.updateTask(taskId, { status: next })
      clearLocked(taskId)
      await options.refresh()
      toast.add({ title: workStatusSuccessTitles[next], color: 'success' })
    } catch (err: unknown) {
      if (apiStatus(err) === 422) {
        markLocked(taskId)
        toast.add({
          title: 'Avanço bloqueado',
          description: apiMessage(err) ?? 'Aguardando etapas anteriores.',
          color: 'warning'
        })
      } else {
        toast.add({
          title: 'Não foi possível atualizar o status',
          description: apiMessage(err),
          color: 'error'
        })
      }
    }
  }

  async function runBulk(
    label: string,
    bodyFor: (taskId: number, leaf: TLeaf) => WorkTaskPatchBody | null,
    ids: readonly number[] = currentSelectedIds()
  ) {
    if (!ids.length) return

    const byId = new Map(
      currentLeaves()
        .filter(leaf => leaf.taskId != null)
        .map(leaf => [leaf.taskId as number, leaf])
    )
    bulkBusy.value = true
    let ok = 0
    let failed = 0
    let locked = 0

    try {
      for (const taskId of ids) {
        const leaf = byId.get(taskId)
        if (!leaf) continue
        const body = bodyFor(taskId, leaf)
        if (!body) continue
        try {
          await options.updateTask(taskId, body)
          clearLocked(taskId)
          ok += 1
        } catch (err: unknown) {
          if (apiStatus(err) === 422) {
            markLocked(taskId)
            locked += 1
            toast.add({
              title: `Bloqueada #${taskId}`,
              description: apiMessage(err) ?? 'Aguardando etapas anteriores.',
              color: 'warning'
            })
          } else {
            failed += 1
            toast.add({
              title: `Falha em #${taskId}`,
              description: apiMessage(err) ?? 'Não foi possível atualizar a tarefa.',
              color: 'error'
            })
          }
        }
      }

      await options.refresh()

      if (ok && !failed && !locked) {
        options.clearSelection()
        toast.add({ title: `${label}: ${ok} tarefa(s)`, color: 'success' })
      } else if (ok) {
        // Partial: keep whatever is still selected so the user can retry the rest.
        toast.add({
          title: `${label}: ${ok} ok${failed ? `, ${failed} erro(s)` : ''}${locked ? `, ${locked} bloqueada(s)` : ''}`,
          color: 'warning'
        })
      } else if (failed || locked) {
        // Nothing moved: the selection is the retry, so it has to survive.
        toast.add({ title: 'Nenhuma tarefa atualizada', color: 'error' })
      }
    } finally {
      bulkBusy.value = false
    }
  }

  async function bulkAdvance(ids?: readonly number[]) {
    await runBulk('Status avançado', (_id, leaf) => {
      if (!leaf.status) return null
      const next = leaf.status === 'todo' ? 'doing' : leaf.status === 'doing' ? 'done' : null
      return next ? { status: next } : null
    }, ids ?? currentSelectedIds())
  }

  async function bulkAssign(memberId: number | null, ids?: readonly number[]) {
    await runBulk('Responsável atualizado', () => ({ assignee_member_id: memberId }), ids ?? currentSelectedIds())
  }

  function openDismiss(ids: number[] | null = null) {
    dismissTargetIds.value = ids
    dismissReason.value = ''
    dismissOpen.value = true
  }

  function openDismissForTaskIds(ids: number[]) {
    if (!ids.length) return
    openDismiss(ids)
  }

  async function confirmDismiss() {
    const reason = dismissReason.value.trim()
    if (!reason) {
      toast.add({ title: 'Informe o motivo da dispensa', color: 'error' })
      return
    }
    const ids = dismissTargetIds.value ?? [...currentSelectedIds()]
    dismissOpen.value = false
    dismissTargetIds.value = null
    await runBulk('Tarefas dispensadas', (_id, leaf) => {
      if (leaf.status === 'dismissed') return null
      return { status: 'dismissed', dismissal_reason: reason }
    }, ids)
    dismissReason.value = ''
  }

  const dismissCount = computed(() => {
    if (dismissTargetIds.value) return dismissTargetIds.value.length
    if (options.selectedCount) return readRefOrGetter(options.selectedCount)
    return currentSelectedIds().length
  })

  watch(dismissOpen, (open) => {
    if (!open) {
      dismissReason.value = ''
      dismissTargetIds.value = null
    }
  })

  return {
    bulkBusy,
    lockedIds,
    dismissOpen,
    dismissReason,
    dismissTargetIds,
    dismissCount,
    markLocked,
    clearLocked,
    resetLocked,
    isTaskIdLocked,
    setTaskStatus,
    runBulk,
    bulkAdvance,
    bulkAssign,
    openDismiss,
    openDismissForTaskIds,
    confirmDismiss
  }
}
