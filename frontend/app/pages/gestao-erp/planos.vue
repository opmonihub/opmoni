<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { OfficeErpCommandResult, OfficeErpOffer, OfficeErpOfferListResponse, OfficeErpOfferTerms } from '#shared/types/officeErpCommercial'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelRowActionsClass, panelTableUi } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const command = useOfficeErpCommand()
const route = useRoute()
const toast = useToast()

const offers = ref<OfficeErpOffer[]>([])
const query = ref('')
const debouncedQuery = refDebounced(query, 300)
const status = ref<'active' | 'archived'>('active')
const page = ref(1)
const perPage = ref<25 | 50 | 100>(25)
const total = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const loadError = ref('')
const confirmedEmpty = ref(false)
const editorOpen = ref(false)
const editingOffer = ref<OfficeErpOffer | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})
const mutationFailure = ref('')
const commandBusy = ref(false)
const archiveTarget = ref<OfficeErpOffer | null>(null)
const archiveConfirmOpen = computed({
  get: () => archiveTarget.value !== null,
  set: (open: boolean) => { if (!open) archiveTarget.value = null }
})
let listRequest = 0
let commandBusyGeneration = 0

const capabilities = context.capabilities
const identityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null,
  capabilities.value?.capability_key ?? null
]))
const moduleKeys = computed(() => capabilities.value?.allowance.module_keys ?? [])
const capacityLabel = computed(() => {
  const allowance = capabilities.value?.allowance
  if (!allowance) return ''
  const used = capabilities.value!.usage.reserved + capabilities.value!.usage.occupied
  return allowance.licence_limit === null
    ? `${used} licenças em uso ou reservadas · capacidade ilimitada`
    : `${used} de ${allowance.licence_limit} licenças em uso ou reservadas`
})
const salesReason = computed(() => {
  const labels: Record<string, string> = {
    subscription_inactive: 'A assinatura do escritório não está ativa.',
    commercial_resale_disabled: 'O plano do escritório não liberou a revenda de ERP.',
    licence_capacity_reached: 'A capacidade de licenças do escritório foi atingida.'
  }
  const reasons = Object.keys(capabilities.value?.reasons ?? {}).map(reason => labels[reason] ?? reason)
  return reasons.join(' ')
})
const legacyNotice = computed(() => route.query.origem === 'admin-erp')
const recoveryKey = computed(() => command.recoveryKey.value)
const pageSizeOptions = [
  { label: '25 por página', value: 25 },
  { label: '50 por página', value: 50 },
  { label: '100 por página', value: 100 }
]

const columns: TableColumn<OfficeErpOffer>[] = [
  { accessorKey: 'name', header: 'Oferta' },
  { id: 'price', header: 'Preço e ciclo' },
  { id: 'modules', header: 'Módulos' },
  { accessorKey: 'status', header: 'Situação' },
  { id: 'actions', header: 'Ações' }
]

function requestParams() {
  const params: Record<string, string | number> = { page: page.value, per_page: perPage.value, status: status.value }
  const search = debouncedQuery.value.trim()
  if (search) params.q = search
  return params
}

function beginCommandRequest() {
  const owner = ++commandBusyGeneration
  commandBusy.value = true
  return () => {
    if (owner === commandBusyGeneration)
      commandBusy.value = false
  }
}

async function loadOffers() {
  const request = ++listRequest
  const scope = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (request !== listRequest || scope !== identityStamp.value) return
    const currentCapabilities = capabilities.value
    if (!currentCapabilities?.can_read) {
      offers.value = []
      total.value = 0
      loadError.value = currentCapabilities
        ? 'A conta selecionada não tem permissão para consultar as ofertas do escritório.'
        : 'Não foi possível confirmar o contexto comercial do escritório. Tente novamente.'
      return
    }

    const token = context.capture()
    const response = await api<OfficeErpOfferListResponse>('/erp-commercial/plans', { params: requestParams() })
    if (request !== listRequest || scope !== identityStamp.value || !context.isCurrent(token)) return
    if (!response || !Array.isArray(response.data) || !response.meta
      || !Number.isSafeInteger(response.meta.total) || !Number.isSafeInteger(response.meta.current_page)
      || !Number.isSafeInteger(response.meta.last_page) || ![25, 50, 100].includes(response.meta.per_page)) {
      throw new Error('A resposta da lista de ofertas está incompleta.')
    }
    offers.value = response.data
    total.value = response.meta.total
    lastPage.value = Math.max(1, response.meta.last_page)
    page.value = response.meta.current_page
    confirmedEmpty.value = response.meta.total === 0
  } catch (error) {
    if (request === listRequest && scope === identityStamp.value)
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível consultar as ofertas agora.'
  } finally {
    if (request === listRequest && scope === identityStamp.value)
      loading.value = false
  }
}

