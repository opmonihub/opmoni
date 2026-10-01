<script setup lang="ts">
import type { Row, SortingState } from '@tanstack/table-core'
import type { TableColumn } from '@nuxt/ui'
import { h, resolveComponent } from 'vue'
import type { DataTableFilterModel } from '~/components/data-table/Filter.vue'
import WorkToolbarTeleport from '~/components/work/WorkToolbarTeleport'
import WorkGroupStatusSelect from '~/components/work/WorkGroupStatusSelect.vue'
import WorkTaskStatusSelect from '~/components/work/WorkTaskStatusSelect.vue'
import { statusPresentation } from '~/composables/useWorkPresentation'
import type { WorkGroupedClient, WorkTaskStatus } from '~/types/work'
import { statusOrder } from '~/utils/workCalendar'
import { pageTableClass } from '~/utils/pageShell'
import {
  derivedProcessStatusForGroup,
  isCascadeAdvanceLockedInProcess
} from '~/utils/workDerivedStatus'
import {
  filterWorkClientesLeaves,
  hasWorkClientesActiveFilters,
  workClientesFilterColumns
} from '~/utils/workClientesFilters'
import {
  isWorkClientesSelectableLeaf,
  withWorkClientesLeavesSelected,
  workClientesLeafSelectionState,
  workClientesSelectedCount,
  workClientesTaskIdsFromSelection
} from '~/utils/workClientesSelection'
import {
  cascadeBadgeColor,
  cascadeLabel,
  showCascadeBadge,
  workDepthIndentStyle,
  workExpandedOptions,
  workGroupExpandButtonClass,
  workGroupedTableOptions,
  workItemCellRowClass,
  workItemEmptyLeafClass,
  workItemGroupLabelClass,
  workItemLeafExpandSpacerClass,
  workItemLeafTitle,
  workItemLeafTitleClass,
  workTableUi
} from '~/utils/workGroupedTable'
import {
  formatWorkDueOn as formatDueOn,
  workAssignMemberItems,
  workLeafCountLabel as leafCountLabel,
  workRatioLabel as ratioLabel
} from '~/utils/workTableFormat'
import { workSortableHeader as sortableHeader } from '~/utils/workSortableHeader'

definePageMeta({ middleware: 'auth' })

const toast = useToast()
const { grouped, updateTask } = useWork()
const { canManageWork } = useAuth()
const { referenceMonth } = useWorkReferenceMonth()
const { memberOptions, error: membersError } = useDirectory()
const { list: listDepartments } = useDepartments()

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')

const { data, status, error, refresh: reload } = await useAsyncData<WorkGroupedClient[]>(
  'work-clientes',
  () => grouped(referenceMonth.value),
  { watch: [referenceMonth] }
)

const groups = computed<WorkGroupedClient[]>(() => data.value ?? [])

const { data: departments } = await useAsyncData(
  'work-clientes-departments',
  () => listDepartments(),
  { default: () => [] }
)

/**
 * The `refresh` below is the composable's, not `reload`'s: the toolbar button and
 * both empty-state actions answer through it, so a failed manual refresh toasts
 * "Não foi possível atualizar a visão de clientes" instead of going quiet.
 * `useWorkTaskActions` below keeps the raw `reload`, because it awaits the
 * refresh inside its own try/catch and has to keep reporting through that.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar os clientes',
  refreshErrorTitle: 'Não foi possível atualizar a visão de clientes'
})

/** Mount only the active viewport tree — CSS `md:hidden` still hydrates ~1k USelects. */
const showDesktop = useClientMediaQuery('(min-width: 768px)')
const showMobile = useClientMediaQuery('(max-width: 767px)')

/** Flat leaf: one per task (or placeholder when a process has no tasks). */
interface ClientTaskLeaf {
  id: string
  clientId: number
  clientName: string
  processId: number
  processName: string
  processRatio: number
  cascade: boolean
  order: number
  taskId: number | null
  title: string
  status: WorkTaskStatus | null
  department_id: number | null
  departmentName: string
  due_on: string | null
  empty: boolean
}

