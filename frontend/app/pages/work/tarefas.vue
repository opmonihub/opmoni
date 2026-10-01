<script setup lang="ts">
import { h, resolveComponent } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import type { SortingState } from '@tanstack/table-core'
import type { DataTableFilterColumn, DataTableFilterModel } from '~/components/data-table/Filter.vue'
import WorkToolbarTeleport from '~/components/work/WorkToolbarTeleport'
import WorkTaskStatusSelect from '~/components/work/WorkTaskStatusSelect.vue'
import { apiMessage, apiStatus } from '~/composables/useApiError'
import { workStatusSuccessTitles } from '~/composables/useWorkTaskActions'
import { priorityPresentation, statusPresentation } from '~/composables/useWorkPresentation'
import type { WorkGroupedClient, WorkTask, WorkTaskStatus } from '~/types/work'
import { isCascadeAdvanceLockedInProcess } from '~/utils/workDerivedStatus'
import { cascadeBadgeColor, cascadeLabel, showCascadeBadge, workFlatTableUi } from '~/utils/workGroupedTable'
import { pageScrollClass } from '~/utils/pageShell'
import {
  workAssignMemberItems
} from '~/utils/workTableFormat'
import { workSortableHeader as sortableHeader } from '~/utils/workSortableHeader'
import {
  filterWorkTasks,
  tasksForWorkScope,
  tasksToLeaves,
  workTarefasFilterColumns,
  type WorkTarefasLeaf
} from '~/utils/workTarefasFilters'

definePageMeta({ middleware: 'auth' })

const toast = useToast()
const { grouped, unscopedTasks, updateTask } = useWork()
const { canManageWork } = useAuth()
const { list: listDepartments } = useDepartments()
const { referenceMonth } = useWorkReferenceMonth()
const { error: membersError, memberOptions, memberName } = useDirectory()

const UBadge = resolveComponent('UBadge')
const UCheckbox = resolveComponent('UCheckbox')
const UButton = resolveComponent('UButton')

type ColumnKey = WorkTask['status']

const viewMode = ref<'board' | 'table'>('board')
const scopeMode = ref<'month' | 'undated'>('month')
const search = ref('')
const dueFrom = ref('')
const dueTo = ref('')
const filterModels = ref<DataTableFilterModel[]>([])
const sorting = ref<SortingState>([])
const rowSelection = ref<Record<string, boolean>>({})
const isDesktop = useClientMediaQuery('(min-width: 768px)')

const { data, status, error, refresh: reload } = await useAsyncData<{
  groups: WorkGroupedClient[]
  unscoped: WorkTask[]
}>(
  'work-tarefas-by-month',
  async () => {
    const [groups, unscoped] = await Promise.all([
      grouped(referenceMonth.value),
      unscopedTasks(referenceMonth.value)
    ])
    return { groups, unscoped }
  },
  { watch: [referenceMonth], default: () => ({ groups: [], unscoped: [] }) }
)

const undatedTasks = computed(() => tasksForWorkScope([], data.value?.unscoped ?? [], 'undated'))
const allTasks = computed(() => tasksForWorkScope(
  data.value?.groups ?? [],
  data.value?.unscoped ?? [],
  scopeMode.value
))

