// app/utils/fiscalClients.ts
//
// A visão fiscal por cliente: rótulos, caminho e CSV testados sem Vue.
//
// Módulo puro por construção — tipos com `import type` e extensão explícita,
// porque o runner de teste do Node o carrega por stripping nativo, sem bundler
// e sem resolver alias. O único runtime que entra aqui são os dois módulos
// puros da casa (`fiscalPresentation.ts` e `taxId.ts`), que o mesmo runner já
// carrega: a moeda, o dia e o CNPJ saem com a mesma formatação da tabela, e
// duas formatações de real na mesma tela divergem no primeiro centavo.
import type { FiscalClientDirection, FiscalClientSummary } from '../types/fiscal.ts'
import { fiscalClientCertificatePresentation, fiscalMissingValue, formatFiscalAmount, formatFiscalDay, modelLabel } from './fiscalPresentation.ts'
import { formatTaxId } from './taxId.ts'

/**
 * A direção do documento em relação ao dono: saída quando o emitente é o
 * próprio cliente, entrada em qualquer outro caso.
 *
 * Mapa `Record` sobre a união, e não ternário, pelo mesmo motivo de
 * `kindLabels` em `fiscalPresentation.ts`: `FiscalClientDirection` é uma lista
 * fechada, e um membro novo que caísse num ternário com padrão viraria "Saída"
 * sem erro de tipo e sem teste quebrado. O `Record` faz o compilador pedir a
 * palavra.
 */
const directionLabels: Record<FiscalClientDirection, string> = {
  saida: 'Saída',
  entrada: 'Entrada'
}

export function fiscalDirectionLabel(direction: FiscalClientDirection): string {
  return directionLabels[direction]
}

/**
 * A competência `YYYY-MM` escrita como `MM/AA`, sem montar data.
 *
 * A chave já é o mês, e montá-la como `new Date` jogaria o rótulo para o mês
 * anterior em qualquer fuso negativo — o mesmo defeito que `fiscalMonthLabel`
 * evita. Ausente é o traço do valor ausente; o que não é mês volta como veio,
 * porque inventar um rótulo para uma chave que o backend não explicou seria a
 * mesma mentira do zero no eixo.
 */
export function fiscalCompetenciaLabel(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return fiscalMissingValue
  const [year = '', month = ''] = value.split('-')
  if (!/^\d{4}$/.test(year) || !/^(0[1-9]|1[0-2])$/.test(month)) return value
  return `${month}/${year.slice(2)}`
}

/**
 * O número da nota com a série, ou o traço quando o backend não mandou.
 *
 * O backfill das colunas novas é `null` para o documento antigo: um zero ou um
 * texto vazio aqui seria um número de nota que o fisco nunca emitiu. A série
 * ausente não esconde o número — ela só não entra na barra.
 */
export function fiscalNumeroLabel(numero: string | null | undefined, serie: string | null | undefined): string {
  if (numero === null || numero === undefined || numero === '') return fiscalMissingValue
  return serie ? `${numero}/${serie}` : numero
}

/**
 * O caminho da tabela já filtrada pelo cliente.
 *
 * Sai daqui, e não de uma string montada na página, pelo mesmo motivo de
 * `fiscalDocumentosPath`: dois lugares fazendo a query à mão garantem duas
 * queries diferentes para o mesmo filtro.
 */
export function fiscalClientDocumentsPath(clientId: number): string {
  return `/fiscal/documentos?client_id=${clientId}`
}

/**
 * O nome do arquivo da planilha do cliente.
 *
 * A competência sai dos sete primeiros caracteres da última emissão
 * (`YYYY-MM` de um ISO), e nunca de `new Date`: fatiar o texto não depende de
 * fuso, e montar data para extrair mês é o defeito do rótulo acima em outra
 * roupa. Sem emissão, o sufixo diz isso em vez de sumir — um arquivo sem
 * competência no nome é um arquivo que o operador não sabe de quando é.
 */
export function fiscalClientCsvFileName(clientId: number, ultimaEmissaoAt: string | null | undefined): string {
  const competencia = (ultimaEmissaoAt ?? '').slice(0, 7)
  const [year = '', month = ''] = competencia.split('-')
  const valida = /^\d{4}$/.test(year) && /^(0[1-9]|1[0-2])$/.test(month)
  return valida
    ? `fiscal-cliente-${clientId}-${competencia}.csv`
    : `fiscal-cliente-${clientId}-sem-emissao.csv`
}

