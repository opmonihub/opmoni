import { useListNavigationState } from '~/composables/useListNavigationState'
import type { officeErpSortFields } from '~/utils/officeErpListSort'
import { officeErpSortParams } from '~/utils/officeErpListSort'

type Criteria = Record<string, string | number>

/** Retain only list criteria; grants and all protected resource state keep their own owners. */
export function useOfficeErpListNavigation<T extends Criteria>(
  key: keyof typeof officeErpSortFields,
  defaults: T,
  context: { capabilities: { value: { capability_key: string } | null }, capture(): { key: string }, confirmation?: { value: string } },
  choices: Partial<Record<keyof T, readonly (string | number)[]>> = {},
  fromQuery?: () => T | null
) {
  const route = useRoute()
  const sortDefaults = { sort: '', direction: 'asc' }
  function normalize(value: unknown): T & typeof sortDefaults {
    const input = value && typeof value === 'object' ? value as Record<string, unknown> : {}
    const result = { ...defaults }
    for (const field of Object.keys(defaults) as Array<keyof T>) {
      const candidate = input[field as string]
      if (choices[field]) {
        if (choices[field]!.includes(candidate as string | number)) result[field] = candidate as T[keyof T]
      } else if (field === 'page') {
        if (Number.isSafeInteger(candidate) && Number(candidate) > 0) result[field] = candidate as T[keyof T]
      } else if (field === 'perPage') {
        if ([25, 50, 100].includes(candidate as number)) result[field] = candidate as T[keyof T]
      } else if (['from', 'to', 'dueFrom', 'dueTo'].includes(String(field))) {
        if (candidate === '' || (typeof candidate === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(candidate)
          && Number.isFinite(Date.parse(candidate)) && new Date(candidate).toISOString().slice(0, 10) === candidate)) result[field] = candidate as T[keyof T]
      } else if (field === 'contractId') {
        if (candidate === '' || (typeof candidate === 'string' && /^[1-9]\d{0,14}$/.test(candidate))) result[field] = candidate as T[keyof T]
      } else if (typeof candidate === 'string') {
        result[field] = candidate as T[keyof T]
      }
    }
    const ordering = officeErpSortParams(key, String(input.sort ?? ''), String(input.direction ?? ''))
    return { ...result, ...sortDefaults, ...ordering }
  }
  let hadExplicitQuery = false
  function readQuery() {
    const query = route.query ?? {}
    const legacy = fromQuery?.()
    const aliases: Record<string, string[]> = { page: ['pagina', 'page'], perPage: ['porPagina', 'per_page'], from: ['from_on', 'received_from'], to: ['to_on', 'received_to'], dueFrom: ['due_from'], dueTo: ['due_to'], contractId: ['contract_id'] }
    const explicit: Record<string, unknown> = { ...defaults, ...sortDefaults, ...legacy }
    let found = legacy !== null && legacy !== undefined
    for (const field of [...Object.keys(defaults), 'sort', 'direction']) {
      const name = (aliases[field] ?? [field]).find(name => Object.hasOwn(query, name))
      if (!name) continue
      found = true
      const candidate = query[name]
      explicit[field] = ['page', 'perPage'].includes(field) && typeof candidate === 'string' && /^\d+$/.test(candidate) ? Number(candidate) : candidate
    }
    if (found) {
      hadExplicitQuery = true
      return normalize(explicit)
    }
    return hadExplicitQuery ? normalize(null) : null
  }
  return useListNavigationState(`office-commercial:${key}`, {
    defaults: () => ({ ...defaults, ...sortDefaults }), normalize, fromQuery: readQuery,
    authority: () => context.capabilities.value || context.confirmation?.value === 'failed' ? context.capture().key : null
  })
}

export function officeErpAvailablePage(meta: { total: number, current_page: number, last_page: number, per_page: number }): number {
  if (!Number.isSafeInteger(meta.total) || meta.total < 0
    || !Number.isSafeInteger(meta.current_page) || meta.current_page < 1
    || !Number.isSafeInteger(meta.last_page) || meta.last_page < 1
    || ![25, 50, 100].includes(meta.per_page)) throw new Error('A paginação retornada está incompleta.')
  return Math.min(meta.current_page, meta.last_page)
}
