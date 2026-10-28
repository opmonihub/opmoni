/** Settings-reference field row: stacked on phones, label and control aligned on desktop. */
import type { FormErrorEvent } from '@nuxt/ui'

export const settingsFieldRowClass = 'flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between' as const

/** Error summary stays visible above the relevant fields and uses alert semantics. */
export const formErrorSummaryClass = 'mb-4' as const

/** Native Nuxt UI error IDs take precedence; server validation stays scoped to its form. */
export function focusFormError(event?: FormErrorEvent, formId?: string) {
  if (typeof document === 'undefined') return
  const id = event?.errors?.[0]?.id
  const target = id
    ? document.getElementById(id)
    : formId
      ? document.getElementById(formId)?.querySelector<HTMLElement>('[aria-invalid="true"]')
      : null
  target?.focus()
  target?.scrollIntoView({ block: 'center', behavior: 'instant' })
}