const rows = computed<ClientTaskLeaf[]>(() => {
  const leaves: ClientTaskLeaf[] = []

  for (const group of groups.value) {
    for (const entry of group.processes) {
      const cascade = Boolean(entry.process.cascade)

      if (entry.tasks.length === 0) {
        leaves.push({
          id: `process-${entry.process.id}-empty`,
          clientId: group.client.id,
          clientName: group.client.name,
          processId: entry.process.id,
          processName: entry.process.name,
          processRatio: entry.ratio,
          cascade,
          order: 0,
          taskId: null,
          title: '',
          status: null,
          department_id: null,
          departmentName: 'Sem departamento',
          due_on: null,
          empty: true
        })
        continue
      }

      const sortedTasks = [...entry.tasks].sort((a, b) => a.order - b.order)

      for (const task of sortedTasks) {
        leaves.push({
          id: String(task.id),
          clientId: group.client.id,
          clientName: group.client.name,
          processId: entry.process.id,
          processName: entry.process.name,
          processRatio: entry.ratio,
          cascade,
          order: task.order,
          taskId: task.id,
          title: task.title,
          status: task.status,
          department_id: task.department_id,
          departmentName: task.department?.name ?? 'Sem departamento',
          due_on: task.due_on,
          empty: false
        })
      }
    }
  }

  return leaves
})

const search = ref('')
const filterModels = ref<DataTableFilterModel[]>([])

const filteredRows = computed(() =>
  filterWorkClientesLeaves(rows.value, filterModels.value, search.value)
)

const hasActiveFilters = computed(() =>
  hasWorkClientesActiveFilters(filterModels.value, search.value)
)

const filterColumns = computed(() =>
  workClientesFilterColumns(rows.value, filterModels.value, departments.value ?? [])
)

const sorting = ref<SortingState>([])
const rowSelection = ref<Record<string, boolean>>({})
const expanded = ref<true | Record<string, boolean>>({})
const groupingOptions = ref(workGroupedTableOptions())

const selectedCount = computed(() => workClientesSelectedCount(rowSelection.value))
const selectedTaskIds = computed(() =>
  workClientesTaskIdsFromSelection(rowSelection.value, filteredRows.value)
)

const selectableLeafIds = computed(() =>
  filteredRows.value.filter(isWorkClientesSelectableLeaf).map(leaf => leaf.id)
)

const headerSelectionState = computed(() =>
  workClientesLeafSelectionState(rowSelection.value, selectableLeafIds.value)
)

function clearSelection() {
  rowSelection.value = {}
}

function clearFilters() {
  filterModels.value = []
  search.value = ''
}

function onHeaderToggle(value: boolean | 'indeterminate') {
  rowSelection.value = withWorkClientesLeavesSelected(
    rowSelection.value,
    selectableLeafIds.value,
    value === true
  )
}

function selectableIdsForRow(row: Row<ClientTaskLeaf>): string[] {
  if (row.getIsGrouped()) {
    return row.getLeafRows()
      .map((leaf: Row<ClientTaskLeaf>) => leaf.original)
      .filter(isWorkClientesSelectableLeaf)
      .map((leaf: ClientTaskLeaf) => leaf.id)
  }
  return isWorkClientesSelectableLeaf(row.original) ? [row.original.id] : []
}

function onRowSelectToggle(row: Row<ClientTaskLeaf>, value: boolean | 'indeterminate') {
  const ids = selectableIdsForRow(row)
  if (!ids.length) return
  rowSelection.value = withWorkClientesLeavesSelected(rowSelection.value, ids, value === true)
}

