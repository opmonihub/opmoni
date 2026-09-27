import type { Ref } from 'vue'
import {
  clientSelectionCount,
  emptyClientSelection,
  headerCheckboxState,
  isClientInSelection,
  withClientSelected,
  type ClientSelection
} from '~/utils/clientSelection'
import type { ClientListParams, ClientSheet } from '~/types/client'

type SelectionListParams = Pick<ClientListParams, 'q' | 'status' | 'tax_regime' | 'tag_id' | 'certificate_status' | 'poa_status' | 'view'>

type UseClientListSelectionOptions = {
  matchingTotal: Ref<number>
  rows: Ref<ClientSheet[]>
  selectionParams: Ref<SelectionListParams> | (() => SelectionListParams)
  createSelection: (body: SelectionListParams) => Promise<{ data: { id: string, ids: number[], count: number } }>
}

function readParams(source: Ref<SelectionListParams> | (() => SelectionListParams)): SelectionListParams {
  return typeof source === 'function' ? source() : source.value
}

export function useClientListSelection(options: UseClientListSelectionOptions) {
  const toast = useToast()

  const selection = ref<ClientSelection>(emptyClientSelection())
  const snapshotIds = shallowRef(new Set<number>())
  const selectingAll = ref(false)
  const selectedCount = computed(() => clientSelectionCount(selection.value))

  function inSnapshot(id: number) {
    return snapshotIds.value.has(id)
  }

  function isClientSelected(id: number) {
    return isClientInSelection(selection.value, id, inSnapshot(id))
  }

  function setClientSelected(id: number, selected: boolean | 'indeterminate') {
    selection.value = withClientSelected(selection.value, id, !!selected, inSnapshot(id))
    if (selection.value.mode === 'explicit' && selection.value.ids.length === 0) snapshotIds.value = new Set()
  }

  function clearSelection() {
    selection.value = emptyClientSelection()
    snapshotIds.value = new Set()
  }

  async function selectAllMatching() {
    if (selectingAll.value || options.matchingTotal.value === 0) return
    selectingAll.value = true
    try {
      const snapshot = await options.createSelection(readParams(options.selectionParams))
      snapshotIds.value = new Set(snapshot.data.ids)
      selection.value = {
        mode: 'all_matching',
        id: snapshot.data.id,
        total: snapshot.data.count,
        excluded: [],
        included: []
      }
    } catch {
      toast.add({ title: 'Não foi possível selecionar os clientes filtrados', color: 'error' })
    } finally {
      selectingAll.value = false
    }
  }

  const rowSelection = computed({
    get: () => Object.fromEntries(
      options.rows.value.filter(client => isClientSelected(client.id)).map(client => [String(client.id), true])
    ),
    // UTable hands back the whole next map, so it can carry a multi-row delta
    // (shift-click, a "select visible" that lands as one patch, a filtered
    // reload). Bailing on anything but a single change silently dropped those.
    set: (value: Record<string, boolean>) => {
      let next = selection.value
      for (const client of options.rows.value) {
        const wanted = !!value[String(client.id)]
        if (isClientInSelection(next, client.id, inSnapshot(client.id)) === wanted) continue
        next = withClientSelected(next, client.id, wanted, inSnapshot(client.id))
      }
      selection.value = next
      if (next.mode === 'explicit' && next.ids.length === 0) snapshotIds.value = new Set()
    }
  })

  const headerState = computed(() => headerCheckboxState(selection.value, options.matchingTotal.value))

  async function onHeaderToggle(value: boolean | 'indeterminate') {
    if (value !== true) {
      clearSelection()
      return
    }
    await selectAllMatching()
  }

  const tagAssignment = computed(() => {
    const current = selection.value
    if (current.mode === 'all_matching') {
      return {
        selection_id: current.id,
        excluded_ids: [...current.excluded],
        ids: [...current.included]
      }
    }
    return { ids: [...current.ids] }
  })

  return {
    selection,
    selectingAll,
    selectedCount,
    isClientSelected,
    setClientSelected,
    clearSelection,
    selectAllMatching,
    rowSelection,
    headerState,
    onHeaderToggle,
    tagAssignment
  }
}
