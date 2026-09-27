import { h } from 'vue'
import DataTableSortButton from '~/components/data-table/SortButton.vue'

type SortableColumn = {
  getIsSorted: () => false | 'asc' | 'desc'
  toggleSorting: (desc?: boolean) => void
}

/** Shared sortable column header for work UTable columns. */
export function workSortableHeader(label: string, column: SortableColumn) {
  const sorted = column.getIsSorted()
  return h(DataTableSortButton, {
    label,
    sorted: sorted || false,
    onToggle: () => column.toggleSorting(sorted === 'asc')
  })
}