/**
 * The `refresh` below is the composable's, not `reload`'s: the toolbar button and
 * the empty-state action answer through it, so a failed manual refresh toasts
 * "Não foi possível atualizar as tarefas" instead of going quiet. Every mutation
 * (status, dispensa, atribuição, massa) keeps calling `reload` inside its own
 * try/catch, so those still report through their own message.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar as tarefas',
  refreshErrorTitle: 'Não foi possível atualizar as tarefas'
})

const { data: departments } = await useAsyncData(
  'work-departments',
  () => listDepartments(),
  { default: () => [] }
)

const membersFailed = computed(() => membersError.value !== null && membersError.value !== undefined)
const membersForbidden = computed(() => apiStatus(membersError.value) === 403)

const membersHint = computed(() => {
  if (!membersFailed.value) return undefined
  return membersForbidden.value
    ? 'Lista de responsáveis indisponível no momento.'
    : 'Não foi possível carregar os responsáveis.'
})

function membersWarning(): { title: string, description: string, color: 'warning' } {
  return {
    title: 'Não foi possível carregar os responsáveis',
    description: membersHint.value ?? 'A atribuição pode estar indisponível.',
    color: 'warning'
  }
}

const filterColumns = computed<DataTableFilterColumn[]>(() => {
  const clients = new Map<number, string>()
  const processes = new Map<number, string>()
  for (const task of allTasks.value) {
    const clientId = task.process?.client?.id
    const clientName = task.process?.client?.name
    if (clientId && clientName) clients.set(clientId, clientName)
    const processId = task.process?.id
    const processName = task.process?.name
    if (processId && processName) processes.set(processId, processName)
  }

  const departmentCatalog = (departments.value ?? []).map(department => ({ id: department.id, name: department.name }))

  return workTarefasFilterColumns({
    clients: [...clients.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name, 'pt-BR')),
    processes: [...processes.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name, 'pt-BR')),
    departments: departmentCatalog,
    assignees: [
      { id: null, label: 'Sem responsável' },
      ...memberOptions.value.map(option => ({ id: option.value, label: option.label }))
    ],
    includeAssignee: canManageWork.value
  })
})

const filteredTasks = computed(() =>
  filterWorkTasks(allTasks.value, filterModels.value, filterColumns.value, search.value, {
    from: dueFrom.value,
    to: dueTo.value
  })
)

const hasDueRange = computed(() => Boolean(dueFrom.value || dueTo.value))
const hasActiveFilters = computed(() => Boolean(
  search.value.trim() || filterModels.value.length || hasDueRange.value
))

function clearDueRange() {
  dueFrom.value = ''
  dueTo.value = ''
}

function clearAllFilters() {
  search.value = ''
  filterModels.value = []
  clearDueRange()
}

const tableRows = computed(() => tasksToLeaves(filteredTasks.value))
const allTableRows = computed(() => tasksToLeaves(allTasks.value))

/** Keep table/mobile mounts bounded — hundreds of status controls freeze clicks. */
const TABLE_PAGE_SIZE = 50
const tablePage = ref(1)
const tablePageCount = computed(() => Math.max(1, Math.ceil(tableRows.value.length / TABLE_PAGE_SIZE)))
/** Filters can shrink the result set under the current page; never slice past the end. */
const currentTablePage = computed(() => Math.min(tablePage.value, tablePageCount.value))
const pagedTableRows = computed(() => {
  const start = (currentTablePage.value - 1) * TABLE_PAGE_SIZE
  return tableRows.value.slice(start, start + TABLE_PAGE_SIZE)
})
const pagedFilteredTasks = computed(() => {
  const start = (currentTablePage.value - 1) * TABLE_PAGE_SIZE
  return filteredTasks.value.slice(start, start + TABLE_PAGE_SIZE)
})

watch([filteredTasks, viewMode, scopeMode], () => {
  tablePage.value = 1
})

const tasksByColumn = computed<Record<ColumnKey, WorkTask[]>>(() => {
  const board: Record<ColumnKey, WorkTask[]> = { todo: [], doing: [], done: [], dismissed: [] }
  for (const task of filteredTasks.value) board[task.status].push(task)
  return board
})

function processLabel(task: WorkTask): string {
  return task.process?.name ?? 'Processo avulso'
}

function formatDueOn(value: string | null): string {
  if (!value) return 'Sem vencimento'
  return new Date(`${value}T00:00:00`).toLocaleDateString('pt-BR')
}

