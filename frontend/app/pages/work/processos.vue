<script setup lang="ts">
import type { Row, SortingState } from '@tanstack/table-core'
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { h, resolveComponent } from 'vue'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import type { DataTableFilterModel } from '~/components/data-table/filter-model'
import WorkToolbarTeleport from '~/components/work/WorkToolbarTeleport'
import WorkGroupStatusSelect from '~/components/work/WorkGroupStatusSelect.vue'
import WorkTaskStatusSelect from '~/components/work/WorkTaskStatusSelect.vue'
import { statusPresentation } from '~/composables/useWorkPresentation'
import type { WorkGroupedClient, WorkTaskStatus } from '~/types/work'
import { statusOrder } from '~/utils/workCalendar'
import { toPanelColumns } from '~/utils/filterPanel'
import { pageTableClass } from '~/utils/pageShell'
import {
  derivedProcessStatusForGroup,
  isCascadeAdvanceLockedInProcess
} from '~/utils/workDerivedStatus'
import {
  filterWorkProcessosLeaves,
  hasWorkProcessosActiveFilters,
  workProcessosFilterColumns
} from '~/utils/workProcessosFilters'
import {
  isWorkProcessosSelectableLeaf,
  withWorkProcessosLeavesSelected,
  workProcessosLeafSelectionState,
  workProcessosSelectedCount,
  workProcessosTaskIdsFromSelection
} from '~/utils/workProcessosSelection'
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

const { grouped, updateTask } = useWork()
const { canManageWork } = useAuth()
const { referenceMonth } = useWorkReferenceMonth()
const { memberOptions } = useDirectory()
const { list: listDepartments } = useDepartments()

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')

const { data, status, error, refresh: reload } = await useAsyncData<WorkGroupedClient[]>(
  'work-processos-grouped',
  () => grouped(referenceMonth.value),
  { watch: [referenceMonth] }
)

const groups = computed<WorkGroupedClient[]>(() => data.value ?? [])

const { data: departments } = await useAsyncData(
  'work-processos-departments',
  () => listDepartments(),
  { default: () => [] }
)

/**
 * The `refresh` below is the composable's, not `reload`'s: the toolbar button and
 * the empty-state action answer through it, so a failed manual refresh toasts
 * "Não foi possível atualizar os processos" instead of going quiet.
 * `useWorkTaskActions` below keeps the raw `reload`, because it awaits the
 * refresh inside its own try/catch and has to keep reporting through that.
 */
const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar os processos',
  refreshErrorTitle: 'Não foi possível atualizar os processos'
})

/** Mount only the active viewport tree — CSS `md:hidden` still hydrates ~1k USelects. */
const showDesktop = useClientMediaQuery('(min-width: 768px)')
const showMobile = useClientMediaQuery('(max-width: 767px)')
const desktopTable = showDesktop

/**
 * Leaf = task (or empty placeholder).
 * Grouping: processKey → clientId → leaf
 * (process domain first: same process name across clients shares a root group)
 */
interface ProcessTaskLeaf {
  id: string
  processKey: string
  processId: number
  processName: string
  processStatus: string
  processDueOn: string | null
  processRatio: number
  templateId: number | null
  templateName: string
  cascade: boolean
  clientId: number
  clientName: string
  order: number
  taskId: number | null
  title: string
  status: WorkTaskStatus | null
  department_id: number | null
  departmentName: string
  due_on: string | null
  empty: boolean
}

