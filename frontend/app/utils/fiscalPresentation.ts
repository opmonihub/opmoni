import type {
  FiscalAttentionItem,
  FiscalAttentionReason,
  FiscalCoverage,
  FiscalKind,
  FiscalLastCapture,
  FiscalSource,
  FiscalStage,
  FiscalSummary
} from '../types/fiscal.ts'

/**
 * A apresentação do painel fiscal: os quatro estados da carteira, os nove
 * motivos de atenção e as leituras que o resumo devolve.
 *
 * Módulo puro por construção — só `import type`, com a extensão explícita,
 * porque o runner de teste do Node o carrega por stripping nativo, sem bundler
 * e sem resolver alias. Um import de runtime aqui (de um pacote, ou de outro
 * util) quebraria a única forma de testar a parte do painel que é decisão, e não
 * marcação.
 */

/** A severidade que a tela pinta. Não é o tom do cliente, é o do motivo. */
export type FiscalTone = 'neutral' | 'success' | 'warning' | 'error'

/**
 * O que a leitura ausente vale na tela.
 *
 * O painel do monitoramento escreve a mesma coisa pelo mesmo motivo: "o resumo
 * não disse" não pode virar célula em branco nem zero, porque os dois seriam uma
 * afirmação sobre o escritório que ninguém mediu.
 */
export const fiscalMissingValue = '—'

/**
 * Os quatro estados da carteira, e não um.
 *
 * Um painel fiscal tem um trabalho só: dizer, com honestidade, se a carteira
 * está sendo capturada — antes de falar de volume. Os quatro casos medem coisas
 * diferentes e nenhum deles é o zero do outro:
 *
 * - `no_clients` — a carteira não tem cliente. O conserto é cadastrar.
 * - `no_capturable` — tem cliente e nenhum tem A1 utilizável. O conserto é
 *   certificado, e a lista de atenção diz de quem.
 * - `no_documents` — dá para consultar e ainda não veio nada. É o primeiro dia.
 * - `with_documents` — há volume para ler.
 *
 * A tela que funde os três primeiros num "vazio" é a que não pode existir: ela
 * diz "não há o que capturar" para quem precisa de uma ação e para quem só
 * precisa de esperar, e a spec é explícita sobre isso.
 */
export type FiscalCoverageState = 'no_clients' | 'no_capturable' | 'no_documents' | 'with_documents'

/** O estado da carteira que o painel vai pintar. */
export function coverageState(summary: Pick<FiscalSummary, 'coverage' | 'documents'>): FiscalCoverageState {
  if (summary.coverage.total === 0) return 'no_clients'
  if (summary.coverage.capturable === 0) return 'no_capturable'
  return summary.documents.total === 0 ? 'no_documents' : 'with_documents'
}

type FiscalStateWithoutDocuments = Exclude<FiscalCoverageState, 'with_documents'>

type StateCopy = { title: string, description: string, icon: string, tone: FiscalTone }

/**
 * A frase de cada estado sem documento, no caso em que não há ninguém em
 * atenção.
 *
 * Só três, porque `with_documents` não tem o que anunciar. `no_clients` fica
 * igual nos dois casos — sem cliente não há de onde vir atenção, e a frase não
 * faz nenhuma afirmação que uma lista pudesse contradizer.
 *
 * Nenhuma delas afirma nada sobre a operação. A tentação era o contrário — "a
 * captura está no ar, o primeiro lote aparece na próxima consulta" — e ela mente
 * no primeiro dia: um cliente novo com A1 válido, ainda sem cursor, devolve
 * capturable 1, nenhum documento, lista vazia e `last_capture` null, e o cartão
 * de última consulta diz, na mesma tela, que a conta **nunca** consultou o
 * fisco. Quem diz se a conta já consultou é esse cartão; o estado diz o que
 * existe.
 */