const tableColumns = computed<TableColumn<WorkTarefasLeaf>[]>(() => {
  const cols: TableColumn<WorkTarefasLeaf>[] = []

  if (canManageWork.value) {
    cols.push({
      id: 'select',
      enableSorting: false,
      meta: { class: { th: 'w-10', td: 'w-10' } },
      header: ({ table }) => {
        const rows = table.getRowModel().rows
        const allSelected = rows.length > 0 && rows.every(row => row.getIsSelected())
        const someSelected = rows.some(row => row.getIsSelected())
        return h(UCheckbox, {
          'modelValue': someSelected && !allSelected ? 'indeterminate' : allSelected,
          'onUpdate:modelValue': (value: boolean | 'indeterminate') => {
            rows.forEach(row => row.toggleSelected(!!value))
          },
          'ariaLabel': 'Selecionar todas as tarefas visíveis'
        })
      },
      cell: ({ row }) => h(UCheckbox, {
        'modelValue': row.getIsSelected(),
        'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
        'ariaLabel': `Selecionar ${row.original.title}`
      })
    })
  }

  cols.push(
    {
      accessorKey: 'order',
      header: ({ column }) => sortableHeader('#', column),
      meta: { class: { th: 'w-14 whitespace-nowrap text-right', td: 'w-14 text-right tabular-nums' } },
      cell: ({ row }) => String(row.original.order)
    },
    {
      accessorKey: 'title',
      header: ({ column }) => sortableHeader('Tarefa', column),
      meta: { class: { th: 'min-w-40 w-full whitespace-nowrap', td: 'min-w-40 w-full max-w-0' } },
      cell: ({ row }) => h('span', { class: 'block truncate', title: row.original.title }, row.original.title)
    },
    {
      accessorKey: 'clientName',
      header: ({ column }) => sortableHeader('Cliente', column),
      meta: { class: { th: 'hidden w-40 whitespace-nowrap md:table-cell', td: 'hidden w-40 max-w-40 md:table-cell' } },
      cell: ({ row }) => h('span', { class: 'block truncate', title: row.original.clientName || undefined }, row.original.clientName || '—')
    },
    {
      accessorKey: 'processName',
      header: ({ column }) => sortableHeader('Processo', column),
      meta: { class: { th: 'hidden w-40 whitespace-nowrap lg:table-cell', td: 'hidden w-40 max-w-40 lg:table-cell' } },
      cell: ({ row }) => h('span', { class: 'block truncate', title: row.original.processName || undefined }, row.original.processName || '—')
    },
    {
      accessorKey: 'status',
      header: ({ column }) => sortableHeader('Status', column),
      meta: { class: { th: 'w-36 whitespace-nowrap', td: 'w-36' } }
    },
    {
      accessorKey: 'priority',
      header: ({ column }) => sortableHeader('Prioridade', column),
      meta: { class: { th: 'hidden w-28 whitespace-nowrap lg:table-cell', td: 'hidden w-28 lg:table-cell' } },
      cell: ({ row }) => {
        const presentation = priorityPresentation(row.original.priority)
        return h(UBadge, {
          color: presentation.color,
          variant: 'subtle',
          label: presentation.label
        })
      }
    },
    {
      accessorKey: 'departmentName',
      header: ({ column }) => sortableHeader('Depto.', column),
      meta: { class: { th: 'hidden w-28 whitespace-nowrap xl:table-cell', td: 'hidden w-28 xl:table-cell' } },
      cell: ({ row }) => row.original.departmentName || 'Sem departamento'
    },
    {
      accessorKey: 'due_on',
      header: ({ column }) => sortableHeader('Vencimento', column),
      meta: { class: { th: 'hidden w-32 whitespace-nowrap md:table-cell', td: 'hidden w-32 whitespace-nowrap md:table-cell' } },
      sortingFn: (a, b) => {
        const left = a.original.due_on ?? ''
        const right = b.original.due_on ?? ''
        return left.localeCompare(right)
      },
      cell: ({ row }) => formatDueOn(row.original.due_on)
    },
    {
      id: 'actions',
      enableSorting: false,
      meta: { class: { th: 'w-12', td: 'w-12' } },
      cell: ({ row }) => {
        if (!row.original.processId) return null
        return h(UButton, {
          'to': `/work/processos/${row.original.processId}`,
          'color': 'neutral',
          'variant': 'ghost',
          'size': 'xs',
          'icon': 'i-lucide-arrow-up-right',
          'aria-label': `Abrir processo ${row.original.processName}`
        })
      }
    }
  )

  return cols
})

