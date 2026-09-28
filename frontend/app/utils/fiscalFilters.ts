// app/utils/fiscalFilters.ts
import type {
  FiscalKind,
  FiscalListFilters,
  FiscalModel,
  FiscalPerPage,
  FiscalSort,
  FiscalSortDirection
} from '../types/fiscal.ts'

/**
 * A URL da tabela de documentos, dos dois lados: o que o operador filtra vira
 * query, e a query vira filtro.
 *
 * A barra de filtro aqui é **derivada** e não a fonte: o `fiscal/documentos.vue`
 * lê `route.query` e escreve com `navigateTo`, e é por isso que um filtro
 * sobrevive a um F5 e a uma cola para outra aba. Um estado de filtro que só
 * existisse dentro da tela seria uma tela que o operador não consegue
 * compartilhar nem recarregar.
 *
 * Módulo puro por construção — só `import type`, com a extensão explícita,
 * porque o runner de teste do Node o carrega por stripping nativo, sem bundler
 * e sem resolver alias. É o mesmo contrato de `fiscalPresentation.ts`.
 */

/** O enum do backend, fechado. `model` fora daqui é 422, não "sem filtro". */
const models: readonly FiscalModel[] = ['nfe', 'nfce', 'cte', 'nfse']
const kinds: readonly FiscalKind[] = ['document', 'event']
const sorts: readonly FiscalSort[] = ['emissao_at', 'valor_total', 'captured_at']
const directions: readonly FiscalSortDirection[] = ['asc', 'desc']
const pages: readonly FiscalPerPage[] = [25, 50, 100]

/**
 * O modelo é um destes.
 *
 * A lista de modelos do backend é uma iteração do que chegou, e um build novo
 * recebe um modelo que este ainda não conhece. Guardar é o que impede esse
 * valor de virar um chip ou um link com `?model=` — que a API recusa com 422.
 */
export function isFiscalModel(value: unknown): value is FiscalModel {
  return typeof value === 'string' && (models as readonly unknown[]).includes(value)
}

/**
 * O que a API faz quando a chave não vem.
 *
 * Estes quatro são o padrão calado de `FiscalDocuments::sorted()` e
 * `::porPagina()` no backend, e a tela precisa conhecê-los para dois motivos
 * concretos: o cabeçalho da coluna ordenada mostra a seta que a lista realmente
 * tem, e `page=1` não polui a URL que o operador vai colar para o colega. A
 * tela não inventa um quarto valor — quando a URL traz um, ele manda; quando
 * não traz, o padrão é o do backend, dito aqui para poder ser conferido.
 */
const defaultSort: FiscalSort = 'emissao_at'
const defaultDirection: FiscalSortDirection = 'desc'
const defaultPerPage: FiscalPerPage = 25
const defaultPage = 1

/** Um valor de query é uma string, uma lista delas, ou nada. */
function segments(value: unknown): string[] {
  if (Array.isArray(value)) return value.flatMap(item => segments(item))
  return typeof value === 'string' ? [value] : []
}

/**
 * O primeiro valor da query que está na lista fechada — e o **valor da lista**,
 * não o texto da URL, porque `per_page` é número e a query chega como string.
 * Comparar por texto (`[25, 50, 100].includes('50')` é falso) faria a tela cair
 * no padrão de 25 enquanto a URL pede 50.
 */
function oneOf<T extends string | number>(value: unknown, allowed: readonly T[]): T | undefined {
  const [first] = segments(value)
  return first === undefined ? undefined : allowed.find(item => String(item) === first)
}

/**
 * O CNPJ do emitente e do destinuatário é prefixo em dígitos, porque é o que o
 * operador digita quando lembra do começo dele. Tudo que não é dígito é
 * descartado aqui, e não no backend: `?issuer=12a` é 422 lá, e um 422 em cima de
 * um campo que o operador preencheu é a pior resposta possível — a barra parece
 * aplicada e a lista some.
 */
function cnpjPrefix(value: unknown): string | undefined {
  const digits = segments(value).join('').replace(/\D/g, '').slice(0, 14)
  return digits || undefined
}

/** `Y-m-d` que existe no calendário — `2026-02-31` é 422, não dia. */
function isoDate(value: unknown): string | undefined {
  const [text] = segments(value)
  if (text === undefined || !/^\d{4}-\d{2}-\d{2}$/.test(text)) return undefined
  const [year, month, day] = text.split('-').map(Number)
  const date = new Date(Date.UTC(year!, month! - 1, day!))
  const real = date.getUTCFullYear() === year
    && date.getUTCMonth() === month! - 1
    && date.getUTCDate() === day
  return real ? text : undefined
}

function wholeNumber(value: unknown): number | undefined {
  const [text] = segments(value)
  if (text === undefined || !/^-?\d+$/.test(text)) return undefined
  const parsed = Number(text)
  return Number.isSafeInteger(parsed) ? parsed : undefined
}

/**
 * O valor do documento é número, e zero é um valor — mas string vazia não é.
 *
 * `Number('')` é 0, e um `?amount_min=` vazio viraria `amount_min=0`: um filtro
 * que não muda a resposta aparecendo na URL, com a barra parecendo aplicada por
 * causa dele. O mesmo número que o backend valida (`min:0`) decide o resto.
 */
function amount(value: unknown): number | undefined {
  const [text] = segments(value)
  if (text === undefined || text.trim() === '') return undefined
  const parsed = Number(text)
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : undefined
}