const stateCopy: Record<FiscalStateWithoutDocuments, StateCopy> = {
  no_clients: {
    title: 'Nenhum cliente na carteira',
    description: 'Não há cliente cadastrado para consultar. A carteira do escritório começa na tela de clientes.',
    icon: 'i-lucide-users',
    tone: 'neutral'
  },
  no_capturable: {
    title: 'Nenhum cliente capturável',
    description: 'Nenhum cliente desta carteira tem certificado A1 utilizável hoje. O motivo de cada um está na lista de atenção.',
    icon: 'i-lucide-shield-off',
    tone: 'warning'
  },
  no_documents: {
    title: 'Ainda sem documentos capturados',
    description: 'Nenhum documento foi capturado até agora nesta conta, então não há volume por modelo nem série de emissão para mostrar.',
    icon: 'i-lucide-inbox',
    tone: 'neutral'
  }
}

/**
 * A frase que **exige** haver atenção para ser verdadeira.
 *
 * `no_documents` e só ele, e a variante é um acréscimo, não uma correção: a
 * frase de base já é verdadeira nos dois casos, e com a lista cheia o cartão
 * ganha um endereço — a lista de baixo é quem diz por quê.
 *
 * O que esta frase não pode fazer é nomear o porquê, e é a armadilha: os cinco
 * motivos de captura nomeiam mecanismos muito diferentes, e nem todos impedem a
 * consulta. `continuity_warning` é um cliente com A1 válido, `last_seen_at` de
 * 46 dias e uma consulta que não trouxe nada — o backend o mantém na cobertura
 * de propósito, e a lista de baixo convida a voltar a capturar. Um cartão
 * dizendo que "há clientes que impedem a consulta de rodar" seria a segunda
 * mentira no mesmo item: uma por dizer que a consulta roda, outra por dizer que
 * não pode. O cartão responde em que estado a carteira está; a lista responde
 * por que aquele cliente está assim.
 */
const stateCopyWithAttention: Partial<Record<FiscalStateWithoutDocuments, StateCopy>> = {
  no_documents: {
    title: 'Ainda sem documentos capturados',
    description: 'Nenhum documento foi capturado até agora nesta conta, e a lista de atenção abaixo diz por que nada chegou ainda.',
    icon: 'i-lucide-inbox',
    tone: 'warning'
  }
}

/**
 * A frase que **exige** não haver atenção para ser verdadeira — o par espelhado
 * do mapa acima.
 *
 * `no_capturable` mandava o operador à lista de atenção. Com a lista vazia, a
 * tela mostrava "o motivo de cada um está na lista" em cima de "nenhum cliente
 * precisa de ação", e cada uma das duas frases é falsa diante da outra. A
 * derivação do backend torna o par impossível hoje — e é por isso que ele
 * importa: uma tela que só se sustenta porque o dado não pode chegar é uma
 * tela esperando o dia em que ele mudar. A frase de agora diz as duas metades
 * da verdade, inclusive a que é um buraco no dado.
 */
const stateCopyWithoutAttention: Partial<Record<FiscalStateWithoutDocuments, StateCopy>> = {
  no_capturable: {
    title: 'Nenhum cliente capturável',
    description: 'Nenhum cliente desta carteira tem certificado A1 utilizável hoje, e a lista de atenção abaixo não traz motivo para nenhum deles.',
    icon: 'i-lucide-shield-off',
    tone: 'warning'
  }
}

/**
 * A frase do estado, qualificada por haver ou não atenção.
 *
 * A decisão continua com quatro valores — é o contrato que a próxima tela usa e
 * que o resumo entrega. O que muda é que a frase não pode afirmar nada que o
 * resto do payload contradiga, e nem nada que a lista de atenção vai explicar
 * por ela: o cartão é o estado, a lista é a causa, e as duas metades da tela
 * escrevem sobre a mesma carteira.
 *
 * `with_documents` não entra: não há o que anunciar quando há o que ler, e a
 * página não desenha esse cartão.
 */
