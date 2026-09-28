<script setup lang="ts">
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import { apiMessage, apiStatus } from '~/composables/useApiError'
import type { FiscalCaptureBlocked, FiscalDetail } from '~/types/fiscal'
import {
  captureQueuedCopy,
  fiscalEventCount,
  fiscalKindLabel,
  fiscalMissingValue,
  fiscalSourceLabel,
  fiscalStageLabel,
  formatFiscalAmount,
  formatFiscalCount,
  formatFiscalDateTime,
  formatFiscalDay,
  modelLabel
} from '~/utils/fiscalPresentation'
import { formatTaxId } from '~/utils/taxId'

/**
 * A folha de detalhe do documento, sobre a tabela de `/fiscal/documentos`.
 *
 * Ela é uma folha e não uma rota porque o documento vive dentro de uma lista
 * filtrável: um link por documento seria um id que o operador cola e outra
 * entrada de histórico para cada leitura. O estado inteiro do detalhe — abrir,
 * carregar, linha do tempo, prévia, download e captura — mora aqui, e a página
 * só diz qual documento é.
 *
 * A separação existe porque essa é a forma que a casa já usa
 * (`MonitoringSheet.vue`, `MessageDetail.vue`) e porque o `.vue` da página não é
 * importável pelo runner de teste: o que é decisão e não marcação desce para
 * `fiscalPresentation.ts`, e o que é comportamento fica num dos dois arquivos.
 */
const { show, download, capture } = useFiscal()
const { canManageClients } = useAuth()
const toast = useToast()

const open = ref(false)
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
 * lista não precisa de guarda porque a chave do `useAsyncData` já separa uma
 * consulta da outra; aqui a escrita é num `ref` desta folha, e o `ref` é o que
 * precisa se defender.
 */
let detailGeneration = 0

/**
 * Abre a folha no documento pedido.
 *
 * A página chama isto com o id da linha; o `id` volta a ser apenas o id, e não
 * um objeto inteiro da lista, porque o detalhe é uma releitura da API e não a
 * linha que o operador tinha na tela.
 */
async function openDocument(id: number) {
  const seen = ++detailGeneration
  open.value = true
  detail.value = null
  detailPending.value = true

  try {
    const loaded = await show(id)
    if (seen !== detailGeneration) return
    detail.value = loaded
  } catch {
    if (seen !== detailGeneration) return
    toast.add({ title: 'Não foi possível abrir o documento', color: 'error' })
  } finally {
    if (seen === detailGeneration) detailPending.value = false
  }
}

defineExpose({ open: openDocument })

const detailFacts = computed<MetaListItem[]>(() => {
  const target = detail.value
  if (!target) return []

  return [
    { label: 'Chave de acesso', value: target.chave_acesso, mono: true },
    { label: 'Modelo', value: modelLabel(target.model) },
    { label: 'Tipo', value: fiscalKindLabel(target.kind) },
    { label: 'Etapa', value: fiscalStageLabel(target.stage) },
    { label: 'Emitente', value: formatTaxId(target.emitente_cnpj), mono: true },
    { label: 'Destinatário', value: formatTaxId(target.destinatario_cnpj), mono: true },
    { label: 'Valor total', value: formatFiscalAmount(target.valor_total), mono: true },
    { label: 'Emissão', value: formatFiscalDay(target.emissao_at), mono: true },
    { label: 'Capturado em', value: formatFiscalDateTime(target.captured_at), mono: true },
    // A distribuição e a posição são duas linhas e não uma composta: com
    // `source` ausente, a composta viraria "— · NSU 12", que parece um valor
    // de distribuição. Separadas, o traço marca o que falta e a posição continua
    // sendo lida.
    { label: 'Distribuição', value: fiscalSourceLabel(target.source) },
    { label: 'Posição na distribuição (NSU)', value: formatFiscalCount(target.nsu) },
    { label: 'Código do evento', value: target.event_id || fiscalMissingValue, mono: true },
    { label: 'Eventos', value: fiscalEventCount(target), mono: true },
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
 * codificação que a projeção recusa em vez de adivinhar. Nenhum dos dois é erro
 * — e nenhum dos dois tira o download, que serve o byte cru que a prévia não
 * conseguiu mostrar.
 */
const xmlPreview = computed(() => detail.value?.xml_preview ?? null)

/**
 * O download é uma requisição autenticada, e por isso um `Blob` e um clique
 * programático.
 *
 * Um `<a href>` para a rota do XML seria uma navegação sem o cookie de sessão,
 * que o servidor responderia com 401 em vez do arquivo. A URL do objeto é
 * revogada assim que o navegador recebeu o clique — ela existe para o download,
 * não para ficar na memória.
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
 * duas divergem. O que fica é só a leitura: o status 409 é o que separa a recusa
 * do fisco de qualquer outra falha, e `blocked_until` é a hora em que a janela
 * acaba, que é o que o operador precisa para não tentar de novo cedo.
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
  // A fonte é o que o pedido de captura leva, e `FiscalDetail.source` é
  // nullable pelo mesmo motivo de `FiscalLastCapture.source`. Sem ela não há
  // distribuição a consultar: o botão nem aparece nesse caso, e a guarda aqui é
  // a segunda metade do mesmo acordo — o template não é a única coisa que
  // protege o `capture` de receber uma fonte que não existe.
  if (!target || !target.source || capturing.value) return

  capturing.value = true
  try {
    await capture(target.client.id, target.source)
    // A frase mora no módulo porque é decisão, não marcação: o `.vue` não é
    // importável pelo runner de teste, e uma decisão que só existe aqui dentro
    // fica sem guarda. O que ela não faz é prometer documento — o detalhe não
    // sabe se este cliente é capturável, e dizer que os documentos chegam seria
    // afirmar o que o payload não sustenta.
    toast.add({
      ...captureQueuedCopy(target.source),
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
  <!--
    A volta para a lista é o botão de fechar, que é onde a lista continua
    exatamente como estava: a folha não navega, e o filtro do operador não muda
    por causa de uma leitura.
  -->
  <USlideover
    v-model:open="open"
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

          A segunda condição é o `source` do detalhe: o pedido de captura é por
          distribuição, e sem a fonte o chamador não teria o que mandar. Um
          botão que enfileiraria a distribuição padrão para um documento cujo
          `source` o backend não mandou seria a tela escolhendo por conta
          própria a consulta que o operador não pediu.
        -->
        <UButton
          v-if="canManageClients && detail && detail.source"
          label="Capturar agora"
          icon="i-lucide-refresh-cw"
          color="neutral"
          variant="outline"
          :loading="capturing"
          :title="detail?.source ? `Consulta de ${fiscalSourceLabel(detail.source)} deste cliente` : undefined"
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
</template>