/**
 * Os filtros da tabela, lidos da query.
 *
 * Devolve os quatro valores de paginação e ordenação **sempre**, mesmo sem a
 * chave na URL, e devolve o resto só quando a query diz algo que a API aceitaria.
 * O ponto é que quem chama nunca precisa saber o padrão: ele lê `filters.sort` e
 * `filters.page` e os dois significam a mesma coisa na primeira visita e na
 * décima.
 */
export function parseFiscalFilters(query: Record<string, unknown>): FiscalListFilters {
  const selected = segments(query.model).filter(isFiscalModel)

  const filters: FiscalListFilters = {
    page: Math.max(defaultPage, wholeNumber(query.page) ?? defaultPage),
    per_page: oneOf(query.per_page, pages) ?? defaultPerPage,
    sort: oneOf(query.sort, sorts) ?? defaultSort,
    direction: oneOf(query.direction, directions) ?? defaultDirection
  }

  const model = [...new Set(selected)]
  if (model.length) filters.model = model

  const clientId = wholeNumber(query.client_id)
  if (clientId !== undefined && clientId > 0) filters.client_id = clientId

  const issuer = cnpjPrefix(query.issuer)
  if (issuer) filters.issuer = issuer

  const recipient = cnpjPrefix(query.recipient)
  if (recipient) filters.recipient = recipient

  const kind = oneOf(query.kind, kinds)
  if (kind) filters.kind = kind

  const issuedFrom = isoDate(query.issued_from)
  if (issuedFrom) filters.issued_from = issuedFrom

  const issuedTo = isoDate(query.issued_to)
  if (issuedTo) filters.issued_to = issuedTo

  const amountMin = amount(query.amount_min)
  if (amountMin !== undefined) filters.amount_min = amountMin

  const amountMax = amount(query.amount_max)
  if (amountMax !== undefined) filters.amount_max = amountMax

  return filters
}

/**
 * O caminho `/fiscal/documentos`, com os filtros já na query.
 *
 * É o que o painel usa para mandar o operador ao modelo que ele acabou de
 * acender, e é a mesma query que a barra escreve — dois lugares fazendo a
 * string à mão garantiriam duas queries diferentes para o mesmo filtro.
 */
export function fiscalDocumentosPath(filters: FiscalListFilters = {}): string {
  const search = fiscalQuery(filters)
  const parts: string[] = []

  for (const [key, value] of Object.entries(search)) {
    for (const item of Array.isArray(value) ? value : [value]) {
      parts.push(`${key}=${encodeURIComponent(item)}`)
    }
  }

  return parts.length ? `/fiscal/documentos?${parts.join('&')}` : '/fiscal/documentos'
}

/**
 * O filtro como query, com o padrão do backend omitido.
 *
 * Só entra o que muda a resposta: valor vazio, `null` e o padrão calado ficam
 * de fora, para que a URL seja a lista sem filtro — a mesma que o operador recebe
 * ao abrir a tela pela primeira vez. É a mesma regra que o leitor acima aplica,
 * e por isso a ida e a volta não inventam chave: nada entra na URL que o leitor
 * não reconheceria de volta.
 */
export function fiscalQuery(filters: FiscalListFilters): Record<string, string | string[]> {
  const query: Record<string, string | string[]> = {}

  if (filters.model?.length) query.model = [...new Set(filters.model)]

  if (filters.client_id) query.client_id = String(filters.client_id)
  if (filters.issuer) query.issuer = filters.issuer
  if (filters.recipient) query.recipient = filters.recipient
  if (filters.kind) query.kind = filters.kind
  if (filters.issued_from) query.issued_from = filters.issued_from
  if (filters.issued_to) query.issued_to = filters.issued_to
  if (filters.amount_min !== undefined && filters.amount_min !== null) query.amount_min = String(filters.amount_min)
  if (filters.amount_max !== undefined && filters.amount_max !== null) query.amount_max = String(filters.amount_max)

  if (filters.sort && filters.sort !== defaultSort) query.sort = filters.sort
  if (filters.direction && filters.direction !== defaultDirection) query.direction = filters.direction
  if (filters.page && filters.page !== defaultPage) query.page = String(filters.page)
  if (filters.per_page && filters.per_page !== defaultPerPage) query.per_page = String(filters.per_page)

  return query
}

/**
 * Os modelos que o filtro de modelo oferece: os da consulta, mais os que o
 * operador já tinha selecionado.
 *
 * O primeiro grupo é o `available_models` da API, e ele é o **resultado
 * inteiro**, não a página: o backend tira o distinct da consulta filtrada antes
 * de paginar justamente para isso. Uma versão que lesse as linhas da tela
 * trocaria o filtro por um estado de paginação — na página 3 de uma lista só de
 * NF-e o chip de CT-e desapareceria, sendo que o CT-e está no resultado e é um
 * filtro legal. A lista de chips é propriedade da consulta, e a paginação não
 * entra nela.
 *
 * O segundo grupo é o que o backend também faz e é o que impede a tela de
 * esconder a única saída de um filtro que esvaziou a tabela. Um `Set` resolve os
 * dois: o mesmo modelo vindo da consulta e da URL é a mesma palavra.
 *
 * A ordem é a da consulta. O guard de valor desconhecido fica em quem chama,
 * com o `isFiscalModel` daqui: um modelo novo de um backend mais novo não pode
 * virar chip, porque o chip vira `?model=` e a API responde 422.
 */
export function availableFiscalModels(
  models: readonly FiscalModel[],
  selected: readonly FiscalModel[] = []
): FiscalModel[] {
  const options = new Set<FiscalModel>(models)

  for (const model of selected) options.add(model)

  return [...options]
}
