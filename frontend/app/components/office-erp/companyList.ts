/** Company lists follow the approved ERP preview without changing other ERP tables. */
export const companyListPanelUi = {
  heading: {
    root: 'mb-1 sm:mb-3',
    container: 'flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between lg:flex',
    wrapper: 'min-w-0',
    description: 'text-sm'
  },
  card: { root: 'min-w-0 overflow-hidden' },
  toolbar: 'flex min-w-0 flex-wrap items-end justify-between gap-4'
} as const

export const companyListTableUi = {
  base: 'w-full min-w-0 table-fixed border-collapse',
  th: 'rounded-none first:rounded-none last:rounded-none border-b border-default px-2 py-3 text-xs font-semibold whitespace-normal sm:px-3',
  td: 'border-b border-default px-2 py-4 align-middle text-sm whitespace-normal wrap-anywhere sm:px-3 sm:py-5'
} as const

export const companyListBodyClass = 'min-w-0 px-3 pt-3 sm:px-6 sm:pt-4'
export const companyListFooterClass = 'mt-2 -mx-3 px-4 py-4 sm:-mx-6 sm:px-6'
