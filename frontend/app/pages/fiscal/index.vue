<script setup lang="ts">
import { VisArea, VisAxis, VisCrosshair, VisLine, VisTooltip, VisXYContainer } from '@unovis/vue'
import type { FiscalAttentionItem, FiscalSummary } from '~/types/fiscal'
import type { FiscalTone } from '~/utils/fiscalPresentation'
import { customerDetailPath } from '~/utils/customerRoutes'
import { fiscalDocumentosPath, isFiscalModel } from '~/utils/fiscalFilters'
import {
  attentionDescription,
  attentionGroups,
  attentionIcon,
  attentionLabel,
  attentionTone,
  blockedRemaining,
  coverageShare,
  coverageState,
  fiscalMissingValue,
  fiscalMonthSeries,
  fiscalNoAttention,
  fiscalStateCopy,
  formatFiscalCount,
  formatFiscalDateTime,
  lastCaptureOutcome,
  modelVolumes
} from '~/utils/fiscalPresentation'
import { pageScrollClass } from '~/utils/pageShell'

/**
 * O painel da captura, em `/fiscal`.
 *
 * Um middleware só, e é o mesmo de todas as páginas do produto: qualquer
 * membro da conta lê o painel. Quem dispara captura é `admin`/`operador`, e o
 * botão disso é da tela de documentos — o painel não esconde nada por causa do
 * papel, porque "não vi o botão" e "não posso ler" têm de continuar sendo
 * coisas diferentes.
 */
definePageMeta({ middleware: 'auth' })

const { summary } = useFiscal()

/** O resumo que o painel tem antes de a chamada responder. Só para o template. */
const EMPTY_SUMMARY: FiscalSummary = {
  coverage: { total: 0, capturable: 0, not_capturable: 0 },
  attention: [],
  documents: { total: 0, models: {}, over_time: [] },
  last_capture: null
}

/**
 * O relógio com que as janelas de bloqueio são contadas.
 *
 * Fica em `useState` por um motivo só: a hidratação. O servidor pinta
 * "faltam 2 dias e 7 h" e o cliente precisa pintar a mesma frase no primeiro
 * render, senão o Vue reclama do texto. O estado do Nuxt viaja no payload, e a
 * hydrated page lê o valor que o servidor gravou.
 *
 * E é por isso que ele é reancorado a cada resposta, logo abaixo: `useState` é
 * global e permanente, e um relógio que nunca é reancorado faz o painel contar
 * a janela de menos a cada recarregamento. O botão "Atualizar" traz
 * `blocked_until` novo do servidor e, se o relógio ficasse no primeiro render,
 * a mesma janela apareceria mais longa a cada clique — três horas depois, um
 * "2 dias e 10 h" para um bloqueio que o servidor disse durar 2 dias e 7 h.
 * A âncora mora aqui, e não no módulo de apresentação, porque ela tem de estar
 * na linha que traz a resposta: o defeito nasceu num `watch` do botão, que
 * reancorava tarde demais, e um helper que a página chamasse ainda poderia ser
 * chamado do lugar errado. A aritmética — o que sobra da contagem — é que
 * desce para `fiscalPresentation.ts`, porque ela é pura e testável ali.
 *
 * E o fato de esta decisão viver num SFC não a deixa sem guarda: o `.vue` não é
 * importável pelo runner de teste, então `tests/fiscalPresentation.test.ts` lê o
 * texto desta página e afirma que a reancoragem está no handler da busca, antes
 * do `return`, e que a contagem lê o relógio reancorado. É a leitura de fonte
 * que substitui o import, e é ela que faz a âncora importada de volta do módulo
 * quebrar um teste em vez de passar em silêncio.
 */
const referenceNow = useState('fiscal-panel-now', () => new Date().toISOString())

