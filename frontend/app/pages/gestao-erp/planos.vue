<script setup lang="ts">
import { onMounted } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { officeErpSortParams } from '~/utils/officeErpListSort'
import OfficeErpPagination from '~/components/office-erp/OfficeErpPagination.vue'
import OfficeErpPanel from '~/components/office-erp/OfficeErpPanel.vue'
import OfficeErpDataTable from '~/components/office-erp/OfficeErpDataTable.vue'
import { listNavigationField } from '~/composables/useListNavigationState'
import { useOfficeErpListNavigation, officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import type { OfficeErpCommandResult, OfficeErpOffer, OfficeErpOfferListResponse, OfficeErpOfferTerms } from '#shared/types/officeErpCommercial'
import { formatOfficeErpMoney, officeErpModuleOptions } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { panelSelectUi } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
// Context confirmation may finish after SSR has rendered this static action.
const mounted = ref(false)
onMounted(() => {
  mounted.value = true
})
const navigation = useOfficeErpListNavigation('plans', { q: '', status: 'active' as 'active' | 'archived', page: 1, perPage: 25 as 25 | 50 | 100 }, context, { status: ['active', 'archived'] })
const sort = listNavigationField(navigation, 'sort')
const direction = listNavigationField(navigation, 'direction')
const command = useOfficeErpCommand()
const route = useRoute()
const toast = useToast()

const offers = ref<OfficeErpOffer[]>([])
const query = listNavigationField(navigation, 'q')
const debouncedQuery = refDebounced(query, 300)
const status = listNavigationField(navigation, 'status')
const page = listNavigationField(navigation, 'page')
const perPage = listNavigationField(navigation, 'perPage')
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
const identityStamp = useOfficeErpReadIdentity(() => [
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null
], () => capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
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
const columns: TableColumn<OfficeErpOffer>[] = [
  { accessorKey: 'name', header: 'Plano', enableSorting: true },
  { id: 'price', header: 'Valor e ciclo', enableHiding: false },
  { accessorKey: 'status', header: 'Situação', enableSorting: true, meta: { class: { th: 'md:w-36' } } },
  { id: 'actions', header: '', meta: { class: { th: 'md:w-36' } } }
]

function requestParams() {
  const params: Record<string, string | number> = { page: page.value, per_page: perPage.value, ...officeErpSortParams('plans', sort.value, direction.value), status: status.value }
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
  const read = reads.begin('loadOffers')
  const request = ++listRequest
  const scope = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (!read.current() || request !== listRequest || scope !== identityStamp.value) return
    const currentCapabilities = capabilities.value
    if (!currentCapabilities?.can_read) {
      offers.value = []
      total.value = 0
      loadError.value = currentCapabilities
        ? 'A conta selecionada não tem permissão para consultar os planos do escritório.'
        : 'Não foi possível confirmar o contexto comercial do escritório. Tente novamente.'
      return
    }

    const token = context.capture()
    const response = await api<OfficeErpOfferListResponse>('/erp-commercial/plans', { signal: read.signal, params: requestParams() })
    if (!read.current() || request !== listRequest || scope !== identityStamp.value || !context.isCurrent(token)) return
    if (!response || !Array.isArray(response.data) || !response.meta
      || !Number.isSafeInteger(response.meta.total) || !Number.isSafeInteger(response.meta.current_page)
      || !Number.isSafeInteger(response.meta.last_page) || ![25, 50, 100].includes(response.meta.per_page)) {
      throw new Error('A resposta da lista de planos está incompleta.')
    }
    const availablePage = officeErpAvailablePage(response.meta)
    if (page.value !== availablePage) {
      page.value = availablePage
      return
    }
    offers.value = response.data
    total.value = response.meta.total
    lastPage.value = Math.max(1, response.meta.last_page)
    page.value = response.meta.current_page
    confirmedEmpty.value = response.meta.total === 0
  } catch (error) {
    if (read.current() && request === listRequest && scope === identityStamp.value)
      loadError.value = apiErrorMessage(error) ?? 'Não foi possível consultar os planos agora.'
  } finally {
    if (read.current() && request === listRequest && scope === identityStamp.value)
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
  const read = reads.begin('openEdit')
  const scope = identityStamp.value
  const token = context.capture()
  fieldErrors.value = {}
  mutationFailure.value = ''
  try {
    const response = await api<{ data: OfficeErpOffer }>(`/erp-commercial/plans/${offer.id}`, { signal: read.signal })
    if (!read.current() || scope !== identityStamp.value || !context.isCurrent(token)) return
    editingOffer.value = response.data
    editorOpen.value = true
  } catch (error) {
    if (read.current() && scope === identityStamp.value && context.isCurrent(token))
      toast.add({ title: apiErrorMessage(error) ?? 'Não foi possível abrir este plano agora.', color: 'error' })
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
    toast.add({ title: target ? 'Nova revisão do plano confirmada' : 'Plano criado', color: 'success' })
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
    toast.add({ title: 'Plano arquivado', color: 'success' })
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

watch([debouncedQuery, status, page, perPage, sort, direction], () => {
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
watch([debouncedQuery, status, perPage, sort, direction], () => {
  page.value = 1
}, { flush: 'sync' })
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-col gap-4">
    <OfficeErpPanel title="Planos" description="Planos disponíveis para novas assinaturas. Alterações criam uma revisão sem modificar os termos dos contratos existentes." list>
      <template #action>
        <UButton
          to="/gestao-erp/configuracoes"
          color="neutral"
          variant="ghost"
          icon="i-lucide-arrow-left"
          size="sm"
        >
          Configurações
        </UButton>
        <UButton
          color="neutral"
          icon="i-lucide-plus"
          :disabled="!mounted || !capabilities?.can_sell_new || command.pending.value"
          @click="openCreate"
        >
          Novo plano
        </UButton>
      </template>
      <template #toolbar>
        <UInput
          v-model="query"
          icon="i-lucide-search"
          placeholder="Buscar plano"
          aria-label="Buscar plano"
          class="w-full sm:max-w-sm"
        />
        <div class="flex min-w-0 flex-wrap items-center gap-2">
          <USelect
            v-model="status"
            :items="[{ label: 'Ativos', value: 'active' }, { label: 'Arquivados', value: 'archived' }]"
            aria-label="Situação dos planos"
            :ui="panelSelectUi"
            class="w-36"
          />
        </div>
      </template>

      <UAlert
        v-if="legacyNotice"
        color="info"
        variant="subtle"
        icon="i-lucide-info"
        title="A gestão dos planos foi movida para o escritório selecionado"
        description="Os planos técnicos e as assinaturas globais continuam disponíveis somente pelas rotas privilegiadas já autorizadas."
      />
      <UAlert
        v-if="capabilities?.can_read && !capabilities.can_sell_new"
        color="warning"
        variant="subtle"
        icon="i-lucide-circle-alert"
        title="Novas vendas estão indisponíveis"
        :description="[salesReason, capacityLabel].filter(Boolean).join(' ')"
      />
      <UAlert
        v-if="recoveryKey"
        color="warning"
        variant="subtle"
        icon="i-lucide-clock-3"
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
        variant="subtle"
        icon="i-lucide-circle-alert"
        title="Operação não concluída"
        :description="mutationFailure"
      />
      <UAlert
        v-if="loadError"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-alert"
        title="Não foi possível carregar os planos"
      >
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

      <UAlert
        v-if="confirmedEmpty && !offers.length"
        color="neutral"
        :title="status === 'active' ? 'Nenhum plano ativo foi cadastrado.' : 'Nenhum plano arquivado foi encontrado.'"
        description="Ajuste a busca ou a situação para consultar outros planos."
      />
      <OfficeErpDataTable
        v-else-if="!loadError || offers.length"
        :data="offers"
        :columns="columns"
        :loading="loading"
        :get-row-id="row => String(row.id)"
        compact
        :mobile-columns="['name', 'actions']"
        label="Planos"
        :ui="{
          thead: 'table-header-group',
          td: 'border-b border-muted px-3 py-2 align-middle text-sm leading-5 whitespace-normal wrap-anywhere'
        }"
      >
        <template #name-header>
          <DataTableSortButton
            label="Plano"
            :sorted="sort === 'name' ? direction === 'desc' ? 'desc' : 'asc' : false"
            @toggle="navigation = { ...navigation, sort: sort === 'name' && direction === 'desc' ? '' : 'name', direction: sort === 'name' && direction === 'asc' ? 'desc' : 'asc', page: 1 }"
          />
        </template>
        <template #status-header>
          <DataTableSortButton
            label="Situação"
            :sorted="sort === 'status' ? direction === 'desc' ? 'desc' : 'asc' : false"
            @toggle="navigation = { ...navigation, sort: sort === 'status' && direction === 'desc' ? '' : 'status', direction: sort === 'status' && direction === 'asc' ? 'desc' : 'asc', page: 1 }"
          />
        </template>
        <template #name-cell="{ row }">
          <div class="min-w-0 space-y-1">
            <p class="font-medium wrap-anywhere text-highlighted">
              {{ row.original.name }}
            </p>
            <p v-if="row.original.description" class="text-xs wrap-anywhere text-muted">
              {{ row.original.description }}
            </p>
            <UCollapsible v-if="row.original.current_revision?.module_keys.length">
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                trailing-icon="i-lucide-chevron-down"
                :label="`${row.original.current_revision.module_keys.length} módulos`"
                aria-label="Ver módulos do plano"
                class="-ml-2.5"
              />
              <template #content>
                <ul class="space-y-1 pb-1 text-xs text-muted">
                  <li v-for="moduleKey in row.original.current_revision.module_keys" :key="moduleKey">
                    {{ officeErpModuleOptions.find(module => module.value === moduleKey)?.label ?? 'Módulo adicional' }}
                  </li>
                </ul>
              </template>
            </UCollapsible>
            <p v-else class="text-xs text-muted">
              Nenhum módulo
            </p>
          </div>
        </template>
        <template #price-cell="{ row }">
          <div v-if="row.original.current_revision" class="space-y-1">
            <p class="tabular-nums text-highlighted">
              {{ formatOfficeErpMoney(row.original.current_revision.price) }}
            </p>
            <p class="text-xs text-muted">
              {{ row.original.current_revision.interval_months === 1 ? 'Mensal' : `A cada ${row.original.current_revision.interval_months} meses` }}
            </p>
          </div>
          <span v-else class="text-muted">Sem revisão</span>
        </template>
        <template #status-cell="{ row }">
          <div class="space-y-1">
            <UBadge :color="row.original.status === 'archived' ? 'neutral' : 'success'" variant="subtle" size="sm">
              {{ row.original.status === 'archived' ? 'Arquivado' : 'Ativo' }}
            </UBadge>
            <p v-if="row.original.current_revision" class="text-xs text-muted">
              r{{ row.original.current_revision.revision_number }} · {{ row.original.current_revision.published ? 'Publicada' : 'Rascunho' }}
            </p>
          </div>
        </template>
        <template #actions-cell="{ row }">
          <div v-if="row.original.status === 'active'" class="flex flex-wrap items-center gap-1.5 md:justify-end">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-pencil"
              label="Editar"
              aria-label="Editar plano"
              :disabled="!capabilities?.can_sell_new || command.pending.value"
              @click="openEdit(row.original)"
            />
            <UTooltip text="Arquivar plano">
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                icon="i-lucide-archive"
                aria-label="Arquivar plano"
                :disabled="!capabilities?.can_maintain || command.pending.value"
                @click="requestArchive(row.original)"
              />
            </UTooltip>
          </div>
          <span v-else class="text-xs text-muted">Somente consulta</span>
        </template>
      </OfficeErpDataTable>
      <OfficeErpPagination
        v-if="!loadError && total > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="total"
        :loading="loading"
        label="planos"
      />
    </OfficeErpPanel>

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

    <UModal v-model:open="archiveConfirmOpen" title="Arquivar plano" description="O plano deixa de aparecer para novas vendas. Os contratos existentes mantêm os termos aceitos.">
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
          Arquivar plano
        </UButton>
      </template>
    </UModal>
  </div>
</template>
