export function formatTaxId(value: string | null): string {
  if (!value) return 'Não informado'
  if (value.length === 11) return value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')
  return value.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')
}

export function maskTaxId(value: string | null): string {
  const formatted = formatTaxId(value)
  return value?.length === 11 ? `***.${value.slice(3, 6)}.${value.slice(6, 9)}-**` : formatted
}
