<script setup lang="ts">
import { h } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import type { DataTableFilterModel } from '~/components/data-table/filter-model'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import DataTableSortButton from '~/components/data-table/SortButton.vue'
import { sheetBodyClass, sheetTableUi } from '~/components/data-table/sheet'
import FiscalDocumentSheet from '~/components/fiscal/FiscalDocumentSheet.vue'
import type {
  FiscalDocumentRow,
  FiscalListFilters,
  FiscalPerPage,
  FiscalSort
} from '~/types/fiscal'
import { fiscalDocumentPanelColumns, fiscalDocumentPanelModel, fiscalFiltersFromPanel } from '~/utils/fiscalDocumentPanel'
import { availableFiscalModels, fiscalQuery, isFiscalModel, parseFiscalFilters } from '~/utils/fiscalFilters'
import { fiscalCompetenciaLabel, fiscalNumeroLabel } from '~/utils/fiscalClients'
import {
  fiscalClientCertificatePresentation,
  fiscalCompletudePresentation,
  fiscalEventCount,
  fiscalKindLabel,
  fiscalSituacaoPresentation,
  formatFiscalAmount,
  formatFiscalCount,
  formatFiscalDay,
  modelLabel
} from '~/utils/fiscalPresentation'
import { formatTaxId } from '~/utils/taxId'

/**
 * A tabela única de documentos capturados, em `/fiscal/documentos`.
 *
 * Middleware nomeado como em todas as páginas do produto: qualquer membro da
 * conta lê a captura (`FiscalDocumentPolicy::viewAny`). Quem **dispara** captura
 * é `admin`/`operador`, e esse controle mora na folha de detalhe — ler e
 * escrever são decisões diferentes e a tela não as mistura.
 *
 * A folha é `FiscalDocumentSheet.vue`, e esta página é a lista: filtro na URL,
 * tabela, cartões do telefone e paginação. O que é decisão de leitura (o valor em
 * reais, o dia da emissão, a contagem de eventos, o nome do tipo e da etapa) é de
 * `fiscalPresentation.ts` e é compartilhado com a folha, para que os dois lados
 * da costura nunca contem a mesma história de formas diferentes.
 */
definePageMeta({ middleware: 'auth' })

const route = useRoute()
const { list, download } = useFiscal()
const toast = useToast()

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

/** A busca está na URL quando o `q` dela sobreviveu ao parse do módulo. */
const hasActiveSearch = computed(() => Boolean(filters.value.q))

function clearFilters() {
  return navigateTo({ query: {} })
}

/* ------------------------------------------------------------------ *
 * Busca por texto: o rascunho sincronizado da URL
 * ------------------------------------------------------------------ */

/**
 * O rascunho da busca, e o valor que ele escreve com debounce de 300 ms.
 *
 * Espelha `clientes.vue`, mas a fonte aqui é a URL: o input é rascunho
 * sincronizado de `filters.q`, e a escrita passa por `updateFilters` — a
 * navegação é o que faz a busca parecer aplicada, e voltar à página 1 é o
 * que faz ela ser. O valor inicial vem da URL, para que a busca sobreviva
 * ao F5 como qualquer outro filtro.
 */
const search = ref(filters.value.q ?? '')
const debouncedSearch = refDebounced(search, 300)

/**
 * A sincronia é uma via de mão só no sentido URL→input, e o `watch` só atua
 * quando o valor difere: uma escrita que a URL já refletiria seria um loop
 * de navegação a cada tecla aplicada.
 */
watch(filters, (current) => {
  const value = current.q ?? ''
  if (search.value !== value) search.value = value
})

/**
 * Aplica a busca escrita, se ela difere do que já está na URL.
 *
 * Limpar o campo escreve `q: null` na URL — o filtro que o operador esvaziou
 * é o filtro que ele quer tirar, e `fiscalQuery` o omite da query.
 */
