/**
 * Page shell roots.
 *
 * A dashboard page body has three shapes in opmoni and no shared name for any of
 * them, so each was re-typed per page — the scroll sheet root alone was six
 * copies of one 80-character class string, which means its `p-3 sm:p-4 lg:p-5`
 * rhythm and its `overflow-y-auto` were being kept in sync by hand.
 *
 * These are the three, named for what they do rather than where they live.
 * `sheetBodyClass` in `components/data-table/sheet.ts` is the fourth: the windowed
 * data-table sheet, and deliberately its own token.
 */

/**
 * A page that scrolls as one column. The default for anything that is not a table
 * and not a record: Work › Modelos, Work › Tarefas, Monitoramento, Painel.
 */
export const pageScrollClass = 'flex min-h-0 min-w-0 flex-1 flex-col gap-4 overflow-y-auto p-3 sm:gap-5 sm:p-4 lg:p-5' as const

/**
 * A page that scrolls as one column but hosts a table which owns its own scroll
 * and a floating selection bar. `relative` anchors that bar.
 */
export const pageTableClass = 'relative flex min-h-0 min-w-0 flex-1 flex-col gap-3 overflow-hidden p-3 sm:gap-4 sm:p-4 lg:p-5' as const

/** The outer scroll box of a centred record page. Pairs with {@link pageDetailClass}. */
export const pageRecordScrollClass = 'h-full overflow-y-auto' as const

/**
 * The measure of a centred record page. `max-w-6xl` is the cap — a record page
 * with a `dl` grid and a two-column body stops being readable well before that,
 * and the cap is what keeps the left edge from drifting on a wide monitor.
 */
export const pageDetailClass = 'mx-auto flex w-full max-w-6xl flex-col gap-3 p-3 sm:p-4' as const
