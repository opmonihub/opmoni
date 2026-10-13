<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import type { ClientSheet } from '~/types/client'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import { monitoringFilters } from '~/utils/monitoringPresentation'
import { clientSheetTaxIdLabel } from '~/utils/portfolioLabels'
import type { SerproManualSearchMode } from '~/types/serpro'
import { manualSearchModePresentation, manualSearchQuotaColor, manualSearchQuotaLabel, quotaExceededMessages } from '~/utils/serproManualSearch'

const props = defineProps<{
  open: boolean
  obligation: MonitoringObligation
  /**
   * The ids the sheet has already loaded for this obligation — the fallback
   * roster when the quota endpoint has not shipped, and the guard that keeps a
   * searched office client out of a list where it is not monitored.
   */
  associatedIds: number[]
  /** The rows the office checked in the sheet before opening the modal. */
  selectedIds?: number[]
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'requested': []
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const toast = useToast()
const { list } = useClients()
const { searchDocuments, searchQuota } = useSerpro()

const search = ref('')
const debouncedSearch = refDebounced(search, 350)
const candidates = ref<ClientSheet[]>([])
const selected = ref<number[]>([])
const loading = ref(false)
const saving = ref(false)
const mode = ref<SerproManualSearchMode>('full')
const recalculateDate = ref('')
/** The quota per client, and whether the read itself failed. */
const quotaByClient = ref<Record<number, { used: number, limit: number }>>({})
const quotaUnavailable = ref(false)
const quotaRefusals = ref<string[]>([])

/**
 * The roster of the obligation: the office's companies that are monitored
 * here. The quota response carries every monitored client's id, so when it
 * arrived it is the authoritative roster and names are joined from the office
 * list; without it, the sheet's loaded rows stand in — a partial roster is
 * still a roster of real members, never one that invents clients.
 */
async function loadCandidates() {
  loading.value = true
  try {
    // `sheet: 1` asks the whole match set instead of a page of it: the roster
    // filter below and the search both mean "every client matching", and a
    // paged list would quietly turn that into "the first 25".
    const response = await list({ sheet: 1, q: debouncedSearch.value.trim(), sort: 'name', direction: 'asc' })
    const companies = (response.data ?? []).filter(client => client.person_type === 'company')
    const roster = quotaUnavailable.value || Object.keys(quotaByClient.value).length === 0
      ? new Set(props.associatedIds)
      : new Set(Object.keys(quotaByClient.value).map(Number))
    candidates.value = companies.filter(client => roster.has(client.id))
    selected.value = selected.value.filter(id => candidates.value.some(client => client.id === id))
  } catch {
    toast.add({ title: 'Não foi possível carregar os clientes', color: 'error' })
  } finally {
    loading.value = false
  }
}

async function loadQuota() {
  try {
    const entries = await searchQuota(props.obligation.slug)
    quotaByClient.value = Object.fromEntries(
      (entries ?? []).map(entry => [entry.client_id, { used: entry.used, limit: entry.limit }])
    )
    quotaUnavailable.value = false
  } catch (e) {
    // A 404 is the read API not having shipped yet — the inert state, not a
    // failure worth shouting about; the column renders the em dash and the
    // roster falls back to the sheet's rows. Anything else is a real failure.
    if (apiStatus(e) === 404) {
      quotaByClient.value = {}
      quotaUnavailable.value = true
      return
    }
    quotaByClient.value = {}
    quotaUnavailable.value = true
    toast.add({ title: 'Não foi possível carregar a cota do mês', description: apiErrorMessage(e), color: 'error' })
  }
}

watch(isOpen, (open) => {
  if (!open) return
  // A fresh open starts a fresh request: the preselected rows enter checked,
  // the optional fields and a previous quota refusal do not survive it.
  mode.value = 'full'
  recalculateDate.value = ''
  quotaRefusals.value = []
  selected.value = [...(props.selectedIds ?? [])]
  void loadQuota().then(() => loadCandidates())
})

watch(debouncedSearch, () => {
  if (!isOpen.value) return
  void loadCandidates()
})

/** "Selecionar todos" means every candidate matching the search. */
const allSelected = computed(() => candidates.value.length > 0 && selected.value.length === candidates.value.length)

function toggleAll() {
  selected.value = allSelected.value ? [] : candidates.value.map(client => client.id)
}

function toggleOne(id: number) {
  selected.value = selected.value.includes(id)
    ? selected.value.filter(item => item !== id)
    : [...selected.value, id]
}

async function submitSearch() {
  if (!selected.value.length || saving.value) return
  saving.value = true
  quotaRefusals.value = []
  try {
    await searchDocuments(props.obligation.slug, {
      client_ids: selected.value,
      mode: mode.value,
      recalculate_date: recalculateDate.value || undefined
    })
    toast.add({
      title: `Busca disparada para ${selected.value.length} ${selected.value.length === 1 ? 'cliente' : 'clientes'}`,
      description: 'As linhas entram em "Processando" enquanto a busca dura.',
      color: 'success'
    })
    isOpen.value = false
    emit('requested')
  } catch (e) {
    // The quota refusal (422) names each exceeded client: it is an alert over
    // the list, not a toast that vanishes — the office has to read who to take
    // out of the selection.
    if (apiStatus(e) === 422) {
      quotaRefusals.value = quotaExceededMessages(e)
      if (!quotaRefusals.value.length) quotaRefusals.value = ['Alguns clientes excederam a cota mensal de buscas.']
    } else {
      toast.add({ title: 'Não foi possível disparar as buscas', description: apiErrorMessage(e), color: 'error' })
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal
    v-model:open="isOpen"
    :title="`Buscar documentos de ${obligation.label}`"
    :description="`Dispara a busca manual para os clientes selecionados. A cota é de 10 buscas por cliente neste documento, por mês.`"
  >
    <template #body>
      <div class="flex flex-col gap-3">
        <UInput
          v-model="search"
          icon="i-lucide-search"
          :placeholder="monitoringFilters.search"
          aria-label="Buscar clientes para buscar documentos"
        />

        <div class="grid gap-3 sm:grid-cols-2">
          <UFormField
            label="Modo da busca"
            name="mode"
          >
            <URadioGroup
              v-model="mode"
              :items="[
                { value: 'full', label: manualSearchModePresentation.full.label, description: manualSearchModePresentation.full.description },
                { value: 'slip_status', label: manualSearchModePresentation.slip_status.label, description: manualSearchModePresentation.slip_status.description }
              ]"
              variant="card"
              size="xs"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Data de recálculo"
            name="recalculate_date"
            help="Opcional. Em branco, a busca cobre o período corrente."
          >
            <UInput
              v-model="recalculateDate"
              type="date"
              class="w-full"
            />
          </UFormField>
        </div>

        <UAlert
          v-if="quotaRefusals.length"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="Cota mensal excedida"
        >
          <template #description>
            <ul class="list-disc ps-4">
              <li
                v-for="(refusal, index) in quotaRefusals"
                :key="index"
              >
                {{ refusal }}
              </li>
            </ul>
          </template>
        </UAlert>

        <USkeleton v-if="loading" class="h-40 w-full" />

        <UEmpty
          v-else-if="candidates.length === 0"
          icon="i-lucide-user-round-search"
          title="Nenhum cliente monitorado"
          description="Nenhum cliente desta obrigação corresponde à busca."
          variant="naked"
        />

        <div
          v-else
          class="flex flex-col gap-1"
        >
          <div class="flex items-center justify-between gap-3 px-2 py-1.5">
            <UCheckbox
              :model-value="allSelected ? true : selected.length > 0 ? 'indeterminate' : false"
              label="Selecionar todos"
              class="min-w-0 flex-1"
              @update:model-value="toggleAll"
            />
            <span class="shrink-0 text-xs font-medium text-muted">
              Consultas do mês
            </span>
          </div>
          <div class="flex max-h-80 flex-col gap-1 overflow-y-auto">
            <div
              v-for="client in candidates"
              :key="client.id"
              class="flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-elevated/50"
            >
              <UCheckbox
                :model-value="selected.includes(client.id)"
                :label="client.name"
                :description="clientSheetTaxIdLabel(client)"
                class="min-w-0 flex-1"
                @update:model-value="toggleOne(client.id)"
              />
              <!--
                The client's share of the month: the bar the plan names "X de
                10". Without a quota entry it renders the em dash — no quota
                arrived, and `0 de 10` would be a claim about data nobody made.
              -->
              <span
                v-if="manualSearchQuotaLabel(quotaByClient[client.id]?.used, quotaByClient[client.id]?.limit)"
                class="flex w-28 shrink-0 flex-col items-end gap-0.5"
              >
                <span class="text-xs tabular-nums text-muted">
                  {{ manualSearchQuotaLabel(quotaByClient[client.id]?.used, quotaByClient[client.id]?.limit) }}
                </span>
                <UProgress
                  :model-value="quotaByClient[client.id]?.used ?? 0"
                  :max="quotaByClient[client.id]?.limit ?? 10"
                  size="2xs"
                  :color="manualSearchQuotaColor(quotaByClient[client.id]?.used ?? 0, quotaByClient[client.id]?.limit ?? 10)"
                />
              </span>
              <span
                v-else
                class="w-28 shrink-0 text-end text-xs text-dimmed"
              >
                —
              </span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between gap-3">
        <UButton
          :label="allSelected ? 'Desmarcar todos' : 'Selecionar todos'"
          color="neutral"
          variant="ghost"
          :disabled="candidates.length === 0"
          @click="toggleAll"
        />
        <UButton
          :label="`Buscar documentos (${selected.length})`"
          icon="i-lucide-search"
          :disabled="selected.length === 0"
          :loading="saving"
          @click="submitSearch"
        />
      </div>
    </template>
  </UModal>
</template>