function applySearch(value: string) {
  if (value === (filters.value.q ?? '')) return

  return updateFilters({ ...filters.value, q: value === '' ? null : value })
}

/** O debounce dispara quando a digitação para; o Enter não espera por ele. */
watch(debouncedSearch, value => applySearch(value))

/**
 * O painel é casca: a URL continua a fonte. Modelo, tipo, cliente, prefixo de
 * CNPJ, emissão e valor entram no mesmo modelo. A busca fica no campo da
 * página — Limpar, dentro de Filtros, não apaga o `q`.
 */
const filterModels = computed(() => fiscalDocumentPanelModel(filters.value))

function onFilters(models: DataTableFilterModel[]) {
  return updateFilters(fiscalFiltersFromPanel(filters.value, models))
}

/**
 * Os modelos que o filtro oferece: os da consulta, mais os já selecionados.
 *
 * A fonte é `available_models`, que o backend tira do **resultado inteiro**
 * antes de paginar, e não as linhas desta página. A diferença é funcional: na
 * página 3 de uma lista só de NF-e o CT-e não tem linha nenhuma aqui e mesmo
 * assim é um filtro legal, offerable. Ler as linhas trocaria o filtro por um
 * estado de paginação — os chips mudariam conforme `page` e `per_page`, e um
 * filtro válido desapareceria.
 *
 * O `isFiscalModel` é o guard da outra ponta: um modelo novo de um backend mais
 * novo não vira chip, porque chip vira `?model=` e a API responde 422. O
 * selecionado entra mesmo sem resultado nenhum atrás, que é o que deixa o
 * filtro que esvaziou a tabela sair sem recarregar a página.
 */
const modelOptions = computed(() =>
  availableFiscalModels(
    (data.value?.available_models ?? []).filter(isFiscalModel),
    filters.value.model ?? []
  ).map(model => ({ label: modelLabel(model), value: model }))
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

const filterColumns = computed(() => fiscalDocumentPanelColumns(modelOptions.value, clientOptions.value))

/* ------------------------------------------------------------------ *
 * A folha de detalhe
 * ------------------------------------------------------------------ */

/**
 * A folha, e a única coisa que a página diz a ela.
 *
 * O detalhe é estado de outro arquivo: ele abre, carrega, guarda a linha do
 * tempo, baixa o XML e dispara a captura. Aqui só entra o id da linha que o
 * operador tocou — passar a linha inteira acoplar os dois lados a um objeto que
 * o detalhe vai reler da API de qualquer jeito.
 *
 * O tipo do `ref` vem do componente (`InstanceType`), e não de uma forma escrita
 * à mão: `useTemplateRef<{ open: (id: number) => void }>` compila igual com ou
 * sem o `defineExpose` do outro lado, e a página ficaria com um método que só
 * existe no papel. Importado à mão por isso — o mesmo que `work/tarefas.vue` faz
 * com os componentes que usa.
 */
const sheet = useTemplateRef<InstanceType<typeof FiscalDocumentSheet>>('sheet')

function openDetail(row: FiscalDocumentRow) {
  sheet.value?.open(row.id)
}

/* ------------------------------------------------------------------ *
 * Download direto por linha
 * ------------------------------------------------------------------ */

/**
 * O XML direto da linha, sem abrir a folha: mesma chamada autenticada que a
 * folha usa, com o mesmo acordo (Blob + clique programático + URL revogada).
 * O erro usa o toast existente, e não um alerta novo — um download que falhou
 * não é a lista que quebrou.
 */
const downloadingId = ref<number | null>(null)

async function downloadRow(row: FiscalDocumentRow) {
  if (downloadingId.value !== null) return

  downloadingId.value = row.id
  try {
    const blob = await download(row.id)
    const url = URL.createObjectURL(blob)
    try {
      const link = document.createElement('a')
      link.href = url
      link.download = `${row.chave_acesso}.xml`
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
    downloadingId.value = null
  }
}

/* ------------------------------------------------------------------ *
 * A tabela
 * ------------------------------------------------------------------ */

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
  { id: 'numero', header: 'Nº', meta: { class: { th: 'whitespace-nowrap tabular-nums', td: 'whitespace-nowrap tabular-nums' } } },
  { id: 'situacao', header: 'Situação', meta: { class: { th: 'whitespace-nowrap', td: 'whitespace-nowrap' } } },
  { id: 'completude', header: 'XML', meta: { class: { th: 'whitespace-nowrap', td: 'whitespace-nowrap' } } },
  { id: 'competencia', header: 'Competência', meta: { class: { th: 'whitespace-nowrap tabular-nums', td: 'whitespace-nowrap tabular-nums' } } },
  { id: 'modelo', header: 'Modelo', meta: { class: { th: 'whitespace-nowrap', td: 'whitespace-nowrap' } } },
  { id: 'emitente', header: 'Emitente', meta: { class: { th: 'min-w-40', td: 'max-w-0' } } },
  { id: 'destinatario', header: 'Destinatário', meta: { class: { th: 'min-w-40', td: 'max-w-0' } } },
  { id: 'valor', header: 'Valor', meta: { class: { th: 'whitespace-nowrap text-right', td: 'text-right tabular-nums' } } },
  { id: 'emissao', header: () => sortableHeader('Emissão', 'emissao_at'), meta: { class: { th: 'whitespace-nowrap', td: 'whitespace-nowrap' } } },
  { id: 'eventos', header: 'Eventos', meta: { class: { th: 'whitespace-nowrap', td: 'whitespace-nowrap' } } },
  { id: 'acoes', meta: { class: { th: 'w-24', td: 'w-24' } } }
])