/**
 * Uma célula do CSV: cerca com aspas quando tem `;`, `"` ou quebra de linha,
 * dobrando a aspa interna — o mesmo acordo de `useClientListExport`.
 */
function csvCell(value: string): string {
  return /[;"\n\r]/.test(value) ? `"${value.replaceAll('"', '""')}"` : value
}

/**
 * A planilha do cliente, a partir do agregado já carregado — sem endpoint novo.
 *
 * Separador `;`, que é o padrão do produto (o Excel pt-BR só abre `,` com
 * assistente). O valor ausente é o traço, e não zero: um zero numa célula de
 * soma é uma medição que ninguém fez.
 */
export function fiscalClientCsv(summary: FiscalClientSummary): string {
  const header = [
    'Cliente',
    'CNPJ',
    'Total de documentos',
    'Saídas (qtd)',
    'Saídas (valor)',
    'Entradas (qtd)',
    'Entradas (valor)',
    'Por modelo',
    'Última emissão'
  ]

  const modelos = Object.entries(summary.por_modelo)
    .map(([model, total]) => `${modelLabel(model)}: ${total}`)
    .join(', ')

  const line = [
    summary.client.name,
    formatTaxId(summary.client.tax_id),
    String(summary.total),
    String(summary.saidas.qtd),
    formatFiscalAmount(summary.saidas.valor),
    String(summary.entradas.qtd),
    formatFiscalAmount(summary.entradas.valor),
    modelos === '' ? fiscalMissingValue : modelos,
    formatFiscalDay(summary.ultima_emissao_at)
  ]

  return [header, line].map(cells => cells.map(csvCell).join(';')).join('\r\n')
}

type FiscalClientsCsvColumn = {
  id: string
  headers: string[]
  values: (summary: FiscalClientSummary) => string[]
}

const fiscalClientsCsvColumns: FiscalClientsCsvColumn[] = [
  {
    id: 'cliente',
    headers: ['Cliente', 'CPF/CNPJ'],
    values: summary => [summary.client.name, formatTaxId(summary.client.tax_id)]
  },
  {
    id: 'certificado',
    headers: ['Certificado A1'],
    values: summary => [fiscalClientCertificatePresentation(summary.certificado_status).label]
  },
  {
    id: 'total',
    headers: ['Total de documentos'],
    values: summary => [String(summary.total)]
  },
  {
    id: 'saidas',
    headers: ['Saídas (qtd)', 'Saídas (valor)'],
    values: summary => [String(summary.saidas.qtd), formatFiscalAmount(summary.saidas.valor)]
  },
  {
    id: 'entradas',
    headers: ['Entradas (qtd)', 'Entradas (valor)'],
    values: summary => [String(summary.entradas.qtd), formatFiscalAmount(summary.entradas.valor)]
  },
  {
    id: 'modelos',
    headers: ['Por modelo'],
    values: (summary) => {
      const models = Object.entries(summary.por_modelo)
        .map(([model, total]) => `${modelLabel(model)}: ${total}`)
      return [models.length ? models.join(', ') : fiscalMissingValue]
    }
  },
  {
    id: 'ultima_emissao',
    headers: ['Última emissão'],
    values: summary => [formatFiscalDay(summary.ultima_emissao_at)]
  }
]

/**
 * A planilha da lista, respeitando as colunas que o operador deixou visíveis.
 *
 * Seleção e ações são controles da tabela, então não entram no arquivo. A
 * identidade do cliente continua exportando nome e CPF/CNPJ juntos, pois o
 * documento é a segunda linha visual da mesma coluna.
 */
export function fiscalClientsCsv(
  summaries: readonly FiscalClientSummary[],
  visibleColumnIds: readonly string[] = fiscalClientsCsvColumns.map(column => column.id)
): string {
  const columns = fiscalClientsCsvColumns.filter(column => visibleColumnIds.includes(column.id))
  const header = columns.flatMap(column => column.headers)
  const lines = summaries.map(summary => columns.flatMap(column => column.values(summary)))

  return [header, ...lines].map(cells => cells.map(csvCell).join(';')).join('\r\n')
}