export function fiscalStateCopy(state: FiscalStateWithoutDocuments, hasAttention: boolean): StateCopy {
  if (hasAttention) return stateCopyWithAttention[state] ?? stateCopy[state]
  return stateCopyWithoutAttention[state] ?? stateCopy[state]
}

/**
 * A participação capturável da carteira, ou `null` quando não há carteira.
 *
 * `null` e não `0`: 0 de 0 é uma divisão que ninguém fez, e o painel que a
 * desenhasse estaria afirmando que a carteira inteira está coberta — ou
 * descoberta — a partir de um número que o resumo nunca mandou. O zero verdadeiro
 * (0 de 4) sai com o total do lado, e é uma medida.
 */
export function coverageShare(coverage: FiscalCoverage): { total: number, capturable: number, percent: number } | null {
  if (coverage.total === 0) return null
  const percent = Math.min(100, Math.round((coverage.capturable / coverage.total) * 100))
  return { total: coverage.total, capturable: coverage.capturable, percent }
}

/** A mesma contagem do monitoramento, com o mesmo formato. */
export function formatFiscalCount(value: number): string {
  return new Intl.NumberFormat('pt-BR').format(value)
}

/**
 * O instante, no formato do produto.
 *
 * `null` e um texto que o cliente não sabe ler dão o traço do valor ausente, e
 * não a data de hoje: uma coluna de "quando rodou" que mostra agora para um
 * campo vazio mente sobre a última execução.
 */
export function formatFiscalDateTime(value: string | null | undefined): string {
  if (!value) return fiscalMissingValue
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return fiscalMissingValue
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(date)
}

/**
 * O dia da emissão, sem hora.
 *
 * A hora da emissão não é informação do documento, e a tabela de documentos tem
 * uma coluna só para isso. O fuso é fixo em UTC pelos dois lados pelo mesmo
 * motivo de `formatFiscalDateTime`: SSR e cliente precisam pintar o mesmo dia,
 * senão o Vue reclama do texto na hidratação.
 */
const fiscalDay = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'UTC' })

export function formatFiscalDay(value: string | null | undefined): string {
  if (!value) return fiscalMissingValue
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? fiscalMissingValue : fiscalDay.format(date)
}

/**
 * O valor do documento em reais, a partir do texto decimal que a API entrega.
 *
 * `valor_total` é `decimal(14,2)` com cast `decimal:2`, e por isso chega como
 * `"55.55"`: um float de JSON não representa `0,01`. Aqui é só parse para
 * exibição — nenhuma aritmética, porque somar em cents sobre float volta a errar
 * no centavo que o texto existe para preservar. Vazio e texto ilegível são o
 * traço do valor ausente, e não zero.
 */
const fiscalCurrency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })

export function formatFiscalAmount(value: string | null | undefined): string {
  if (value === null || value === undefined || value.trim() === '') return fiscalMissingValue
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? fiscalCurrency.format(parsed) : fiscalMissingValue
}

/**
 * A contagem de eventos da linha, com o zero dito por extenso.
 *
 * O número conta as próprias linhas de evento da chave de acesso, sem
 * autoexclusão, e é o mesmo que o detalhe repete para a mesma chave: subtrair um
 * aqui colocaria dois números diferentes para a mesma chave na mesma tela. Zero é
 * "sem eventos" escrito, porque uma célula vazia parece um dado que não chegou.
 */
export function fiscalEventCount(row: { event_count: number }): string {
  return row.event_count === 0 ? 'Sem eventos' : formatFiscalCount(row.event_count)
}

/**
 * O que a linha é: o documento autorizado ou o evento que o acompanha.
 *
 * Mapa `Record` sobre o tipo, e não ternário, pelo mesmo motivo de
 * `attentionCopy`: `FiscalKind` é uma lista fechada, e um membro novo que caísse
 * num ternário com padrão viraria "Documento" em toda linha nova sem erro de
 * tipo e sem teste quebrado. O `Record` faz o compilador pedir a palavra.
 */