const columnVisibility = ref<Record<string, boolean>>({})

const hideableColumns = [
  { id: 'cliente', label: 'Cliente' },
  { id: 'numero', label: 'Nº' },
  { id: 'situacao', label: 'Situação' },
  { id: 'completude', label: 'XML' },
  { id: 'competencia', label: 'Competência' },
  { id: 'modelo', label: 'Modelo' },
  { id: 'emitente', label: 'Emitente' },
  { id: 'destinatario', label: 'Destinatário' },
  { id: 'valor', label: 'Valor' },
  { id: 'emissao', label: 'Emissão' },
  { id: 'eventos', label: 'Eventos' }
]
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <div :class="sheetBodyClass">
      <DataTableFilterPanel
        :columns="filterColumns"
        :model-value="filterModels"
        :disabled="isLoading"
        class="min-w-0"
        @update:model-value="onFilters"
      >
        <p class="shrink-0 text-sm font-medium text-highlighted">
          Documentos capturados
        </p>

        <UInput
          v-model="search"
          icon="i-lucide-search"
          placeholder="Buscar nº, chave ou cliente…"
          maxlength="200"
          class="w-full min-w-0 flex-1"
          :disabled="isLoading"
          @keydown.enter="applySearch(search)"
        />
        <template #trailing>
          <DataTableColumnMenu
            v-model="columnVisibility"
            :columns="hideableColumns"
            class="hidden shrink-0 md:flex"
          />
        </template>
      </DataTableFilterPanel>

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
            ? hasActiveSearch
              ? 'A busca e os filtros aplicados não correspondem a nenhum documento capturado nesta conta. Ajuste a busca, os filtros ou volte para a lista inteira.'
              : 'Ajuste os filtros ou volte para a lista inteira para ver o que a conta capturou.'
            : 'Nenhum documento foi capturado nesta conta até agora. A primeira consulta aparece aqui quando a captura rodar.'"
          variant="naked"
          :actions="hasActiveFilters
            ? [{ label: 'Limpar filtros', icon: 'i-lucide-filter-x', color: 'neutral', variant: 'outline', onClick: clearFilters }]
            : undefined"
        />

        <template v-else>
          <!-- Mobile: cartões, porque oito colunas numa tela de bolso é rolagem lateral. -->
          <div class="min-h-0 flex-1 space-y-3 overflow-y-auto md:hidden">
            <UCard
              v-for="row in rows"
              :key="row.id"
              variant="subtle"
              :ui="{ root: 'overflow-visible', body: 'p-4' }"
            >
              <div class="flex items-start justify-between gap-3">
                <!--
                  O certificado é um indicador ao lado do nome, e não uma
                  coluna: o ponto pinta o estado do A1 e o tooltip nomeia —
                  `title` nativo, que é o que o telefone também lê.
                -->
                <div class="flex min-w-0 items-start gap-1.5">
                  <span
                    class="mt-1.5 size-2 shrink-0 rounded-full"
                    :class="{
                      'bg-error': fiscalClientCertificatePresentation(row.client_certificate_status).color === 'error',
                      'bg-warning': fiscalClientCertificatePresentation(row.client_certificate_status).color === 'warning',
                      'bg-success': fiscalClientCertificatePresentation(row.client_certificate_status).color === 'success',
                      'bg-elevated': fiscalClientCertificatePresentation(row.client_certificate_status).color === 'neutral'
                    }"
                    :title="`Certificado: ${fiscalClientCertificatePresentation(row.client_certificate_status).label}`"
                    role="img"
                    :aria-label="`Certificado: ${fiscalClientCertificatePresentation(row.client_certificate_status).label}`"
                  />
                  <DataTableIdentity
                    :title="row.client.name"
                    :meta="row.client.tax_id ? formatTaxId(row.client.tax_id) : ''"
                  />
                </div>
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
                    :aria-label="`Abrir documento ${fiscalNumeroLabel(row.numero, row.serie)}`"
                    @click="openDetail(row)"
                  />
                </div>
              </div>

              <div class="mt-3 flex flex-wrap items-center gap-1.5">
                <UBadge
                  :label="fiscalSituacaoPresentation(row.situacao).label"
                  :color="fiscalSituacaoPresentation(row.situacao).color"
                  variant="subtle"
                  size="sm"
                />
                <UBadge
                  v-if="row.completude"
                  :label="fiscalCompletudePresentation(row.completude).label"
                  :color="fiscalCompletudePresentation(row.completude).color"
                  variant="subtle"
                  size="sm"
                />
                <UBadge
                  :label="fiscalKindLabel(row.kind)"
                  variant="subtle"
                  size="sm"
                />
                <UBadge
                  :label="fiscalEventCount(row)"
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

              <div class="mt-3 flex items-center justify-between gap-3 text-xs tabular-nums text-muted">
                <span>Nº {{ fiscalNumeroLabel(row.numero, row.serie) }} · {{ fiscalCompetenciaLabel(row.competencia) }}</span>
                <span>{{ formatFiscalAmount(row.valor_total) }}</span>
              </div>
              <div class="mt-1 flex items-center justify-between gap-3 text-xs tabular-nums text-muted">
                <span>{{ formatFiscalDay(row.emissao_at) }}</span>
                <UButton
                  icon="i-lucide-download"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  :loading="downloadingId === row.id"
                  :aria-label="`Baixar XML do documento ${fiscalNumeroLabel(row.numero, row.serie)}`"
                  @click="downloadRow(row)"
                />
              </div>
            </UCard>
          </div>

          <div class="hidden min-h-0 min-w-0 flex-1 flex-col md:flex">
            <UTable
              v-model:column-visibility="columnVisibility"
              sticky
              :data="rows"
              :columns="columns"
              :loading="isLoading"
              class="h-full min-h-0 w-full flex-1"
              :ui="sheetTableUi"
            >
              <template #cliente-cell="{ row }">
                <!--
                  O certificado é um indicador ao lado do nome, e não uma
                  coluna: o ponto pinta o estado do A1 e o tooltip nomeia —
                  `title` nativo, que é o que o leitor de tela também lê via
                  `aria-label`.
                -->
                <div class="flex min-w-0 items-center gap-1.5">
                  <span
                    class="size-2 shrink-0 rounded-full"
                    :class="{
                      'bg-error': fiscalClientCertificatePresentation(row.original.client_certificate_status).color === 'error',
                      'bg-warning': fiscalClientCertificatePresentation(row.original.client_certificate_status).color === 'warning',
                      'bg-success': fiscalClientCertificatePresentation(row.original.client_certificate_status).color === 'success',
                      'bg-elevated': fiscalClientCertificatePresentation(row.original.client_certificate_status).color === 'neutral'
                    }"
                    :title="`Certificado: ${fiscalClientCertificatePresentation(row.original.client_certificate_status).label}`"
                    role="img"
                    :aria-label="`Certificado: ${fiscalClientCertificatePresentation(row.original.client_certificate_status).label}`"
                  />
                  <DataTableIdentity
                    :title="row.original.client.name"
                    :meta="row.original.client.tax_id ? formatTaxId(row.original.client.tax_id) : ''"
                  />
                </div>
              </template>

              <template #numero-cell="{ row }">
                {{ fiscalNumeroLabel(row.original.numero, row.original.serie) }}
              </template>

              <template #situacao-cell="{ row }">
                <UBadge
                  :label="fiscalSituacaoPresentation(row.original.situacao).label"
                  :color="fiscalSituacaoPresentation(row.original.situacao).color"
                  variant="subtle"
                  :ui="{ base: 'max-w-full', label: 'truncate' }"
                />
              </template>

              <template #completude-cell="{ row }">
                <!--
                  `null` na completude é a linha em que a pergunta não se
                  aplica — CT-e, evento, nota do próprio CNPJ — e a célula
                  fica com o traço do valor ausente, nunca com uma cor
                  inventada. Sem ação de manifestação aqui: a tela expõe o
                  estado, e o disparo mora fora da tabela.
                -->
                <UBadge
                  :label="fiscalCompletudePresentation(row.original.completude).label"
                  :color="fiscalCompletudePresentation(row.original.completude).color"
                  variant="subtle"
                  :ui="{ base: 'max-w-full', label: 'truncate' }"
                />
              </template>

              <template #competencia-cell="{ row }">
                {{ fiscalCompetenciaLabel(row.original.competencia) }}
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

              <template #emitente-cell="{ row }">
                <span class="tabular-nums">{{ formatTaxId(row.original.emitente_cnpj) }}</span>
              </template>

              <template #destinatario-cell="{ row }">
                <span class="tabular-nums">{{ formatTaxId(row.original.destinatario_cnpj) }}</span>
              </template>

              <template #valor-cell="{ row }">
                {{ formatFiscalAmount(row.original.valor_total) }}
              </template>

              <template #emissao-cell="{ row }">
                {{ formatFiscalDay(row.original.emissao_at) }}
              </template>

              <template #eventos-cell="{ row }">
                {{ fiscalEventCount(row.original) }}
              </template>

              <template #acoes-cell="{ row }">
                <div class="flex items-center justify-end gap-1">
                  <UButton
                    icon="i-lucide-download"
                    color="neutral"
                    variant="ghost"
                    :loading="downloadingId === row.original.id"
                    :aria-label="`Baixar XML do documento ${fiscalNumeroLabel(row.original.numero, row.original.serie)}`"
                    @click="downloadRow(row.original)"
                  />
                  <UButton
                    icon="i-lucide-chevron-right"
                    color="neutral"
                    variant="ghost"
                    :aria-label="`Abrir documento ${fiscalNumeroLabel(row.original.numero, row.original.serie)}`"
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
      O detalhe é uma folha sobre a tabela, e não uma rota. A folha, a linha
      do tempo, a prévia, o download e a captura moram no componente, e a
      página só diz qual documento abrir: a volta para a lista é o botão de
      fechar, que é onde a lista continua exatamente como estava.
    -->
    <FiscalDocumentSheet ref="sheet" />
  </div>
</template>
