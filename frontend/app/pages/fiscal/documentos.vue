<script setup lang="ts">
import { h } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import type { DataTableFilterColumn, DataTableFilterModel } from '~/components/data-table/Filter.vue'
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import DataTableSortButton from '~/components/data-table/SortButton.vue'
import { sheetBodyClass, sheetTableUi } from '~/components/data-table/sheet'
import { apiMessage, apiStatus } from '~/composables/useApiError'
import type {
  FiscalCaptureBlocked,
  FiscalDetail,
  FiscalDocumentRow,
  FiscalListFilters,
  FiscalPerPage,
  FiscalSort
} from '~/types/fiscal'
import { availableFiscalModels, fiscalQuery, isFiscalModel, parseFiscalFilters } from '~/utils/fiscalFilters'
import {
  fiscalMissingValue,
  fiscalSourceLabel,
  formatFiscalCount,
  formatFiscalDateTime,
  modelLabel
} from '~/utils/fiscalPresentation'
import { formatTaxId } from '~/utils/taxId'

/**
 * A tabela única de documentos capturados, em `/fiscal/documentos`.
 *
 * Middleware nomeado como em todas as páginas do produto: qualquer membro da
 * conta lê a captura (`FiscalDocumentPolicy::viewAny`). Quem **dispara** captura
 * é `admin`/`operador`, e esse controle aparece adiante — ler e escrever são
 * decisões diferentes e a tela não as mistura.
 */
definePageMeta({ middleware: 'auth' })

const route = useRoute()
const toast = useToast()
const { list, show, download, capture } = useFiscal()
const { canManageClients } = useAuth()

/**
 * A URL é a fonte da verdade do filtro, e o módulo puro é quem a traduz.
 *
 * `filters` é derivado de `route.query` e nunca guardado: um estado de filtro
 * que só existisse dentro da tela desapareceria no F5, e o operador não
 * conseguiria colar a consulta para o colega. Por isso **toda** escrita de
 * filtro passa por `updateFilters`, que navega — inclusive a volta para a
 * primeira página, que é o que faz o filtro parecer aplicado.
 */
const filters = computed(() => parseFiscalFilters(route.query))

const listKey = computed(() => `fiscal-documents-${JSON.stringify(filters.value)}`)

/**
 * A chave é o filtro inteiro, e é ela que segura a corrida entre duas
 * consultas: o `useAsyncData` guarda uma entrada por chave, então a resposta
 * lenta dos filtros antigos escreve na entrada antiga — que a página já não
 * está lendo. Sem isso, `?model=nfe` voltando depois de `?model=cte` trocaria a
 * lista de baixo para cima na tela de quem já mudou o filtro.
 */
const { data, status, error, refresh: reload } = await useAsyncData(listKey, () => list(filters.value))

/**
 * `sticky`: uma falha de carga é fatal e o operador sai dela recarregando.
 *
 * Sem isso, um refiltro que volta a funcionar limparia o alerta por baixo e a
 * tela pintaria o conteúdo velho como se a consulta tivesse dado certo — e o
 * pior dos dois é o estado vazio embaixo do alerta dizer que nada corresponde
 * aos filtros, que é uma afirmação que ninguém fez.
 */
const { isLoading, showError, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar os documentos',
  refreshErrorTitle: 'Não foi possível atualizar os documentos',
  sticky: true
})

const rows = computed(() => data.value?.data ?? [])
const pageMeta = computed(() => data.value?.meta ?? null)
const total = computed(() => pageMeta.value?.total ?? rows.value.length)

/** A página e a ordenação também moram na URL, e a primeira é a resposta. */
function updateFilters(next: FiscalListFilters) {
  return navigateTo({ query: fiscalQuery({ ...next, page: 1 }) })
}

const currentPage = computed({
  get: () => filters.value.page ?? 1,
  set: (value: number) => navigateTo({ query: fiscalQuery({ ...filters.value, page: value }) })
})

function setPerPage(value: FiscalPerPage) {
  return navigateTo({ query: fiscalQuery({ ...filters.value, per_page: value, page: 1 }) })
}

/**
 * Tem filtro aplicado quando a URL tem algo que a API usaria para mudar a
 * resposta — e a resposta é a do próprio módulo, que já omite o padrão. Não é
 * uma comparação com os valores padrão aqui: duas listas de padrão que podem
 * divergir é exatamente o que o módulo existe para evitar.
 */
const hasActiveFilters = computed(() => Object.keys(fiscalQuery(filters.value)).length > 0)

function clearFilters() {
  return navigateTo({ query: {} })
}

/* ------------------------------------------------------------------ *
 * Filtro: as opções que o `DataTableFilter` sabe mostrar
 * ------------------------------------------------------------------ */

/**
 * Os chips de modelo, tipo e cliente saem do `DataTableFilter`, e os valores
 * deles vivem na URL como qualquer outro filtro — o componente é só a casca.
 */
