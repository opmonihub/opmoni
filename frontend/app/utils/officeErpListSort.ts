export const officeErpSortFields = {
  clients: ['id', 'name'],
  plans: ['id', 'name', 'status'],
  contracts: ['id', 'client', 'agreement_status'],
  invoices: ['id', 'number', 'due_on', 'document_status'],
  receipts: ['id', 'received_on', 'reference']
} as const

export function officeErpSortParams(resource: keyof typeof officeErpSortFields, sort: string, direction: string): Record<string, string> {
  if (!(officeErpSortFields[resource] as readonly string[]).includes(sort)) return {}
  return { sort, direction: direction === 'desc' ? 'desc' : 'asc' }
}