const columns = computed<TableColumn<ClientTaskLeaf>[]>(() => {
  const cols: TableColumn<ClientTaskLeaf>[] = []

  if (canManageWork.value) {
    cols.push({
      id: 'select',
      enableSorting: false,
      enableHiding: false,
      meta: { class: { th: 'w-10', td: 'w-10' } },
      header: () => h(UCheckbox, {
        'modelValue': headerSelectionState.value,
        'disabled': selectableLeafIds.value.length === 0,
        'onUpdate:modelValue': (value: boolean | 'indeterminate') => onHeaderToggle(value),
        'ariaLabel': 'Selecionar todas as tarefas visíveis'
      }),
      cell: ({ row }) => {
        const ids = selectableIdsForRow(row)
        if (!ids.length) return null
        return h(UCheckbox, {
          'modelValue': workClientesLeafSelectionState(rowSelection.value, ids),
          'onUpdate:modelValue': (value: boolean | 'indeterminate') => onRowSelectToggle(row, value),
          'ariaLabel': row.getIsGrouped()
            ? `Selecionar tarefas de ${row.groupingColumnId === 'clientId' ? row.original.clientName : row.original.processName}`
            : `Selecionar ${row.original.title}`
        })
      }
    })
  }

  cols.push(
    {
      id: 'item',
      accessorKey: 'title',
      enableSorting: true,
      header: ({ column }) => sortableHeader('Item', column),
      meta: { class: { th: 'min-w-48 w-full whitespace-nowrap', td: 'min-w-48 w-full max-w-0' } },
      aggregationFn: 'count',
      sortingFn: (a, b) => {
        const left = a.original.empty ? Number.POSITIVE_INFINITY : a.original.order
        const right = b.original.empty ? Number.POSITIVE_INFINITY : b.original.order
        return left - right
      }
    },
    { id: 'clientId', accessorKey: 'clientId', enableSorting: false },
    { id: 'processId', accessorKey: 'processId', enableSorting: false },
    {
      accessorKey: 'status',
      enableSorting: true,
      header: ({ column }) => sortableHeader('Status', column),
      meta: { class: { th: 'w-36 whitespace-nowrap', td: 'w-36' } },
      sortingFn: (a, b) => {
        // Workflow order (todo → doing → done → dismissed), not alphabetical.
        // Sem status (processo sem tarefa) vai para o fim, como no Item.
        const left = a.original.status ? statusOrder[a.original.status] : Number.POSITIVE_INFINITY
        const right = b.original.status ? statusOrder[b.original.status] : Number.POSITIVE_INFINITY
        return left - right
      }
    },
    {
      accessorKey: 'departmentName',
      enableSorting: true,
      header: ({ column }) => sortableHeader('Depto.', column),
      meta: { class: { th: 'w-28 whitespace-nowrap', td: 'w-28' } },
      cell: ({ row }) => {
        if (row.getIsGrouped() || row.original.empty) return '—'
        return row.original.departmentName || 'Sem departamento'
      }
    },
    {
      accessorKey: 'due_on',
      enableSorting: true,
      header: ({ column }) => sortableHeader('Vencimento', column),
      meta: { class: { th: 'w-32 whitespace-nowrap', td: 'w-32 whitespace-nowrap' } },
      cell: ({ row }) => {
        if (row.getIsGrouped() || row.original.empty) return '—'
        return formatDueOn(row.original.due_on)
      }
    },
    {
      id: 'actions',
      enableSorting: false,
      header: '',
      meta: { class: { th: 'w-12', td: 'w-12' } },
      cell: ({ row }) => {
        if (row.getIsGrouped() && row.groupingColumnId !== 'processId') return null
        if (!row.getIsGrouped() && row.original.empty) return null

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

const rowSelectionOptions = {
  enableRowSelection: (row: Row<ClientTaskLeaf>) => selectableIdsForRow(row).length > 0
}

const mobileGroups = computed(() => {
  const map = new Map<number, {
    clientId: number
    clientName: string
    processes: Map<number, {
      processId: number
      processName: string
      processRatio: number
      cascade: boolean
      tasks: ClientTaskLeaf[]
    }>
  }>()

  for (const leaf of filteredRows.value) {
    let client = map.get(leaf.clientId)
    if (!client) {
      client = { clientId: leaf.clientId, clientName: leaf.clientName, processes: new Map() }
      map.set(leaf.clientId, client)
    }
    let process = client.processes.get(leaf.processId)
    if (!process) {
      process = {
        processId: leaf.processId,
        processName: leaf.processName,
        processRatio: leaf.processRatio,
        cascade: leaf.cascade,
        tasks: []
      }
      client.processes.set(leaf.processId, process)
    }
    process.tasks.push(leaf)
  }

  return [...map.values()].map(client => ({
    ...client,
    processes: [...client.processes.values()]
  }))
})

const {
  bulkBusy,
  dismissOpen,
  dismissReason,
  dismissCount,
  isTaskIdLocked,
  setTaskStatus,
  runBulk,
  bulkAdvance,
  bulkAssign,
  openDismiss,
  openDismissForTaskIds,
  confirmDismiss
} = useWorkTaskActions({
  updateTask,
  refresh: reload,
  clearSelection,
  leaves: rows,
  selectedTaskIds,
  selectedCount
})

function isLeafCascadeLocked(leaf: ClientTaskLeaf): boolean {
  if (!leaf.taskId || !leaf.status) return false
  return isTaskIdLocked(leaf.taskId)
    || isCascadeAdvanceLockedInProcess(leaf.cascade, leaf.processId, leaf.order, rows.value)
}

function derivedStatusForClient(clientId: number) {
  return derivedProcessStatusForGroup(rows.value, leaf => leaf.clientId === clientId)
}

function derivedStatusForProcess(processId: number) {
  return derivedProcessStatusForGroup(rows.value, leaf => leaf.processId === processId)
}

function leavesForClient(clientId: number): ClientTaskLeaf[] {
  return rows.value.filter(leaf => leaf.clientId === clientId)
}

function leavesForProcess(processId: number): ClientTaskLeaf[] {
  return rows.value.filter(leaf => leaf.processId === processId)
}

function leavesForGroupedRow(row: { groupingColumnId?: string, original: ClientTaskLeaf }): ClientTaskLeaf[] {
  return row.groupingColumnId === 'clientId'
    ? leavesForClient(row.original.clientId)
    : leavesForProcess(row.original.processId)
}

function derivedStatusForGroupedRow(row: { groupingColumnId?: string, original: ClientTaskLeaf }) {
  return row.groupingColumnId === 'clientId'
    ? derivedStatusForClient(row.original.clientId)
    : derivedStatusForProcess(row.original.processId)
}

function taskIdsFromLeaves(leaves: ClientTaskLeaf[]): number[] {
  return leaves
    .filter(isWorkClientesSelectableLeaf)
    .map(leaf => leaf.taskId as number)
}

const assignItems = computed(() => workAssignMemberItems(memberOptions.value))

async function setLeafStatus(leaf: ClientTaskLeaf, next: Exclude<WorkTaskStatus, 'dismissed'>) {
  if (!leaf.taskId || !leaf.status) return
  await setTaskStatus(leaf.taskId, leaf.status, next)
}

async function applyGroupStatus(leaves: ClientTaskLeaf[], next: Exclude<WorkTaskStatus, 'dismissed'>) {
  const ids = taskIdsFromLeaves(leaves).filter((id) => {
    const leaf = rows.value.find(row => row.taskId === id)
    return leaf?.status && leaf.status !== next
  })
  await runBulk('Status do grupo', () => ({ status: next }), ids)
}

function openDismissForLeaf(leaf: ClientTaskLeaf) {
  if (!leaf.taskId) return
  openDismissForTaskIds([leaf.taskId])
}

function openDismissForLeaves(leaves: ClientTaskLeaf[]) {
  openDismissForTaskIds(taskIdsFromLeaves(leaves))
}

watch(membersError, (value) => {
  if (value) {
    toast.add({
      title: 'Não foi possível carregar os responsáveis',
      description: 'A atribuição em massa pode ficar limitada.',
      color: 'warning'
    })
  }
})

watch([filterModels, search, referenceMonth], () => {
  if (selectedCount.value) clearSelection()
})
</script>

<template>
  <div :class="pageTableClass">
    <ClientOnly>
      <WorkToolbarTeleport>
        <UButton
          icon="i-lucide-refresh-cw"
          color="neutral"
          variant="ghost"
          aria-label="Atualizar clientes"
          :loading="isLoading"
          @click="refresh"
        />
      </WorkToolbarTeleport>
    </ClientOnly>

    <DataTableFilter
      :columns="filterColumns"
      :model-value="filterModels"
      :disabled="isLoading"
      class="min-w-0 shrink-0"
      @update:model-value="filterModels = $event"
    >
      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="Buscar cliente, processo ou tarefa..."
        class="min-w-0 flex-1"
        :disabled="isLoading"
      />
    </DataTableFilter>

    <ErrorRetryAlert
      v-if="showError"
      title="Não foi possível carregar os clientes"
      @retry="retry"
    />

    <WorkTableSkeleton
      v-else-if="isLoading && groups.length === 0"
      :columns="5"
      :rows="8"
      grouped
      class="min-h-0 flex-1"
    />

    <UEmpty
      v-else-if="groups.length === 0"
      icon="i-lucide-users"
      title="Nenhum cliente com rotinas neste mês"
      description="Quando houver processos gerados, eles aparecem aqui agrupados por cliente."
      variant="naked"
      :actions="[{ label: 'Atualizar', icon: 'i-lucide-refresh-cw', onClick: () => refresh() }]"
    />

    <UEmpty
      v-else-if="filteredRows.length === 0"
      icon="i-lucide-search-x"
      title="Nenhuma tarefa encontrada"
      description="Ajuste a busca ou limpe os filtros aplicados."
      variant="naked"
      :actions="hasActiveFilters
        ? [{ label: 'Limpar filtros', icon: 'i-lucide-filter-x', color: 'neutral', variant: 'outline', onClick: () => clearFilters() }]
        : [{ label: 'Atualizar', icon: 'i-lucide-refresh-cw', onClick: () => refresh() }]"
    />

    <template v-else>
      <!-- Mobile card fallback (v-if: do not mount alongside desktop) -->
      <div
        v-if="showMobile"
        class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto"
        :class="canManageWork && selectedCount ? 'pb-16' : ''"
      >
        <UCard
          v-for="client in mobileGroups"
          :key="client.clientId"
          variant="subtle"
          :ui="{ body: 'space-y-2 px-3 py-2.5' }"
        >
          <div class="flex items-center justify-between gap-2">
            <div class="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden">
              <p class="min-w-0 truncate text-sm font-semibold text-highlighted" :title="client.clientName">
                {{ client.clientName }}
              </p>
              <WorkGroupStatusSelect
                class="shrink-0"
                :derived="derivedStatusForClient(client.clientId)"
                :can-manage="canManageWork"
                @change="(next) => applyGroupStatus(leavesForClient(client.clientId), next)"
                @dismiss="openDismissForLeaves(leavesForClient(client.clientId))"
              />
            </div>
            <UCheckbox
              v-if="canManageWork"
              :model-value="workClientesLeafSelectionState(
                rowSelection,
                client.processes.flatMap(p => p.tasks).filter(isWorkClientesSelectableLeaf).map(t => t.id)
              )"
              size="sm"
              :ui="{ base: 'rounded-full' }"
              :aria-label="`Selecionar tarefas de ${client.clientName}`"
              @update:model-value="rowSelection = withWorkClientesLeavesSelected(
                rowSelection,
                client.processes.flatMap(p => p.tasks).filter(isWorkClientesSelectableLeaf).map(t => t.id),
                $event === true
              )"
            />
          </div>

          <div
            v-for="process in client.processes"
            :key="process.processId"
            class="space-y-1.5 rounded-lg bg-default px-2.5 py-2 ring ring-default"
          >
            <div class="flex min-w-0 items-center gap-1.5 overflow-hidden">
              <UCheckbox
                v-if="canManageWork"
                class="shrink-0"
                :model-value="workClientesLeafSelectionState(
                  rowSelection,
                  process.tasks.filter(isWorkClientesSelectableLeaf).map(t => t.id)
                )"
                size="sm"
                :aria-label="`Selecionar tarefas de ${process.processName}`"
                @update:model-value="rowSelection = withWorkClientesLeavesSelected(
                  rowSelection,
                  process.tasks.filter(isWorkClientesSelectableLeaf).map(t => t.id),
                  $event === true
                )"
              />
              <span class="min-w-0 flex-1 truncate text-sm font-medium text-highlighted" :title="process.processName">
                {{ process.processName }}
              </span>
              <UBadge
                size="sm"
                color="primary"
                variant="subtle"
                :label="ratioLabel(process.processRatio)"
                class="shrink-0"
              />
              <WorkGroupStatusSelect
                class="shrink-0"
                :derived="derivedStatusForProcess(process.processId)"
                :can-manage="canManageWork"
                @change="(next) => applyGroupStatus(leavesForProcess(process.processId), next)"
                @dismiss="openDismissForLeaves(leavesForProcess(process.processId))"
              />
              <UBadge
                v-if="showCascadeBadge(process.cascade)"
                size="sm"
                :color="cascadeBadgeColor(process.cascade)"
                variant="subtle"
                :label="cascadeLabel()"
                class="shrink-0"
              />
              <UButton
                :to="`/work/processos/${process.processId}`"
                color="neutral"
                variant="ghost"
                size="xs"
                icon="i-lucide-arrow-up-right"
                class="shrink-0"
                :aria-label="`Abrir processo ${process.processName}`"
              />
            </div>

            <div
              v-for="task in process.tasks"
              :key="task.id"
              class="flex items-center gap-2 border-t border-default pt-1.5 first:border-t-0 first:pt-0"
            >
              <UCheckbox
                v-if="canManageWork && isWorkClientesSelectableLeaf(task)"
                :model-value="!!rowSelection[task.id]"
                size="sm"
                class="shrink-0"
                :aria-label="`Selecionar ${task.title}`"
                @update:model-value="rowSelection = withWorkClientesLeavesSelected(rowSelection, [task.id], $event === true)"
              />
              <div class="min-w-0 flex-1">
                <template v-if="task.empty">
                  <p class="text-sm text-muted">
                    Nenhuma tarefa neste processo
                  </p>
                </template>
                <template v-else>
                  <div class="flex items-center justify-between gap-2">
                    <p class="min-w-0 truncate text-sm text-highlighted" :title="workItemLeafTitle(task.order, task.title)">
                      <span class="tabular-nums">{{ task.order }}.</span>{{ ' ' }}{{ task.title }}
                    </p>
                    <WorkTaskStatusSelect
                      v-if="canManageWork && task.status"
                      :status="task.status"
                      :locked="isLeafCascadeLocked(task)"
                      class="shrink-0"
                      @change="(next) => setLeafStatus(task, next)"
                      @dismiss="openDismissForLeaf(task)"
                    />
                    <UBadge
                      v-else-if="task.status"
                      size="sm"
                      :color="statusPresentation(task.status).color"
                      variant="subtle"
                      :label="statusPresentation(task.status).label"
                      class="shrink-0"
                    />
                  </div>
                  <p class="truncate text-xs text-muted">
                    {{ task.departmentName || 'Sem departamento' }} · {{ formatDueOn(task.due_on) }}
                  </p>
                </template>
              </div>
            </div>
          </div>
        </UCard>
      </div>

      <!-- Desktop grouped table -->
      <UCard
        v-else-if="showDesktop"
        variant="subtle"
        class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden"
        :class="canManageWork && selectedCount ? 'pb-16' : ''"
        :ui="{ body: 'flex min-h-0 flex-1 flex-col overflow-auto p-0 sm:p-0', root: 'flex min-h-0 flex-1 flex-col' }"
      >
        <UTable
          v-model:sorting="sorting"
          v-model:row-selection="rowSelection"
          v-model:expanded="expanded"
          :data="filteredRows"
          :columns="columns"
          :grouping="['clientId', 'processId']"
          :grouping-options="groupingOptions"
          :expanded-options="workExpandedOptions"
          :row-selection-options="rowSelectionOptions"
          :loading="isLoading"
          :get-row-id="(row: ClientTaskLeaf) => row.id"
          sticky
          class="min-h-0 flex-1"
          :ui="workTableUi"
        >
          <template #item-cell="{ row }">
            <div v-if="row.getIsGrouped()" :class="workItemCellRowClass">
              <span
                class="inline-block shrink-0"
                :style="workDepthIndentStyle(row.depth)"
              />

              <UButton
                variant="outline"
                color="neutral"
                :class="workGroupExpandButtonClass"
                size="xs"
                square
                :icon="row.getIsExpanded() ? 'i-lucide-minus' : 'i-lucide-plus'"
                :aria-label="row.getIsExpanded() ? 'Recolher' : 'Expandir'"
                @click="(e: Event) => { e.stopPropagation(); row.toggleExpanded() }"
              />

              <div
                v-if="row.groupingColumnId === 'clientId'"
                class="flex min-w-0 items-center gap-1.5 overflow-hidden"
              >
                <strong :class="workItemGroupLabelClass" :title="row.original.clientName">
                  {{ row.original.clientName }}
                </strong>
                <UBadge
                  size="sm"
                  color="neutral"
                  variant="subtle"
                  :label="leafCountLabel(row)"
                  class="shrink-0"
                />
              </div>

              <div
                v-else-if="row.groupingColumnId === 'processId'"
                class="flex min-w-0 items-center gap-1.5 overflow-hidden"
              >
                <strong :class="workItemGroupLabelClass" :title="row.original.processName">
                  {{ row.original.processName }}
                </strong>
                <UBadge
                  size="sm"
                  color="primary"
                  variant="subtle"
                  :label="ratioLabel(row.original.processRatio)"
                  class="shrink-0"
                />
                <UBadge
                  size="sm"
                  color="neutral"
                  variant="subtle"
                  :label="leafCountLabel(row)"
                  class="shrink-0"
                />
                <UBadge
                  v-if="showCascadeBadge(row.original.cascade)"
                  size="sm"
                  :color="cascadeBadgeColor(row.original.cascade)"
                  variant="subtle"
                  :label="cascadeLabel()"
                  class="shrink-0"
                />
              </div>
            </div>
            <div v-else :class="workItemCellRowClass">
              <span
                class="inline-block shrink-0"
                :style="workDepthIndentStyle(row.depth)"
              />
              <span :class="workItemLeafExpandSpacerClass" aria-hidden="true" />
              <span
                v-if="row.original.empty"
                :class="workItemEmptyLeafClass"
              >
                Nenhuma tarefa neste processo
              </span>
              <span
                v-else
                :class="workItemLeafTitleClass"
                :title="workItemLeafTitle(row.original.order, row.original.title)"
              >
                <span class="tabular-nums">{{ row.original.order }}.</span>{{ ' ' }}{{ row.original.title }}
              </span>
            </div>
          </template>

          <template #status-cell="{ row }">
            <WorkGroupStatusSelect
              v-if="row.getIsGrouped()"
              :derived="derivedStatusForGroupedRow(row)"
              :can-manage="canManageWork"
              @change="(next) => applyGroupStatus(leavesForGroupedRow(row), next)"
              @dismiss="openDismissForLeaves(leavesForGroupedRow(row))"
            />
            <span v-else-if="row.original.empty" class="text-muted">—</span>
            <WorkTaskStatusSelect
              v-else-if="canManageWork && row.original.status"
              :status="row.original.status"
              :locked="isLeafCascadeLocked(row.original)"
              @change="(next) => setLeafStatus(row.original, next)"
              @dismiss="openDismissForLeaf(row.original)"
            />
            <UBadge
              v-else-if="row.original.status"
              :color="statusPresentation(row.original.status).color"
              variant="subtle"
              :label="statusPresentation(row.original.status).label"
            />
          </template>
        </UTable>
      </UCard>

      <WorkTableSkeleton
        v-else
        :columns="5"
        :rows="8"
        grouped
        class="min-h-0 flex-1"
      />
    </template>

    <Transition
      enter-active-class="transition duration-150 ease-out motion-reduce:transition-none"
      enter-from-class="translate-y-2 opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-100 ease-in motion-reduce:transition-none"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-2 opacity-0"
    >
      <WorkSelectionBar
        v-if="canManageWork && selectedCount"
        class="absolute bottom-3 left-1/2 z-20 w-max max-w-[calc(100%-1.5rem)] -translate-x-1/2"
        :count="selectedCount"
        :disabled="isLoading || bulkBusy"
        :member-items="assignItems"
        @clear="clearSelection"
        @advance="bulkAdvance"
        @dismiss="openDismiss()"
        @assign="bulkAssign"
      />
    </Transition>

    <WorkDismissModal
      v-model:open="dismissOpen"
      v-model:reason="dismissReason"
      :count="dismissCount"
      :loading="bulkBusy"
      @confirm="confirmDismiss"
    />
  </div>
</template>