const filterModels = computed<DataTableFilterModel[]>(() => {
  const applied: DataTableFilterModel[] = []
  const current = filters.value

  if (current.model?.length) {
    applied.push({
      columnId: 'model',
      type: 'multiOption',
      operator: current.model.length > 1 ? 'include any of' : 'include',
      values: [...current.model]
    })
  }
  if (current.kind) applied.push({ columnId: 'kind', type: 'option', operator: 'is', values: [current.kind] })
  if (current.client_id) applied.push({ columnId: 'client_id', type: 'option', operator: 'is', values: [String(current.client_id)] })

  return applied
})

/**
 * O que o `onFilters` devolve, lido de novo como filtro.
 *
 * O caminho inverso do acima, e ele existe porque é o componente que emite: a
 * barra não conhece a URL, só valores. As colunas que ele não sabe editar
 * (prefixo de CNPJ, intervalo) não entram aqui — elas têm controle próprio
 * abaixo, e o `columnId` delas nunca chega neste mapa.
 *
 * O "não é" que o menu oferece não sobrevive: a API só sabe inclusão, e a
 * pílula volta mostrando o filtro que a consulta de fato aplicou. Deixar o
 * "não é" na tela seria uma promessa que a URL não consegue cumprir.
 */
function onFilters(models: DataTableFilterModel[]) {
  const model = models.find(entry => entry.columnId === 'model')?.values.map(String) ?? []
  const kind = models.find(entry => entry.columnId === 'kind')?.values[0]
  const client = models.find(entry => entry.columnId === 'client_id')?.values[0]

  return updateFilters({
    ...filters.value,
    model: model.filter(isFiscalModel),
    kind: kind === 'document' || kind === 'event' ? kind : null,
    client_id: client === undefined ? null : Number(client)
  })
}

/**
 * Os modelos que o filtro oferece, pelas linhas da tela mais os já
 * selecionados.
 *
 * O selecionado entra mesmo sem linha atrás: é o que o backend também faz em
 * `available_models`, e é o que deixa o filtro de modelo que esvaziou a tabela
 * sair sem recarregar a página.
 */
const modelOptions = computed(() =>
  availableFiscalModels(rows.value, filters.value.model ?? [])
    .map(model => ({ label: modelLabel(model), value: model }))
)

/**
 * Os clientes que o filtro de cliente oferece.
 *
 * Vem das linhas da própria página, e não da carteira inteira: a lista de
 * documentos já é a carteira capturada, e um catálogo truncado apresentado como
 * catálogo seria pior do que um filtro que oferece o que a tela mostra. O
 * cliente já filtrado entra de qualquer jeito — sem nome na página ele é
 * identificado pelo id, que é o que a API devolveu.
 */
const clientOptions = computed(() => {
  const names = new Map<number, string>()
  for (const row of rows.value) names.set(row.client.id, row.client.name)

  const selected = filters.value.client_id
  if (selected && !names.has(selected)) names.set(selected, `Cliente #${selected}`)

  return [...names.entries()].map(([id, name]) => ({ label: name, value: String(id) }))
})

const filterColumns = computed<DataTableFilterColumn[]>(() => [
  { id: 'model', label: 'Modelo', icon: 'i-lucide-file-text', type: 'multiOption', options: modelOptions.value },
  {
    id: 'kind',
    label: 'Tipo',
    icon: 'i-lucide-tags',
    type: 'option',
    options: [
      { label: 'Documento', value: 'document' },
      { label: 'Evento', value: 'event' }
    ]
  },
  { id: 'client_id', label: 'Cliente', icon: 'i-lucide-building-2', type: 'option', options: clientOptions.value }
])

/* ------------------------------------------------------------------ *
 * Filtro: o que não é opção — prefixo de CNPJ e intervalos
 * ------------------------------------------------------------------ */

/**
 * O rascunho dos filtros de escrita livre, aplicado só quando o operador
 * confirma.
 *
 * A barra de opções é um menu de valores prontos; digitar um prefixo de CNPJ é
 * outra coisa. O rascunho nasce da URL quando o painel abre e vira URL no
 * "Aplicar" — sem isso, cada tecla seria uma navegação, uma entrada de
 * histórico e uma consulta.
 */
const rangeOpen = ref(false)
const draft = ref({
  issuer: '',
  recipient: '',
  issued_from: '',
  issued_to: '',
  amount_min: '',
  amount_max: ''
})

watch(rangeOpen, (open) => {
  if (!open) return
  const current = filters.value
  draft.value = {
    issuer: current.issuer ?? '',
    recipient: current.recipient ?? '',
    issued_from: current.issued_from ?? '',
    issued_to: current.issued_to ?? '',
    amount_min: current.amount_min === undefined || current.amount_min === null ? '' : String(current.amount_min),
    amount_max: current.amount_max === undefined || current.amount_max === null ? '' : String(current.amount_max)
  }
})

