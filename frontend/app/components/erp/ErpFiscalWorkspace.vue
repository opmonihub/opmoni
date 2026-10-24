<script setup lang="ts">
import { erpWorkspaceModuleDestinations, erpWorkspaceModuleSelection } from '~/utils/erpWorkspaceNav'
import type { ErpFiscalCertificate, ErpFiscalDocument, ErpFiscalInvalidation, ErpFiscalModel } from '#shared/types/erpFiscal'

const workspace = reactive(useErpFiscalWorkspace())
const route = useRoute()
const router = useRouter()
const mounted = ref(false)
const uiAuthority = useErpAdministrationContext(() => workspace.selectedEstablishmentId)
const allowedDestination = (permission: string) => workspace.can(permission)
const destinations = computed(() => erpWorkspaceModuleDestinations('fiscal', allowedDestination))
const selection = computed(() => erpWorkspaceModuleSelection('fiscal', mounted.value ? route.hash : '', allowedDestination))
const activeArea = computed(() => selection.value.permitted ? selection.value.destination?.fiscalArea : null)
const selectedModel = computed<ErpFiscalModel>(() => selection.value.destination?.fiscalModel ?? '55')
const destinationLinks = computed(() => [destinations.value.map(destination => ({
  label: destination.label,
  to: { path: route.path, query: route.query, hash: `#${destination.id}` },
  active: selection.value.permitted && selection.value.destination?.id === destination.id
}))])
function selectModel(model: ErpFiscalModel) {
  const destination = destinations.value.find(item => item.fiscalModel === model)
  if (destination) void router.push({ path: route.path, query: route.query, hash: `#${destination.id}` })
}
const certificateMode = ref<'upload' | 'rotate'>('upload')
const selectedCertificate = ref<ErpFiscalCertificate | null>(null)
const pendingAction = ref<{ kind: 'queue' | 'transmit' | 'consult' | 'contingency' | 'reconcile-contingency', document: ErpFiscalDocument } | null>(null)
const actionConfirmationOpen = ref(false)
const eventConfirmationOpen = ref(false)
const recoveredEventConfirmationOpen = ref(false)
const invalidationConfirmation = ref<ErpFiscalInvalidation | null>(null)
const invalidationConfirmationOpen = ref(false)
const pendingEvent = ref<{ type: string, fields: Record<string, unknown>, key: string } | null>(null)
const draftFormEpoch = ref(0)
const draftFormOpen = ref(false)
const eventFormEpoch = ref(0)
const invalidationFormEpoch = ref(0)
const localBusy = ref(false)
const requestError = ref('')
let uiEpoch = 0
const fiscalStatuses: Record<string, string> = {
  draft: 'Rascunho', queued: 'Na fila local', pending: 'Pendente', submitting: 'Em processamento', indeterminate: 'Resultado incerto',
  authorized: 'Autorizado', accepted: 'Aceito', rejected: 'Rejeitado', blocked_authority: 'Bloqueado pela autorização atual', attention_required: 'Requer atenção', artifact_pending: 'Artefato pendente',
  contingency_local: 'Contingência local', closed: 'Encerrado', canceled: 'Cancelado', cancelled: 'Cancelado', invalidated: 'Inutilizada'
}
const eventLabels: Record<string, string> = { cancel: 'Cancelamento', correction: 'Carta de correção', substitution_cancel: 'Cancelamento por substituição', close: 'Encerramento do MDF-e' }
const actionLabels: Record<string, string> = { 'queue': 'Colocar na fila local', 'transmit': 'Transmitir documento', 'consult': 'Consultar provedor', 'contingency': 'Registrar contingência local NFC-e', 'reconcile-contingency': 'Reconciliar contingência' }
const fiscalStatus = (status: string) => fiscalStatuses[status] ?? 'Estado não reconhecido'
const establishmentItems = computed(() => workspace.establishments.map(row => ({ label: `${row.name}${row.cnpj ? ` · ${row.cnpj}` : ''}`, value: row.id })))
const focusedDocument = computed(() => workspace.selectedDocument)
const canSubmitEvent = computed(() => workspace.canCreateEvents
  && !workspace.pendingRecovery
  && workspace.configuration?.release_gates.provider !== 'unavailable'
  && (workspace.configuration?.environment === 'homologation' || workspace.configuration?.release_gates.production_transmission_enabled === true))
