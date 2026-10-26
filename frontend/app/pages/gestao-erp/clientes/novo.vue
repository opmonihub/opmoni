<script setup lang="ts">
import type { OfficeErpCommandResult, OfficeErpInvitationOutcome, OfficeErpOffer, OfficeErpOfferListResponse, OfficeErpOfferRevision, OfficeErpOnboardingPreview, OfficeErpPortfolioClient, OfficeErpPortfolioClientListResponse } from '#shared/types/officeErpCommercial'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { panelBodyClass } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const command = useOfficeErpCommand()
const route = useRoute()
const toast = useToast()

const clients = ref<OfficeErpPortfolioClient[]>([])
const requestedClientPage = Number(route.query.pagina)
const clientQuery = ref(typeof route.query.q === 'string' ? route.query.q : '')
const clientSearch = refDebounced(clientQuery, 300)
const clientPage = ref(Number.isSafeInteger(requestedClientPage) && requestedClientPage > 0 ? requestedClientPage : 1)
const clientTotal = ref(0)
const clientLastPage = ref(1)
const clientsLoading = ref(false)
const clientsError = ref('')
const offers = ref<OfficeErpOffer[]>([])
const offerQuery = ref('')
const offerSearch = refDebounced(offerQuery, 300)
const offerPage = ref(1)
const offerTotal = ref(0)
const offerLastPage = ref(1)
const offersLoading = ref(false)
const offersError = ref('')
const perPage = ref<25 | 50 | 100>(25)
type OfficeErpClientOption = OfficeErpPortfolioClient & { label: string }
type OfficeErpPublishedRevision = { id: number, revisionId: number, offer: OfficeErpOffer, label: string, revision: OfficeErpOfferRevision }

const admissionReasonMessages: Record<string, string> = {
  subscription_inactive: 'A assinatura do escritório não está ativa.',
  commercial_resale_disabled: 'O plano do escritório não liberou a revenda de ERP.',
  licence_capacity_reached: 'A capacidade de licenças do escritório foi atingida.'
}
const genericAdmissionReasonMessage = 'Este convite não pode ser emitido com a capacidade atual.'

const selectedClient = ref<OfficeErpClientOption>()
const selectedRevision = ref<OfficeErpPublishedRevision>()
const ownerName = ref('')
const ownerEmail = ref('')
const preview = ref<OfficeErpOnboardingPreview | null>(null)
const previewLoading = ref(false)
const previewError = ref('')
const mutationError = ref('')
const fieldError = ref('')
const commandBusy = ref(false)
const invitation = ref<OfficeErpInvitationOutcome | null>(null)
const transientUrl = ref<string | null>(null)
const revokeReason = ref('')
const revokeError = ref('')
let clientRequest = 0
let offerRequest = 0
let preselectedClientHandled = false

const identityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null, currentAccount.value?.role ?? null,
  context.capabilities.value?.capability_key ?? null
]))
const clientOptions = computed<OfficeErpClientOption[]>(() => clients.value.map(client => ({
  ...client,
  label: client.trade_name || client.name
})))
const publishedRevisions = computed<OfficeErpPublishedRevision[]>(() => offers.value.flatMap((offer) => {
  const revision = offer.current_revision
  if (offer.status !== 'active' || !revision?.published || !revision.published_at) return []
  return [{ id: revision.id, revisionId: revision.id, offer, label: `${revision.name} · revisão ${revision.revision_number}`, revision }]
}))
const recoveryKey = computed(() => command.recoveryKey.value)
const commandPending = computed(() => command.pending.value)
const selectedClientId = computed(() => selectedClient.value?.id ?? null)
const selectedRevisionId = computed(() => selectedRevision.value?.revisionId ?? null)
const ownerSnapshot = computed(() => `${ownerName.value.trim()}\n${ownerEmail.value.trim().toLowerCase()}`)
const canReview = computed(() => !!selectedClient.value && !!selectedRevision.value && !!ownerName.value.trim() && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ownerEmail.value.trim()))

function requestBody() {
  return { revision_id: selectedRevision.value!.revisionId, owner: { full_name: ownerName.value.trim(), email: ownerEmail.value.trim() } }
}

function admissionReasonMessage(reason: string | null | undefined) {
  return reason ? admissionReasonMessages[reason] ?? genericAdmissionReasonMessage : genericAdmissionReasonMessage
}

function resetInvitation() {
  invitation.value = null
  transientUrl.value = null
  revokeReason.value = ''
  revokeError.value = ''
}

