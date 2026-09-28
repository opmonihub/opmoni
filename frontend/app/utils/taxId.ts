const CNPJ_PUNCTUATION = /[./-]/g

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
  return /^\d{14}$/.test(value.replace(CNPJ_PUNCTUATION, ''))
}

/** Caminho de entrada do CNPJ de uma empresa: consulta, digitação ou documento incompleto. */
export type CompanyTaxIdEntry = 'lookup' | 'manual' | 'incomplete'

/**
 * Decide como o CNPJ de uma empresa entra: `lookup` consulta a Receita, `manual`
 * segue com a razão social digitada (a fonte pública não conhece documento
 * alfanumérico) e `incomplete` ainda não é um documento.
 */
export function companyTaxIdEntry(value: string): CompanyTaxIdEntry {
  const normalized = value.replace(CNPJ_PUNCTUATION, '').toUpperCase()

  if (!/^[A-Z0-9]{14}$/.test(normalized)) return 'incomplete'

  return canLookupCnpj(value) ? 'lookup' : 'manual'
}

/**
 * A fonte pública de CNPJ responde sobre o CNPJ alfanumérico com um `404`, e o
 * backend trata esse `404` como cadastro digitado — não só para o documento
 * alfanumérico, mas para qualquer CNPJ que ela não conheça. Por isso o caminho
 * digitado existe para todo documento completo, e não apenas para o que não
 * pode ser consultado: com o critério anterior, o CNPJ numérico que o tier
 * gratuito não tem era impossível de cadastrar pela tela, embora a API o
 * aceitasse.
 */
export function canRegisterTypedCnpj(value: string): boolean {
  return companyTaxIdEntry(value) !== 'incomplete'
}

export function maskTaxId(value: string | null): string {
  const formatted = formatTaxId(value)
  return value?.length === 11 ? `***.${value.slice(3, 6)}.${value.slice(6, 9)}-**` : formatted
}
