type QueryValue = string | null | (string | null)[] | undefined

const allowedText = (value: QueryValue, max = 255) => typeof value === 'string' ? value.slice(0, max) : undefined
const allowedPositiveInteger = (value: QueryValue) => typeof value === 'string' && /^[1-9]\d{0,14}$/.test(value) ? value : undefined

/** Keep only safe, useful list intent when old customer routes redirect to Companies. */
export function officeErpCompanyListQuery(query: Record<string, QueryValue>): Record<string, string> {
  const result: Record<string, string> = {}
  const search = allowedText(query.q)?.trim()
  const page = allowedPositiveInteger(query.pagina)
  const selectedClient = allowedPositiveInteger(query.cliente)
  const perPage = query.porPagina
  const sort = query.sort
  const direction = query.direction
  if (search) result.q = search
  if (page) result.pagina = page
  if (perPage === '25' || perPage === '50' || perPage === '100') result.porPagina = perPage
  if (selectedClient) result.cliente = selectedClient
  if (sort === 'name' || sort === 'registered_at') result.sort = sort
  if (direction === 'asc' || direction === 'desc') result.direction = direction
  return result
}
