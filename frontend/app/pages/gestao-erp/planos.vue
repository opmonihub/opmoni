<script setup lang="ts">
import OfficeErpPagination from '~/components/office-erp/OfficeErpPagination.vue'
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
const navigation = useOfficeErpListNavigation('plans', { q: '', status: 'active' as 'active' | 'archived', page: 1, perPage: 25 as 25 | 50 | 100 }, context, { status: ['active', 'archived'] })
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
watch([debouncedQuery, status, perPage], () => {
  page.value = 1
}, { flush: 'sync' })
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-4 sm:gap-6">
    <UPageCard title="Planos do escritório" variant="naked" orientation="horizontal" :ui="{ title: 'text-base', container: 'p-0 sm:p-0' }">
      <UButton icon="i-lucide-plus" class="w-fit sm:ms-auto" :disabled="!capabilities?.can_sell_new || command.pending.value" @click="openCreate">
        Novo plano
      </UButton>
    </UPageCard>

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

    <section aria-label="Lista de planos" class="flex min-w-0 flex-col gap-4">
      <div class="flex flex-wrap items-end gap-3">
        <UInput
          v-model="query"
          icon="i-lucide-search"
          placeholder="Buscar plano"
          aria-label="Buscar plano"
          class="w-full sm:max-w-sm"
        />
        <div class="flex w-full items-center gap-1.5 sm:w-auto sm:flex-wrap">
          <USelect
            v-model="status"
            :items="[{ label: 'Ativos', value: 'active' }, { label: 'Arquivados', value: 'archived' }]"
            aria-label="Situação dos planos"
            :ui="panelSelectUi"
            class="min-w-0 flex-1 sm:w-36 sm:flex-none"
          />
        </div>
      </div>
      <div
        v-if="confirmedEmpty && !offers.length"
        class="flex min-h-40 items-center justify-center border-t border-default px-4 text-center text-sm text-muted"
      >
        {{ status === 'active' ? 'Nenhum plano ativo foi cadastrado.' : 'Nenhum plano arquivado foi encontrado.' }}
      </div>
      <div v-else-if="!loadError || offers.length" class="space-y-3" :aria-busy="loading">
        <USkeleton v-if="loading && !offers.length" class="h-28 w-full" />
        <UPageCard v-for="offer in offers" :key="offer.id" variant="subtle" :ui="{ container: 'gap-0 p-4 sm:p-5' }">
          <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="wrap-anywhere text-sm font-semibold text-highlighted">{{ offer.name }}</h3>
                <UBadge :color="offer.status === 'archived' ? 'neutral' : 'success'" variant="subtle" size="sm">{{ offer.status === 'archived' ? 'Arquivado' : 'Ativo' }}</UBadge>
                <span v-if="offer.current_revision && !offer.current_revision.published" class="text-xs text-muted">Rascunho</span>
              </div>
              <p v-if="offer.current_revision" class="mt-1 text-sm tabular-nums text-highlighted">
                {{ formatOfficeErpMoney(offer.current_revision.price) }}
                <span class="text-muted">/ {{ offer.current_revision.interval_months === 1 ? 'mês' : `${offer.current_revision.interval_months} meses` }}</span>
              </p>
              <p v-else class="mt-1 text-sm text-muted">Sem revisão</p>
            </div>
            <div v-if="offer.status === 'active'" class="flex shrink-0 items-center gap-1">
              <UButton color="neutral" variant="ghost" icon="i-lucide-pencil" aria-label="Editar plano" class="min-h-11 min-w-11" :disabled="!capabilities?.can_sell_new || command.pending.value" @click="openEdit(offer)" />
              <UButton color="neutral" variant="ghost" icon="i-lucide-archive" aria-label="Arquivar plano" class="min-h-11 min-w-11" :disabled="!capabilities?.can_maintain || command.pending.value" @click="requestArchive(offer)" />
            </div>
          </div>
          <UCollapsible class="mt-2">
            <UButton color="neutral" variant="link" trailing-icon="i-lucide-chevron-down" class="min-h-11 px-0 text-muted" aria-label="Ver detalhes do plano">
              {{ offer.current_revision?.module_keys.length || 0 }} módulos
            </UButton>
            <template #content>
              <div class="space-y-3 border-t border-default pt-3 text-sm">
                <p v-if="offer.description" class="text-muted">{{ offer.description }}</p>
                <ul v-if="offer.current_revision?.module_keys.length" class="grid gap-2 sm:grid-cols-2">
                  <li v-for="key in offer.current_revision.module_keys" :key="key" class="text-default">
                    {{ officeErpModuleOptions.find(module => module.value === key)?.label ?? 'Módulo adicional' }}
                  </li>
                </ul>
                <p v-else class="text-muted">Nenhum módulo</p>
                <p v-if="offer.current_revision" class="text-xs text-muted">Revisão {{ offer.current_revision.revision_number }} · {{ offer.current_revision.published ? 'Publicada' : 'Rascunho' }}</p>
              </div>
            </template>
          </UCollapsible>
        </UPageCard>
      </div>

      <OfficeErpPagination
        v-if="!loadError && total > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="total"
        :loading="loading"
        label="planos"
      />
    </section>

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
