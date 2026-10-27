export interface AdminListPaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

/** Laravel Resource::collection paginator (`data` + `meta`, não campos flat). */
export function readPaginatedMeta(
  response: { meta?: (Partial<AdminListPaginationMeta> & { page?: number }) | null } & Partial<AdminListPaginationMeta>
): AdminListPaginationMeta {
  const source = response.meta ?? response
  const currentPage = Number(source.current_page ?? ('page' in source ? source.page : undefined))
  const lastPage = Number(source.last_page ?? Math.ceil(Number(source.total) / Number(source.per_page)))

  return {
    current_page: Number.isFinite(currentPage) && currentPage > 0 ? currentPage : 1,
    last_page: Number.isFinite(lastPage) && lastPage > 0 ? lastPage : 1,
    per_page: Number(source.per_page) > 0 ? Number(source.per_page) : 15,
    total: Number.isFinite(Number(source.total)) ? Number(source.total) : 0
  }
}

export function adminListParams(
  page: number,
  search: string,
  filterKey: string,
  filterValue: string,
  sort = '',
  direction = 'asc',
  sortFields: readonly string[] = []
): Record<string, string | number> {
  const params: Record<string, string | number> = { page }
  const query = search.trim()

  if (query) params.q = query
  if (filterValue !== 'all') params[filterKey] = filterValue
  if (sortFields.includes(sort)) {
    params.sort = sort
    params.direction = direction === 'desc' ? 'desc' : 'asc'
  }

  return params
}

export function pageWithinLastPage(currentPage: number, lastPage: number): number {
  const safeLast = Math.max(1, Number.isFinite(lastPage) ? lastPage : 1)
  const safeCurrent = Number.isFinite(currentPage) && currentPage > 0 ? currentPage : 1

  return Math.min(safeCurrent, safeLast)
}

interface LatestRequestHandlers<T> {
  onSuccess: (value: T) => void
  onError: (error: unknown) => void
  onSettled: () => void
}

export function createLatestRequestRunner() {
  let latestRequest = 0
  let controller: AbortController | undefined

  async function runLatest<T>(
    request: (signal: AbortSignal) => Promise<T>,
    handlers: LatestRequestHandlers<T>
  ): Promise<void> {
    const requestId = ++latestRequest
    controller?.abort()
    const attempt = new AbortController()
    controller = attempt

    try {
      const value = await request(attempt.signal)
      if (requestId === latestRequest && !attempt.signal.aborted) handlers.onSuccess(value)
    } catch (error) {
      if (requestId === latestRequest && !attempt.signal.aborted) handlers.onError(error)
    } finally {
      if (requestId === latestRequest) {
        controller = undefined
        handlers.onSettled()
      }
    }
  }
  runLatest.invalidate = () => {
    latestRequest++
    controller?.abort()
    controller = undefined
  }
  return runLatest
}

/** Only supported list criteria travel through the existing authority registry. */
export function adminListNavigationOptions(filters: readonly string[] = ['all'], sortFields: readonly string[] = [], query?: () => Record<string, unknown>) {
  const defaults = () => ({ q: '', page: 1, filter: 'all', sort: '', direction: 'asc' })
  function normalize(value: unknown) {
    const input = value as Record<string, unknown> | null | undefined
    const sort = typeof input?.sort === 'string' && sortFields.includes(input.sort) ? input.sort : ''
    return {
      q: typeof input?.q === 'string' ? input.q : '',
      page: Number.isSafeInteger(input?.page) && Number(input?.page) > 0 ? Number(input?.page) : 1,
      filter: typeof input?.filter === 'string' && filters.includes(input.filter) ? input.filter : 'all',
      sort,
      direction: sort && input?.direction === 'desc' ? 'desc' : 'asc'
    }
  }
  let hadQuery = false
  return {
    defaults,
    normalize,
    fromQuery: query
      ? () => {
          const input = query()
          if (['q', 'page', 'filter', 'status', 'type', 'sort', 'direction'].some(key => Object.hasOwn(input, key))) {
            hadQuery = true
            return normalize({ ...input, filter: input.filter ?? input.status ?? input.type,
              page: typeof input.page === 'string' && /^[1-9]\d*$/.test(input.page) ? Number(input.page) : 1 })
          }
          return hadQuery ? defaults() : null
        }
      : undefined
  }
}