/** Quantos desses filtros estão na URL — é o que acende o botão do painel. */
const rangeCount = computed(() => {
  const current = filters.value
  return [
    current.issuer,
    current.recipient,
    current.issued_from,
    current.issued_to,
    current.amount_min,
    current.amount_max
  ].filter(value => value !== undefined && value !== null).length
})

/**
 * Aplica o rascunho e fecha o painel.
 *
 * Fechar junto é o que diz ao operador que a consulta rodou: o popover aberto de
 * novo sobre a lista já filtrada é o estado em que "não sei se aplicou" aparece.
 */
function applyRange() {
  const applied = draft.value
  rangeOpen.value = false
  return updateFilters({
    ...filters.value,
    issuer: applied.issuer.trim() || null,
    recipient: applied.recipient.trim() || null,
    issued_from: applied.issued_from || null,
    issued_to: applied.issued_to || null,
    amount_min: applied.amount_min === '' ? null : Number(applied.amount_min),
    amount_max: applied.amount_max === '' ? null : Number(applied.amount_max)
  })
}

function clearRange() {
  rangeOpen.value = false
  return updateFilters({
    ...filters.value,
    issuer: null,
    recipient: null,
    issued_from: null,
    issued_to: null,
    amount_min: null,
    amount_max: null
  })
}

/* ------------------------------------------------------------------ *
 * A tabela
 * ------------------------------------------------------------------ */

const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })

/**
 * O valor do documento vem como texto decimal e nunca como número.
 *
 * A coluna é `decimal(14,2)` e o Resource devolve `"55.55"`: um float de JSON
 * não representa `0,01`, e converter para número de volta — ou pior, somar em
 * cents sobre float — devolveria o erro que o formato de texto existe para
 * evitar. Aqui é só parse para exibição.
 */
function formatAmount(value: string | null) {
  if (value === null) return fiscalMissingValue
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? currency.format(parsed) : fiscalMissingValue
}

/**
 * A emissão é um dia, e o dia é o do fisco.
 *
 * `formatFiscalDateTime` mostra a hora, e a hora da emissão não é informação do
 * documento. Fuso fixo em UTC pelos dois lados, senão o SSR e o cliente pintam
 * dias diferentes na mesma tabela e o Vue reclama do texto.
 */
const dayFormat = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeZone: 'UTC' })

function formatDay(value: string | null) {
  if (!value) return fiscalMissingValue
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? fiscalMissingValue : dayFormat.format(date)
}

/**
 * A contagem de eventos da linha é a mesma que o detalhe repete, e o zero é
 * dito por extenso.
 *
 * O número conta as próprias linhas de evento da chave, sem autoexclusão, e
 * subtrair um aqui colocaria dois números diferentes para a mesma chave de
 * acesso na mesma tela. Zero é "sem eventos" escrito: uma célula vazia parece
 * um dado que não chegou.
 */
function eventCount(row: FiscalDocumentRow) {
  return row.event_count === 0 ? 'Sem eventos' : formatFiscalCount(row.event_count)
}

/**
 * O veredito do digest da chave, e os três estados que ele tem.
 *
 * `null` é o terceiro, e não "divergiu": é a outra etapa da distribuição que
 * ainda não chegou, e a tela que pintasse isso de vermelho acusaria um documento
 * íntegro de estar corrompido.
 */
const digestConfere = { label: 'Confere', color: 'success' as const, icon: 'i-lucide-circle-check' }
const digestDiverge = { label: 'Diverge', color: 'error' as const, icon: 'i-lucide-circle-alert' }
const digestPendente = { label: 'Ainda não conferido', color: 'neutral' as const, icon: 'i-lucide-circle-minus' }

function digestOf(row: FiscalDocumentRow) {
  if (row.digval_confere === true) return digestConfere
  if (row.digval_confere === false) return digestDiverge
  return digestPendente
}

const stageLabels: Record<FiscalDocumentRow['stage'], string> = {
  summary: 'Resumo da distribuição',
  document: 'Documento autorizado',
  event: 'Evento autorizado'
}

const kindLabels: Record<FiscalDocumentRow['kind'], string> = {
  document: 'Documento',
  event: 'Evento'
}

/**
 * O botão de ordenação, só nas colunas que a API sabe ordenar.
 *
 * A primeira vez numa coluna nova já entra decrescente, que é a direção com que
 * a lista chega por padrão — trocar de coluna e ver a lista de cabeça para baixo
 * é o que o operador teria de desfazer no clique seguinte. A segunda vez inverte,
 * e o estado vive na URL como todo o resto do filtro.
 */
