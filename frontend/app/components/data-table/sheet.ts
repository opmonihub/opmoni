export const sheetTableUi = {
  root: 'min-h-0 flex-1 overflow-auto',
  base: 'min-w-full table-fixed w-full border-separate border-spacing-0',
  tr: 'data-[selected=true]:bg-elevated/50',
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'px-3 py-2 text-xs first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r whitespace-normal',
  td: 'border-b border-default px-3 py-1.5 text-sm overflow-hidden whitespace-normal',
  separator: 'h-0'
}

export const sheetToolbarUi = {
  root: 'min-w-0 overflow-x-hidden',
  left: 'min-w-0 flex-1',
  right: 'shrink-0'
}

export const sheetBodyClass = 'relative flex min-h-0 flex-1 flex-col gap-3 overflow-hidden p-3 sm:p-4 lg:p-5'