function openCreate() {
  if (!capabilities.value?.can_sell_new || command.pending.value) return
  editingOffer.value = null
  fieldErrors.value = {}
  mutationFailure.value = ''
  editorOpen.value = true
}

async function openEdit(offer: OfficeErpOffer) {
  if (!capabilities.value?.can_sell_new || command.pending.value) return
  const scope = identityStamp.value
  const token = context.capture()
  fieldErrors.value = {}
  mutationFailure.value = ''
  try {
    const response = await api<{ data: OfficeErpOffer }>(`/erp-commercial/plans/${offer.id}`)
    if (scope !== identityStamp.value || !context.isCurrent(token)) return
    editingOffer.value = response.data
    editorOpen.value = true
  } catch (error) {
    if (scope === identityStamp.value && context.isCurrent(token))
      toast.add({ title: apiErrorMessage(error) ?? 'Não foi possível abrir esta oferta agora.', color: 'error' })
  }
}

function reportMutation<T>(result: OfficeErpCommandResult<T>, scope: string): boolean {
  if (scope !== identityStamp.value) return false
  if (result.status === 'confirmed') {
    fieldErrors.value = {}
    mutationFailure.value = ''
    return true
  }
  if (result.status === 'unknown') {
    mutationFailure.value = 'A resposta não confirmou o resultado. Consulte a operação pelo identificador original.'
    return false
  }
  if (result.status === 'failed') {
    fieldErrors.value = result.failure.errors ?? {}
    mutationFailure.value = result.failure.message
  }
  return false
}

async function saveOffer(terms: OfficeErpOfferTerms) {
  if (!capabilities.value?.can_sell_new || command.pending.value || commandBusy.value) return
  const scope = identityStamp.value
  const token = context.capture()
  const target = editingOffer.value
  const path = target ? `/erp-commercial/plans/${target.id}/revisions` : '/erp-commercial/plans'
  const finishCommandRequest = beginCommandRequest()
  let result: OfficeErpCommandResult<OfficeErpOffer>
  try {
    result = await command.execute<OfficeErpOffer>(terms.published ? 'offer.publish' : 'offer.create', path, { ...terms }, 'POST')
  } finally {
    finishCommandRequest()
  }
  if (scope !== identityStamp.value || !context.isCurrent(token)) return
  if (reportMutation(result, scope)) {
    toast.add({ title: target ? 'Nova revisão da oferta confirmada' : 'Oferta criada', color: 'success' })
    editorOpen.value = false
    await loadOffers()
  }
}

function requestArchive(offer: OfficeErpOffer) {
  if (!capabilities.value?.can_maintain || command.pending.value) return
  archiveTarget.value = offer
}

async function archiveOffer() {
  const target = archiveTarget.value
  if (!target || !capabilities.value?.can_maintain || command.pending.value || commandBusy.value) return
  const scope = identityStamp.value
  const token = context.capture()
  const finishCommandRequest = beginCommandRequest()
  let result: OfficeErpCommandResult<OfficeErpOffer>
  try {
    result = await command.execute<OfficeErpOffer>('offer.archive', `/erp-commercial/plans/${target.id}/archive`, { sales_plan_id: target.id })
  } finally {
    finishCommandRequest()
  }
  if (scope !== identityStamp.value || !context.isCurrent(token)) return
  if (reportMutation(result, scope)) {
    toast.add({ title: 'Oferta arquivada', color: 'success' })
    archiveTarget.value = null
    await loadOffers()
  }
}