const selectedIds = computed(() =>
  Object.entries(rowSelection.value)
    .filter(([, selected]) => selected)
    .map(([id]) => Number(id))
    .filter(id => Number.isFinite(id))
)

const selectedTasks = computed(() =>
  filteredTasks.value.filter(task => selectedIds.value.includes(task.id))
)

const selectedCount = computed(() => selectedTasks.value.length)

const assigneeItems = computed(() => workAssignMemberItems(memberOptions.value))

const lockedIds = ref<Set<number>>(new Set())
const busyId = ref<number | null>(null)
const bulkBusy = ref(false)

watch(data, () => {
  lockedIds.value = new Set()
})

function markLocked(taskId: number) {
  lockedIds.value = new Set(lockedIds.value).add(taskId)
}

function clearLocked(taskId: number) {
  const next = new Set(lockedIds.value)
  next.delete(taskId)
  lockedIds.value = next
}

function clearSelection() {
  rowSelection.value = {}
}

function isTaskSelected(taskId: number): boolean {
  return Boolean(rowSelection.value[String(taskId)])
}

function setTaskSelected(taskId: number, value: boolean | 'indeterminate') {
  const key = String(taskId)
  if (value) {
    rowSelection.value = { ...rowSelection.value, [key]: true }
    return
  }
  rowSelection.value = Object.fromEntries(
    Object.entries(rowSelection.value).filter(([id]) => id !== key)
  )
}

function findTask(taskId: number): WorkTask | undefined {
  return filteredTasks.value.find(task => task.id === taskId)
}

function isTaskCascadeLocked(leaf: WorkTarefasLeaf): boolean {
  return lockedIds.value.has(leaf.id)
    || isCascadeAdvanceLockedInProcess(leaf.cascade, leaf.processId, leaf.order, allTableRows.value, leaf.cascadeLocked)
}

function isWorkTaskCascadeLocked(task: WorkTask): boolean {
  const processId = task.process?.id ?? 0
  return lockedIds.value.has(task.id)
    || isCascadeAdvanceLockedInProcess(
      Boolean(task.process?.cascade),
      processId,
      task.order,
      allTableRows.value,
      task.cascade_locked
    )
}

async function setTaskStatus(taskId: number, next: Exclude<WorkTaskStatus, 'dismissed'>) {
  const task = findTask(taskId)
  if (!task || task.status === next) return
  busyId.value = taskId
  try {
    await updateTask(taskId, { status: next })
    clearLocked(taskId)
    await reload()
    toast.add({ title: workStatusSuccessTitles[next], color: 'success' })
  } catch (error: unknown) {
    if (apiStatus(error) === 422) {
      markLocked(taskId)
      toast.add({ title: 'Avanço bloqueado', description: apiMessage(error) ?? 'Aguardando etapas anteriores.', color: 'warning' })
    } else {
      toast.add({ title: 'Não foi possível atualizar o status', description: apiMessage(error), color: 'error' })
    }
  } finally {
    busyId.value = null
  }
}

function openDismissForLeaf(leaf: WorkTarefasLeaf) {
  const task = findTask(leaf.id)
  if (task) openDismiss(task)
}

async function advance(task: WorkTask) {
  const next = task.status === 'todo' ? 'doing' : task.status === 'doing' ? 'done' : null
  if (!next) return
  await setTaskStatus(task.id, next)
}

async function moveBack(task: WorkTask) {
  const previous = task.status === 'doing' ? 'todo' : task.status === 'done' ? 'doing' : null
  if (!previous) return
  await setTaskStatus(task.id, previous)
}

