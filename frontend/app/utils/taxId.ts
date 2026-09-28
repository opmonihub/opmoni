export function formatTaxId(value: string | null): string {
  if (!value) return 'Não informado'
  if (value.length === 11) return value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')
  // Os doze primeiros caracteres do CNPJ aceitam letras (RFB IN 2.119/2022); os dois
  // dígitos verificadores continuam numéricos.
  return value.replace(/([A-Z0-9]{2})([A-Z0-9]{3})([A-Z0-9]{3})([A-Z0-9]{4})(\d{2})/i, '$1.$2.$3/$4-$5')
}

/**
 * A consulta pública de CNPJ é numérica: um documento alfanumérico se cadastra sem
 * lookup, porque a fonte pública não o conhece.
 */
export function canLookupCnpj(value: string): boolean {
  return /^\d{14}$/.test(value.replace(/[./-]/g, ''))
}

export function maskTaxId(value: string | null): string {
  const formatted = formatTaxId(value)
  return value?.length === 11 ? `***.${value.slice(3, 6)}.${value.slice(6, 9)}-**` : formatted
}