const kindLabels: Record<FiscalKind, string> = {
  document: 'Documento',
  event: 'Evento'
}

export function fiscalKindLabel(kind: FiscalKind): string {
  return kindLabels[kind]
}

/**
 * A etapa da distribuição que entregou a linha.
 *
 * É vocabulário do fisco, e o operador do escritório não o conhece: o resumo é
 * o documento autorizado e o evento que o acompanha, e não "stage document".
 *
 * `Record` pelo mesmo motivo de `kindLabels`: a etapa é uma união fechada, e o
 * padrão de um ternário decorava a terceira etapa de "Documento autorizado" sem
 * que ninguém visse.
 */
const stageLabels: Record<FiscalStage, string> = {
  summary: 'Resumo da distribuição',
  document: 'Documento autorizado',
  event: 'Evento autorizado'
}

export function fiscalStageLabel(stage: FiscalStage): string {
  return stageLabels[stage]
}

/**
 * A origem de uma distribuição, pelo nome que o fisco usa.
 *
 * Aceita `null` porque o Resource escreve `$this->source?->value`, e o contrato
 * em `types/fiscal.ts` espelha a expressão — não a intenção de quem a escreveu.
 * O `null` sai como o traço do valor ausente, e não como `undefined`, que é o que
 * um `sourceLabels[null]` devolve e o que a tela pintaria no lugar de uma fonte.
 */
const sourceLabels: Record<FiscalSource, string> = {
  nfe_distribuicao: 'NF-e',
  cte_distribuicao: 'CT-e'
}

export function fiscalSourceLabel(source: FiscalSource | null): string {
  return source === null ? fiscalMissingValue : sourceLabels[source]
}

/**
 * Os nove motivos, cada um com a ação que o operador tem pela frente.
 *
 * A lista é o que `FiscalAttentionReason` promete: nove, e cada uma nomeando um
 * passo diferente. Os quatro primeiros tiram o cliente da cobertura porque sem
 * A1 não existe consulta; os cinco últimos descrevem a operação de um cliente
 * que conta como capturável — por isso o rótulo de `capture_blocked` não fala de
 * certificado, e o conserto que o painel ofereceria ali não resolveria nada.
 *
 * `history_interrupted` e `continuity_warning` são o par que mais importa: as
 * duas descrevem uma captura parada, e só uma delas se resolve voltando a
 * capturar.
 */
type AttentionCopy = { label: string, description: string, icon: string, tone: FiscalTone }

