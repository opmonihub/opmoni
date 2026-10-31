/** ERP-only composition; Admin and Equipe retain their shared panel-card layout. */
export const companyListPanelUi = {
  heading: {
    root: 'mb-0',
    container: 'flex flex-wrap items-center justify-between gap-3',
    wrapper: 'min-w-0',
    description: 'text-sm'
  },
  card: { root: 'min-w-0' },
  toolbar: 'flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto'
} as const

export const officeErpListToolbarClass = companyListPanelUi.toolbar

/** Keep Nuxt UI table structure, dividers and typography; only adjust density. */
export const companyListTableUi = {
  base: 'w-full table-auto',
  th: 'px-3 py-3',
  td: 'px-3 py-2'
} as const

export const companyListBodyClass = 'flex min-w-0 flex-col gap-3'
export const companyListFooterClass = 'mt-0'