const contextFormKey = computed(() => `${uiAuthority.key.value}:${workspace.authorityGeneration}`)

const visibleListState = computed(() => activeArea.value === 'configuration' ? null : workspace.listStates?.[activeArea.value as 'documents' | 'certificates' | 'events' | 'invalidations'])

function beginUiAction() {
  return { epoch: ++uiEpoch, context: uiAuthority.capture(), establishmentId: workspace.selectedEstablishmentId, documentId: focusedDocument.value?.id ?? null }
}

function isCurrentUi(action: ReturnType<typeof beginUiAction>, requireDocument = false) {
  return action.epoch === uiEpoch && uiAuthority.isCurrent(action.context)
    && action.establishmentId === workspace.selectedEstablishmentId
    && (!requireDocument || action.documentId === (focusedDocument.value?.id ?? null))
}

watch(() => [uiAuthority.key.value, workspace.selectedEstablishmentId, workspace.authorityGeneration], () => {
  uiEpoch++
  localBusy.value = false
  requestError.value = ''
  actionConfirmationOpen.value = false
  eventConfirmationOpen.value = false
  recoveredEventConfirmationOpen.value = false
  invalidationConfirmationOpen.value = false
  pendingAction.value = null
  pendingEvent.value = null
  invalidationConfirmation.value = null
  selectedCertificate.value = null
  certificateMode.value = 'upload'
  draftFormOpen.value = false
}, { flush: 'sync' })

watch(() => [mounted.value, selection.value.destination?.id, selection.value.permitted, uiAuthority.key.value, workspace.authorityGeneration] as const, () => {
  if (!mounted.value) return
  workspace.setDocumentModel(selectedModel.value)
  if (selection.value.permitted) void workspace.load()
}, { immediate: true, flush: 'sync' })

onMounted(() => {
  mounted.value = true
  void workspace.recoverPendingOperations()
})

function selectEstablishment(id: number | null) {
  if (!id) return
  void workspace.load(id)
}

function selectDocument(id: number) {
  uiEpoch++
  localBusy.value = false
  actionConfirmationOpen.value = false
  eventConfirmationOpen.value = false
  recoveredEventConfirmationOpen.value = false
  pendingAction.value = null
  pendingEvent.value = null
  void workspace.loadDocument(id)
}

function selectSale(id: number) {
  void workspace.loadSaleSnapshot(id)
}

function selectReturn(id: number) {
  void workspace.loadReturnSnapshot(id)
}

function selectCertificateForRotation(certificate: ErpFiscalCertificate) {
  if (workspace.certificateMutationInFlight || workspace.pendingCertificateRecovery) return
  selectedCertificate.value = certificate
  certificateMode.value = 'rotate'
}

function requestAction(kind: NonNullable<typeof pendingAction.value>['kind'], document: ErpFiscalDocument) {
  pendingAction.value = { kind, document }
  actionConfirmationOpen.value = true
}

async function confirmAction() {
  if (!pendingAction.value) return
  const action = beginUiAction()
  const pending = pendingAction.value
  localBusy.value = true
  requestError.value = ''
  try {
    const { kind, document } = pending
    if (kind === 'transmit') await workspace.transmitDocument(document, crypto.randomUUID())
    else await workspace.runDocumentAction(document, kind)
    if (!isCurrentUi(action, true) || focusedDocument.value?.id !== document.id) return
    actionConfirmationOpen.value = false
  } catch (error) {
    if (!isCurrentUi(action, true)) return
    requestError.value = error instanceof Error ? error.message : 'Não foi possível concluir a operação fiscal.'
  } finally {
    if (isCurrentUi(action, true)) localBusy.value = false
  }
}