const attentionCopy: Record<FiscalAttentionReason, AttentionCopy> = {
  certificate_absent: {
    label: 'Envie o certificado A1',
    description: 'Não há certificado A1 cadastrado para este cliente. Sem ele nenhuma consulta ao fisco acontece, e nada mais sobre a captura dele é observável.',
    icon: 'i-lucide-file-x',
    tone: 'warning'
  },
  certificate_expired: {
    label: 'Reenvie o certificado A1 vencido',
    description: 'A validade do certificado passou, e captura não roda com certificado vencido. Reenviar o arquivo conserta a validade e, de quebra, a senha que estiver faltando.',
    icon: 'i-lucide-calendar-x',
    tone: 'error'
  },
  certificate_password_missing: {
    label: 'Cadastre a senha do certificado',
    description: 'O certificado é válido, mas a senha dele não foi guardada. Sem a senha o arquivo não abre, e a captura deste cliente não sai.',
    icon: 'i-lucide-key-round',
    tone: 'warning'
  },
  certificate_reupload: {
    label: 'Reenvie o certificado',
    description: 'A senha guardada não abre o certificado — a cifra pode ter se perdido numa troca de chave da aplicação. O conserto é reenviar o arquivo com a senha certa.',
    icon: 'i-lucide-file-key-2',
    tone: 'error'
  },
  gap_abandoned: {
    label: 'Recuperação esgotada',
    description: 'O fisco entregou uma posição e a busca dela acabou. O documento daquela posição não existe aqui e nenhuma captura futura o traz: ele está perdido e o cliente precisa ser avisado.',
    icon: 'i-lucide-file-search',
    tone: 'error'
  },
  history_interrupted: {
    label: 'Histórico interrompido',
    // Depois de 60 dias sem consulta o serviço para de gerar posições para este
    // CNPJ e não as recupera depois. A frase não promete o que a próxima
    // execução não pode cumprir: voltar a capturar é o que segue funcionando, e
    // o período não volta.
    description: 'A captura parou por mais de 60 dias. O fisco não gera posições retroativas, então o período perdido não pode ser recuperado continuando a captura.',
    icon: 'i-lucide-history',
    tone: 'error'
  },
  capture_blocked: {
    label: 'Captura bloqueada pelo fisco',
    // Sem a palavra "certificado" e de propósito: o backend é explícito que o
    // conserto que o painel ofereceria aqui não resolveria nada, porque o A1
    // está de pé e o que para é a janela do CNPJ. Dizer "o certificado está de
    // pé" seria o mesmo convite com mais palavras — o operador lê o termo que
    // aparece e vai atrás dele.
    description: 'O fisco bloqueou este CNPJ por consumo indevido, e a consulta volta sozinha quando a janela terminar. O tempo que falta está no item.',
    icon: 'i-lucide-ban',
    tone: 'warning'
  },
  continuity_warning: {
    label: 'Captura parada há muito tempo',
    description: 'A última consulta bem-sucedida já está perto da janela que interrompe o histórico. Ainda dá para voltar a consultar sem perder nada — vale retomá-la agora.',
    icon: 'i-lucide-clock-alert',
    tone: 'warning'
  },
  capture_failed: {
    label: 'A captura falhou',
    description: 'A última consulta terminou em erro. A próxima execução tenta de novo; se o erro persistir, ele fica registrado aqui para o escritório investigar.',
    icon: 'i-lucide-circle-alert',
    tone: 'warning'
  }
}

/**
 * A precedência com que os motivos aparecem na tela.
 *
 * A mesma ordem de `FiscalCoverage::motivo()`: do que impede a consulta de
 * existir ao que se resolve sozinho. O backend entrega a lista em ordem
 * alfabética de nome e deixa o agrupamento com o painel — a ordem do backend
 * não separa o urgente do tranquilo, e uma lista inteira em ordem alfabética
 * obriga o operador a procurar o que importa.
 */
const attentionOrder: readonly FiscalAttentionReason[] = [
  'certificate_absent',
  'certificate_expired',
  'certificate_password_missing',
  'certificate_reupload',
  'gap_abandoned',
  'history_interrupted',
  'capture_blocked',
  'continuity_warning',
  'capture_failed'
]

/**
 * O texto de um motivo, e o que acontece com um motivo que este módulo não
 * conhece.
 *
 * O tipo é fechado de propósito, e o painel é escrito para o contrato de hoje.
 * Ainda assim, um décimo motivo vindo do backend não pode derrubar a tela
 * inteira — um `attentionCopy[reason].label` em `undefined` levaria o SSR
 * inteiro junto. O desconhecido cai no próprio código, que é a única coisa que
 * o operador consegue comparar com o que a API mandou, e é o mesmo caminho que
 * `monitoringAttentionReasonPresentation` já faz no outro módulo.
 */
function copyOf(reason: FiscalAttentionReason): AttentionCopy {
  return attentionCopy[reason] ?? {
    label: reason,
    description: 'Este motivo ainda não tem um texto próprio no painel. O código veio do resumo da API.',
    icon: 'i-lucide-circle-help',
    tone: 'warning'
  }
}

/** A ação que o motivo pede, no cabeçalho do grupo. */
export function attentionLabel(reason: FiscalAttentionReason): string {
  return copyOf(reason).label
}

