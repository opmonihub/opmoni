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

/**
 * Uma edição no documento invalida a consulta anterior, e a comparação é sobre o
 * documento **normalizado**: `12.ABC.345/01DE-35` e `12ABC34501DE35` são o mesmo
 * CNPJ, e formatar o campo não pode jogar fora um `preview` que é a resposta
 * daquele documento.
 *
 * Sem esta distinção, o caminho de cadastro de CNPJ alfanumérico — consultar A,
 * voltar, trocar o campo para B e voltar para A — perderia a consulta que era
 * perfeitamente válida. Com ela, a edição que muda o documento é a única que
 * invalida, e trocar apenas os separadores não.
 *
 * A comparação é o que o modal usa para decidir se precisa soltar `preview`,
 * `step` e `typedName`: os três descrevem **um** documento consultado, e deixar
 * qualquer um deles vivo depois que o documento mudou é o que faz o cadastro
 * enviar o nome, o email e o regime do documento anterior sob o número do
 * seguinte — sem nenhum campo que acuse, porque `typedNameRequired` esconde a
 * razão social justamente quando há `preview`.
 */
export function taxIdEditInvalidatesLookup(previous: string, next: string): boolean {
  const normalize = (value: string) => value.replace(CNPJ_PUNCTUATION, '').toUpperCase()

  return normalize(previous) !== normalize(next)
}

export function maskTaxId(value: string | null): string {
  const formatted = formatTaxId(value)
  return value?.length === 11 ? `***.${value.slice(3, 6)}.${value.slice(6, 9)}-**` : formatted
}