async function createDraft(body: Record<string, unknown>) {
  const action = beginUiAction()
  try {
    await workspace.createDocumentDraft(body)
    if (!isCurrentUi(action) || Number(body.erp_establishment_id) !== workspace.selectedEstablishmentId) return
    if (workspace.documents.some(document => document.idempotency_key === body.idempotency_key)) {
      draftFormEpoch.value++
      selectModel(selectedModel.value)
    }
  } catch (error) {
    if (!isCurrentUi(action)) return
    requestError.value = error instanceof Error ? error.message : 'Revise os dados do rascunho fiscal.'
  }
}

async function submitEvent(eventType: string, fields: Record<string, unknown>, key: string) {
  if (!workspace.selectedDocument) return
  const action = beginUiAction()
  const documentId = workspace.selectedDocument.id
  localBusy.value = true
  requestError.value = ''
  try {
    await workspace.createAndSubmitEvent(workspace.selectedDocument, eventType, fields, key)
    if (!isCurrentUi(action, true) || focusedDocument.value?.id !== documentId) return
    eventFormEpoch.value++
    eventConfirmationOpen.value = false
  } catch (error) {
    if (!isCurrentUi(action, true)) return
    requestError.value = error instanceof Error ? error.message : 'O evento não foi confirmado.'
  } finally {
    if (isCurrentUi(action, true)) localBusy.value = false
  }
}

async function confirmRecoveredEventDraft() {
  const action = beginUiAction()
  localBusy.value = true
  requestError.value = ''
  try {
    await workspace.continueRecoveredEventDraft()
    if (!isCurrentUi(action)) return
    recoveredEventConfirmationOpen.value = false
  } catch (error) {
    if (!isCurrentUi(action)) return
    requestError.value = error instanceof Error ? error.message : 'O envio do evento recuperado não foi confirmado.'
  } finally {
    if (isCurrentUi(action)) localBusy.value = false
  }
}

async function submitInvalidation(body: Record<string, unknown>, key: string) {
  const action = beginUiAction()
  try {
    await workspace.createInvalidation(body, key)
    if (!isCurrentUi(action) || Number(body.erp_establishment_id) !== workspace.selectedEstablishmentId) return
    const created = workspace.invalidations.find(row => row.idempotency_key === key)
    if (created) {
      invalidationFormEpoch.value++
      invalidationConfirmation.value = created
      invalidationConfirmationOpen.value = true
    }
  } catch (error) {
    if (!isCurrentUi(action)) return
    requestError.value = error instanceof Error ? error.message : 'A faixa não foi confirmada.'
  }
}

async function confirmInvalidation() {
  if (!invalidationConfirmation.value) return
  const action = beginUiAction()
  const invalidationId = invalidationConfirmation.value.id
  try {
    await workspace.submitInvalidation(invalidationConfirmation.value)
    if (!isCurrentUi(action) || invalidationConfirmation.value?.id !== invalidationId) return
    invalidationConfirmationOpen.value = false
  } catch (error) {
    if (!isCurrentUi(action)) return
    requestError.value = error instanceof Error ? error.message : 'A inutilização não foi confirmada.'
  }
}

async function handleArtifact(document: ErpFiscalDocument, format: 'xml' | 'pdf') {
  const action = beginUiAction()
  try {
    await workspace.downloadArtifact(document, format)
  } catch {
    if (!isCurrentUi(action, true) || focusedDocument.value?.id !== document.id) return
    // The workspace holds a safe API error for display.
  }
}
</script>