/**
 * Uma busca só, no servidor e no cliente, com a chave estável do módulo.
 *
 * O shell em `pages/fiscal.vue` não busca nada: quem busca é a página filha, e
 * é por isso que trocar de aba não repete a chamada. `getCachedData: () =>
 * undefined` força o dado a vir da API em cada entrada em vez de reaproveitar o
 * payload de um SSR antigo — um painel de captura que mostra a cobertura de
 * ontem é a mesma mentira de um gráfico de ontem.
 *
 * O relógio é reancorado aqui, dentro da busca, e não num `watch` do botão:
 * toda resposta nova invalida o relógio velho, e o caminho que chega a
 * `blockedUntil` novo é este. Um watcher só do botão deixaria de fora a
 * reancora da primeira renderização no cliente e qualquer outro caminho que
 * reexecute a busca.
 */
const { data, status, error, refresh: reload } = await useAsyncData<FiscalSummary>(
  'fiscal-summary',
  async () => {
    const fresh = await summary()
    referenceNow.value = new Date().toISOString()
    return fresh
  },
  { getCachedData: () => undefined }
)

const { isLoading, showError, refresh, retry } = useRetryableLoad({
  refresh: reload,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar o painel fiscal',
  refreshErrorTitle: 'Não foi possível atualizar o painel fiscal'
})

/**
 * A costura do recarregamento.
 *
 * O botão "Atualizar" do cabeçalho incrementa um contador em `useState`, e quem
 * responde é esta página, que é quem tem o dado — o mesmo par que
 * `useMonitoringActions` liga ao navbar do painel de monitoramento. O contador
 * é a ponta que a captura sobemandada vai bater quando a tela de documentos a
 * entregar: quem dispara a captura é quem sabe que a carteira mudou, e um
 * `refresh()` escondido dentro do clique não ensinaria o painel a se atualizar.
 */
const refreshRequest = useState('fiscal-refresh', () => 0)
watch(refreshRequest, () => {
  void refresh()
})

/**
 * O resumo, com o estado da carteira já resolvido.
 *
 * Os quatro estados saem de uma função testada, e não de quatro `v-if` espalhados
 * pelo template: é a decisão que o teste cobre, e um template que a reescreve
 * passa a poder discordar dela.
 *
 * O zerinho de espera é o mesmo do monitoramento: ele existe só para o template
 * não trabalhar com `undefined`, e nenhuma seção que afirma um estado o desenha
 * — todas estão atrás de `!isLoading`, e o erro tem o seu próprio caminho. Um
 * zero que escapa para a tela seria a tela mentindo, e o resumo zerado é
 * exatamente o número que a spec proíbe.
 */
const summaryData = computed<FiscalSummary>(() => data.value ?? EMPTY_SUMMARY)
const state = computed(() => coverageState(summaryData.value))
const share = computed(() => coverageShare(summaryData.value.coverage))
const hasDocuments = computed(() => summaryData.value.documents.total > 0)
const models = computed(() => modelVolumes(summaryData.value.documents.models))

/**
 * O mesmo volume, cada cartão com o caminho da tabela já filtrada por ele.
 *
 * `model.model` é `string` porque o mapa de volume do resumo traz o que o
 * backend mandou, e o filtro de modelo é uma lista fechada: um modelo fora
 * dela não pode virar `?model=`, porque a API responde 422 a valor que não
 * conhece. O cartão sem link continua dizendo o volume, que é o que ele sabe.
 */
const modelCards = computed(() => models.value.map(model => ({
  ...model,
  to: isFiscalModel(model.model) ? fiscalDocumentosPath({ model: [model.model] }) : undefined
})))
const series = computed(() => fiscalMonthSeries(summaryData.value.documents.over_time))
const groups = computed(() => attentionGroups(summaryData.value.attention))
const attentionTotal = computed(() => summaryData.value.attention.length)
const lastCapture = computed(() => lastCaptureOutcome(summaryData.value.last_capture))

/**
 * A frase do estado, sabendo se há alguém em atenção.
 *
 * O sinal vem do payload que a página já tem — `attention.length` — e não de
 * uma segunda chamada nem de um cálculo novo. Ele importa porque os dois
 * cartões são a mesma tela: sem ele, `no_documents` diria que a captura está no
 * ar em cima de uma lista dizendo que o fisco segurou a consulta, e
 * `no_capturable` mandaria à lista um cartão de lista vazia.
 */