const dismissOpen = ref(false)
const dismissTarget = ref<WorkTask | null>(null)
const dismissBulk = ref(false)
const dismissReason = ref('')
const dismissing = ref(false)

function openDismiss(task: WorkTask) {
  dismissTarget.value = task
  dismissBulk.value = false
  dismissReason.value = ''
  dismissOpen.value = true
}

function openBulkDismiss() {
  if (!selectedCount.value) return
  dismissTarget.value = null
  dismissBulk.value = true
  dismissReason.value = ''
  dismissOpen.value = true
}

async function dismissWithReason(task: WorkTask, reason: string) {
  await updateTask(task.id, { status: 'dismissed', dismissal_reason: reason.trim() })
  clearLocked(task.id)
}

async function onConfirmDismiss() {
  if (!dismissReason.value.trim()) {
    toast.add({ title: 'Informe o motivo da dispensa', color: 'error' })
    return
  }
  dismissing.value = true
  try {
    if (dismissBulk.value) {
      await runBulk(async (task) => {
        await dismissWithReason(task, dismissReason.value)
      }, 'Dispensa em massa')
      dismissOpen.value = false
      dismissBulk.value = false
      clearSelection()
    } else if (dismissTarget.value) {
      await dismissWithReason(dismissTarget.value, dismissReason.value)
      dismissOpen.value = false
      dismissTarget.value = null
      await reload()
      toast.add({ title: 'Tarefa dispensada', color: 'success' })
    }
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível dispensar a tarefa', description: apiMessage(error), color: 'error' })
  } finally {
    dismissing.value = false
  }
}

async function assign(task: WorkTask, memberId: number | null) {
  if (task.assignee_member_id === memberId) return
  busyId.value = task.id
  try {
    await updateTask(task.id, { assignee_member_id: memberId })
    await reload()
    toast.add({ title: memberId === null ? 'Responsável removido' : 'Responsável atualizado', color: 'success' })
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível atribuir o responsável', description: apiMessage(error), color: 'error' })
  } finally {
    busyId.value = null
  }
}

async function runBulk(
  action: (task: WorkTask) => Promise<void>,
  label: string
) {
  const targets = selectedTasks.value
  if (!targets.length) return
  bulkBusy.value = true
  let ok = 0
  let blocked = 0
  let failed = 0
  try {
    for (const task of targets) {
      try {
        await action(task)
        clearLocked(task.id)
        ok++
      } catch (error: unknown) {
        if (apiStatus(error) === 422) {
          markLocked(task.id)
          blocked++
        } else {
          failed++
        }
      }
    }
    await reload()
    if (failed === 0 && blocked === 0) {
      toast.add({ title: `${label}: ${ok} atualizada(s)`, color: 'success' })
    } else {
      toast.add({
        title: `${label}: ${ok} ok, ${blocked} bloqueada(s), ${failed} com erro`,
        color: blocked && !failed ? 'warning' : 'error'
      })
    }
  } finally {
    bulkBusy.value = false
  }
}

async function onBulkAssign(memberId: number | null) {
  await runBulk(
    async (task) => {
      await updateTask(task.id, { assignee_member_id: memberId })
    },
    'Atribuição em massa'
  )
  clearSelection()
}

async function onBulkStatus(next: 'todo' | 'doing' | 'done') {
  await runBulk(
    async (task) => {
      await updateTask(task.id, { status: next })
    },
    'Status em massa'
  )
  clearSelection()
}

watch(membersError, (value) => {
  if (value) toast.add(membersWarning())
}, { immediate: true })

watch(dismissOpen, (open) => {
  if (!open) {
    dismissTarget.value = null
    dismissBulk.value = false
    dismissReason.value = ''
  }
})

watch(scopeMode, clearAllFilters)

watch([filterModels, search, dueFrom, dueTo, referenceMonth, viewMode, scopeMode], () => {
  clearSelection()
})
</script>