/** A frase que explica o motivo, sem instruir nada além do que é verdade. */
export function attentionDescription(reason: FiscalAttentionReason): string {
  return copyOf(reason).description
}

export function attentionIcon(reason: FiscalAttentionReason): string {
  return copyOf(reason).icon
}

export function attentionTone(reason: FiscalAttentionReason): FiscalTone {
  return copyOf(reason).tone
}

/**
 * A lista de atenção agrupada por motivo, na precedência acima.
 *
 * Lista vazia é lista vazia: o painel desenha o estado "ninguém precisa de
 * ação" e não um número de grupos, porque um zero de grupos é uma contagem que
 * ele mesmo inventou para não ficar sem o que mostrar.
 *
 * A ordem dentro do grupo é a do backend, que já vem estável entre duas
 * chamadas — reordenar por nome aqui seria jogar fora a garantia.
 */
export function attentionGroups(items: readonly FiscalAttentionItem[]): { reason: FiscalAttentionReason, items: FiscalAttentionItem[] }[] {
  const porMotivo = new Map<FiscalAttentionReason, FiscalAttentionItem[]>()

  for (const item of items) {
    const grupo = porMotivo.get(item.reason)
    if (grupo) grupo.push(item)
    else porMotivo.set(item.reason, [item])
  }

  const conhecidos = attentionOrder.filter(reason => porMotivo.has(reason))
  const desconhecidos = [...porMotivo.keys()].filter(reason => !attentionOrder.includes(reason))

  return [...conhecidos, ...desconhecidos].map(reason => ({ reason, items: porMotivo.get(reason) ?? [] }))
}

/**
 * O aviso de captura enfileirada, e o que ele pode afirmar.
 *
 * O detalhe do documento não tem campo de cobertura: ele sabe o cliente e a
 * distribuição, e nada sobre se aquele cliente tem certificado utilizável agora.
 * Por isso o texto do toast é o que a casa tem de honesto aqui — ele afirma
 * **o que o pedido fez** (entrou na fila, para esta distribuição, deste cliente)
 * e não **o que o lote vai trazer**. A versão anterior fechava com "os
 * documentos chegam na tabela quando o lote terminar", e para um cliente cujo
 * motivo de atenção é `certificate_absent` isso é falso: não há certificado para
 * autenticar a consulta, o lote termina e nada chega. Um toast que promete o
 * documento é a mesma mentira do cartão de estado, em outra tela.
 *
 * Onde o operador descobre se o cliente era capturável é a lista de atenção do
 * painel, e é para lá que a frase aponta — em vez de inventar o motivo, que o
 * detalhe não carrega.
 */
export function captureQueuedCopy(source: FiscalSource | null): { title: string, description: string } {
  const fonte = source === null ? '' : ` de ${fiscalSourceLabel(source)}`

  return {
    title: 'Captura enfileirada',
    description: `A consulta${fonte} deste cliente entrou na fila. O que o lote encontrar aparece na tabela quando ele terminar — e a lista de atenção do painel diz o que está impedindo a consulta deste cliente, se algo estiver.`
  }
}

/**
 * O que o painel diz quando a lista de atenção está vazia.
 *
 * A frase nomeia o que foi conferido e não o que a carteira está fazendo. Um
 * "está rodando sozinha" seria uma afirmação sobre a operação que a lista vazia
 * não prova — a mesma lista está vazia numa carteira com zero cliente, e aí a
 * frase estaria descrevendo um porte que não existe.
 */
export const fiscalNoAttention: { title: string, description: string, icon: string } = {
  title: 'Nenhum cliente precisa de ação',
  description: 'Nenhum cliente desta conta está sem certificado, com certificado vencido, senha faltando, ou com captura parada, bloqueada ou em falha.',
  icon: 'i-lucide-circle-check'
}