const stateCopy = computed(() => {
  if (state.value === 'with_documents') return null
  return fiscalStateCopy(state.value, attentionTotal.value > 0)
})

/** O tempo que falta da janela, para o único motivo que tem hora de fim. */
function remaining(item: FiscalAttentionItem): string | null {
  return blockedRemaining(item.blocked_until, new Date(referenceNow.value))
}

/**
 * A série de emissão, no eixo do `unovis`.
 *
 * O gráfico é o mesmo `VisXYContainer` que o gráfico de crescimento da carteira
 * usa, e a série só entra no eixo quando tem mês: um gráfico com eixo em zero e
 * nenhuma medição é um número que ninguém emitiu. Por isso o cartão inteiro
 * depende de `series.length`, e não só de haver documentos.
 */
type Point = { index: number, label: string, total: number }

const chartRef = useTemplateRef<HTMLElement | null>('chartRef')
const { width } = useElementSize(chartRef)

const points = computed<Point[]>(() => series.value.map((point, index) => ({ index, ...point })))
const x = (point: Point) => point.index
const y = (point: Point) => point.total

/**
 * Rótulo a cada seis meses, e sempre o último.
 *
 * Uma série de emissão não tem tamanho conhecido — pode ser um mês de carbide ou
 * três anos de carteira — e um eixo com o mês escrito em cada tick vira uma tira
 * de números que não cabe na largura do cartão.
 */
const tickStride = computed(() => Math.max(1, Math.ceil(points.value.length / 6)))
const xTicks = (index: number) => (index % tickStride.value === 0 || index === points.value.length - 1 ? points.value[index]?.label ?? '' : '')
const tooltip = (point: Point) => `${point.label}: ${formatFiscalCount(point.total)}`

/**
 * As cores por severidade, escritas aqui e não no módulo de apresentação.
 *
 * Classe de Tailwind é apresentação e fica no arquivo que a consome; o módulo
 * puro devolve o nome do tom, e quem pinta decide a classe. O mapa é `Record`
 * sobre o tom do módulo, e não sobre `string`: um tom novo que ninguém pintou
 * vira erro de tipo aqui, e não uma caixa cinza silenciosa na tela.
 */
const toneClasses: Record<FiscalTone, string> = {
  neutral: 'bg-elevated text-muted ring-default',
  success: 'bg-success/10 text-success ring-success/20',
  warning: 'bg-warning/10 text-warning ring-warning/20',
  error: 'bg-error/10 text-error ring-error/20'
}

function toneClass(tone: FiscalTone): string {
  return toneClasses[tone]
}
</script>

