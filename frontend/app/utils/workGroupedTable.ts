import type { GroupingOptions } from '@tanstack/table-core'
import { getGroupedRowModel } from '@tanstack/table-core'

/** Shared TanStack grouping options for Nuxt UI "With grouped rows" tables. */
export function workGroupedTableOptions(): GroupingOptions {
  return {
    groupedColumnMode: 'remove',
    getGroupedRowModel: getGroupedRowModel()
  }
}

/**
 * Prevent TanStack/UTable from collapsing groups on every data redraw
 * (status update, refresh, computed rows). See nuxt/ui#6030.
 */
export const workExpandedOptions = { autoResetExpanded: false } as const

/**
 * Table chrome for work list/grouped tables.
 * Use `min-w-full` (not `min-w-max`) so a flex Item/Tarefa column can absorb slack
 * without pushing Status/Depto/Vencimento off-screen and creating a phantom scrollbar.
 * `table-fixed` keeps fixed-width columns from expanding past their meta classes.
 * Compact `th`/`td` padding overrides Nuxt UI defaults (`py-3.5` / `p-4`) for dense rows.
 *
 * Tone is left to Nuxt UI defaults: no row band, no header tint. A grouped Work table
 * reads its hierarchy from the indent plus a bold group label, exactly like the
 * Nuxt UI grouped-rows example — an extra fill here only stacks another gray.
 */
export const workTableUi = {
  root: 'min-h-0 min-w-full overflow-auto',
  base: 'min-w-full table-fixed',
  th: 'whitespace-nowrap px-3 py-2 text-xs',
  td: 'empty:p-0 px-3 py-1.5'
} as const

export const workFlatTableUi = {
  root: 'min-h-0 min-w-full overflow-auto',
  base: 'min-w-full table-fixed',
  th: 'whitespace-nowrap px-3 py-2 text-xs',
  td: 'px-3 py-1.5'
} as const

/** Progressive left indent only — ~1rem per depth (Nuxt docs / TaskHub-like nesting). */
export const WORK_GROUP_DEPTH_INDENT_REM = 1

export function workDepthIndentStyle(depth: number): { width: string } {
  return { width: `calc(${depth} * ${WORK_GROUP_DEPTH_INDENT_REM}rem)` }
}

/**
 * Expand +/- control next to group labels.
 *
 * No forced `size-*`/`p-0`: `size="xs"` with an icon and no label already resolves
 * `square`, and Nuxt UI's `xs + square` compound variant supplies the padding. Pinning
 * a height and zeroing the padding here fought that variant and left the icon
 * overflowing its ring. The button owns its own box, as in the Nuxt UI example.
 */
export const workGroupExpandButtonClass = 'shrink-0' as const

/**
 * Item cell row chrome: indent → ± (or size-matched spacer on leaves) → label.
 * Shared by Clientes / Processos so hierarchy and `N. Título` stay aligned.
 */
export const workItemCellRowClass = 'flex min-w-0 items-center gap-1 overflow-hidden' as const

/**
 * Invisible peer of the expand control — keeps leaf `N. Título` under group labels.
 * `size-5` mirrors what the button renders at `size="xs" square` (p-0.5 + size-4
 * icon), so the column of titles stays flush under the group names.
 */
export const workItemLeafExpandSpacerClass = 'size-5 shrink-0 invisible pointer-events-none' as const

/** Group name in the merged Item column. */
export const workItemGroupLabelClass = 'min-w-0 truncate font-semibold text-highlighted' as const

/** Leaf task title (`N. Título`) — body weight, still scannable in the primary column. */
export const workItemLeafTitleClass = 'min-w-0 truncate text-highlighted' as const

/** Empty process placeholder in the Item column. */
export const workItemEmptyLeafClass = 'min-w-0 truncate text-muted' as const

/** Title attribute / aria for leaf Item cells. */
export function workItemLeafTitle(order: number, title: string): string {
  return `${order}. ${title}`
}

/**
 * Cascade badge label. The badge is opt-in — every call site sits under
 * {@link showCascadeBadge}, so `cascade === false` is never rendered and the
 * "Cascata: não" wording is dead. Kept in step with the `Cascata` / `Sem
 * cascata` filter options in work{Clientes,Processos}Filters.
 */
export function cascadeLabel(): string {
  return 'Cascata'
}

/** Cascade is opt-in — only surface the badge when enabled. */
export function showCascadeBadge(cascade: boolean | null | undefined): boolean {
  return cascade === true
}

export function cascadeBadgeColor(cascade: boolean | null | undefined): 'warning' | 'neutral' {
  return cascade ? 'warning' : 'neutral'
}
