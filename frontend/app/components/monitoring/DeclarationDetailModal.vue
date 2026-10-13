<script setup lang="ts">
import type { MetaListItem } from '~/components/data-table/MetaList.vue'
import type { MonitoringAssessmentPeriod } from '~/types/serpro'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import {
  formatMonitoringConsultedAt,
  formatMonitoringDate,
  monitoringActions,
  monitoringMissingValue,
  monitoringSlipStatusPresentation,
  slipStatusFor
} from '~/utils/monitoringPresentation'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  open: boolean
  obligation: MonitoringObligation
  clientId: number
  clientName: string
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const toast = useToast()
const { declarationDetail, downloadDeclarationArtifact } = useSerpro()
const { canManageClients } = useAuth()

const detailKey = computed(() =>
  `serpro-declaration-${props.obligation.slug}-${props.clientId}-${props.open ? 'open' : 'closed'}`
)

const { data, status, error, refresh } = await useAsyncData(
  detailKey,
  async () => {
    if (!props.open) return null
    return await declarationDetail(props.obligation.slug, props.clientId)
  },
  { watch: [() => props.open, () => props.clientId, () => props.obligation.slug], immediate: true }
)

const periods = computed(() => data.value?.periods ?? [])

const selectedPeriod = ref<string | undefined>(undefined)

watch([() => props.open, periods], () => {
  if (!props.open) {
    selectedPeriod.value = undefined
    return
  }
  const list = periods.value
  if (!list.length) {
    selectedPeriod.value = undefined
    return
  }
  const still = list.some(item => item.period === selectedPeriod.value)
  if (!still) {
    const sorted = [...list].sort((a, b) => b.period.localeCompare(a.period))
    selectedPeriod.value = sorted[0]?.period
  }
}, { immediate: true })

const activePeriod = computed(() =>
  periods.value.find(item => item.period === selectedPeriod.value) ?? null
)

const { isLoading, showError, retry } = useRetryableLoad({
  refresh,
  error,
  loading: computed(() => status.value === 'pending'),
  loadErrorTitle: 'Não foi possível carregar o detalhe da declaração',
  refreshErrorTitle: 'Não foi possível atualizar o detalhe',
  sticky: true
})

const periodOptions = computed(() =>
  periods.value.map(item => ({ label: item.period, value: item.period }))
)

function slipLabel(period: MonitoringAssessmentPeriod) {
  const status = slipStatusFor([period], period.period)
  return monitoringSlipStatusPresentation[status.status].label
}

const facts = computed<MetaListItem[]>(() => {
  const period = activePeriod.value
  if (!period) return []
  return [
    { label: 'Competência', value: period.period, mono: true, truncate: true },
    {
      label: 'Transmitida em',
      value: period.declared_at ? formatMonitoringDate(period.declared_at) : monitoringMissingValue,
      mono: true,
      truncate: true
    },
    {
      label: 'Número da declaração',
      value: period.declaration_number ?? monitoringMissingValue,
      mono: true,
      truncate: true
    },
    {
      label: 'Retificadora',
      value: period.rectified ? 'Sim' : 'Não',
      mono: true,
      truncate: true
    },
    { label: 'Guia (DAS)', value: slipLabel(period), mono: true, truncate: true },
    {
      label: 'Número do DAS',
      value: period.slip_number ?? monitoringMissingValue,
      mono: true,
      truncate: true
    },
    {
      label: 'DAS emitido em',
      value: period.slip_issued_at ? formatMonitoringDate(period.slip_issued_at) : monitoringMissingValue,
      mono: true,
      truncate: true,
      when: !!period.slip_issued_at
    }
  ]
})

const consultedAt = computed(() =>
  data.value?.consulted_at ? formatMonitoringConsultedAt(data.value.consulted_at) : monitoringMissingValue
)

const downloading = ref<'guia' | 'recibo' | 'declaracao' | null>(null)

function canDownload(artifact: 'guia' | 'recibo' | 'declaracao', period: MonitoringAssessmentPeriod | null): boolean {
  if (!period) return false
  if (artifact === 'guia') return !!period.declared_at
  return !!period.declaration_number
}

async function download(artifact: 'guia' | 'recibo' | 'declaracao') {
  const period = activePeriod.value
  if (!period || !canDownload(artifact, period)) return

  downloading.value = artifact
  try {
    const blob = await downloadDeclarationArtifact(
      props.obligation.slug,
      props.clientId,
      period.period,
      artifact
    )
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${artifact}-${period.period}.pdf`
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    toast.add({ title: 'Não foi possível baixar o documento', color: 'error' })
  } finally {
    downloading.value = null
  }
}
</script>

<template>
  <UModal
    v-bind="$attrs"
    v-model:open="isOpen"
    title="Detalhe da declaração"
    :description="`${obligation.label} · ${clientName}`"
  >
    <template #body>
      <ErrorRetryAlert
        v-if="showError"
        title="Não foi possível carregar o detalhe"
        @retry="retry"
      />

      <div v-else-if="isLoading && !periods.length" class="flex flex-col gap-3">
        <USkeleton class="h-10 w-full" />
        <USkeleton class="h-32 w-full" />
      </div>

      <div v-else class="flex flex-col gap-4">
        <p class="text-xs text-muted">
          Última consulta ao provedor: {{ consultedAt }}
        </p>

        <UAlert
          v-if="!periods.length"
          color="neutral"
          variant="subtle"
          icon="i-lucide-file-x"
          title="Nenhum período sincronizado"
          description="Este cliente ainda não tem competências PGDAS na base sincronizada. Dispare uma busca manual ou aguarde a próxima execução."
        />

        <template v-else>
          <UFormField label="Competência">
            <USelect
              v-model="selectedPeriod"
              :items="periodOptions"
              value-key="value"
              class="w-full"
            />
          </UFormField>

          <DataTableMetaList :items="facts" columns="grid-cols-2 gap-x-3 gap-y-2" />

          <UAlert
            v-if="!canManageClients"
            color="neutral"
            variant="subtle"
            icon="i-lucide-lock"
            title="Só administradores e operadores baixam documentos"
            description="A consulta de PDFs no Integra Contador é bilhetada. Peça a um administrador ou operador do escritório."
          />

          <div class="flex flex-wrap gap-2">
            <UButton
              :label="monitoringActions.downloadGuia"
              icon="i-lucide-file-down"
              color="neutral"
              variant="outline"
              size="sm"
              :disabled="!canManageClients || !canDownload('guia', activePeriod)"
              :loading="downloading === 'guia'"
              @click="download('guia')"
            />
            <UButton
              :label="monitoringActions.downloadRecibo"
              icon="i-lucide-file-down"
              color="neutral"
              variant="outline"
              size="sm"
              :disabled="!canManageClients || !canDownload('recibo', activePeriod)"
              :loading="downloading === 'recibo'"
              @click="download('recibo')"
            />
            <UButton
              :label="monitoringActions.downloadDeclaracao"
              icon="i-lucide-file-down"
              color="neutral"
              variant="outline"
              size="sm"
              :disabled="!canManageClients || !canDownload('declaracao', activePeriod)"
              :loading="downloading === 'declaracao'"
              @click="download('declaracao')"
            />
          </div>
        </template>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end">
        <UButton
          label="Fechar"
          color="neutral"
          variant="subtle"
          @click="isOpen = false"
        />
      </div>
    </template>
  </UModal>
</template>
