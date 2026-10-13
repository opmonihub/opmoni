<script setup lang="ts">
import { MapProjection } from '@unovis/ts'
import { VisSingleContainer, VisTopoJSONMap, VisTooltip, VisTopoJSONMapSelectors } from '@unovis/vue'
import type { PortfolioBucket } from '~/types/client'
import { formatPtCount } from '~/utils/portfolioLabels'
import brazilUf from '~/assets/maps/brazil-uf.topo.json'

const props = defineProps<{
  items: PortfolioBucket[]
  loading?: boolean
}>()

type AreaDatum = {
  id: string
  name: string
  count: number
}

const UF_NAMES: Record<string, string> = {
  AC: 'Acre',
  AL: 'Alagoas',
  AP: 'Amapá',
  AM: 'Amazonas',
  BA: 'Bahia',
  CE: 'Ceará',
  DF: 'Distrito Federal',
  ES: 'Espírito Santo',
  GO: 'Goiás',
  MA: 'Maranhão',
  MT: 'Mato Grosso',
  MS: 'Mato Grosso do Sul',
  MG: 'Minas Gerais',
  PA: 'Pará',
  PB: 'Paraíba',
  PR: 'Paraná',
  PE: 'Pernambuco',
  PI: 'Piauí',
  RJ: 'Rio de Janeiro',
  RN: 'Rio Grande do Norte',
  RS: 'Rio Grande do Sul',
  RO: 'Rondônia',
  RR: 'Roraima',
  SC: 'Santa Catarina',
  SP: 'São Paulo',
  SE: 'Sergipe',
  TO: 'Tocantins'
}

/** Solid steps — CSS color-mix inside linear-gradient often fails; discrete swatches stay visible. */
const EMPTY_COLOR = 'var(--ui-elevated)'
const HEAT_STEPS = [
  { ratio: 0.2, color: 'color-mix(in oklab, var(--ui-primary) 28%, var(--ui-bg))' },
  { ratio: 0.4, color: 'color-mix(in oklab, var(--ui-primary) 45%, var(--ui-bg))' },
  { ratio: 0.6, color: 'color-mix(in oklab, var(--ui-primary) 62%, var(--ui-bg))' },
  { ratio: 0.8, color: 'color-mix(in oklab, var(--ui-primary) 78%, var(--ui-bg))' },
  { ratio: 1, color: 'var(--ui-primary)' }
] as const

const cardRef = useTemplateRef<HTMLElement | null>('cardRef')
const { width } = useElementSize(cardRef)
const mapHeight = 300

const topojson = brazilUf as {
  type: 'Topology'
  objects: Record<string, unknown>
  arcs: unknown
  transform?: unknown
}

/** Conic equal-area fits Brazil better than plain Mercator. */
const projection = MapProjection.ConicEqualArea()
  .parallels([-2, -22])
  .rotate([54, 0])

const counts = computed(() =>
  new Map(props.items.map(item => [item.key.toUpperCase(), item.count]))
)

const max = computed(() => Math.max(1, ...props.items.map(item => item.count)))

const areas = computed<AreaDatum[]>(() =>
  Object.keys(UF_NAMES).map(id => ({
    id,
    name: UF_NAMES[id]!,
    count: counts.value.get(id) ?? 0
  }))
)

const mapData = computed(() => ({ areas: areas.value }))

function heatColor(ratio: number) {
  const clamped = Math.min(1, Math.max(0, ratio))
  for (const step of HEAT_STEPS) {
    if (clamped <= step.ratio) return step.color
  }
  return HEAT_STEPS[HEAT_STEPS.length - 1]!.color
}

function areaColor(d: AreaDatum) {
  if (d.count <= 0) return EMPTY_COLOR
  return heatColor(d.count / max.value)
}

const areaId = (d: AreaDatum) => d.id
const areaCursor = () => 'pointer'
const areaLabel = (d: AreaDatum) => (d.count > 0 ? d.id : '')

const legendSteps = computed(() => [
  { label: '0', color: EMPTY_COLOR },
  ...HEAT_STEPS.map((step, index) => ({
    label: index === HEAT_STEPS.length - 1 ? formatPtCount(max.value) : '',
    color: step.color
  }))
])

const triggers = {
  [VisTopoJSONMapSelectors.feature]: (feature: { id?: string | number, data?: AreaDatum, properties?: { name?: string, sigla?: string } }) => {
    const area = feature?.data
    const id = area?.id ?? String(feature?.id ?? feature?.properties?.sigla ?? '')
    const name = area?.name ?? feature?.properties?.name ?? UF_NAMES[id] ?? id
    const count = area?.count ?? counts.value.get(id) ?? 0
    return `${name} (${id}): ${formatPtCount(count)}`
  }
}
</script>

<template>
  <UCard
    ref="cardRef"
    class="flex h-full min-w-0 flex-col"
    :ui="{
      root: 'flex h-full flex-col',
      header: 'px-3 py-2.5 sm:px-4',
      body: 'flex flex-1 flex-col gap-3 px-3 py-3 sm:px-4 sm:py-3'
    }"
  >
    <template #header>
      <h3 class="truncate text-sm font-semibold text-highlighted">
        Mapa por estado
      </h3>
    </template>

    <div v-if="loading" class="space-y-2">
      <USkeleton class="h-72 w-full" />
      <USkeleton class="h-4 w-full" />
    </div>

    <template v-else>
      <ClientOnly>
        <VisSingleContainer
          :data="mapData"
          :width="Math.max(width, 240)"
          :height="mapHeight"
          class="mx-auto h-72 w-full"
        >
          <VisTopoJSONMap
            :topojson="topojson"
            map-feature-name="estados"
            :projection="projection"
            :area-id="areaId"
            :area-color="areaColor"
            :area-cursor="areaCursor"
            :area-label="areaLabel"
            :disable-zoom="true"
          />
          <VisTooltip :triggers="triggers" />
        </VisSingleContainer>
        <template #fallback>
          <USkeleton class="h-72 w-full" />
        </template>
      </ClientOnly>

      <div class="mt-auto space-y-1.5">
        <div class="flex items-center gap-2 text-xs text-muted">
          <span class="shrink-0 tabular-nums">0</span>
          <div
            class="flex h-2.5 min-w-0 flex-1 overflow-hidden rounded-full ring ring-inset ring-default"
            role="img"
            :aria-label="`Escala de clientes por estado, de 0 a ${formatPtCount(max)}`"
          >
            <div
              v-for="(step, index) in legendSteps"
              :key="index"
              class="h-full flex-1"
              :style="{ backgroundColor: step.color }"
            />
          </div>
          <span class="shrink-0 tabular-nums">{{ formatPtCount(max) }}</span>
        </div>
        <p class="text-[11px] text-dimmed">
          Cor mais forte = mais clientes na UF
        </p>
      </div>
    </template>
  </UCard>
</template>

<style scoped>
:deep(.unovis-single-container) {
  --vis-map-feature-color: var(--ui-elevated);
  --vis-map-boundary-color: color-mix(in oklab, var(--ui-border) 70%, var(--ui-text-dimmed));
  --vis-map-area-label-text-color: var(--ui-text-highlighted);
  --vis-map-area-label-font-size: 10px;
  --vis-map-area-label-font-weight: 600;
  --vis-tooltip-background-color: var(--ui-bg);
  --vis-tooltip-border-color: var(--ui-border);
  --vis-tooltip-text-color: var(--ui-text-highlighted);
}

:deep(.unovis-single-container path) {
  stroke-width: 0.75;
}
</style>
