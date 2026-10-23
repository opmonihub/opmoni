import { panelTableUi } from '~/components/data-table/panel'

export const erpPageBodyClass = 'space-y-5 p-4 sm:p-6' as const

/** Keep the panel surface while letting operational columns size to their content. */
export const erpOperationalTableUi = {
  ...panelTableUi,
  base: 'min-w-full table-auto border-separate border-spacing-0'
} as const