async function recoverOperation(key = recoveryKey.value) {
  if (!key || key !== recoveryKey.value || commandBusy.value || (command.pending.value && !recoveryKey.value)) return
  const scope = identityStamp.value
  const token = context.capture()
  const finishCommandRequest = beginCommandRequest()
  let result: OfficeErpCommandResult<OfficeErpOffer>
  try {
    result = await command.recover<OfficeErpOffer>(key)
  } finally {
    finishCommandRequest()
  }
  if (scope !== identityStamp.value || !context.isCurrent(token)) return
  if (reportMutation(result, scope)) {
    toast.add({ title: 'Operação confirmada', color: 'success' })
    editorOpen.value = false
    archiveTarget.value = null
    await loadOffers()
  } else if (result.status === 'failed') {
    mutationFailure.value = result.failure.message
  }
}

watch([debouncedQuery, status, page, perPage], () => {
  void loadOffers()
}, { immediate: true })
watch(identityStamp, () => {
  commandBusyGeneration++
  commandBusy.value = false
  listRequest++
  offers.value = []
  total.value = 0
  confirmedEmpty.value = false
  loadError.value = ''
  editorOpen.value = false
  editingOffer.value = null
  archiveTarget.value = null
  fieldErrors.value = {}
  mutationFailure.value = ''
  void loadOffers()
}, { flush: 'sync' })
watch([debouncedQuery, status], () => {
  page.value = 1
})
</script>

