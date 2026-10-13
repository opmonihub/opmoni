/**
 * Read-only key → value facts, as a `dl`.
 *
 * opmoni labels every recorded fact (vencimento, ciência, série, situação) and
 * these definitions were being hand-written in four different grid shapes across
 * the monitoring surfaces and the client detail page. Two layouts carry all of
 * them, so those are the two this file names.
 *
 * `MetaList.vue` renders them; these are the class tokens it composes.
 */

/** Label above value. `columns` carries the track count. */
export const metaListGridClass = 'grid' as const

/** Label left, value right, one row per fact. */
export const metaListStackClass = 'space-y-1.5 text-xs' as const

/** One stacked fact: the row that puts the label against the right-hand value. */
export const metaListStackRowClass = 'flex justify-between gap-3' as const

/**
 * Value colour per tone. `default` is the Nuxt UI `text-default` role so a fact
 * list reads the same as body copy on every surface.
 */
export const metaListToneClass = {
  default: 'text-default',
  error: 'text-error',
  success: 'text-success',
  warning: 'text-warning',
  info: 'text-info'
} as const
