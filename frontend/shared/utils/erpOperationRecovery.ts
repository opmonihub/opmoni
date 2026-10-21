export interface ErpPendingOperation {
  identity: string
  operation: string
  path: string
  payload: Record<string, unknown>
  key: string
}

export type ErpOperationRoutes = Record<string, string | RegExp>

export function pendingOperationLookup(operation: ErpPendingOperation) {
  return {
    path: `/erp/operations/lookup?operation=${encodeURIComponent(operation.operation)}`,
    headers: { 'Idempotency-Key': operation.key }
  }
}

export function createPendingOperation(
  identity: string,
  operation: string,
  path: string,
  payload: Record<string, unknown>,
  key: string
): ErpPendingOperation {
  return { identity, operation, path, payload: structuredClone(payload), key }
}

export function parsePendingOperation(
  value: unknown,
  identity: string,
  routes: ErpOperationRoutes
): ErpPendingOperation | null {
  if (!value || typeof value !== 'object') return null
  const record = value as Partial<ErpPendingOperation>
  if (typeof record.operation !== 'string' || record.identity !== identity || typeof record.path !== 'string'
    || typeof record.key !== 'string' || !/^[0-9a-f-]{36}$/i.test(record.key)
    || !record.payload || typeof record.payload !== 'object' || Array.isArray(record.payload)) return null
  const expected = routes[record.operation]
  const validPath = typeof expected === 'string' ? record.path === expected : expected?.test(record.path)
  if (!validPath) return null
  return record as ErpPendingOperation
}

export function readPendingOperation(
  storage: Pick<Storage, 'getItem'>,
  storageKey: string,
  identity: string,
  routes: ErpOperationRoutes
): ErpPendingOperation | null {
  try {
    const raw = storage.getItem(storageKey)
    return raw ? parsePendingOperation(JSON.parse(raw), identity, routes) : null
  } catch {
    return null
  }
}

export function writePendingOperation(
  storage: Pick<Storage, 'setItem' | 'removeItem'>,
  storageKey: string,
  operation: ErpPendingOperation | null
) {
  if (operation) storage.setItem(storageKey, JSON.stringify(operation))
  else storage.removeItem(storageKey)
}