function sortableHeader(label: string, key: FiscalSort) {
  return h(DataTableSortButton, {
    label,
    sorted: filters.value.sort === key ? filters.value.direction : false,
    onToggle: () => {
      const same = filters.value.sort === key
      return updateFilters({
        ...filters.value,
        sort: key,
        direction: same && filters.value.direction === 'desc' ? 'asc' : 'desc'
      })
    }
  })
}

/**
 * `computed` e não uma lista solta: o cabeçalho da coluna ordenada é uma função
 * que lê a URL, e uma lista criada no setup mostraria a seta do ordenamento
 * anterior até a tabela receber linhas novas.
 */
const columns = computed<TableColumn<FiscalDocumentRow>[]>(() => [
  { id: 'cliente', header: 'Cliente', meta: { class: { th: 'min-w-52', td: 'max-w-0' } } },
  { id: 'modelo', header: 'Modelo', meta: { class: { th: 'whitespace-nowrap', td: 'whitespace-nowrap' } } },
  { id: 'chave', header: 'Chave de acesso', meta: { class: { th: 'min-w-44', td: 'max-w-0' } } },
  { id: 'emitente', header: 'Emitente', meta: { class: { th: 'min-w-40', td: 'max-w-0' } } },
  { id: 'destinatario', header: 'Destinatário', meta: { class: { th: 'min-w-40', td: 'max-w-0' } } },
  { id: 'valor', header: 'Valor', meta: { class: { th: 'whitespace-nowrap text-right', td: 'text-right tabular-nums' } } },
  { id: 'emissao', header: () => sortableHeader('Emissão', 'emissao_at'), meta: { class: { th: 'whitespace-nowrap', td: 'whitespace-nowrap' } } },
  { id: 'eventos', header: 'Eventos', meta: { class: { th: 'whitespace-nowrap', td: 'whitespace-nowrap' } } },
  { id: 'digest', header: 'Digest', meta: { class: { th: 'whitespace-nowrap', td: 'whitespace-nowrap' } } },
  { id: 'acoes', meta: { class: { th: 'w-12', td: 'w-12' } } }
])

/* ------------------------------------------------------------------ *
 * O detalhe: uma folha sobre a tabela, não uma rota
 * ------------------------------------------------------------------ */

const detailOpen = ref(false)
const detail = ref<FiscalDetail | null>(null)
const detailPending = ref(false)

/**
 * A guarda de geração, do mesmo formato que a de
 * `customers/[documento]/[[situacao].vue`: cada abertura toma um número e só a
 * última escreve.
 *
 * Duas linhas clicadas em sequência dão duas respostas em voo, e a da primeira
 * chega depois. Sem o número, a folha trocava de documento no meio da leitura
 * — o operador leria os metadados do evento B no cabeçalho do documento A. A
 * lista não precisa da guarda porque a chave do `useAsyncData` já separa uma
 * consulta da outra; aqui a escrita é num `ref` da própria folha, e o `ref` é
 * o que precisa se defender.
 */
let detailGeneration = 0

async function openDetail(row: FiscalDocumentRow) {
  const seen = ++detailGeneration
  detailOpen.value = true
  detail.value = null
  detailPending.value = true

  try {
    const loaded = await show(row.id)
    if (seen !== detailGeneration) return
    detail.value = loaded
  } catch {
    if (seen !== detailGeneration) return
    toast.add({ title: 'Não foi possível abrir o documento', color: 'error' })
  } finally {
    if (seen === detailGeneration) detailPending.value = false
  }
}

const detailFacts = computed<MetaListItem[]>(() => {
  const target = detail.value
  if (!target) return []

  return [
    { label: 'Chave de acesso', value: target.chave_acesso, mono: true },
    { label: 'Modelo', value: modelLabel(target.model) },
    { label: 'Tipo', value: kindLabels[target.kind] },
    { label: 'Etapa', value: stageLabels[target.stage] },
    { label: 'Emitente', value: formatTaxId(target.emitente_cnpj), mono: true },
    { label: 'Destinatário', value: formatTaxId(target.destinatario_cnpj), mono: true },
    { label: 'Valor total', value: formatAmount(target.valor_total), mono: true },
    { label: 'Emissão', value: formatDay(target.emissao_at), mono: true },
    { label: 'Capturado em', value: formatFiscalDateTime(target.captured_at), mono: true },
    { label: 'Distribuição', value: `${fiscalSourceLabel(target.source)} · NSU ${formatFiscalCount(target.nsu)}` },
    { label: 'Código do evento', value: target.event_id || fiscalMissingValue, mono: true },
    { label: 'Eventos', value: target.event_count === 0 ? 'Sem eventos' : formatFiscalCount(target.event_count), mono: true },
    { label: 'Layout', value: target.schema ?? fiscalMissingValue, mono: true },
    { label: 'XML gravado', value: `${formatFiscalCount(target.xml_bytes)} bytes`, mono: true },
    { label: 'Digest do XML', value: target.digval ?? fiscalMissingValue, mono: true },
    { label: 'SHA-256', value: target.sha256, mono: true },
    { label: 'Mascarado pelo fisco', value: target.mascarado ? 'Sim' : 'Não' }
  ]
})

