/**
 * Chrome for the panel list pages — the `UPageCard` header + bordered table +
 * counted footer shape shared by the six Admin lists and the Equipe lists.
 *
 * These were six byte-identical `tableUi` objects plus a repeated `UPageCard`
 * `:ui` override, repeated inline per page. They are here so the density of a
 * panel table is decided once: `sheetTableUi` (data-table/sheet.ts) is the other
 * family and is deliberately a different token — that one is a windowed sheet
 * with `text-sm` cells and its own scroll root, not a bordered card table.
 */

/** Bordered card table: hairline row rules, elevated header strip, no outer border. */
export const panelTableUi = {
  base: 'table-fixed border-separate border-spacing-0',
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
  td: 'border-b border-default',
  separator: 'h-0'
} as const

/**
 * Panel card with a toolbar: the body padding is dropped so the toolbar and the
 * table meet across the full width, and the toolbar is the only thing that draws
 * a rule under itself.
 */
export const panelCardUi = {
  container: 'p-0 sm:p-0 gap-y-0',
  wrapper: 'items-stretch',
  header: 'p-4 mb-0 border-b border-default'
} as const

/** Toolbar row above a panel table: search on the left, filters on the right. */
export const panelToolbarClass = 'flex flex-wrap items-center justify-between gap-1.5' as const

/** Panel body: one padded frame around the table and its footer. */
export const panelBodyClass = 'flex flex-col gap-4 p-4 sm:p-6' as const

/** Counted footer: result count on the left, pagination on the right. */
export const panelFooterClass = 'flex items-center justify-between gap-3 border-t border-default pt-4' as const

export const panelFooterCountClass = 'text-sm text-muted' as const

export const panelPaginationClass = 'flex items-center gap-1.5' as const

/**
 * `USelect` chevron that rotates while the menu is open. Five call sites
 * hand-rolled this; it is the Nuxt UI default behaviour the template simply does
 * not ship, and a list filter that does not say which way it opens is a list
 * filter you open twice.
 */
export const panelSelectUi = {
  trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200'
} as const

/**
 * `div.text-right` wrapper that keeps a trailing row-actions menu flush right
 * inside a panel table cell.
 */
export const panelRowActionsClass = 'text-right' as const

/**
 * Empty state for a `panelTableUi` table. See `PanelTableEmpty.vue` for why this
 * is not `UEmpty variant="naked"` yet.
 */
export const panelTableEmptyClass = 'flex flex-col items-center justify-center gap-2 py-8 text-sm text-muted' as const

/** Row-actions trigger: the ellipsis button itself, flush right. */
export const panelRowActionsTriggerClass = 'ml-auto' as const