/**
 * Quanto falta da janela de bloqueio, em português.
 *
 * `null` quando o item não é um bloqueio — o backend manda `blocked_until` só
 * em `capture_blocked`, e a frase de quem não tem hora de fim não pode ser a
 * mesma de quem tem. Para quem tem, o tempo que resta é a informação: dizer
 * "bloqueado" sem dizer até quando deixa o operador adivinhar se pode esperar.
 *
 * O `now` chega por parâmetro e a função não tem relógio próprio, de propósito:
 * quem tem o dado é quem decide a partir de quando ele vale, e a única coisa que
 * a tela pode fazer de errado com essa data é contar a janela com o relógio
 * velho. A página reancora esse relógio a cada resposta, dentro do handler da
 * busca — e `tests/fiscalPresentation.test.ts` lê o texto do SFC para garantir
 * que a reancoragem continua ali, porque nenhum teste deste arquivo alcançaria
 * uma linha dentro de um `.vue`.
 */
export function blockedRemaining(blockedUntil: string | null | undefined, now: Date): string | null {
  if (!blockedUntil) return null

  const until = new Date(blockedUntil)
  if (Number.isNaN(until.getTime())) return null

  const minutes = Math.round((until.getTime() - now.getTime()) / 60_000)
  if (minutes <= 0) return 'a janela já passou'

  const days = Math.floor(minutes / 1440)
  const hours = Math.floor((minutes % 1440) / 60)
  const restMinutes = minutes % 60

  if (days > 0) {
    const partes = [`${days} ${days === 1 ? 'dia' : 'dias'}`]
    if (hours > 0) partes.push(`${hours} h`)
    return `faltam ${partes.join(' e ')}`
  }

  if (hours > 0) {
    return restMinutes > 0 ? `faltam ${hours} h ${restMinutes} min` : `faltam ${hours} h`
  }

  return `faltam ${restMinutes} min`
}

/**
 * Os nomes de modelo que o produto já usa, e a queda para o que não conhece.
 *
 * O mapa de volume vem só com o modelo que tem documento, então a lista de
 * modelos é uma iteração do que chegou — nunca os casos do enum. Um modelo novo
 * (CT-e quando o conector entrar) entra por aqui sem tocar nesta tela, e um
 * rótulo fixo por modelo quebraria na primeira linha inesperada.
 */
const modelLabels: Record<string, string> = {
  nfe: 'NF-e',
  nfce: 'NFC-e',
  cte: 'CT-e',
  cte_os: 'CT-e OS',
  gtve: 'GTV-e',
  nfse: 'NFS-e'
}

export function modelLabel(model: string): string {
  return modelLabels[model] ?? model
}

/** O volume por modelo, na ordem em que o resumo mandou. */
export function modelVolumes(models: Record<string, number>): { model: string, label: string, total: number }[] {
  return Object.entries(models).map(([model, total]) => ({ model, label: modelLabel(model), total }))
}

/**
 * O eixo do mês, escrito a partir da chave `YYYY-MM`.
 *
 * Sem `new Date`: a chave já é o mês, e montá-la como data jogaria o rótulo
 * para o mês anterior em qualquer fuso negativo — um eixo que conta janeiro em
 * dezembro é pior do que nenhum eixo.
 */
export function fiscalMonthLabel(month: string): string {
  const [year = '', mes = ''] = month.split('-')
  if (!/^\d{4}$/.test(year) || !/^\d{2}$/.test(mes)) return month
  // O mês também tem de existir. `new Date(2026, 12, 1)` não erra — ele vira
  // janeiro de 2027 e pinta o eixo com um mês que o banco nunca mandou, que é a
  // mesma mentira de um zero no eixo, com mais passo.
  if (Number(mes) < 1 || Number(mes) > 12) return month
  return `${mes}/${year.slice(2)}`
}

/** A série de emissão pronta para o eixo e para o tooltip. */
export function fiscalMonthSeries(overTime: readonly { month: string, total: number }[]): { label: string, total: number }[] {
  return overTime.map(point => ({ label: fiscalMonthLabel(point.month), total: point.total }))
}

