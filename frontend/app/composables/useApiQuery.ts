export function queryOf(params: object): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(params)
      .filter(([, v]) => v !== null && v !== undefined && v !== '')
      .map(([k, v]) => [Array.isArray(v) ? `${k}[]` : k, v])
  )
}