async function loadClients() {
  const request = ++clientRequest
  const identity = identityStamp.value
  clientsLoading.value = true
  clientsError.value = ''
  try {
    await context.refresh()
    if (request !== clientRequest || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) throw new Error(context.capabilities.value
      ? 'A conta selecionada não tem permissão para consultar a carteira do escritório.'
      : 'Não foi possível confirmar o contexto comercial do escritório. Tente novamente.')
    const token = context.capture()
    const params: Record<string, string | number> = { page: clientPage.value, per_page: perPage.value }
    if (clientSearch.value.trim()) params.q = clientSearch.value.trim()
    const response = await api<OfficeErpPortfolioClientListResponse>('/erp-commercial/clients', { params })
    if (request !== clientRequest || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)) throw new Error('A resposta da carteira está incompleta.')
    clients.value = response.data
    clientTotal.value = response.meta.total
    clientLastPage.value = Math.max(1, response.meta.last_page)
    clientPage.value = response.meta.current_page
    const requestedId = Number(route.query.cliente)
    if (!preselectedClientHandled && Number.isSafeInteger(requestedId) && requestedId > 0) {
      const requestedClient = clientOptions.value.find(item => item.id === requestedId)
      if (requestedClient) {
        selectedClient.value = requestedClient
        preselectedClientHandled = true
      }
    }
  } catch (error) {
    if (request === clientRequest && identity === identityStamp.value) clientsError.value = apiErrorMessage(error) ?? 'Não foi possível consultar a carteira agora.'
  } finally {
    if (request === clientRequest && identity === identityStamp.value) clientsLoading.value = false
  }
}

async function loadOffers() {
  const request = ++offerRequest
  const identity = identityStamp.value
  offersLoading.value = true
  offersError.value = ''
  try {
    await context.refresh()
    if (request !== offerRequest || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) throw new Error(context.capabilities.value
      ? 'A conta selecionada não tem permissão para consultar as ofertas do escritório.'
      : 'Não foi possível confirmar o contexto comercial do escritório. Tente novamente.')
    const token = context.capture()
    const params: Record<string, string | number> = { page: offerPage.value, per_page: perPage.value, status: 'active' }
    if (offerSearch.value.trim()) params.q = offerSearch.value.trim()
    const response = await api<OfficeErpOfferListResponse>('/erp-commercial/plans', { params })
    if (request !== offerRequest || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)) throw new Error('A resposta das ofertas está incompleta.')
    offers.value = response.data
    offerTotal.value = response.meta.total
    offerLastPage.value = Math.max(1, response.meta.last_page)
    offerPage.value = response.meta.current_page
  } catch (error) {
    if (request === offerRequest && identity === identityStamp.value) offersError.value = apiErrorMessage(error) ?? 'Não foi possível consultar as ofertas agora.'
  } finally {
    if (request === offerRequest && identity === identityStamp.value) offersLoading.value = false
  }
}

function invalidateReview() {
  preview.value = null
  previewError.value = ''
  mutationError.value = ''
  fieldError.value = ''
  resetInvitation()
}

async function reviewTerms() {
  if (!canReview.value || previewLoading.value || command.pending.value) return
  const identity = identityStamp.value
  const token = context.capture()
  const clientId = selectedClient.value!.id
  const revisionId = selectedRevision.value!.revisionId
  const owner = ownerSnapshot.value
  previewLoading.value = true
  previewError.value = ''
  try {
    const response = await api<{ data: OfficeErpOnboardingPreview }>(`/erp-commercial/clients/${clientId}/onboarding-preview`, { method: 'POST', body: requestBody() })
    if (identity !== identityStamp.value || !context.isCurrent(token) || clientId !== selectedClientId.value || revisionId !== selectedRevisionId.value || owner !== ownerSnapshot.value) return
    const data = response?.data
    if (!data?.capacity || !data.offer || !data.client || !data.owner) throw new Error('A revisão dos termos está incompleta.')
    preview.value = data
    if (!data.capacity.can_issue) previewError.value = admissionReasonMessage(data.capacity.reason)
  } catch (error) {
    if (identity === identityStamp.value && context.isCurrent(token)) previewError.value = apiErrorMessage(error) ?? 'Não foi possível revisar os termos agora.'
  } finally {
    if (identity === identityStamp.value) previewLoading.value = false
  }
}