/**
 * O cabeçalho do gráfico de emissão, dizendo o que cada número é.
 *
 * `documents.total` e a soma de `documents.over_time` não são a mesma grandeza,
 * e o motivo está no backend: a coluna `emissao_at` é nullable, e um
 * documento sem data de emissão conta no total e não tem mês na série. Como o
 * total é verdadeiro e útil — é "quantos documentos a conta guarda" — o que não
 * pode é o cabeçalho apresentá-lo como a soma do que o gráfico desenha. Um
 * operador que some as barras e não chega ao número do canto precisa poder ler
 * na tela por quê.
 *
 * Por isso a distinção está escrita, e não inferida: `series` diz quantos
 * documentos o gráfico soma, `stored` diz quantos a guarda, e `undated` nomeia
 * a diferença quando ela existe. Quando os dois batem, `undated` é `null` — a
 * linha some em vez de dizer "0 documentos sem data de emissão", que é uma
 * afirmação sobre um dado que ninguém olhou.
 */
export function documentsOverTimeHeader(documents: Pick<FiscalSummary['documents'], 'total' | 'over_time'>): {
  series: string
  stored: string
  undated: string | null
} {
  const naSerie = documents.over_time.reduce((soma, ponto) => soma + ponto.total, 0)
  const semData = Math.max(0, documents.total - naSerie)

  return {
    series: `${formatFiscalCount(naSerie)} no gráfico`,
    stored: `${formatFiscalCount(documents.total)} guardados`,
    undated: semData === 0
      ? null
      : `${formatFiscalCount(semData)} sem data de emissão, fora do gráfico`
  }
}

/**
 * A última consulta da conta, em uma leitura.
 *
 * `null` é "a conta nunca consultou" — o backend manda `null` de propósito
 * para essa distinção não se perder no Resource, e a tela precisa repeti-la: uma
 * captura que nunca rodou não é a mesma notícia que uma que rodou e não achou
 * nada, e o operador age diferente nas duas.
 *
 * O `error` entra como frase, do jeito que a coluna o guardou (nome de classe,
 * classificação do conector ou frase fixa), e nunca como um dos nove motivos: o
 * resumo não mandou qual motivo foi, e inventar um aqui seria uma leitura do que
 * a consulta deu.
 */
export function lastCaptureOutcome(last: FiscalLastCapture | null): { title: string, description: string, tone: FiscalTone } {
  if (!last) {
    // "Nunca consultou" é sobre a conta, não sobre os clientes dela: uma
    // carteira com dez clientes e nenhum A1 também nunca consultou, e a frase
    // precisa ficar do lado do que a API respondeu, que foi nada.
    return {
      title: 'A conta nunca consultou o fisco',
      description: 'Nenhuma execução de captura foi registrada até agora, então ainda não há resultado para ler — nem um documento faltando, nem um erro para investigar.',
      tone: 'neutral'
    }
  }

  // A fonte entra na frase só quando o resumo a mandou. Com `null` a frase
  // continua dizendo o que se sabe — que houve uma consulta e como ela terminou
  // — em vez de repetir o traço do valor ausente no meio dela ("última consulta
  // de — concluída"), que é um texto que ninguém consegue ler.
  const fonte = last.source === null ? '' : ` de ${fiscalSourceLabel(last.source)}`

  if (last.error) {
    return {
      title: `Última consulta${fonte} terminou em erro`,
      description: `Em ${formatFiscalDateTime(last.ran_at)}. O que ficou registrado foi "${last.error}", e a próxima execução tenta de novo.`,
      tone: 'warning'
    }
  }

  return {
    title: `Última consulta${fonte} concluída`,
    description: `Terminou em ${formatFiscalDateTime(last.ran_at)} sem erro.`,
    tone: 'success'
  }
}