const allRows = computed<ProcessTaskLeaf[]>(() => {
  const leaves: ProcessTaskLeaf[] = []

  for (const group of groups.value) {
    for (const entry of group.processes) {
      const cascade = Boolean(entry.process.cascade)
      const processKey = entry.process.template
        ? `template-${entry.process.template.id}`
        : `process-${entry.process.id}`
      const templateId = entry.process.template?.id ?? null
      const templateName = entry.process.template?.name ?? ''

      if (entry.tasks.length === 0) {
        leaves.push({
          id: `process-${entry.process.id}-empty`,
          processKey,
          processId: entry.process.id,
          processName: entry.process.name,
          processStatus: entry.process.status,
          processDueOn: entry.process.due_on,
          processRatio: entry.ratio,
          templateId,
          templateName,
          cascade,
          clientId: group.client.id,
          clientName: group.client.name,
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
          processKey,
          processId: entry.process.id,
          processName: entry.process.name,
          processStatus: entry.process.status,
          processDueOn: entry.process.due_on,
          processRatio: entry.ratio,
          templateId,
          templateName,
          cascade,
          clientId: group.client.id,
          clientName: group.client.name,
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

  return leaves.sort((a, b) => {
    if (a.processKey !== b.processKey) return a.processKey.localeCompare(b.processKey, 'pt-BR')
    if (a.clientName !== b.clientName) return a.clientName.localeCompare(b.clientName, 'pt-BR')
    return a.order - b.order
  })
})

const search = ref('')
const filterModels = ref<DataTableFilterModel[]>([])

const rows = computed(() =>
  filterWorkProcessosLeaves(allRows.value, filterModels.value, search.value)
)

const hasActiveFilters = computed(() =>
  hasWorkProcessosActiveFilters(filterModels.value, search.value)
)

const filterColumns = computed(() =>
  workProcessosFilterColumns(allRows.value, filterModels.value, departments.value ?? [])
)
const panelColumns = computed(() => toPanelColumns(filterColumns.value, { operators: true }))

const sorting = ref<SortingState>([])
const rowSelection = ref<Record<string, boolean>>({})
const expanded = ref<true | Record<string, boolean>>({})
const groupingOptions = ref(workGroupedTableOptions())

const selectedCount = computed(() => workProcessosSelectedCount(rowSelection.value))
const selectedTaskIds = computed(() =>
  workProcessosTaskIdsFromSelection(rowSelection.value, rows.value)
)

const selectableLeafIds = computed(() =>
  rows.value.filter(isWorkProcessosSelectableLeaf).map(leaf => leaf.id)
)

const headerSelectionState = computed(() =>
  workProcessosLeafSelectionState(rowSelection.value, selectableLeafIds.value)
)

function clearSelection() {
  rowSelection.value = {}
}

function clearFilters() {
  filterModels.value = []
  search.value = ''
}

function onFilters(models: DataTableFilterModel[]) {
  filterModels.value = models
}

function onHeaderToggle(value: boolean | 'indeterminate') {
  rowSelection.value = withWorkProcessosLeavesSelected(
    rowSelection.value,
    selectableLeafIds.value,
    value === true
  )
}

function selectableIdsForRow(row: Row<ProcessTaskLeaf>): string[] {
  if (row.getIsGrouped()) {
    return row.getLeafRows()
      .map(leaf => leaf.original)
      .filter(isWorkProcessosSelectableLeaf)
      .map(leaf => leaf.id)
  }
  return isWorkProcessosSelectableLeaf(row.original) ? [row.original.id] : []
}

function onRowSelectToggle(row: Row<ProcessTaskLeaf>, value: boolean | 'indeterminate') {
  const ids = selectableIdsForRow(row)
  if (!ids.length) return
  rowSelection.value = withWorkProcessosLeavesSelected(rowSelection.value, ids, value === true)
}

function setLeafSelected(leafId: string, selected: boolean | 'indeterminate') {
  rowSelection.value = withWorkProcessosLeavesSelected(
    rowSelection.value,
    [leafId],
    selected === true
  )
}

function uniqueClients(row: { getLeafRows: () => { original: ProcessTaskLeaf }[] }): number {
  return new Set(row.getLeafRows().map(leaf => leaf.original.clientId)).size
}

const columns = computed<TableColumn<ProcessTaskLeaf>[]>(() => {
  const cols: TableColumn<ProcessTaskLeaf>[] = []

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
        'ariaLabel': 'Selecionar todas as tarefas filtradas'
      }),
      cell: ({ row }) => {
        const ids = selectableIdsForRow(row)
        if (!ids.length) return null
        return h(UCheckbox, {
          'modelValue': workProcessosLeafSelectionState(rowSelection.value, ids),
          'onUpdate:modelValue': (value: boolean | 'indeterminate') => onRowSelectToggle(row, value),
          'ariaLabel': row.getIsGrouped()
            ? `Selecionar tarefas de ${row.groupingColumnId === 'processKey' ? row.original.processName : row.original.clientName}`
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
    { id: 'processKey', accessorKey: 'processKey', enableSorting: false },
    { id: 'clientId', accessorKey: 'clientId', enableSorting: false },
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
        if (row.getIsGrouped() && row.groupingColumnId !== 'clientId') return null
        if (!row.getIsGrouped() && row.original.empty) return null

        return h(UButton, {
          'to': `/work/processos/${row.original.processId}`,
          'color': 'neutral',
          'variant': 'ghost',
          'size': 'xs',
          'icon': 'i-lucide-arrow-up-right',
          'aria-label': `Abrir processo ${row.original.processName} · ${row.original.clientName}`
        })
      }
    }
  )

  return cols
})

const columnVisibility = ref<Record<string, boolean>>({})

const hideableColumns = [
  { id: 'item', label: 'Item' },
  { id: 'status', label: 'Status' },
  { id: 'departmentName', label: 'Depto.' },
  { id: 'due_on', label: 'Vencimento' }
]

const rowSelectionOptions = {
  enableRowSelection: (row: Row<ProcessTaskLeaf>) => selectableIdsForRow(row).length > 0
}

/** Mobile: Processo → Cliente → tasks (mirrors desktop hierarchy). */
const mobileGroups = computed(() => {
  const map = new Map<string, {
    processKey: string
    processName: string
    templateName: string
    cascade: boolean
    clients: Map<number, {
      clientId: number
      clientName: string
      processId: number
      processRatio: number
      processStatus: string
      processDueOn: string | null
      tasks: ProcessTaskLeaf[]
    }>
  }>()

  for (const leaf of rows.value) {
    let process = map.get(leaf.processKey)
    if (!process) {
      process = {
        processKey: leaf.processKey,
        processName: leaf.processName,
        templateName: leaf.templateName,
        cascade: leaf.cascade,
        clients: new Map()
      }
      map.set(leaf.processKey, process)
    }
    let client = process.clients.get(leaf.clientId)
    if (!client) {
      client = {
        clientId: leaf.clientId,
        clientName: leaf.clientName,
        processId: leaf.processId,
        processRatio: leaf.processRatio,
        processStatus: leaf.processStatus,
        processDueOn: leaf.processDueOn,
        tasks: []
      }
      process.clients.set(leaf.clientId, client)
    }
    client.tasks.push(leaf)
  }

  return [...map.values()].map(process => ({
    ...process,
    clients: [...process.clients.values()]
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
  leaves: allRows,
  selectedTaskIds,
  selectedCount
})

function isLeafCascadeLocked(leaf: ProcessTaskLeaf): boolean {
  if (!leaf.taskId || !leaf.status) return false
  return isTaskIdLocked(leaf.taskId)
    || isCascadeAdvanceLockedInProcess(leaf.cascade, leaf.processId, leaf.order, allRows.value)
}

function derivedStatusForProcessKey(processKey: string) {
  return derivedProcessStatusForGroup(allRows.value, leaf => leaf.processKey === processKey)
}

function derivedStatusForProcess(processId: number) {
  return derivedProcessStatusForGroup(allRows.value, leaf => leaf.processId === processId)
}

function leavesForProcessKey(processKey: string): ProcessTaskLeaf[] {
  return allRows.value.filter(leaf => leaf.processKey === processKey)
}

function leavesForProcess(processId: number): ProcessTaskLeaf[] {
  return allRows.value.filter(leaf => leaf.processId === processId)
}

function leavesForGroupedRow(row: { groupingColumnId?: string, original: ProcessTaskLeaf }): ProcessTaskLeaf[] {
  return row.groupingColumnId === 'processKey'
    ? leavesForProcessKey(row.original.processKey)
    : leavesForProcess(row.original.processId)
}

function derivedStatusForGroupedRow(row: { groupingColumnId?: string, original: ProcessTaskLeaf }) {
  return row.groupingColumnId === 'processKey'
    ? derivedStatusForProcessKey(row.original.processKey)
    : derivedStatusForProcess(row.original.processId)
}

function taskIdsFromLeaves(leaves: ProcessTaskLeaf[]): number[] {
  return leaves
    .filter(isWorkProcessosSelectableLeaf)
    .map(leaf => leaf.taskId as number)
}

async function setLeafStatus(leaf: ProcessTaskLeaf, next: Exclude<WorkTaskStatus, 'dismissed'>) {
  if (!leaf.taskId || !leaf.status) return
  await setTaskStatus(leaf.taskId, leaf.status, next)
}

async function applyGroupStatus(leaves: ProcessTaskLeaf[], next: Exclude<WorkTaskStatus, 'dismissed'>) {
  const ids = taskIdsFromLeaves(leaves).filter((id) => {
    const leaf = allRows.value.find(row => row.taskId === id)
    return leaf?.status && leaf.status !== next
  })
  await runBulk('Status do grupo', () => ({ status: next }), ids)
}

function openDismissForLeaf(leaf: ProcessTaskLeaf) {
  if (!leaf.taskId) return
  openDismissForTaskIds([leaf.taskId])
}

function openDismissForLeaves(leaves: ProcessTaskLeaf[]) {
  openDismissForTaskIds(taskIdsFromLeaves(leaves))
}

const assignItems = computed(() => workAssignMemberItems(memberOptions.value))

const selectionMenu = computed<DropdownMenuItem[][]>(() => [[
  {
    label: 'Avançar',
    icon: 'i-lucide-arrow-right',
    onSelect: () => { void bulkAdvance() }
  },
  {
    label: 'Atribuir',
    icon: 'i-lucide-user-round',
    children: assignItems.value.map(item => ({
      label: item.label,
      onSelect: () => { void bulkAssign(item.value) }
    }))
  },
  {
    label: 'Dispensar',
    icon: 'i-lucide-circle-minus',
    onSelect: () => openDismiss()
  },
  {
    label: 'Cancelar seleção',
    icon: 'i-lucide-x',
    onSelect: clearSelection
  }
]])

watch([filterModels, search, referenceMonth], () => {
  if (selectedCount.value) clearSelection()
})
</script>

<template>
  <div :class="pageTableClass">
    <ClientOnly>
      <WorkToolbarTeleport>
        <div class="flex items-center gap-1">
          <UDropdownMenu
            v-if="canManageWork && selectedCount"
            :items="selectionMenu"
            :content="{ align: 'end' }"
          >
            <UButton
              :label="desktopTable ? 'Seleção' : undefined"
              icon="i-lucide-list-checks"
              color="neutral"
              variant="subtle"
              aria-label="Ações da seleção"
            >
              <template #trailing>
                <UKbd>{{ selectedCount }}</UKbd>
              </template>
            </UButton>
          </UDropdownMenu>
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            aria-label="Atualizar processos"
            :loading="isLoading"
            @click="refresh"
          />
        </div>
      </WorkToolbarTeleport>
    </ClientOnly>

    <DataTableFilterPanel
      :columns="panelColumns"
      :model-value="filterModels"
      :disabled="isLoading"
      class="min-w-0"
      @update:model-value="onFilters"
    >
      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="Buscar processo, modelo, cliente ou tarefa..."
        class="w-full min-w-0 flex-1"
        :disabled="isLoading"
      />
      <template #trailing>
        <DataTableColumnMenu
          v-if="showDesktop"
          v-model="columnVisibility"
          :columns="hideableColumns"
          class="shrink-0"
        />
      </template>
    </DataTableFilterPanel>

    <ErrorRetryAlert
      v-if="showError"
      title="Não foi possível carregar os processos"
      @retry="retry"
    />

    <WorkTableSkeleton
      v-else-if="isLoading && groups.length === 0"
      :columns="5"
      :rows="8"
      grouped
    />

    <UEmpty
      v-else-if="groups.length === 0"
      icon="i-lucide-layers"
      title="Nenhum processo neste mês"
      description="Os processos gerados a partir dos modelos aparecem aqui agrupados por processo, cliente e tarefa."
      variant="naked"
      :actions="[{ label: 'Atualizar', icon: 'i-lucide-refresh-cw', onClick: () => refresh() }]"
    />

    <UEmpty
      v-else-if="rows.length === 0"
      icon="i-lucide-filter-x"
      title="Nenhum resultado com estes filtros"
      description="Ajuste a busca ou limpe os filtros aplicados."
      variant="naked"
      :actions="hasActiveFilters
        ? [{ label: 'Limpar filtros', color: 'neutral', variant: 'outline', onClick: clearFilters }]
        : []"
    />

    <template v-else>
      <!-- Mobile: Processo → Cliente → tarefas (v-if: do not mount alongside desktop) -->
      <div
        v-if="showMobile"
        class="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto"
        :class="canManageWork && selectedCount ? 'pb-16' : ''"
      >
        <section
          v-for="process in mobileGroups"
          :key="process.processKey"
          class="space-y-1.5"
        >
          <div class="flex min-w-0 items-center gap-1.5 overflow-hidden px-0.5">
            <strong class="min-w-0 truncate text-sm text-highlighted" :title="process.processName">
              {{ process.processName }}
            </strong>
            <UBadge
              size="sm"
              color="neutral"
              variant="subtle"
              :label="`${process.clients.length} cliente(s)`"
              class="shrink-0"
            />
            <UBadge
              v-if="showCascadeBadge(process.cascade)"
              size="sm"
              :color="cascadeBadgeColor(process.cascade)"
              variant="subtle"
              :label="cascadeLabel()"
              class="shrink-0"
            />
            <WorkGroupStatusSelect
              class="shrink-0"
              :derived="derivedStatusForProcessKey(process.processKey)"
              :can-manage="canManageWork"
              @change="(next) => applyGroupStatus(leavesForProcessKey(process.processKey), next)"
              @dismiss="openDismissForLeaves(leavesForProcessKey(process.processKey))"
            />
          </div>

          <div
            v-for="client in process.clients"
            :key="`${process.processKey}-${client.clientId}`"
            class="space-y-1.5"
          >
            <div class="flex min-w-0 items-center justify-between gap-1.5 px-0.5">
              <div class="flex min-w-0 items-center gap-1.5 overflow-hidden">
                <span class="min-w-0 truncate text-sm font-medium text-highlighted" :title="client.clientName">
                  {{ client.clientName }}
                </span>
                <UBadge
                  size="sm"
                  color="primary"
                  variant="subtle"
                  :label="ratioLabel(client.processRatio)"
                  class="shrink-0"
                />
                <WorkGroupStatusSelect
                  class="shrink-0"
                  :derived="derivedStatusForProcess(client.processId)"
                  :can-manage="canManageWork"
                  @change="(next) => applyGroupStatus(leavesForProcess(client.processId), next)"
                  @dismiss="openDismissForLeaves(leavesForProcess(client.processId))"
                />
                <span class="shrink-0 text-xs text-muted">
                  {{ formatDueOn(client.processDueOn) }}
                </span>
              </div>
              <UButton
                :to="`/work/processos/${client.processId}`"
                color="neutral"
                variant="ghost"
                size="xs"
                icon="i-lucide-arrow-up-right"
                class="shrink-0"
                :aria-label="`Abrir processo ${process.processName} · ${client.clientName}`"
              />
            </div>

            <UCard
              v-for="leaf in client.tasks.filter(isWorkProcessosSelectableLeaf)"
              :key="leaf.id"
              variant="subtle"
              :ui="{ body: 'px-2.5 py-2' }"
            >
              <div class="flex items-center gap-2">
                <UCheckbox
                  v-if="canManageWork"
                  :model-value="!!rowSelection[leaf.id]"
                  size="sm"
                  class="shrink-0"
                  :aria-label="`Selecionar ${leaf.title}`"
                  @update:model-value="setLeafSelected(leaf.id, $event)"
                />
                <div class="min-w-0 flex-1">
                  <p class="min-w-0 truncate text-sm font-medium text-highlighted" :title="workItemLeafTitle(leaf.order, leaf.title)">
                    <span class="tabular-nums">{{ leaf.order }}.</span>{{ ' ' }}{{ leaf.title }}
                  </p>
                  <div class="mt-1 flex flex-wrap items-center gap-1">
                    <WorkTaskStatusSelect
                      v-if="canManageWork && leaf.status"
                      :status="leaf.status"
                      :locked="isLeafCascadeLocked(leaf)"
                      class="shrink-0"
                      @change="(next) => setLeafStatus(leaf, next)"
                      @dismiss="openDismissForLeaf(leaf)"
                    />
                    <UBadge
                      v-else-if="leaf.status"
                      size="sm"
                      :color="statusPresentation(leaf.status).color"
                      variant="subtle"
                      :label="statusPresentation(leaf.status).label"
                    />
                    <span class="text-xs text-muted">{{ leaf.departmentName }}</span>
                    <span class="text-xs text-muted">{{ formatDueOn(leaf.due_on) }}</span>
                  </div>
                </div>
              </div>
            </UCard>

            <p
              v-if="client.tasks.every(leaf => leaf.empty)"
              class="px-0.5 text-sm text-muted"
            >
              Nenhuma tarefa neste processo
            </p>
          </div>
        </section>
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
          v-model:column-visibility="columnVisibility"
          v-model:sorting="sorting"
          v-model:row-selection="rowSelection"
          v-model:expanded="expanded"
          :data="rows"
          :columns="columns"
          :grouping="['processKey', 'clientId']"
          :grouping-options="groupingOptions"
          :expanded-options="workExpandedOptions"
          :row-selection-options="rowSelectionOptions"
          :loading="isLoading"
          :get-row-id="(row: ProcessTaskLeaf) => row.id"
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
                v-if="row.groupingColumnId === 'processKey'"
                class="flex min-w-0 items-center gap-1.5 overflow-hidden"
              >
                <strong :class="workItemGroupLabelClass" :title="row.original.processName">
                  {{ row.original.processName }}
                </strong>
                <UBadge
                  size="sm"
                  color="neutral"
                  variant="subtle"
                  :label="`${uniqueClients(row)} cliente(s)`"
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

              <div
                v-else-if="row.groupingColumnId === 'clientId'"
                class="flex min-w-0 items-center gap-1.5 overflow-hidden"
              >
                <strong :class="workItemGroupLabelClass" :title="row.original.clientName">
                  {{ row.original.clientName }}
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