function validOutcome(value: unknown): OfficeErpInvitationOutcome | null {
  if (!value || typeof value !== 'object') return null
  const candidate = value as Partial<OfficeErpInvitationOutcome>
  if (!Number.isSafeInteger(candidate.invitation_id) || typeof candidate.expires_at !== 'string'
    || !Number.isFinite(Date.parse(candidate.expires_at)) || !['pending', 'accepted', 'revoked', 'expired'].includes(String(candidate.status))) return null
  return candidate as OfficeErpInvitationOutcome
}

function safeInvitationUrl(value: unknown): string | null {
  if (typeof value !== 'string' || typeof window === 'undefined') return null
  try {
    const expectedOrigin = new URL(window.location.origin)
    const candidate = new URL(value)
    const secureOrigin = expectedOrigin.protocol === 'https:' && candidate.protocol === 'https:'
    const localHttpOrigin = expectedOrigin.protocol === 'http:' && candidate.protocol === 'http:'
      && ['localhost', '127.0.0.1', '[::1]'].includes(expectedOrigin.hostname)
    if (candidate.origin !== expectedOrigin.origin || (!secureOrigin && !localHttpOrigin)
      || candidate.pathname !== '/erp/ativar' || candidate.search !== ''
      || candidate.username !== '' || candidate.password !== ''
      || !/^#token=[a-f\d]{64}$/i.test(candidate.hash)) return null
    return candidate.href
  } catch {
    return null
  }
}

function acceptOutcome(value: unknown, recovered = false): boolean {
  const payload = value && typeof value === 'object' && 'data' in value ? (value as { data: unknown }).data : value
  const outcome = validOutcome(payload)
  if (!outcome) {
    mutationError.value = recovered ? 'O resultado não corresponde a um convite. A operação foi consultada sem repetir a solicitação.' : 'A confirmação não trouxe um resultado de convite válido.'
    return false
  }
  const { url, ...durableOutcome } = outcome
  invitation.value = durableOutcome
  transientUrl.value = safeInvitationUrl(url)
  mutationError.value = ''
  preview.value = null
  return true
}

async function issueInvitation() {
  if (!preview.value?.capacity.can_issue || !selectedClient.value || !selectedRevision.value || command.pending.value || commandBusy.value) return
  const identity = identityStamp.value
  const token = context.capture()
  const snapshot = ownerSnapshot.value
  commandBusy.value = true
  mutationError.value = ''
  try {
    const result = await command.execute<OfficeErpInvitationOutcome>('invitation.issue', `/erp-commercial/clients/${selectedClient.value.id}/invitations`, requestBody()) as OfficeErpCommandResult<OfficeErpInvitationOutcome>
    if (identity !== identityStamp.value || !context.isCurrent(token) || snapshot !== ownerSnapshot.value) return
    if (result.status === 'confirmed') acceptOutcome(result.data)
    else if (result.status === 'unknown') mutationError.value = 'A resposta não confirmou o resultado. Consulte a operação pelo identificador original.'
    else if (result.status === 'failed') {
      mutationError.value = result.failure.message
      fieldError.value = Object.values(result.failure.errors ?? {}).flat()[0] ?? ''
    }
  } finally { commandBusy.value = false }
}

async function recoverOperation() {
  const key = recoveryKey.value
  if (!key || commandBusy.value) return
  const identity = identityStamp.value
  const token = context.capture()
  commandBusy.value = true
  try {
    const result = await command.recover<OfficeErpInvitationOutcome>(key) as OfficeErpCommandResult<OfficeErpInvitationOutcome>
    if (identity !== identityStamp.value || !context.isCurrent(token)) return
    if (result.status === 'confirmed') acceptOutcome(result.data, true)
    else if (result.status === 'failed') mutationError.value = result.failure.message
    else if (result.status === 'stale') mutationError.value = 'Não foi possível confirmar a operação neste contexto. A solicitação não foi repetida.'
  } finally { commandBusy.value = false }
}

async function reissueInvitation() {
  const current = invitation.value
  if (!current || current.status !== 'pending' || command.pending.value || commandBusy.value) return
  const identity = identityStamp.value
  const token = context.capture()
  commandBusy.value = true
  mutationError.value = ''
  try {
    const result = await command.execute<OfficeErpInvitationOutcome>('invitation.issue', `/erp-commercial/invitations/${current.invitation_id}/reissue`, {}) as OfficeErpCommandResult<OfficeErpInvitationOutcome>
    if (identity !== identityStamp.value || !context.isCurrent(token)) return
    if (result.status === 'confirmed') acceptOutcome(result.data)
    else if (result.status === 'unknown') mutationError.value = 'A resposta não confirmou o novo link. Consulte o resultado antes de tentar novamente.'
    else if (result.status === 'failed') mutationError.value = result.failure.message
  } finally { commandBusy.value = false }
}

async function revokeInvitation() {
  const current = invitation.value
  const reason = revokeReason.value.trim()
  if (!current || current.status !== 'pending' || reason.length < 3 || command.pending.value || commandBusy.value) return
  const identity = identityStamp.value
  const token = context.capture()
  commandBusy.value = true
  revokeError.value = ''
  try {
    const result = await command.execute<OfficeErpInvitationOutcome>('invitation.revoke', `/erp-commercial/invitations/${current.invitation_id}/revoke`, { reason }) as OfficeErpCommandResult<OfficeErpInvitationOutcome>
    if (identity !== identityStamp.value || !context.isCurrent(token)) return
    if (result.status === 'confirmed') {
      const outcome = validOutcome(result.data)
      if (outcome) {
        invitation.value = outcome
        transientUrl.value = null
        toast.add({ title: 'Convite revogado', color: 'success' })
      } else {
        revokeError.value = 'A confirmação da revogação não trouxe um resultado válido.'
      }
    } else if (result.status === 'unknown') revokeError.value = 'A resposta não confirmou a revogação. Consulte o resultado pelo identificador original.'
    else if (result.status === 'failed') revokeError.value = result.failure.message
  } finally { commandBusy.value = false }
}

watch([clientSearch, clientPage, perPage], () => {
  void loadClients()
}, { immediate: true })
watch([offerSearch, offerPage, perPage], () => {
  void loadOffers()
}, { immediate: true })
watch([clientSearch, perPage], () => {
  clientPage.value = 1
})
watch([offerSearch, perPage], () => {
  offerPage.value = 1
})
watch([selectedClientId, selectedRevisionId, ownerName, ownerEmail], invalidateReview, { flush: 'sync' })
watch(identityStamp, () => {
  clientRequest++
  offerRequest++
  commandBusy.value = false
  clients.value = []
  offers.value = []
  clientPage.value = 1
  offerPage.value = 1
  selectedClient.value = undefined
  selectedRevision.value = undefined
  ownerName.value = ''
  ownerEmail.value = ''
  preselectedClientHandled = false
  preview.value = null
  previewError.value = ''
  clientsError.value = ''
  offersError.value = ''
  mutationError.value = ''
  fieldError.value = ''
  resetInvitation()
  void loadClients()
  void loadOffers()
}, { flush: 'sync' })
onBeforeUnmount(() => {
  transientUrl.value = null
})
</script>

<template>
  <div class="flex min-h-0 flex-col gap-4">
    <DataTablePanelList title="Novo convite para o ERP" description="Selecione um cliente da carteira e uma oferta publicada para revisar as condições antes do envio.">
      <template #action>
        <UButton
          to="/gestao-erp/clientes"
          color="neutral"
          variant="outline"
          icon="i-lucide-arrow-left"
        >
          Voltar à carteira
        </UButton>
      </template>
      <div :class="panelBodyClass" class="space-y-6">
        <UAlert
          v-if="recoveryKey"
          color="warning"
          title="Há uma operação sem confirmação"
          description="Consulte o identificador original. Nenhuma solicitação será reenviada automaticamente."
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span>Os dados do responsável permanecem nesta tela.</span><UButton
                color="neutral"
                variant="outline"
                size="sm"
                :loading="commandBusy"
                :disabled="commandBusy"
                @click="recoverOperation"
              >
                Verificar resultado
              </UButton>
            </div>
          </template>
        </UAlert>
        <UAlert
          v-if="mutationError"
          color="error"
          title="Operação não confirmada"
          :description="mutationError"
        />
        <section class="space-y-4" aria-labelledby="client-section-title">
          <div>
            <h2 id="client-section-title" class="font-semibold text-highlighted">
              1. Cliente da carteira
            </h2><p class="text-sm text-muted">
              A busca e a paginação consultam a carteira do escritório selecionado.
            </p>
          </div>
          <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(18rem,1fr)]">
            <UFormField name="client-search" label="Buscar cliente">
              <UInput
                v-model="clientQuery"
                icon="i-lucide-search"
                placeholder="Nome ou CNPJ"
                aria-label="Buscar cliente"
              />
            </UFormField>
            <UFormField name="client_id" label="Cliente">
              <USelectMenu
                v-model="selectedClient"
                :items="clientOptions"
                :search-input="false"
                :loading="clientsLoading"
                :disabled="clientsLoading || !!clientsError"
                placeholder="Selecione um cliente"
              />
            </UFormField>
          </div>
          <UAlert
            v-if="clientsError"
            color="error"
            variant="subtle"
            title="Carteira indisponível"
            :description="clientsError"
          >
            <template #description>
              <div class="flex items-center justify-between gap-3">
                <span>{{ clientsError }}</span><UButton
                  size="sm"
                  color="neutral"
                  variant="outline"
                  @click="loadClients"
                >
                  Tentar novamente
                </UButton>
              </div>
            </template>
          </UAlert>
          <UAlert
            v-else-if="clientTotal === 0 && !clientsLoading"
            color="neutral"
            :title="clientSearch.trim() ? 'Nenhum cliente encontrado' : 'Nenhum cliente cadastrado'"
            :description="clientSearch.trim() ? 'Altere a busca para consultar outros clientes da carteira.' : 'A carteira deste escritório ainda não tem clientes.'"
          />
          <div v-else class="flex items-center justify-between gap-3 text-sm text-muted">
            <span>{{ clientTotal }} clientes · página {{ clientPage }} de {{ clientLastPage }}</span><UPagination
              v-model:page="clientPage"
              :total="clientTotal"
              :items-per-page="perPage"
              :disabled="clientsLoading"
            />
          </div>
        </section>

        <section class="space-y-4" aria-labelledby="offer-section-title">
          <div>
            <h2 id="offer-section-title" class="font-semibold text-highlighted">
              2. Oferta publicada
            </h2><p class="text-sm text-muted">
              Somente a revisão publicada atual de uma oferta ativa pode ser selecionada.
            </p>
          </div>
          <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(18rem,1fr)]">
            <UFormField name="offer-search" label="Buscar oferta">
              <UInput
                v-model="offerQuery"
                icon="i-lucide-search"
                placeholder="Nome da oferta"
                aria-label="Buscar oferta"
              />
            </UFormField>
            <UFormField name="revision_id" label="Oferta e revisão">
              <USelectMenu
                v-model="selectedRevision"
                :items="publishedRevisions"
                :search-input="false"
                :loading="offersLoading"
                :disabled="offersLoading || !!offersError"
                placeholder="Selecione uma revisão publicada"
              />
            </UFormField>
          </div>
          <UAlert
            v-if="offersError"
            color="error"
            variant="subtle"
            title="Ofertas indisponíveis"
            :description="offersError"
          >
            <template #description>
              <div class="flex items-center justify-between gap-3">
                <span>{{ offersError }}</span><UButton
                  size="sm"
                  color="neutral"
                  variant="outline"
                  @click="loadOffers"
                >
                  Tentar novamente
                </UButton>
              </div>
            </template>
          </UAlert>
          <UAlert
            v-else-if="offerTotal === 0 && !offersLoading"
            color="neutral"
            title="Nenhuma oferta ativa"
            description="Crie e publique uma oferta antes de convidar responsáveis."
          />
          <UAlert
            v-else-if="offerTotal > 0 && publishedRevisions.length === 0 && !offersLoading"
            color="warning"
            title="Esta página não tem revisões publicadas"
            description="Altere a busca ou avance pelas ofertas para localizar uma revisão publicada."
          />
          <div v-else class="flex items-center justify-between gap-3 text-sm text-muted">
            <span>{{ offerTotal }} ofertas na busca · página {{ offerPage }} de {{ offerLastPage }}</span><UPagination
              v-model:page="offerPage"
              :total="offerTotal"
              :items-per-page="perPage"
              :disabled="offersLoading"
            />
          </div>
        </section>

        <section class="space-y-4" aria-labelledby="owner-section-title">
          <div>
            <h2 id="owner-section-title" class="font-semibold text-highlighted">
              3. Responsável do cliente
            </h2><p class="text-sm text-muted">
              O link de ativação aparece somente após emissão confirmada e fica nesta tela temporariamente.
            </p>
          </div>
          <div class="grid gap-3 md:grid-cols-2">
            <UFormField name="owner.full_name" label="Nome completo">
              <UInput v-model="ownerName" autocomplete="name" placeholder="Nome do responsável" />
            </UFormField>
            <UFormField name="owner.email" label="E-mail">
              <UInput
                v-model="ownerEmail"
                type="email"
                autocomplete="email"
                placeholder="responsavel@empresa.com.br"
              />
            </UFormField>
          </div>
          <p v-if="fieldError" class="text-sm text-error">
            {{ fieldError }}
          </p>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-clipboard-check"
            :loading="previewLoading"
            :disabled="!canReview || previewLoading || commandPending"
            @click="reviewTerms"
          >
            Revisar termos
          </UButton>
        </section>

        <section v-if="preview" class="space-y-4 rounded-lg border border-default p-4" aria-labelledby="review-title">
          <div>
            <h2 id="review-title" class="font-semibold text-highlighted">
              Revise antes de enviar
            </h2><p class="text-sm text-muted">
              Confira a conta vendedora, os dados do responsável e os termos desta revisão.
            </p>
          </div>
          <UAlert
            v-if="previewError"
            color="warning"
            title="Convite indisponível"
            :description="previewError"
          />
          <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-muted">
                Escritório vendedor
              </dt><dd class="font-medium">
                {{ preview.seller.name }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Cliente
              </dt><dd class="font-medium">
                {{ preview.client.trade_name || preview.client.name }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Responsável
              </dt><dd class="font-medium">
                {{ preview.owner.full_name }} · {{ preview.owner.email }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Oferta
              </dt><dd class="font-medium">
                {{ preview.offer.name }} · {{ formatOfficeErpMoney(preview.offer.price) }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Ciclo
              </dt><dd class="font-medium">
                A cada {{ preview.offer.interval_months }} {{ preview.offer.interval_months === 1 ? 'mês' : 'meses' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Vencimento
              </dt><dd class="font-medium">
                {{ preview.service.due_in_days }} dias · {{ preview.service.billing_timezone }}
              </dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-muted">
                Módulos incluídos
              </dt><dd class="font-medium">
                {{ preview.offer.module_keys.length ? preview.offer.module_keys.join(', ') : 'Nenhum módulo' }}
              </dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-muted">
                Capacidade
              </dt><dd class="font-medium">
                {{ preview.capacity.reserved }} reservadas · {{ preview.capacity.occupied }} ocupadas · {{ preview.capacity.available ?? 'ilimitada' }} disponíveis
              </dd>
            </div>
          </dl>
          <p v-if="!previewError" class="text-sm text-muted">
            Confirme os termos antes de enviar o convite.
          </p>
          <UButton
            v-if="!previewError"
            icon="i-lucide-send"
            :loading="commandBusy"
            :disabled="commandBusy || commandPending"
            @click="issueInvitation"
          >
            Enviar convite
          </UButton>
        </section>

        <section v-if="invitation" class="space-y-4 rounded-lg border border-success/30 bg-success/5 p-4" aria-live="polite">
          <UAlert :color="invitation.status === 'revoked' ? 'neutral' : 'success'" :title="invitation.status === 'revoked' ? 'Convite revogado' : 'Convite emitido'" :description="`Convite ${invitation.invitation_id} · ${invitation.status === 'revoked' ? 'revogado' : 'expira em ' + invitation.expires_at}`" />
          <div v-if="transientUrl" class="space-y-2">
            <p class="text-sm text-muted">
              Link temporário de ativação. Compartilhe com o responsável agora.
            </p><a class="break-all text-sm font-medium text-primary underline" :href="transientUrl">{{ transientUrl }}</a>
          </div>
          <div v-if="invitation.status === 'pending'" class="space-y-3">
            <UButton
              color="neutral"
              variant="outline"
              :disabled="commandBusy || commandPending"
              @click="reissueInvitation"
            >
              Gerar novo link
            </UButton>
            <div class="flex flex-col gap-2 sm:max-w-xl">
              <UFormField name="revoke.reason" label="Motivo da revogação">
                <UInput v-model="revokeReason" placeholder="Informe o motivo" />
              </UFormField><UButton
                color="error"
                variant="outline"
                :disabled="revokeReason.trim().length < 3 || commandBusy || commandPending"
                :loading="commandBusy"
                @click="revokeInvitation"
              >
                Revogar convite
              </UButton><p v-if="revokeError" class="text-sm text-error">
                {{ revokeError }}
              </p>
            </div>
          </div>
        </section>
      </div>
    </DataTablePanelList>
  </div>
</template>