<template>
  <div class="flex min-h-0 flex-col gap-4">
    <UAlert
      v-if="legacyNotice"
      color="info"
      title="A gestão das ofertas foi movida para o escritório selecionado"
      description="Os planos técnicos e as assinaturas globais continuam disponíveis somente pelas rotas privilegiadas já autorizadas."
    />
    <UAlert
      v-if="capabilities?.can_read && !capabilities.can_sell_new"
      color="warning"
      title="Novas vendas estão indisponíveis"
      :description="[salesReason, capacityLabel].filter(Boolean).join(' ')"
    />
    <UAlert
      v-if="recoveryKey"
      color="warning"
      title="Há uma operação comercial sem confirmação"
      description="Verifique o identificador original. Uma nova operação não será enviada automaticamente."
    >
      <template #description>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span>Verifique o resultado da operação antes de iniciar outra alteração.</span>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            :loading="commandBusy"
            :disabled="commandBusy"
            @click="recoverOperation()"
          >
            Verificar resultado
          </UButton>
        </div>
      </template>
    </UAlert>
    <UAlert
      v-if="mutationFailure && !editorOpen"
      color="error"
      title="Operação não concluída"
      :description="mutationFailure"
    />
    <UAlert v-if="loadError" color="error" title="Não foi possível carregar as ofertas">
      <template #description>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span>{{ loadError }}</span>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            :loading="loading"
            @click="loadOffers"
          >
            Tentar novamente
          </UButton>
        </div>
      </template>
    </UAlert>

    <DataTablePanelList title="Ofertas do escritório" description="Defina os termos comerciais que serão usados em novas vendas. Contratos existentes preservam a revisão aceita.">
      <template #action>
        <UButton icon="i-lucide-plus" :disabled="!capabilities?.can_sell_new || command.pending.value" @click="openCreate">
          Nova oferta
        </UButton>
      </template>
      <template #toolbar>
        <div class="flex w-full flex-wrap items-center justify-between gap-3">
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Buscar oferta"
            aria-label="Buscar oferta"
            class="w-full sm:max-w-sm"
          />
          <div class="flex flex-wrap items-center gap-2">
            <USelect
              v-model="status"
              :items="[{ label: 'Ativas', value: 'active' }, { label: 'Arquivadas', value: 'archived' }]"
              aria-label="Situação das ofertas"
              class="min-w-36"
            />
            <USelect
              v-model="perPage"
              :items="pageSizeOptions"
              aria-label="Ofertas por página"
              class="min-w-40"
            />
          </div>
        </div>
      </template>

      <div :class="panelBodyClass">
        <div v-if="!loadError || offers.length" class="overflow-x-auto">
          <UTable
            :columns="columns"
            :data="offers"
            :loading="loading"
            :ui="panelTableUi"
          >
            <template #name-cell="{ row }">
              <div class="min-w-44 py-1">
                <p class="font-medium text-highlighted">
                  {{ row.original.name }}
                </p>
                <p v-if="row.original.description" class="mt-1 line-clamp-2 text-sm text-muted">
                  {{ row.original.description }}
                </p>
                <p v-if="row.original.current_revision" class="mt-1 text-xs text-muted">
                  Revisão {{ row.original.current_revision.revision_number }}
                </p>
              </div>
            </template>
            <template #price-cell="{ row }">
              <div v-if="row.original.current_revision" class="min-w-36">
                <p class="font-medium text-highlighted">
                  {{ formatOfficeErpMoney(row.original.current_revision.price) }}
                </p>
                <p class="mt-1 text-sm text-muted">
                  {{ row.original.current_revision.interval_months === 1 ? 'Mensal' : `A cada ${row.original.current_revision.interval_months} meses` }}
                </p>
              </div>
              <span v-else class="text-sm text-muted">Sem revisão</span>
            </template>
            <template #modules-cell="{ row }">
              <span class="text-sm text-muted">{{ row.original.current_revision?.module_keys.join(', ') || 'Nenhum módulo' }}</span>
            </template>
            <template #status-cell="{ row }">
              <div class="flex flex-col items-start gap-1">
                <UBadge :color="row.original.status === 'archived' ? 'neutral' : 'success'" variant="subtle">
                  {{ row.original.status === 'archived' ? 'Arquivada' : 'Ativa' }}
                </UBadge>
                <span v-if="row.original.current_revision" class="text-xs text-muted">
                  {{ row.original.current_revision.published ? 'Publicada' : 'Rascunho' }}
                </span>
              </div>
            </template>
            <template #actions-cell="{ row }">
              <div :class="panelRowActionsClass" class="flex flex-wrap justify-end gap-1">
                <UButton
                  v-if="row.original.status === 'active'"
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  icon="i-lucide-pencil"
                  aria-label="Editar oferta"
                  :disabled="!capabilities?.can_sell_new || command.pending.value"
                  @click="openEdit(row.original)"
                />
                <UButton
                  v-if="row.original.status === 'active'"
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  icon="i-lucide-archive"
                  aria-label="Arquivar oferta"
                  :disabled="!capabilities?.can_maintain || command.pending.value"
                  @click="requestArchive(row.original)"
                />
              </div>
            </template>
            <template #empty>
              <div v-if="confirmedEmpty" class="py-8 text-center text-sm text-muted">
                {{ status === 'active' ? 'Nenhuma oferta ativa foi cadastrada.' : 'Nenhuma oferta arquivada foi encontrada.' }}
              </div>
            </template>
          </UTable>
        </div>

        <div v-if="!loading && !loadError && total > 0" :class="panelFooterClass">
          <p :class="panelFooterCountClass">
            {{ total }} {{ total === 1 ? 'oferta' : 'ofertas' }} · página {{ page }} de {{ lastPage }}
          </p>
          <div :class="panelPaginationClass">
            <UPagination
              v-model:page="page"
              :total="total"
              :items-per-page="perPage"
              :sibling-count="1"
              show-edges
            />
          </div>
        </div>
      </div>
    </DataTablePanelList>

    <OfficeErpPlanEditor
      v-model:open="editorOpen"
      :offer="editingOffer"
      :module-keys="moduleKeys"
      :can-sell-new="!!capabilities?.can_sell_new"
      :saving="commandBusy"
      :field-errors="fieldErrors"
      :failure="editorOpen ? mutationFailure : null"
      :recovery-key="recoveryKey"
      @save="saveOffer"
      @recover="recoverOperation"
    />

    <UModal v-model:open="archiveConfirmOpen" title="Arquivar oferta" description="A oferta deixa de aparecer para novas vendas. Os contratos existentes mantêm os termos aceitos.">
      <template #footer>
        <UButton
          color="neutral"
          variant="outline"
          :disabled="commandBusy"
          @click="archiveTarget = null"
        >
          Cancelar
        </UButton>
        <UButton
          color="error"
          :loading="commandBusy"
          :disabled="!capabilities?.can_maintain || command.pending.value || commandBusy"
          @click="archiveOffer"
        >
          Arquivar oferta
        </UButton>
      </template>
    </UModal>
  </div>
</template>