/**
 * O XML é texto, e é renderizado como texto.
 *
 * `xml_preview` é o que o backend projetou para exibição, com teto de 4 KiB, e
 * vem `null` em dois casos honestos: o arquivo sumiu do disco ou o byte é de uma
 * codificação que a projeção recusa em vez de adivinhar. Nenhum dos dois é
 * erro — e nenhum dos dois tira o download, que serve o byte cru que a prévia
 * não conseguiu mostrar.
 */
const xmlPreview = computed(() => detail.value?.xml_preview ?? null)

/**
 * O download é uma requisição autenticada, e por isso um `Blob` e um clique
 * programático.
 *
 * Um `<a href>` para a rota do XML seria uma navegação sem o cookie de sessão,
 * que o servidor responderia com 401 em vez do arquivo. A URL do objeto é
 * revogada assim que o navegador recebeu o clique — ela existe para o
 * download, não para ficar na memória.
 */
const downloading = ref(false)

async function downloadXml() {
  const target = detail.value
  if (!target || downloading.value) return

  downloading.value = true
  try {
    const blob = await download(target.id)
    const url = URL.createObjectURL(blob)
    try {
      const link = document.createElement('a')
      link.href = url
      link.download = `${target.chave_acesso}.xml`
      link.rel = 'noopener'
      document.body.append(link)
      link.click()
      link.remove()
    } finally {
      URL.revokeObjectURL(url)
    }
  } catch {
    toast.add({ title: 'Não foi possível baixar o XML', color: 'error' })
  } finally {
    downloading.value = false
  }
}

/* ------------------------------------------------------------------ *
 * A captura sob demanda
 * ------------------------------------------------------------------ */

const capturing = ref(false)

/**
 * O contador que o painel fiscal escuta.
 *
 * O painel relê o resumo toda vez que entra, então a consulta enfileirada aparece
 * nele sem ajuda de ninguém; este contador é a costura que o painel deixou para
 * a captura, e não um `refresh()` escondido aqui dentro — quem dispara a captura
 * é quem sabe que a carteira mudou.
 */
const refreshRequest = useState('fiscal-refresh', () => 0)

/**
 * O corpo da recusa por bloqueio, lido do erro.
 *
 * O 409 do backend traz `{message, blocked_until}` e o tipo dele mora em
 * `types/fiscal.ts` — declarar a forma aqui dentro seria a segunda cópia, e as
 * duas divergem. O que fica é só a leitura: o status 409 é o que separa a
 * recusa do fisco de qualquer outra falha, e `blocked_until` é a hora em que a
 * janela acaba, que é o que o operador precisa para não tentar de novo cedo.
 */
function blockedRefusal(error: unknown): FiscalCaptureBlocked | null {
  if (apiStatus(error) !== 409) return null

  const body = typeof error === 'object' && error !== null ? (error as { data?: unknown }).data : null
  if (typeof body !== 'object' || body === null) return null

  const { message, blocked_until } = body as Partial<FiscalCaptureBlocked>
  if (typeof message !== 'string' || typeof blocked_until !== 'string') return null

  return { message, blocked_until }
}