<template>
  <ErpCatalogPageShell title="Fiscal" description="Configuração, emissão e acompanhamento de documentos fiscais vinculados à empresa ativa.">
    <template #actions>
      <UButton
        variant="ghost"
        icon="i-lucide-refresh-cw"
        aria-label="Atualizar operação fiscal"
        :loading="workspace.loading"
        @click="workspace.load()"
      />
    </template>
    <template #toolbar>
      <div class="flex w-full flex-col gap-3 px-1 py-2 sm:flex-row sm:items-center sm:justify-between">
        <USelect
          :model-value="workspace.selectedEstablishmentId ?? undefined"
          :items="establishmentItems"
          value-key="value"
          label-key="label"
          placeholder="Selecione o estabelecimento emissor"
          class="w-full sm:max-w-md"
          :disabled="workspace.loading || workspace.noCompany"
          @update:model-value="selectEstablishment($event == null ? null : Number($event))"
        />
        <UBadge v-if="workspace.readOnly" color="neutral" variant="subtle">
          Acesso somente leitura
        </UBadge>
      </div>
    </template>

    <UAlert
      v-if="workspace.noCompany"
      color="warning"
      variant="subtle"
      title="Empresa ativa necessária"
      description="Selecione uma empresa ERP ativa no menu da empresa para acessar o Fiscal."
    />
    <UAlert
      v-else-if="!workspace.canRead"
      color="warning"
      variant="subtle"
      title="Acesso fiscal não concedido"
      description="Seu perfil atual não possui permissão para consultar os recursos fiscais desta empresa."
    />
    <template v-else>
      <UAlert
        v-if="workspace.recoveryMessage || workspace.pendingRecovery"
        color="warning"
        variant="subtle"
        title="Operação fiscal pendente de confirmação"
        :description="workspace.recoveryMessage || 'Há uma identidade fiscal salva neste navegador. Recupere a operação original antes de iniciar outra.'"
      >
        <template #actions>
          <UButton
            size="sm"
            color="warning"
            variant="outline"
            :loading="localBusy"
            @click="workspace.recoverPendingOperations()"
          >
            Recuperar operação original
          </UButton>
        </template>
      </UAlert>
      <UAlert
        v-if="workspace.releaseMessage"
        color="warning"
        variant="subtle"
        title="Portão fiscal ativo"
        :description="workspace.releaseMessage"
      />
      <UAlert
        v-if="workspace.errorMessage || requestError"
        color="error"
        variant="subtle"
        title="Não foi possível concluir"
        :description="requestError || workspace.errorMessage"
      />
      <UNavigationMenu :items="destinationLinks" aria-label="Áreas do Fiscal" class="w-full min-w-0 overflow-x-auto" />
      <UAlert
        v-if="!selection.permitted"
        color="error"
        title="Seu acesso não permite consultar esta área fiscal."
      />

      <UAlert
        v-if="visibleListState?.error"
        color="warning"
        variant="subtle"
        title="Listagem fiscal indisponível"
        :description="visibleListState.confirmed ? 'Os dados exibidos podem estar desatualizados. Tente atualizar a listagem.' : 'Não foi possível confirmar a listagem. Tente novamente.'"
      >
        <template #actions>
          <UButton
            size="sm"
            variant="outline"
            :loading="workspace.loading"
            @click="workspace.load()"
          >
            Tentar novamente
          </UButton>
        </template>
      </UAlert>
      <p v-else-if="visibleListState?.loading" role="status" class="text-sm text-muted">
        {{ visibleListState.confirmed ? 'Atualizando a listagem…' : 'Carregando a listagem…' }}
      </p>
      <p v-else-if="visibleListState && !visibleListState.confirmed" role="status" class="text-sm text-muted">
        {{ visibleListState.permitted ? 'Listagem ainda não confirmada.' : 'Listagem não disponível para seu acesso atual.' }}
      </p>

      <template v-if="activeArea === 'documents'">
        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)]">
          <section class="space-y-4">
            <div class="flex items-center justify-between">
              <h2 class="text-lg font-semibold">
                Documentos fiscais
              </h2><span v-if="workspace.listStates?.documents.confirmed" class="text-sm text-muted">{{ workspace.documents.length }} registro(s){{ workspace.listStates.documents.error ? ' · desatualizados' : '' }}</span>
            </div>
            <div class="max-h-[34rem] space-y-2 overflow-auto pr-1">
              <button
                v-for="row in workspace.documents"
                :key="row.id"
                type="button"
                class="w-full rounded-lg border p-3 text-left transition hover:bg-elevated"
                :class="focusedDocument?.id === row.id ? 'border-primary bg-elevated' : 'border-default'"
                @click="selectDocument(row.id)"
              >
                <span class="flex items-center justify-between gap-2"><span class="font-medium">{{ row.model }} · {{ row.number ? `Nº ${row.number}` : `Documento #${row.id}` }}</span><UBadge variant="subtle" :color="row.status === 'authorized' ? 'success' : row.status === 'rejected' ? 'error' : 'warning'">{{ fiscalStatus(row.status) }}</UBadge></span>
                <span class="mt-1 block text-xs text-muted">{{ row.environment === 'production' ? 'Produção' : 'Homologação' }} · Série {{ row.series ?? '—' }}</span>
              </button>
              <p v-if="workspace.listStates?.documents.confirmed && !workspace.listStates.documents.loading && !workspace.listStates.documents.error && !workspace.documents.length" class="rounded-lg border border-dashed border-default p-6 text-center text-sm text-muted">
                Nenhum documento fiscal foi encontrado para esta empresa.
              </p>
            </div>
            <UCard v-if="workspace.canCreateDocuments && workspace.configuration" class="mt-5">
              <template #header>
                <div class="flex items-center justify-between gap-3">
                  <div>
                    <h3 class="font-semibold">
                      {{ draftFormOpen ? 'Novo rascunho fiscal' : 'Criar documento fiscal' }}
                    </h3><p class="text-sm text-muted">
                      {{ draftFormOpen ? 'A numeração e a chave ficam vinculadas aos dados confirmados neste envio.' : 'Consulte a lista e os detalhes. Abra o formulário quando for preparar um documento.' }}
                    </p>
                  </div>
                  <div class="flex items-center gap-2">
                    <USelect
                      v-if="draftFormOpen"
                      :model-value="selectedModel"
                      :items="[{ label: 'NF-e (55)', value: '55' }, { label: 'NFC-e (65)', value: '65' }, { label: 'CT-e (57)', value: '57' }, { label: 'MDF-e (58)', value: '58' }]"
                      value-key="value"
                      label-key="label"
                      class="w-36"
                      @update:model-value="selectModel($event as ErpFiscalModel)"
                    />
                    <UButton v-else data-testid="open-fiscal-draft" @click="draftFormOpen = true">
                      Novo rascunho
                    </UButton>
                  </div>
                </div>
              </template>
              <ErpFiscalDocumentForm
                v-if="draftFormOpen"
                :key="`${contextFormKey}:${draftFormEpoch}`"
                :establishment-id="workspace.selectedEstablishmentId!"
                :configuration="workspace.configuration"
                :model="selectedModel"
                :sales="workspace.sales"
                :selected-sale="workspace.selectedSale"
                :returns="workspace.returns"
                :selected-return="workspace.selectedReturn"
                :original-documents="workspace.documentSources"
                :can-create="workspace.canCreateDocuments"
                :busy="workspace.saving"
                :field-errors="workspace.fieldErrors"
                @update:model="selectModel"
                @select-sale="selectSale"
                @select-return="selectReturn"
                @create="createDraft"
              />
            </UCard>
          </section>
          <section class="rounded-xl border border-default p-4 sm:p-5">
            <ErpFiscalDocumentDetail
              :document="focusedDocument"
              :can-queue="workspace.canQueue && !workspace.pendingRecovery"
              :can-transmit="workspace.canTransmit && !workspace.pendingRecovery"
              :can-consult="workspace.canConsult && !workspace.pendingRecovery"
              :can-enable-contingency="workspace.canEnableContingency && !workspace.pendingRecovery"
              :can-reconcile="workspace.canReconcileContingency && !workspace.pendingRecovery"
              :can-download-xml="workspace.canDownloadXml"
              :can-download-pdf="workspace.canDownloadPdf"
              :busy="workspace.saving || localBusy"
              :artifact-error="workspace.artifactError"
              :release-message="workspace.releaseMessage"
              @refresh="focusedDocument && workspace.loadDocument(focusedDocument.id)"
              @queue="requestAction('queue', $event)"
              @transmit="requestAction('transmit', $event)"
              @consult="requestAction('consult', $event)"
              @contingency="requestAction('contingency', $event)"
              @reconcile="requestAction('reconcile-contingency', $event)"
              @artifact="handleArtifact"
            />
            <div v-if="focusedDocument?.supported_events?.length && workspace.canCreateEvents" class="mt-6 border-t border-default pt-5">
              <h3 class="mb-3 font-semibold">
                Evento do documento
              </h3>
              <ErpFiscalEventForm
                :key="`${contextFormKey}:${focusedDocument.id}:${eventFormEpoch}`"
                :document="focusedDocument"
                :replacement-documents="workspace.documents"
                :can-submit="canSubmitEvent"
                :busy="localBusy || workspace.saving"
                :field-errors="workspace.fieldErrors"
                @submit="(type, fields, key) => { eventConfirmationOpen = true; pendingEvent = { type, fields, key } }"
              />
            </div>
          </section>
        </div>
      </template>

      <template v-else-if="activeArea === 'configuration'">
        <UAlert
          v-if="!workspace.selectedEstablishmentId"
          color="warning"
          variant="subtle"
          title="Selecione o estabelecimento emissor"
          description="Os dados fiscais e a numeração são específicos do estabelecimento."
        />
        <ErpFiscalConfigurationForm
          :key="contextFormKey"
          :configuration="workspace.configuration"
          :can-write="workspace.canWriteConfiguration"
          :busy="workspace.saving"
          :field-errors="workspace.fieldErrors"
          :operation-natures="workspace.operationNatures"
          @save="workspace.saveConfiguration"
        />
      </template>

      <template v-else-if="activeArea === 'certificates'">
        <UAlert
          v-if="workspace.pendingCertificateRecovery"
          color="warning"
          variant="subtle"
          title="Alteração de certificado sem confirmação"
          description="A consulta mostra metadados atuais, mas não prova qual arquivo foi enviado. Confira o estabelecimento e o certificado antes de liberar outra alteração; arquivo e senha não foram guardados."
        >
          <template #actions>
            <UButton
              size="sm"
              variant="outline"
              :disabled="workspace.certificateMutationInFlight"
              @click="workspace.recoverCertificateMutation"
            >
              Consultar metadados
            </UButton>
            <UButton
              v-if="workspace.certificateRecoveryReview"
              size="sm"
              color="warning"
              variant="outline"
              :disabled="workspace.certificateMutationInFlight"
              @click="workspace.confirmCertificateRecoveryReview"
            >
              Confirmar conferência manual
            </UButton>
          </template>
        </UAlert>
        <div class="grid gap-5 xl:grid-cols-2">
          <UCard>
            <template #header>
              <h2 class="font-semibold">
                {{ certificateMode === 'rotate' ? 'Substituir certificado' : 'Enviar certificado A1' }}
              </h2>
            </template><ErpFiscalCertificateForm
              :key="contextFormKey"
              :mode="certificateMode"
              :establishments="workspace.establishments"
              :establishment-id="workspace.selectedEstablishmentId"
              :certificate="selectedCertificate"
              :can-write="(certificateMode === 'rotate' ? workspace.canRotateCertificates : workspace.canUploadCertificates) && !workspace.pendingCertificateRecovery && !workspace.certificateMutationInFlight"
              :busy="workspace.saving"
              :field-errors="workspace.fieldErrors"
              @submit="workspace.uploadCertificate"
            />
          </UCard>
          <section class="space-y-3">
            <h2 class="text-lg font-semibold">
              Certificados do emissor
            </h2><article v-for="certificate in workspace.certificates" :key="certificate.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-default p-4">
              <div>
                <p class="font-medium">
                  {{ certificate.label }}
                </p><p class="text-sm text-muted">
                  {{ certificate.cnpj ?? 'CNPJ não informado' }} · revisão {{ certificate.revision }} · {{ certificate.status }}
                </p><p class="text-xs text-muted">
                  Validade: {{ certificate.valid_from ?? '—' }} a {{ certificate.valid_until ?? '—' }}
                </p>
              </div><UButton
                size="sm"
                variant="outline"
                :disabled="!workspace.canRotateCertificates || !!workspace.pendingCertificateRecovery || workspace.certificateMutationInFlight"
                @click="selectCertificateForRotation(certificate)"
              >
                Substituir arquivo
              </UButton>
            </article><p v-if="workspace.listStates?.certificates.confirmed && !workspace.listStates.certificates.loading && !workspace.listStates.certificates.error && !workspace.certificates.length" class="rounded-lg border border-dashed border-default p-6 text-center text-sm text-muted">
              Nenhum certificado foi encontrado. A senha e o arquivo enviados nunca são exibidos nem reidratados.
            </p>
          </section>
        </div>
      </template>

      <template v-else-if="activeArea === 'events'">
        <div class="grid gap-4 md:grid-cols-2">
          <UCard v-for="event in workspace.events" :key="event.id">
            <template #header>
              <div class="flex items-center justify-between">
                <span class="font-medium">{{ eventLabels[event.event_type] ?? 'Evento fiscal' }}</span><UBadge variant="subtle">
                  {{ fiscalStatus(event.status) }}
                </UBadge>
              </div>
            </template><p class="text-sm text-muted">
              Documento #{{ event.erp_fiscal_document_id }} · {{ event.protocol ? `Protocolo ${event.protocol}` : 'Sem protocolo confirmado' }}
            </p><p v-if="event.justification" class="mt-2 text-sm">
              {{ event.justification }}
            </p>
          </UCard>
        </div>
        <UCard v-if="workspace.recoverableEventDraft">
          <p class="font-medium">
            Rascunho de evento recuperado
          </p>
          <p class="mt-1 text-sm text-muted">
            Evento {{ eventLabels[workspace.recoverableEventDraft.event_type] ?? 'fiscal' }} do documento #{{ workspace.recoverableEventDraft.erp_fiscal_document_id }}. Confirme para continuar o mesmo evento com a chave original.
          </p>
          <UButton class="mt-3" :disabled="!workspace.canCreateEvents || !!workspace.pendingCertificateRecovery || !!workspace.pendingRecovery && workspace.pendingRecovery.action !== 'event:draft'" @click="recoveredEventConfirmationOpen = true">
            Revisar e confirmar envio
          </UButton>
        </UCard>
        <p v-if="workspace.listStates?.events.confirmed && !workspace.listStates.events.loading && !workspace.listStates.events.error && !workspace.events.length" class="rounded-lg border border-dashed border-default p-6 text-center text-sm text-muted">
          Nenhum evento fiscal registrado.
        </p>
        <p class="text-sm text-muted">
          Para registrar cancelamento, carta de correção, substituição ou encerramento, selecione um documento e use os eventos permitidos em seu detalhe.
        </p>
      </template>

      <template v-else-if="activeArea === 'invalidations'">
        <UCard>
          <template #header>
            <h2 class="font-semibold">
              Inutilização de faixa fiscal
            </h2>
          </template><ErpFiscalInvalidationForm
            :key="`${contextFormKey}:${invalidationFormEpoch}`"
            :configuration="workspace.configuration"
            :can-submit="workspace.canCreateInvalidations && !workspace.pendingRecovery"
            :busy="workspace.saving"
            :field-errors="workspace.fieldErrors"
            @submit="submitInvalidation"
          />
        </UCard>
        <div class="grid gap-4 md:grid-cols-2">
          <UCard v-for="invalidation in workspace.invalidations" :key="invalidation.id">
            <template #header>
              <div class="flex items-center justify-between">
                <span class="font-medium">{{ invalidation.model }} · série {{ invalidation.series }}</span><UBadge variant="subtle">
                  {{ fiscalStatus(invalidation.status) }}
                </UBadge>
              </div>
            </template><p class="text-sm">
              Números {{ invalidation.number_from }} a {{ invalidation.number_to }} · {{ invalidation.year }}
            </p><p class="mt-1 text-sm text-muted">
              {{ invalidation.justification }}
            </p><p v-if="invalidation.protocol" class="mt-2 text-xs text-muted">
              Protocolo {{ invalidation.protocol }}
            </p><UButton
              v-if="['draft', 'queued'].includes(invalidation.status)"
              class="mt-3"
              size="sm"
              variant="outline"
              :disabled="!workspace.canSubmitInvalidations || workspace.configuration?.release_gates.provider === 'unavailable'"
              @click="invalidationConfirmation = invalidation; invalidationConfirmationOpen = true"
            >
              Confirmar solicitação ao provedor
            </UButton>
          </UCard>
        </div>
      </template>
    </template>

    <UModal v-model:open="actionConfirmationOpen" title="Confirmar operação fiscal">
      <template #body>
        <p class="text-sm">
          {{ pendingAction?.kind === 'transmit' ? 'A transmissão enviará o documento ao serviço fiscal e poderá gerar efeitos externos. Confirme somente após revisar os dados e os portões de liberação.' : `Confirme “${actionLabels[pendingAction?.kind ?? ''] ?? 'operação fiscal'}” para o documento #${pendingAction?.document.id ?? '—'}.` }}
        </p><UAlert
          v-if="pendingAction?.document.environment === 'production'"
          class="mt-3"
          color="warning"
          variant="subtle"
          title="Ambiente de produção"
          description="Esta ação se refere a um documento de produção."
        />
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="outline" @click="actionConfirmationOpen = false">
            Voltar
          </UButton><UButton :disabled="!pendingAction || !!workspace.pendingRecovery || (workspace.configuration?.release_gates.provider === 'unavailable' && ['transmit', 'consult', 'reconcile-contingency'].includes(pendingAction.kind)) || (pendingAction.kind === 'transmit' && pendingAction.document.environment === 'production' && workspace.productionBlocked)" :loading="localBusy" @click="confirmAction">
            Confirmar operação
          </UButton>
        </div>
      </template>
    </UModal>

    <UModal v-model:open="eventConfirmationOpen" title="Confirmar envio do evento">
      <template #body>
        <p class="text-sm">
          O evento fiscal será enviado ao serviço configurado. Uma rejeição do evento não modifica o estado de autorização do documento relacionado.
        </p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="outline" @click="eventConfirmationOpen = false">
            Voltar
          </UButton><UButton :disabled="!canSubmitEvent" :loading="localBusy" @click="pendingEvent && submitEvent(pendingEvent.type, pendingEvent.fields, pendingEvent.key)">
            Confirmar evento
          </UButton>
        </div>
      </template>
    </UModal>

    <UModal v-model:open="recoveredEventConfirmationOpen" title="Continuar evento recuperado">
      <template #body>
        <p v-if="workspace.recoverableEventDraft">
          O evento {{ eventLabels[workspace.recoverableEventDraft.event_type] ?? 'fiscal' }} foi criado, mas seu envio não foi confirmado. Esta ação envia o mesmo evento #{{ workspace.recoverableEventDraft.id }} usando a chave original. Não cria outro evento.
        </p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="outline" @click="recoveredEventConfirmationOpen = false">
            Voltar
          </UButton>
          <UButton :disabled="!workspace.recoverableEventDraft || !workspace.canCreateEvents || !!workspace.pendingCertificateRecovery" :loading="localBusy" @click="confirmRecoveredEventDraft">
            Confirmar envio
          </UButton>
        </div>
      </template>
    </UModal>

    <UModal v-model:open="invalidationConfirmationOpen" title="Confirmar inutilização de faixa">
      <template #body>
        <p class="text-sm">
          A faixa {{ invalidationConfirmation?.number_from }} a {{ invalidationConfirmation?.number_to }} do modelo {{ invalidationConfirmation?.model }} será enviada para inutilização no provedor. Esta ação fiscal é definitiva quando aceita.
        </p>
      </template>
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton variant="outline" @click="invalidationConfirmationOpen = false">
            Voltar
          </UButton><UButton :disabled="!workspace.canSubmitInvalidations || workspace.configuration?.release_gates.provider === 'unavailable'" :loading="workspace.saving" @click="confirmInvalidation">
            Confirmar envio
          </UButton>
        </div>
      </template>
    </UModal>
  </ErpCatalogPageShell>
</template>
