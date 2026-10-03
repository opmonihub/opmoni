export interface AdminListPaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

/** Laravel Resource::collection paginator (`data` + `meta`, não campos flat). */
export function readPaginatedMeta(
  response: { meta?: Partial<AdminListPaginationMeta> | null } & Partial<AdminListPaginationMeta>
): AdminListPaginationMeta {
  const source = response.meta ?? response
  const currentPage = Number(source.current_page)
  const lastPage = Number(source.last_page)

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
  filterValue: string
): Record<string, string | number> {
  const params: Record<string, string | number> = { page }
  const query = search.trim()

  if (query) params.q = query
  if (filterValue !== 'all') params[filterKey] = filterValue

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

  return async function runLatest<T>(
    request: () => Promise<T>,
    handlers: LatestRequestHandlers<T>
  ): Promise<void> {
    const requestId = ++latestRequest

    try {
      const value = await request()
      if (requestId === latestRequest) handlers.onSuccess(value)
    } catch (error) {
      if (requestId === latestRequest) handlers.onError(error)
    } finally {
      if (requestId === latestRequest) handlers.onSettled()
    }
  }
}