<template>
  <div :class="pageScrollClass">
    <header class="flex min-w-0 items-center justify-between gap-3">
      <div class="flex min-w-0 items-center gap-2.5">
        <UIcon name="i-lucide-layout-dashboard" class="size-5 shrink-0 text-primary" />
        <h2 class="truncate text-base font-semibold tracking-tight text-highlighted sm:text-lg">
          Painel da captura
        </h2>
      </div>

      <UButton
        icon="i-lucide-refresh-cw"
        color="neutral"
        variant="outline"
        label="Atualizar"
        :loading="isLoading"
        class="shrink-0"
        @click="refreshRequest++"
      />
    </header>

    <ErrorRetryAlert
      v-if="showError"
      title="Não foi possível carregar o painel fiscal"
      @retry="retry"
    />

    <template v-else>
      <!--
        A leitura primária. A cobertura vem antes do volume porque é a única
        das três que responde "a carteira está sendo vigiada": o total de
        documentos diz quanto chegou, não se alguém estava olhando.
      -->
      <UPageGrid class="gap-3 sm:gap-3 lg:grid-cols-3 lg:gap-px">
        <MetricCard
          icon="i-lucide-shield-check"
          title="Cobertura da captura"
          tone="brand"
          :loading="isLoading"
        >
          <USkeleton v-if="isLoading" class="h-8 w-12" />
          <template v-else-if="share">
            <span class="text-2xl font-semibold tabular-nums text-highlighted">{{ share.percent }}%</span>
            <p class="mt-1 text-xs text-muted">
              {{ formatFiscalCount(share.capturable) }} de {{ formatFiscalCount(share.total) }} clientes capturáveis
            </p>
          </template>
          <template v-else>
            <span class="text-2xl font-semibold text-muted">{{ fiscalMissingValue }}</span>
            <p class="mt-1 text-xs text-muted">
              Nenhum cliente na carteira
            </p>
          </template>
        </MetricCard>

        <MetricCard
          icon="i-lucide-files"
          title="Documentos capturados"
          :to="hasDocuments ? '/fiscal/documentos' : undefined"
          :loading="isLoading"
        >
          <USkeleton v-if="isLoading" class="h-8 w-12" />
          <!--
            Sem documento o cartão não escreve "0": a captura pode não ter
            rodado, e um zero aqui seria uma medição que ninguém fez. A frase
            abaixo do número é que diz o que existe.
          -->
          <template v-else-if="hasDocuments">
            <span class="text-2xl font-semibold tabular-nums text-highlighted">{{ formatFiscalCount(summaryData.documents.total) }}</span>
            <p class="mt-1 text-xs text-muted">
              Documentos guardados nesta conta
            </p>
          </template>
          <template v-else>
            <span class="text-2xl font-semibold text-muted">nenhum ainda</span>
            <p class="mt-1 text-xs text-muted">
              Nada foi capturado até agora
            </p>
          </template>
        </MetricCard>

        <!--
          O zero aqui é a boa notícia que a spec pede em voz alta: nenhum
          cliente precisa de ação. Ele não tem a ambiguidade do zero de
          documentos, que não distingue "não havia" de "não dava para buscar".
        -->
        <MetricCard
          icon="i-lucide-circle-alert"
          title="Clientes em atenção"
          :loading="isLoading"
          :value="formatFiscalCount(attentionTotal)"
          :value-class="attentionTotal > 0 ? 'text-warning' : 'text-highlighted'"
          :to="attentionTotal > 0 ? '#atencao-operacional' : undefined"
        />
      </UPageGrid>

      <!--
        O estado da carteira, em uma frase por caso. Só aparece quando há algo
        a dizer: um painel com captura funcionando e documentos guardados não
        anuncia nada.
      -->
      <UCard
        v-if="!isLoading && stateCopy"
        class="min-w-0 ring ring-default"
        :ui="{ body: 'flex items-start gap-3 p-4 sm:p-4' }"
      >
        <span :class="['flex size-9 shrink-0 items-center justify-center rounded-lg ring ring-inset', toneClass(stateCopy.tone)]">
          <UIcon :name="stateCopy.icon" class="size-5" />
        </span>
        <div class="min-w-0">
          <h3 class="text-sm font-semibold text-highlighted">
            {{ stateCopy.title }}
          </h3>
          <p class="mt-1 text-sm text-muted">
            {{ stateCopy.description }}
          </p>
        </div>
      </UCard>

      <!--
        As medições de documento só existem quando existe documento, e o teste
        disso é o total, não o estado da carteira: uma conta sem cliente
        capturável pode ter guardado documento de quando tinha, e esconder esse
        número seria uma segunda mentira, do mesmo jeito que mostrá-lo como zero.
      -->
      <template v-if="!isLoading && hasDocuments">
        <section class="flex min-w-0 flex-col gap-3 pt-1">
          <div class="flex items-center gap-2">
            <UIcon name="i-lucide-receipt" class="size-4 shrink-0 text-muted" />
            <h3 class="text-sm font-semibold text-highlighted">
              Documentos por modelo
            </h3>
            <span class="hidden truncate text-xs text-muted sm:inline">
              Volume capturado, por modelo do documento.
            </span>
          </div>

          <UPageGrid class="gap-3 sm:gap-3 lg:grid-cols-4 lg:gap-px">
            <!--
              O cartão de modelo abre a tabela já filtrada por ele, e o caminho
              sai do mesmo módulo que a barra de filtro usa para escrever a URL —
              duas strings montadas à mão garantiriam dois links diferentes para
              o mesmo filtro. Um modelo que este build não conhece não vira
              link: `?model=` fora da lista fechada é 422, e um cartão que
              leva o operador a um erro não é atalho para lugar nenhum.
            -->
            <MetricCard
              v-for="model in modelCards"
              :key="model.model"
              icon="i-lucide-file-text"
              :title="model.label"
              :value="formatFiscalCount(model.total)"
              :to="model.to"
            />
          </UPageGrid>
        </section>

        <section class="flex min-w-0 flex-col gap-3 pt-1">
          <div class="flex items-center gap-2">
            <UIcon name="i-lucide-chart-no-axes-combined" class="size-4 shrink-0 text-muted" />
            <h3 class="text-sm font-semibold text-highlighted">
              Documentos por mês de emissão
            </h3>
            <span class="hidden truncate text-xs text-muted sm:inline">
              Só os meses que tiveram documento capturado.
            </span>
          </div>

          <UCard :ui="{ header: 'px-3 py-2.5 sm:px-4', body: 'px-0! pt-0! pb-2!' }">
            <template #header>
              <div class="flex min-w-0 items-baseline justify-between gap-3">
                <span class="truncate text-xs text-muted">
                  Documentos capturados
                </span>
                <span class="shrink-0 text-xs tabular-nums text-muted">
                  {{ formatFiscalCount(summaryData.documents.total) }} no total
                </span>
              </div>
            </template>

            <div v-if="series.length === 0" class="px-3 py-6 text-center text-sm text-muted">
              Nenhum documento com mês de emissão para desenhar a série.
            </div>

            <div v-else ref="chartRef" class="px-3 pb-3">
              <ClientOnly>
                <VisXYContainer
                  :data="points"
                  :padding="{ top: 16 }"
                  :margin="{ left: -5, right: -5 }"
                  class="h-48"
                  :width="width"
                >
                  <VisLine
                    :x="x"
                    :y="y"
                    color="var(--ui-primary)"
                  />
                  <VisArea
                    :x="x"
                    :y="y"
                    color="var(--ui-primary)"
                    :opacity="0.12"
                  />
                  <VisAxis
                    type="x"
                    :x="x"
                    :tick-format="xTicks"
                  />
                  <VisCrosshair
                    :x="x"
                    :y="y"
                    color="var(--ui-primary)"
                    :template="tooltip"
                  />
                  <VisTooltip />
                </VisXYContainer>
                <template #fallback>
                  <div class="h-48 w-full" />
                </template>
              </ClientOnly>
            </div>
          </UCard>
        </section>
      </template>

      <section class="flex min-w-0 flex-col gap-3 pt-1">
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-refresh-cw" class="size-4 shrink-0 text-muted" />
          <h3 class="text-sm font-semibold text-highlighted">
            Última consulta
          </h3>
          <span class="hidden truncate text-xs text-muted sm:inline">
            O que a última execução deixou na conta.
          </span>
        </div>

        <UCard class="min-w-0" :ui="{ body: 'flex items-start gap-3 p-4 sm:p-4' }">
          <span :class="['flex size-9 shrink-0 items-center justify-center rounded-lg ring ring-inset', toneClass(lastCapture.tone)]">
            <UIcon
              :name="lastCapture.tone === 'success' ? 'i-lucide-circle-check' : lastCapture.tone === 'warning' ? 'i-lucide-circle-alert' : 'i-lucide-clock'"
              class="size-5"
            />
          </span>
          <div class="min-w-0">
            <p class="text-sm font-medium text-highlighted">
              {{ lastCapture.title }}
            </p>
            <p class="mt-0.5 text-sm text-muted">
              {{ lastCapture.description }}
            </p>
          </div>
        </UCard>
      </section>

      <section id="atencao-operacional" class="flex min-w-0 flex-col gap-3 pt-1">
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-circle-alert" class="size-4 shrink-0 text-muted" />
          <h3 class="text-sm font-semibold text-highlighted">
            Atenção operacional
          </h3>
          <span class="hidden truncate text-xs text-muted sm:inline">
            O motivo de cada cliente e o que a captura não resolve sozinha.
          </span>
        </div>

        <div v-if="isLoading" class="space-y-2">
          <USkeleton v-for="n in 2" :key="n" class="h-24 w-full" />
        </div>

        <div
          v-else-if="groups.length === 0"
          class="flex items-start gap-3 rounded-lg bg-elevated/50 px-4 py-5"
        >
          <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
            <UIcon :name="fiscalNoAttention.icon" class="size-5" />
          </span>
          <div class="min-w-0">
            <p class="text-sm font-medium text-highlighted">
              {{ fiscalNoAttention.title }}
            </p>
            <p class="mt-0.5 text-sm text-muted">
              {{ fiscalNoAttention.description }}
            </p>
          </div>
        </div>

        <div v-else class="grid min-w-0 gap-3 lg:grid-cols-2">
          <UCard
            v-for="group in groups"
            :key="group.reason"
            class="min-w-0 overflow-hidden ring ring-default"
            :ui="{
              header: 'border-b border-default bg-elevated/25 px-3 py-3 sm:px-4',
              body: 'p-0 sm:p-0'
            }"
          >
            <template #header>
              <div class="flex min-w-0 items-center gap-2">
                <span :class="['flex size-8 shrink-0 items-center justify-center rounded-lg ring ring-inset', toneClass(attentionTone(group.reason))]">
                  <UIcon :name="attentionIcon(group.reason)" class="size-4" />
                </span>
                <h4 class="min-w-0 flex-1 truncate text-sm font-semibold text-highlighted">
                  {{ attentionLabel(group.reason) }}
                </h4>
                <UBadge
                  :label="formatFiscalCount(group.items.length)"
                  variant="subtle"
                  size="sm"
                  class="shrink-0"
                />
              </div>
            </template>

            <div class="border-b border-default px-4 py-3">
              <p class="text-xs text-muted">
                {{ attentionDescription(group.reason) }}
              </p>
            </div>

            <ul class="max-h-72 divide-y divide-default overflow-y-auto">
              <li
                v-for="item in group.items"
                :key="`${group.reason}-${item.client_id}`"
                class="min-h-14"
              >
                <NuxtLink
                  :to="customerDetailPath(item.client_id)"
                  class="flex min-h-14 items-center justify-between gap-3 px-4 py-2.5 transition-colors hover:bg-elevated/50"
                >
                  <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-highlighted">
                      {{ item.client_name }}
                    </p>
                    <!--
                      Só o bloqueio tem a linha de baixo, porque só ele tem hora
                      de fim. A razão é a do grupo — repeti-la em cada linha seria
                      nove vezes a mesma frase.
                    -->
                    <p v-if="remaining(item)" class="mt-0.5 truncate text-xs tabular-nums text-muted">
                      Bloqueado até {{ formatFiscalDateTime(item.blocked_until) }} · {{ remaining(item) }}
                    </p>
                  </div>
                  <UIcon name="i-lucide-arrow-up-right" class="size-4 shrink-0 text-muted" />
                </NuxtLink>
              </li>
            </ul>
          </UCard>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.unovis-xy-container {
  --vis-crosshair-line-stroke-color: var(--ui-primary);
  --vis-crosshair-circle-stroke-color: var(--ui-bg);
  --vis-axis-grid-color: var(--ui-border);
  --vis-axis-tick-color: var(--ui-border);
  --vis-axis-tick-label-color: var(--ui-text-dimmed);
  --vis-tooltip-background-color: var(--ui-bg);
  --vis-tooltip-border-color: var(--ui-border);
  --vis-tooltip-text-color: var(--ui-text-highlighted);
}
</style>
