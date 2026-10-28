/** Row separators shared by card and windowed-sheet tables. */
export const tableChromeUi = {
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  separator: 'h-0'
} as const
