/** Localize read-only canonical decimals without rounding or changing stored values. */
export function formatErpDecimalDisplay(
  value: string | number | null | undefined,
  options?: { minimumFractionDigits?: number }
): string {
  if (value === null || value === undefined || value === '')
    return '—'

  const original = String(value)
  const match = /^(-?)(0|[1-9]\d*)(?:\.(\d+))?$/.exec(original)
  if (!match)
    return original

  const minimum = options?.minimumFractionDigits ?? 0
  const integer = match[2]!.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  const fraction = (match[3] ?? '').replace(/0+$/, '').padEnd(minimum, '0')
  return `${match[1]}${integer}${fraction ? `,${fraction}` : ''}`
}