<template>
  <div :class="pageScrollClass">
    <ClientOnly>
      <WorkToolbarTeleport>
        <div class="flex items-center gap-1">
          <div class="flex shrink-0 items-center gap-1 rounded-lg bg-elevated p-1" role="group" aria-label="Alternar visualização">
            <UButton
              icon="i-lucide-kanban-square"
              size="xs"
              :color="viewMode === 'board' ? 'primary' : 'neutral'"
              :variant="viewMode === 'board' ? 'solid' : 'ghost'"
              aria-label="Ver como quadro"
              @click="viewMode = 'board'"
            />
            <UButton
              icon="i-lucide-table"
              size="xs"
              :color="viewMode === 'table' ? 'primary' : 'neutral'"
              :variant="viewMode === 'table' ? 'solid' : 'ghost'"
              aria-label="Ver como tabela"
              @click="viewMode = 'table'"
            />
          </div>
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            aria-label="Atualizar tarefas"
            :loading="isLoading"
            @click="refresh"
          />
        </div>
      </WorkToolbarTeleport>
    </ClientOnly>

    <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Exibir tarefas">
      <UButton
        label="Do mês"
        size="sm"
        :color="scopeMode === 'month' ? 'primary' : 'neutral'"
        :variant="scopeMode === 'month' ? 'soft' : 'ghost'"
        :aria-pressed="scopeMode === 'month'"
        @click="scopeMode = 'month'"
      />
      <UButton
        :label="`Sem prazo (${undatedTasks.length})`"
        size="sm"
        :color="scopeMode === 'undated' ? 'primary' : 'neutral'"
        :variant="scopeMode === 'undated' ? 'soft' : 'ghost'"
        :aria-pressed="scopeMode === 'undated'"
        @click="scopeMode = 'undated'"
      />
      <span v-if="scopeMode === 'undated'" class="text-xs text-muted">
        Independente da competência selecionada
      </span>
    </div>

    <DataTableFilter
      :columns="filterColumns"
      :model-value="filterModels"
      :disabled="isLoading"
      class="min-w-0"
      @update:model-value="filterModels = $event"
    >
      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="Buscar tarefa, cliente ou processo..."
        class="min-w-0 flex-1"
        :disabled="isLoading"
      />
      <template #trailing>
        <UPopover v-if="scopeMode === 'month'" :content="{ align: 'end' }" :ui="{ content: 'w-72 p-4' }">
          <UTooltip text="Intervalo de vencimento">
            <UButton
              icon="i-lucide-calendar-range"
              color="neutral"
              :variant="hasDueRange ? 'soft' : 'outline'"
              :disabled="isLoading"
              class="shrink-0"
              aria-label="Filtrar por intervalo de vencimento"
            />
          </UTooltip>

          <template #content>
            <div class="space-y-3">
              <p class="text-sm font-medium text-highlighted">
                Intervalo de vencimento
              </p>
              <p class="text-xs text-muted">
                Filtra tarefas por data de vencimento
                (não altera o mês de competência).
              </p>
              <div class="grid grid-cols-2 gap-2">
                <UFormField label="De" name="due_from">
                  <UInput v-model="dueFrom" type="date" class="w-full" />
                </UFormField>
                <UFormField label="Até" name="due_to">
                  <UInput v-model="dueTo" type="date" class="w-full" />
                </UFormField>
              </div>
              <div class="flex justify-end">
                <UButton
                  label="Limpar intervalo"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  :disabled="!hasDueRange"
                  @click="clearDueRange"
                />
              </div>
            </div>
          </template>
        </UPopover>
      </template>
    </DataTableFilter>

    <ErrorRetryAlert
      v-if="showError"
      title="Não foi possível carregar as tarefas"
      @retry="retry"
    />

    <WorkTableSkeleton
      v-else-if="isLoading && allTasks.length === 0 && viewMode === 'table'"
      :columns="7"
      :rows="8"
    />

    <WorkTarefasBoard
      v-else-if="isLoading && allTasks.length === 0"
      :tasks-by-column="tasksByColumn"
      loading
      :can-manage-work="canManageWork"
      :is-locked="isWorkTaskCascadeLocked"
      :busy-id="busyId"
      :assignee-items="assigneeItems"
      :members-failed="membersFailed"
      :members-hint="membersHint"
      :member-name="memberName"
    />

    <UEmpty
      v-else-if="filteredTasks.length === 0"
      icon="i-lucide-kanban-square"
      :title="allTasks.length === 0
        ? (scopeMode === 'undated' ? 'Nenhuma tarefa sem prazo' : 'Nenhuma tarefa neste mês')
        : 'Nenhuma tarefa com esses filtros'"
      :description="allTasks.length === 0
        ? (scopeMode === 'undated'
          ? 'Tarefas de processos sem competência e sem vencimento aparecem aqui.'
          : 'Tarefas da competência e tarefas avulsas com vencimento neste mês aparecem aqui.')
        : 'Ajuste a busca ou limpe os filtros para ver mais tarefas.'"
      variant="naked"
      :actions="hasActiveFilters
        ? [{ label: 'Limpar filtros', icon: 'i-lucide-filter-x', color: 'neutral', variant: 'outline', onClick: clearAllFilters }]
        : [{ label: 'Atualizar', icon: 'i-lucide-refresh-cw', onClick: () => refresh() }]"
    />

    <template v-else-if="viewMode === 'table'">
      <!-- Mobile cards -->
      <div
        v-if="!isDesktop"
        class="flex flex-col gap-3"
        :class="canManageWork && selectedCount ? 'pb-16' : ''"
      >
        <UCard
          v-for="task in pagedFilteredTasks"
          :key="task.id"
          variant="subtle"
          :ui="{ body: 'p-3 sm:p-4' }"
        >
          <div class="flex items-start gap-3">
            <UCheckbox
              v-if="canManageWork"
              :model-value="isTaskSelected(task.id)"
              class="mt-0.5"
              :aria-label="`Selecionar ${task.title}`"
              @update:model-value="setTaskSelected(task.id, $event)"
            />
            <div class="min-w-0 flex-1 space-y-2">
              <div class="flex items-start gap-2">
                <p class="min-w-0 flex-1 text-sm font-medium text-highlighted" :title="task.title">
                  <span class="me-1.5 text-muted tabular-nums">#{{ task.order }}</span>
                  {{ task.title }}
                </p>
                <WorkTaskStatusSelect
                  v-if="canManageWork"
                  :status="task.status"
                  :locked="isWorkTaskCascadeLocked(task)"
                  @change="(next) => setTaskStatus(task.id, next)"
                  @dismiss="openDismiss(task)"
                />
                <UBadge
                  v-else
                  :color="statusPresentation(task.status).color"
                  variant="subtle"
                  :label="statusPresentation(task.status).label"
                />
              </div>
              <p class="truncate text-xs text-muted">
                {{ task.process?.client?.name ?? 'Sem cliente' }} · {{ processLabel(task) }}
              </p>
              <div class="flex flex-wrap items-center gap-1.5">
                <UBadge
                  :color="priorityPresentation(task.priority).color"
                  variant="subtle"
                  :label="priorityPresentation(task.priority).label"
                />
                <UBadge color="neutral" variant="outline" :label="task.department?.name ?? 'Sem departamento'" />
                <UBadge
                  v-if="showCascadeBadge(task.process?.cascade)"
                  size="sm"
                  :color="cascadeBadgeColor(task.process?.cascade)"
                  variant="subtle"
                  :label="cascadeLabel()"
                />
                <span class="inline-flex items-center gap-1 text-xs text-muted">
                  <UIcon name="i-lucide-calendar" class="size-3.5 shrink-0" />
                  {{ formatDueOn(task.due_on) }}
                </span>
              </div>
              <p class="text-xs text-muted">
                Responsável: {{ memberName(task.assignee_member_id) }}
              </p>
              <UButton
                :to="`/work/processos/${task.process?.id}`"
                label="Abrir processo"
                icon="i-lucide-arrow-up-right"
                size="xs"
                color="neutral"
                variant="ghost"
                class="-ms-1"
              />
            </div>
          </div>
        </UCard>
      </div>

      <!-- Desktop flat table -->
      <UCard
        v-else
        variant="subtle"
        class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden"
        :class="selectedCount ? 'pb-16' : ''"
        :ui="{ body: 'flex min-h-0 flex-1 flex-col overflow-auto p-0 sm:p-0', root: 'flex min-h-0 flex-1 flex-col' }"
      >
        <UTable
          v-model:row-selection="rowSelection"
          v-model:sorting="sorting"
          :data="pagedTableRows"
          :columns="tableColumns"
          :loading="isLoading"
          :get-row-id="(row: WorkTarefasLeaf) => String(row.id)"
          sticky
          class="min-h-0 flex-1"
          :ui="workFlatTableUi"
        >
          <template #status-cell="{ row }">
            <WorkTaskStatusSelect
              v-if="canManageWork"
              :status="row.original.status"
              :locked="isTaskCascadeLocked(row.original)"
              @change="(next) => setTaskStatus(row.original.id, next)"
              @dismiss="openDismissForLeaf(row.original)"
            />
            <UBadge
              v-else
              :color="statusPresentation(row.original.status).color"
              variant="subtle"
              :label="statusPresentation(row.original.status).label"
            />
          </template>
        </UTable>
      </UCard>

      <div
        v-if="tablePageCount > 1"
        class="flex flex-wrap items-center justify-between gap-2"
      >
        <p class="text-xs text-muted">
          {{ filteredTasks.length }} tarefa(s) · página {{ currentTablePage }} de {{ tablePageCount }}
        </p>
        <UPagination
          v-model="tablePage"
          :total="filteredTasks.length"
          :items-per-page="TABLE_PAGE_SIZE"
          :sibling-count="1"
          show-edges
          size="sm"
        />
      </div>
    </template>

    <WorkTarefasBoard
      v-else
      :tasks-by-column="tasksByColumn"
      :can-manage-work="canManageWork"
      :is-locked="isWorkTaskCascadeLocked"
      :busy-id="busyId"
      :assignee-items="assigneeItems"
      :members-failed="membersFailed"
      :members-hint="membersHint"
      :member-name="memberName"
      @advance="advance"
      @move-back="moveBack"
      @dismiss="openDismiss"
      @assign="assign"
    />

    <WorkTarefasBulkBar
      v-if="canManageWork && viewMode === 'table' && selectedCount > 0"
      :count="selectedCount"
      :disabled="bulkBusy || dismissing"
      :assignee-items="assigneeItems"
      :members-failed="membersFailed"
      @clear="clearSelection"
      @assign="onBulkAssign"
      @status="onBulkStatus"
      @dismiss="openBulkDismiss"
    />

    <WorkDismissModal
      v-model:open="dismissOpen"
      v-model:reason="dismissReason"
      :count="dismissBulk ? selectedCount : 1"
      :title="dismissBulk ? 'Dispensar tarefas' : 'Dispensar tarefa'"
      :description="dismissBulk
        ? `Informar o motivo da dispensa de ${selectedCount} tarefa(s)`
        : (dismissTarget ? `Informar o motivo da dispensa de ${dismissTarget.title}` : 'Informar o motivo da dispensa')"
      confirm-color="warning"
      field-label="Motivo da dispensa"
      field-help="O motivo é obrigatório e fica registrado em cada tarefa."
      placeholder="Ex.: sem movimento no mês"
      :rows="4"
      :loading="dismissing || bulkBusy"
      @confirm="onConfirmDismiss"
    />
  </div>
</template>
