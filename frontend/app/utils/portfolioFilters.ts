export type PortfolioDocument = 'certificate' | 'poa'

export interface PortfolioListing {
  document: PortfolioDocument
  status: string
}

export function pinnedDocumentFilter(listing: PortfolioListing): PortfolioDocument | null {
  if (listing.status === 'all') return null
  return listing.document
}

export function withoutPinnedDocumentFilter<T extends { certificate: readonly string[], poa: readonly string[] }>(
  filters: T,
  pinned: PortfolioDocument | null
): T {
  if (pinned === 'certificate') return { ...filters, certificate: [] }
  if (pinned === 'poa') return { ...filters, poa: [] }
  return filters
}

/** Values that still split the current rows. One value only repeats the list. */
export function singleValueFacets(values: ReadonlyArray<string | null | undefined>): string[] {
  const counts = new Map<string, number>()
  for (const value of values) {
    if (!value) continue
    counts.set(value, (counts.get(value) ?? 0) + 1)
  }
  if (counts.size < 2) return []
  return [...counts.keys()]
}

/** Tags on some rows, not all. A tag on every row cannot narrow the list. */
export function tagFacets(rows: ReadonlyArray<{ tags?: ReadonlyArray<{ id: number }> }>): number[] {
  const counts = new Map<number, number>()
  for (const row of rows) {
    for (const id of new Set((row.tags ?? []).map(tag => tag.id))) {
      counts.set(id, (counts.get(id) ?? 0) + 1)
    }
  }
  return [...counts.entries()]
    .filter(([, count]) => count > 0 && count < rows.length)
    .map(([id]) => id)
}