async function triggerCapture() {
  const target = detail.value
  if (!target || capturing.value) return

  capturing.value = true
  try {
    await capture(target.client.id, target.source)
    toast.add({
      title: 'Captura enfileirada',
      description: `A consulta de ${fiscalSourceLabel(target.source)} do cliente entrou na fila. Os documentos chegam na tabela quando o lote terminar.`,
      color: 'success'
    })
    refreshRequest.value += 1
  } catch (error) {
    const blocked = blockedRefusal(error)
    if (blocked) {
      toast.add({
        title: 'A consulta deste cliente está em espera',
        description: `${blocked.message} A janela acaba em ${formatFiscalDateTime(blocked.blocked_until)}.`,
        color: 'warning'
      })
      return
    }
    toast.add({
      title: 'Não foi possível enfileirar a captura',
      description: apiMessage(error),
      color: 'error'
    })
  } finally {
    capturing.value = false
  }
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <div :class="sheetBodyClass">
      <DataTableFilter
        :columns="filterColumns"
        :model-value="filterModels"
        :disabled="isLoading"
        class="min-w-0"
        @update:model-value="onFilters"
      >
        <p class="shrink-0 text-sm font-medium text-highlighted">
          Documentos capturados
        </p>

        <template #trailing>
          <UPopover
            v-model:open="rangeOpen"
            :content="{ align: 'end' }"
            :ui="{ content: 'w-80 p-4' }"
          >
            <UButton
              icon="i-lucide-calendar-range"
              color="neutral"
              :variant="rangeCount > 0 ? 'soft' : 'outline'"
              :disabled="isLoading"
              class="shrink-0"
              aria-label="Filtros de emitente, destinatário, emissão e valor"
            >
              <UBadge
                v-if="rangeCount > 0"
                :label="formatFiscalCount(rangeCount)"
                color="primary"
                variant="subtle"
                size="sm"
              />
            </UButton>

            <template #content>
              <div class="space-y-3">
                <p class="text-sm font-medium text-highlighted">
                  Emitente, destinatário, emissão e valor
                </p>
                <p class="text-xs text-muted">
                  O CNPJ é prefixo: digite o começo dele. O filtro vai para a URL
                  quando você aplicar, e a lista volta para a primeira página.
                </p>

                <UFormField label="Emitente" name="issuer" help="Só dígitos, no máximo 14.">
                  <UInput
                    v-model="draft.issuer"
                    inputmode="numeric"
                    placeholder="000000000001"
                    class="w-full"
                  />
                </UFormField>

                <UFormField label="Destinatário" name="recipient" help="Só dígitos, no máximo 14.">
                  <UInput
                    v-model="draft.recipient"
                    inputmode="numeric"
                    placeholder="000000000001"
                    class="w-full"
                  />
                </UFormField>

                <div class="grid grid-cols-2 gap-2">
                  <UFormField label="Emissão de" name="issued_from">
                    <UInput v-model="draft.issued_from" type="date" class="w-full" />
                  </UFormField>
                  <UFormField label="Até" name="issued_to">
                    <UInput v-model="draft.issued_to" type="date" class="w-full" />
                  </UFormField>
                </div>

                <div class="grid grid-cols-2 gap-2">
                  <UFormField label="Valor de" name="amount_min">
                    <UInput
                      v-model="draft.amount_min"
                      type="number"
                      min="0"
                      step="0.01"
                      placeholder="0,00"
                      class="w-full"
                    />
                  </UFormField>
                  <UFormField label="Até" name="amount_max">
                    <UInput
                      v-model="draft.amount_max"
                      type="number"
                      min="0"
                      step="0.01"
                      placeholder="0,00"
                      class="w-full"
                    />
                  </UFormField>
                </div>

                <div class="flex items-center justify-between gap-2">
                  <UButton
                    label="Limpar"
                    icon="i-lucide-filter-x"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    :disabled="rangeCount === 0"
                    @click="clearRange"
                  />
                  <UButton label="Aplicar" size="sm" @click="applyRange" />
                </div>
              </div>
            </template>
          </UPopover>
        </template>
      </DataTableFilter>

      <ErrorRetryAlert
        v-if="showError"
        title="Não foi possível carregar os documentos"
        @retry="retry"
      />

      <!--
        A lista inteira fica atrás do `v-else` do alerta, e não ao lado dele.
        Com a carga quebrada, a tela que mostra "nenhum documento corresponde aos
        filtros" embaixo de um "não foi possível carregar" estaria afirmando duas
        coisas contrárias sobre a mesma lista — e a segunda delas é a que o
        operador acredita.
      -->
      <template v-else>
        <div v-if="isLoading && rows.length === 0" class="space-y-2">
          <USkeleton v-for="index in 6" :key="index" class="h-12 w-full" />
        </div>

        <UEmpty
          v-else-if="rows.length === 0"
          icon="i-lucide-search-x"
          title="Nenhum documento corresponde aos filtros"
          :description="hasActiveFilters
            ? 'Ajuste os filtros ou volte para a lista inteira para ver o que a conta capturou.'
            : 'Nenhum documento foi capturado nesta conta até agora. A primeira consulta aparece aqui quando a captura rodar.'"
          variant="naked"
          :actions="hasActiveFilters
            ? [{ label: 'Limpar filtros', icon: 'i-lucide-filter-x', color: 'neutral', variant: 'outline', onClick: clearFilters }]
            : undefined"
        />

        <template v-else>
          <!-- Mobile: cartões, porque nove colunas numa tela de bolso é rolagem lateral. -->
          <div class="min-h-0 flex-1 space-y-3 overflow-y-auto md:hidden">
            <UCard
              v-for="row in rows"
              :key="row.id"
              variant="subtle"
              :ui="{ root: 'overflow-visible', body: 'p-4' }"
            >
              <div class="flex items-start justify-between gap-3">
                <DataTableIdentity
                  :title="row.client.name"
                  :meta="row.client.tax_id ? formatTaxId(row.client.tax_id) : ''"
                />
                <div class="flex shrink-0 items-center gap-1">
                  <UBadge
                    :label="modelLabel(row.model)"
                    color="primary"
                    variant="subtle"
                  />
                  <UButton
                    icon="i-lucide-chevron-right"
                    color="neutral"
                    variant="ghost"
                    :aria-label="`Abrir documento ${row.chave_acesso}`"
                    @click="openDetail(row)"
                  />
                </div>
              </div>

              <div class="mt-3 flex flex-wrap items-center gap-1.5">
                <UBadge
                  :label="kindLabels[row.kind]"
                  variant="subtle"
                  size="sm"
                />
                <UBadge
                  :label="eventCount(row)"
                  variant="subtle"
                  size="sm"
                />
                <UBadge
                  :label="digestOf(row).label"
                  :color="digestOf(row).color"
                  :icon="digestOf(row).icon"
                  variant="subtle"
                  size="sm"
                />
                <UBadge
                  v-if="row.mascarado"
                  label="Mascarado"
                  color="neutral"
                  variant="subtle"
                  size="sm"
                />
              </div>

              <p class="mt-3 truncate text-xs tabular-nums text-muted" :title="row.chave_acesso">
                {{ row.chave_acesso }}
              </p>
              <div class="mt-1 flex items-center justify-between gap-3 text-xs tabular-nums text-muted">
                <span>{{ formatDay(row.emissao_at) }}</span>
                <span>{{ formatAmount(row.valor_total) }}</span>
              </div>
            </UCard>
          </div>

          <div class="hidden min-h-0 min-w-0 flex-1 flex-col md:flex">
            <UTable
              sticky
              :data="rows"
              :columns="columns"
              :loading="isLoading"
              class="h-full min-h-0 w-full flex-1"
              :ui="sheetTableUi"
            >
              <template #cliente-cell="{ row }">
                <DataTableIdentity
                  :title="row.original.client.name"
                  :meta="row.original.client.tax_id ? formatTaxId(row.original.client.tax_id) : ''"
                />
              </template>

              <template #modelo-cell="{ row }">
                <div class="flex flex-wrap items-center gap-1">
                  <UBadge
                    :label="modelLabel(row.original.model)"
                    color="primary"
                    variant="subtle"
                  />
                  <UBadge
                    v-if="row.original.kind === 'event'"
                    label="Evento"
                    variant="subtle"
                    size="sm"
                  />
                  <UBadge
                    v-if="row.original.mascarado"
                    label="Mascarado"
                    color="neutral"
                    variant="subtle"
                    size="sm"
                  />
                </div>
              </template>

              <template #chave-cell="{ row }">
                <button
                  type="button"
                  class="block w-full truncate text-left tabular-nums text-highlighted hover:text-primary"
                  :title="row.original.chave_acesso"
                  @click="openDetail(row.original)"
                >
                  {{ row.original.chave_acesso }}
                </button>
              </template>

              <template #emitente-cell="{ row }">
                <span class="tabular-nums">{{ formatTaxId(row.original.emitente_cnpj) }}</span>
              </template>

              <template #destinatario-cell="{ row }">
                <span class="tabular-nums">{{ formatTaxId(row.original.destinatario_cnpj) }}</span>
              </template>

              <template #valor-cell="{ row }">
                {{ formatAmount(row.original.valor_total) }}
              </template>

              <template #emissao-cell="{ row }">
                {{ formatDay(row.original.emissao_at) }}
              </template>

              <template #eventos-cell="{ row }">
                {{ eventCount(row.original) }}
              </template>

              <template #digest-cell="{ row }">
                <UBadge
                  :label="digestOf(row.original).label"
                  :color="digestOf(row.original).color"
                  :icon="digestOf(row.original).icon"
                  variant="subtle"
                  :ui="{ base: 'max-w-full', label: 'truncate' }"
                />
              </template>

              <template #acoes-cell="{ row }">
                <div class="text-right">
                  <UButton
                    icon="i-lucide-chevron-right"
                    color="neutral"
                    variant="ghost"
                    :aria-label="`Abrir documento ${row.original.chave_acesso}`"
                    @click="openDetail(row.original)"
                  />
                </div>
              </template>
            </UTable>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-muted">
              {{ formatFiscalCount(total) }} documento(s) · página {{ filters.page }} de {{ pageMeta?.last_page ?? 1 }}
            </p>

            <div class="flex items-center gap-2">
              <USelect
                :model-value="String(filters.per_page ?? 25)"
                :items="[{ label: '25 por página', value: '25' }, { label: '50 por página', value: '50' }, { label: '100 por página', value: '100' }]"
                size="sm"
                class="w-36"
                aria-label="Documentos por página"
                @update:model-value="setPerPage(Number($event) as FiscalPerPage)"
              />
              <UPagination
                v-model:page="currentPage"
                :total="total"
                :items-per-page="filters.per_page ?? 25"
                :sibling-count="1"
                show-edges
                size="sm"
              />
            </div>
          </div>
        </template>
      </template>
    </div>

    <!--
      O detalhe é uma folha sobre a tabela, e não uma rota. A tela pede uma
      tabela filtrável e o documento vive dentro dela: um link por documento
      seria um id que o operador cola e outra entrada de histórico para cada
      leitura. A volta para a lista é o botão de fechar, que é onde a lista
      continua exatamente como estava.
    -->
    <USlideover
      v-model:open="detailOpen"
      title="Detalhe do documento"
      :description="detail ? `${modelLabel(detail.model)} · ${detail.client.name}` : undefined"
      :ui="{ footer: 'justify-between' }"
    >
      <template #body>
        <div v-if="detailPending" class="space-y-2">
          <USkeleton class="h-8 w-full" />
          <USkeleton class="h-40 w-full" />
          <USkeleton class="h-24 w-full" />
        </div>

        <div v-else-if="!detail" class="py-8 text-center text-sm text-muted">
          O documento não pôde ser carregado.
        </div>

        <div v-else class="flex min-w-0 flex-col gap-5">
          <DataTableMetaList :items="detailFacts" columns="grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2" />

          <section class="flex min-w-0 flex-col gap-2">
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-timeline" class="size-4 shrink-0 text-muted" />
              <h4 class="text-sm font-semibold text-highlighted">
                Linha do tempo
              </h4>
              <span class="truncate text-xs text-muted">
                Eventos da chave de acesso, em ordem cronológica.
              </span>
            </div>

            <p v-if="detail.events.length === 0" class="text-sm text-muted">
              Sem eventos registrados para esta chave de acesso.
            </p>

            <ul v-else class="divide-y divide-default rounded-lg ring ring-default">
              <li
                v-for="event in detail.events"
                :key="event.id"
                class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
              >
                <div class="flex min-w-0 items-center gap-2">
                  <UBadge
                    :label="event.event_id"
                    color="neutral"
                    variant="subtle"
                    class="tabular-nums"
                  />
                  <UBadge
                    v-if="event.mascarado"
                    label="Mascarado"
                    color="neutral"
                    variant="subtle"
                    size="sm"
                  />
                </div>
                <div class="flex shrink-0 flex-col items-end text-xs tabular-nums text-muted">
                  <span>{{ formatFiscalDateTime(event.evento_ocorrido_em_at) }}</span>
                  <span>Capturado em {{ formatFiscalDateTime(event.captured_at) }}</span>
                </div>
              </li>
            </ul>
          </section>

          <section class="flex min-w-0 flex-col gap-2">
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-file-code" class="size-4 shrink-0 text-muted" />
              <h4 class="text-sm font-semibold text-highlighted">
                Prévia do XML
              </h4>
              <span class="truncate text-xs text-muted">
                Texto, com teto de 4 KiB. O download serve o arquivo inteiro.
              </span>
            </div>

            <!--
              `{{ }}` e nada de `v-html`: o XML é o que o fisco gravou, e a
              prévia existe para o operador reconhecer a nota, não para executar
              marcação. Um documento hostil renderizado como HTML seria a entrega
              mais rápida do que esta tela existe para evitar.
            -->
            <pre
              v-if="xmlPreview"
              class="max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-elevated/50 p-3 font-mono text-xs text-highlighted ring ring-default"
            >{{ xmlPreview }}</pre>

            <p v-else class="text-sm text-muted">
              Sem prévia para este XML: o arquivo não está mais no disco, ou o byte gravado
              não é de uma codificação que a leitura de exibição aceite. O download serve o
              arquivo gravado como está.
            </p>
          </section>
        </div>
      </template>

      <template #footer="{ close }">
        <UButton
          label="Fechar"
          color="neutral"
          variant="ghost"
          @click="close"
        />

        <div class="flex items-center gap-2">
          <!--
            Ausente, e não desabilitado, para quem só lê. Um botão acinzentado
            convida a explicar por que está acinzentado, e a resposta — "o seu
            papel não captura" — é uma informação sobre permissão que a tela de
            documentos não precisa dar a quem não pode agir. O `v-if` é lido de
            `useAuth().canManageClients` no script, que é a mesma policy que o
            `capture` do backend autoriza.
          -->
          <UButton
            v-if="canManageClients && detail"
            label="Capturar agora"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="outline"
            :loading="capturing"
            :title="detail ? `Consulta de ${fiscalSourceLabel(detail.source)} deste cliente` : undefined"
            @click="triggerCapture"
          />

          <!--
            O download fica disponível mesmo sem prévia: são coisas diferentes.
            A prévia é a projeção para leitura, e o arquivo é o byte que o
            fisco gravou — que é o que se confere com o fisco.
          -->
          <UButton
            label="Baixar XML"
            icon="i-lucide-download"
            :loading="downloading"
            :disabled="!detail"
            @click="downloadXml"
          />
        </div>
      </template>
    </USlideover>
  </div>
</template>
