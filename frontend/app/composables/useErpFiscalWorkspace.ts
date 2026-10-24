import type {
  ErpFiscalCertificate,
  ErpFiscalAttempt,
  ErpFiscalConfiguration,
  ErpFiscalDocument,
  ErpFiscalEvent,
  ErpFiscalInvalidation,
  ErpFiscalModel,
  ErpFiscalRecoveryRecord,
  ErpFiscalSale
} from '../../shared/types/erpFiscal'
import type { ErpOperationNatureRecord, ErpPaginated } from '../../shared/types/erpCatalogs'
import type { FetchOptions, ResponseType } from 'ofetch'
import { apiErrorMessage, apiStatus } from './useApiError'
import { useAccessApi } from './useAccessApi'
import { useErpAdministrationContext } from './useErpAdministrationContext'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'
import { buildErpFiscalCertificateUpload, createErpFiscalRecoveryRecord, erpFiscalRecoveryStorageKey, fiscalApiFieldErrors, parseErpFiscalRecoveryRecord } from '#shared/utils/erpFiscal'

type FiscalListKey = 'documents' | 'certificates' | 'events' | 'invalidations'
type FiscalReadKey = FiscalListKey | 'establishments' | 'configuration' | 'documentSources'
type FiscalListState = { confirmed: boolean, loading: boolean, error: string, permitted: boolean }

type ApiEnvelope<T> = { data: T, meta?: Record<string, unknown> }
type CertificateRecovery = { version: 1, actorId: number, companyId: number, establishmentId: number, mode: 'upload' | 'rotate', certificateId: number | null, previousRevision: number | null, baseline: Array<{ id: number, revision: number }>, label: string, savedAt: string, operationId?: string }
type CertificateOperationOwner = { operationId: string, authorityKey: string, actorId: number, companyId: number }

const actionGrants = {
  documentRead: 'erp.fiscal.documents.index',
  documentShow: 'erp.fiscal.documents.show',
  documentCreate: 'erp.fiscal.documents.store',
  queue: 'erp.fiscal.documents.queue',
  transmit: 'erp.fiscal.documents.transmit',
  consult: 'erp.fiscal.documents.consult',
  contingency: 'erp.fiscal.documents.contingency',
  contingencyReconcile: 'erp.fiscal.documents.reconcile-contingency',
  xml: 'erp.fiscal.documents.xml',
  pdf: 'erp.fiscal.documents.pdf',
  configurationRead: 'erp.fiscal.configuration.show',
  configurationWrite: 'erp.fiscal.configuration.update',
  certificateRead: 'erp.fiscal.certificates.index',
  certificateUpload: 'erp.fiscal.certificates.store',
  certificateRotate: 'erp.fiscal.certificates.rotate',
  eventRead: 'erp.fiscal.events.index',
  eventShow: 'erp.fiscal.events.show',
  eventCreate: 'erp.fiscal.events.store',
  eventSubmit: 'erp.fiscal.events.submit',
  invalidationRead: 'erp.fiscal.invalidations.index',
  invalidationShow: 'erp.fiscal.invalidations.show',
  invalidationCreate: 'erp.fiscal.invalidations.store',
  invalidationSubmit: 'erp.fiscal.invalidations.submit'
} as const

function rowData<T>(result: ApiEnvelope<T>): T {
  return result.data
}

function rows<T>(result: ApiEnvelope<T[]>): T[] {
  return Array.isArray(result.data) ? result.data : []
}

function definitiveBeforeDispatch(status: number | null): boolean {
  return status !== null && status >= 400 && status < 500 && status !== 408 && status !== 409 && status !== 429
}

export function useErpFiscalWorkspace() {
  const { api: requestApi } = useAccessApi()
  const { user, currentCompany, currentAccount, refreshWithStatus } = useAuth()
  const selectedEstablishmentId = ref<number | null>(null)
  const authority = useErpAdministrationContext(() => selectedEstablishmentId.value)
  const sessionGeneration = useState('access-session-generation', () => 0)
  const establishments = ref<Array<{ id: number, name: string, cnpj: string | null, status: string }>>([])
  const configuration = ref<ErpFiscalConfiguration | null>(null)
  const documents = ref<ErpFiscalDocument[]>([])
  const documentModel = ref<ErpFiscalModel | null>(null)
  const documentSources = ref<ErpFiscalDocument[]>([])
  const sales = ref<ErpFiscalSale[]>([])
  const returns = ref<ErpFiscalSale[]>([])
  const certificates = ref<ErpFiscalCertificate[]>([])
  const events = ref<ErpFiscalEvent[]>([])
  const invalidations = ref<ErpFiscalInvalidation[]>([])
  const newListState = (): FiscalListState => ({ confirmed: false, loading: false, error: '', permitted: false })
  const listStates = reactive<Record<FiscalListKey, FiscalListState>>({ documents: newListState(), certificates: newListState(), events: newListState(), invalidations: newListState() })
  const operationNatures = ref<ErpOperationNatureRecord[]>([])
  const selectedDocument = ref<ErpFiscalDocument | null>(null)
  const selectedSale = ref<ErpFiscalSale | null>(null)
  const selectedReturn = ref<ErpFiscalSale | null>(null)
  const documentForm = ref<Record<string, unknown>>({})
  const configurationForm = ref<Record<string, unknown>>({})
  const fieldErrors = ref<Record<string, string>>({})
  const loading = ref(false)
  const saving = ref(false)
  const errorMessage = ref('')
  const releaseMessage = ref('')
  const recoveryMessage = ref('')
  const artifactError = ref('')
  const pendingRecovery = ref<ErpFiscalRecoveryRecord | null>(null)
  const pendingCertificateRecovery = ref<CertificateRecovery | null>(null)
  const certificateRecoveryReview = ref<{ savedAt: string, operationId?: string, establishmentId: number, rows: ErpFiscalCertificate[] } | null>(null)
  const certificateOperationOwner = ref<CertificateOperationOwner | null>(null)
  const recoverableEventDraft = ref<ErpFiscalEvent | null>(null)
  const operationEpoch = ref(0)
  const listEpoch = ref(0)
  const authorityNeedsRefresh = ref(false)
  let authorityRefreshEpoch = 0
  let detailSelectionEpoch = 0
  let sourceSelectionEpoch = 0

  const actorId = computed(() => Number(user.value?.id ?? 0))
  const companyId = computed(() => Number(currentCompany.value?.id ?? 0))
  const noCompany = computed(() => !currentCompany.value?.id || currentCompany.value.status !== 'active')
  const grants = computed(() => currentCompany.value?.permissions ?? [])
  const directWriter = computed(() => currentCompany.value?.access_kind === 'direct' && ['owner', 'manager', 'operator'].includes(String(currentCompany.value?.role)))
  const delegatedWriter = computed(() => currentCompany.value?.access_kind === 'delegated'
    && currentAccount.value?.status === 'active'
    && Number(currentCompany.value?.delegated_office_id ?? 0) > 0
    && Number(currentCompany.value?.delegated_office_id) === Number(currentAccount.value?.id)
    && ['admin', 'operador'].includes(String(currentAccount.value?.role)))
  const can = (permission: string) => {
    if (noCompany.value || authorityNeedsRefresh.value) return false
    const isWrite = !/\.(index|show|read|xml|pdf)$/.test(permission)
    if (isWrite && !directWriter.value && !delegatedWriter.value) return false
    return erpCompanyHasPermission(grants.value, permission)
  }
  const canRead = computed(() => can(actionGrants.documentRead)
    || can(actionGrants.documentShow)
    || can(actionGrants.configurationRead)
    || can(actionGrants.certificateRead)
    || can(actionGrants.eventRead)
    || can(actionGrants.eventShow)
    || can(actionGrants.invalidationRead)
    || can(actionGrants.invalidationShow))
  const readOnly = computed(() => !can(actionGrants.documentCreate) && !can(actionGrants.configurationWrite) && !can(actionGrants.certificateUpload) && !can(actionGrants.certificateRotate) && !can(actionGrants.eventCreate) && !can(actionGrants.invalidationCreate))
  const canWriteConfiguration = computed(() => can(actionGrants.configurationWrite))
  const canUploadCertificates = computed(() => can(actionGrants.certificateUpload))
  const canRotateCertificates = computed(() => can(actionGrants.certificateRotate))
  const canCreateDocuments = computed(() => can(actionGrants.documentCreate))
  const productionBlocked = computed(() => configuration.value?.release_gates.production_transmission_enabled !== true)
  const productionBlocker = computed(() => configuration.value?.release_gates.production_blocker ?? (configuration.value?.release_gates.provider === 'unavailable' ? 'provider_unavailable' : null))
  const certificateMutationInFlight = computed(() => {
    const owner = certificateOperationOwner.value
    return Boolean(owner && owner.actorId === actorId.value && owner.companyId === companyId.value && !noCompany.value)
  })

  function isCurrent(token: ReturnType<typeof authority.capture>, epoch: number): boolean {
    return authority.isCurrent(token) && operationEpoch.value === epoch && !noCompany.value && !authorityNeedsRefresh.value
  }

  function authorityErrorPayload(error: unknown): unknown {
    const failure = error as { data?: unknown, response?: { _data?: unknown } } | null
    return failure?.data ?? failure?.response?._data
  }

  async function isNamedContextConflict(error: unknown): Promise<boolean> {
    let data = authorityErrorPayload(error)
    if (typeof Blob !== 'undefined' && data instanceof Blob && data.type.toLowerCase().includes('json')) {
      try {
        data = JSON.parse(await data.text())
      } catch {
        return false
      }
    }

    return typeof data === 'object' && data !== null && (data as { code?: unknown }).code === 'company_context_changed'
  }

  async function refreshAfterAuthorityDenial(refreshEpoch: number) {
    try {
      const result = await refreshWithStatus()
      if (authorityRefreshEpoch !== refreshEpoch || !result.committed || !result.identity) return
      authorityNeedsRefresh.value = false
      errorMessage.value = ''
      currentRecovery()
      readCertificateRecovery()
    } catch {
      // Only a committed identity may release this refusal; the durable journals remain untouched.
    }
  }

  async function handleAuthorityDenial(error: unknown, token: ReturnType<typeof authority.capture>, epoch: number) {
    if (!isCurrent(token, epoch)) return
    const status = apiStatus(error)
    let denied = status === 401 || status === 403
    if (!denied && status === 409) {
      denied = await isNamedContextConflict(error)
      if (!isCurrent(token, epoch)) return
    }
    if (!denied) return
    const refreshEpoch = ++authorityRefreshEpoch
    authorityNeedsRefresh.value = true
    clearContextState()
    errorMessage.value = 'Sua autorização mudou. Os dados privados fiscais foram removidos. Atualize a sessão para continuar.'
    void refreshAfterAuthorityDenial(refreshEpoch)
  }

  /** Every private read/mutation, including auxiliary/recovery/download requests, shares the refusal boundary. */
  async function api<T, R extends ResponseType = 'json'>(path: string, options: FetchOptions<R> = {}): Promise<T> {
    if (authorityNeedsRefresh.value) throw new Error('Atualize a autorização antes de continuar.')
    const token = authority.capture()
    const epoch = operationEpoch.value
    try {
      return await requestApi<T, R>(path, options)
    } catch (error) {
      await handleAuthorityDenial(error, token, epoch)
      throw error
    }
  }

  function clearContextState() {
    operationEpoch.value++
    listEpoch.value++
    establishments.value = []
    configuration.value = null
    configurationForm.value = {}
    documents.value = []
    documentSources.value = []
    sales.value = []
    returns.value = []
    certificates.value = []
    events.value = []
    invalidations.value = []
    for (const key of Object.keys(listStates) as FiscalListKey[]) listStates[key] = newListState()
    operationNatures.value = []
    selectedDocument.value = null
    selectedSale.value = null
    selectedReturn.value = null
    documentForm.value = {}
    fieldErrors.value = {}
    pendingRecovery.value = null
    pendingCertificateRecovery.value = null
    certificateRecoveryReview.value = null
    recoverableEventDraft.value = null
    errorMessage.value = ''
    releaseMessage.value = ''
    recoveryMessage.value = ''
    artifactError.value = ''
    loading.value = false
    saving.value = false
  }

  watch(authority.key, clearContextState, { flush: 'sync' })
  watch([actorId, companyId, sessionGeneration], (next, previous) => {
    const authenticatedSessionChanged = next[2] !== previous[2] && next[0] > 0
    if (authorityNeedsRefresh.value && !authenticatedSessionChanged) {
      operationEpoch.value++
      pendingRecovery.value = null
      return
    }
    authorityRefreshEpoch++
    authorityNeedsRefresh.value = false
    operationEpoch.value++
    pendingRecovery.value = null
  }, { flush: 'sync' })
  onScopeDispose(() => {
    authorityRefreshEpoch++
    clearContextState()
  })

  function storageKey(): string | null {
    if (actorId.value <= 0 || companyId.value <= 0) return null
    return erpFiscalRecoveryStorageKey(actorId.value, companyId.value)
  }

  function storage(): Storage | null {
    try {
      return typeof globalThis.sessionStorage === 'undefined' ? null : globalThis.sessionStorage
    } catch {
      return null
    }
  }

  function certificateRecoveryKey(): string | null {
    return actorId.value > 0 && companyId.value > 0 ? `opmoni:erp-fiscal:certificate-recovery:v1:${actorId.value}:${companyId.value}` : null
  }

  function readCertificateRecovery(): CertificateRecovery | null {
    if (authorityNeedsRefresh.value) return null
    const key = certificateRecoveryKey()
    const target = storage()
    if (!key || !target) return pendingCertificateRecovery.value
    try {
      const raw: unknown = JSON.parse(target.getItem(key) ?? 'null')
      if (!raw || typeof raw !== 'object') return null
      const record = raw as CertificateRecovery
      if (record.version !== 1 || record.actorId !== actorId.value || record.companyId !== companyId.value || !['upload', 'rotate'].includes(record.mode)) return null
      pendingCertificateRecovery.value = record
      return record
    } catch {
      return pendingCertificateRecovery.value
    }
  }

  function persistCertificateRecovery(record: CertificateRecovery): boolean {
    const key = certificateRecoveryKey()
    const target = storage()
    if (!key || !target) {
      recoveryMessage.value = 'Não foi possível guardar a operação do certificado neste navegador. Nenhum arquivo foi enviado.'
      return false
    }
    try {
      target.setItem(key, JSON.stringify(record))
      pendingCertificateRecovery.value = record
      return true
    } catch {
      recoveryMessage.value = 'Não foi possível guardar a operação do certificado neste navegador. Nenhum arquivo foi enviado.'
      return false
    }
  }

  function sameCertificateOperation(left: CertificateRecovery, right: CertificateRecovery): boolean {
    if (left.operationId || right.operationId) return Boolean(left.operationId && right.operationId && left.operationId === right.operationId)
    return left.savedAt === right.savedAt && left.actorId === right.actorId && left.companyId === right.companyId
  }

  function clearCertificateRecovery(record: CertificateRecovery) {
    const key = certificateRecoveryKey()
    const target = storage()
    if (!key || !target) {
      recoveryMessage.value = 'A confirmação não foi removida do armazenamento. A alteração continua bloqueada até uma conferência persistida.'
      return
    }
    try {
      const current = JSON.parse(target.getItem(key) ?? 'null') as CertificateRecovery | null
      if (!current || !sameCertificateOperation(current, record)) return
      target.removeItem(key)
      pendingCertificateRecovery.value = null
    } catch {
      recoveryMessage.value = 'O resultado do certificado não pôde ser removido do estado de recuperação. Consulte os metadados antes de outra alteração.'
    }
  }

  async function recoverCertificateMutation() {
    if (authorityNeedsRefresh.value) return
    if (certificateMutationInFlight.value) return
    const record = readCertificateRecovery()
    if (!record || record.actorId !== actorId.value || record.companyId !== companyId.value || noCompany.value || !can(actionGrants.certificateRead)) return
    const token = authority.capture()
    const epoch = operationEpoch.value
    try {
      const result = await api<ApiEnvelope<ErpFiscalCertificate[]>>('/erp/fiscal/certificates?per_page=100')
      const currentRecord = readCertificateRecovery()
      if (!isCurrent(token, epoch) || certificateMutationInFlight.value || !currentRecord || !sameCertificateOperation(currentRecord, record)) return
      const rows = result.data.filter(row => row.erp_establishment_id === record.establishmentId)
      certificates.value = [...certificates.value.filter(row => row.erp_establishment_id !== record.establishmentId), ...rows]
      certificateRecoveryReview.value = { savedAt: record.savedAt, operationId: record.operationId, establishmentId: record.establishmentId, rows }
      recoveryMessage.value = 'Metadados atuais carregados. Eles não provam qual arquivo foi enviado; confira o certificado e confirme manualmente antes de liberar novas alterações.'
    } catch {
      if (isCurrent(token, epoch)) recoveryMessage.value = 'Não foi possível consultar os metadados do certificado. A alteração permanece bloqueada até uma verificação bem-sucedida.'
    }
  }

  function confirmCertificateRecoveryReview() {
    if (authorityNeedsRefresh.value) return
    if (certificateMutationInFlight.value) return
    const record = readCertificateRecovery()
    const review = certificateRecoveryReview.value
    if (!record || !review || record.savedAt !== review.savedAt || record.operationId !== review.operationId || record.establishmentId !== review.establishmentId) return
    if (record.actorId !== actorId.value || record.companyId !== companyId.value || noCompany.value) return
    const grant = record.mode === 'rotate' ? actionGrants.certificateRotate : actionGrants.certificateUpload
    if (!can(grant)) throw new Error('Seu acesso não permite liberar esta alteração de certificado.')
    clearCertificateRecovery(record)
    certificateRecoveryReview.value = null
    recoveryMessage.value = 'A conferência manual foi confirmada. Nenhum arquivo ou senha foi recuperado; registre uma nova alteração somente após validar o certificado no estabelecimento.'
  }

  function persistRecovery(record: ErpFiscalRecoveryRecord) {
    const key = storageKey()
    const target = storage()
    if (!key || !target) {
      recoveryMessage.value = 'A identidade desta operação permanece apenas nesta tela; não foi possível guardá-la para recuperação após recarga.'
      return false
    }
    try {
      target.setItem(key, JSON.stringify(record))
      pendingRecovery.value = record
      return true
    } catch {
      recoveryMessage.value = 'Não foi possível guardar a identidade desta tentativa neste navegador. Mantenha esta tela aberta e consulte o documento antes de iniciar outra operação.'
      return false
    }
  }

  function abandonUnsentRecovery(record: ErpFiscalRecoveryRecord) {
    if (pendingRecovery.value?.idempotencyKey === record.idempotencyKey && pendingRecovery.value.path === record.path) {
      pendingRecovery.value = null
    }
  }

  function currentRecovery(): ErpFiscalRecoveryRecord | null {
    if (authorityNeedsRefresh.value) return null
    if (pendingRecovery.value) return pendingRecovery.value
    const key = storageKey()
    const target = storage()
    if (!key || !target) return null
    try {
      const record = parseErpFiscalRecoveryRecord(target.getItem(key), actorId.value, companyId.value)
      pendingRecovery.value = record
      return record
    } catch {
      return null
    }
  }

  function clearRecovery(record: ErpFiscalRecoveryRecord) {
    const key = storageKey()
    const target = storage()
    if (!key || !target) {
      abandonUnsentRecovery(record)
      return
    }
    let current: ErpFiscalRecoveryRecord | null
    try {
      current = parseErpFiscalRecoveryRecord(target.getItem(key), actorId.value, companyId.value)
    } catch {
      return
    }
    if (current?.idempotencyKey !== record.idempotencyKey || current.path !== record.path) return
    target.removeItem(key)
    pendingRecovery.value = null
    recoveryMessage.value = ''
  }

  function makeRecovery(action: string, path: string, idempotencyKey: string, body: Record<string, unknown>, resourceId?: number | null, recoveryMode: 'replay' | 'read' = 'replay') {
    return createErpFiscalRecoveryRecord({ actorId: actorId.value, companyId: companyId.value, action, path, idempotencyKey, body, resourceId, recoveryMode })
  }

  function acceptRows<T>(result: ApiEnvelope<T[]>, rowsRef: { value: T[] }) {
    rowsRef.value = rows(result)
  }

  function updateDocumentRow(document: ErpFiscalDocument) {
    documentSources.value = documentSources.value.some(row => row.id === document.id)
      ? documentSources.value.map(row => row.id === document.id ? document : row)
      : [document, ...documentSources.value]
    if (documentModel.value && document.model !== documentModel.value) {
      if (selectedDocument.value?.id === document.id) selectedDocument.value = document
      return
    }
    documents.value = documents.value.some(row => row.id === document.id)
      ? documents.value.map(row => row.id === document.id ? document : row)
      : [document, ...documents.value]
    if (selectedDocument.value?.id === document.id) selectedDocument.value = document
  }

  function setDocumentModel(model: ErpFiscalModel) {
    if (documentModel.value === model) return
    documentModel.value = model
    listEpoch.value++
    documents.value = []
    listStates.documents = newListState()
  }

  async function load(establishmentId: number | null = selectedEstablishmentId.value) {
    if (noCompany.value || !canRead.value) {
      errorMessage.value = noCompany.value ? 'Selecione uma empresa ativa para acessar o Fiscal.' : 'Seu acesso não permite consultar a operação fiscal desta empresa.'
      return
    }
    if (establishmentId !== null) selectedEstablishmentId.value = establishmentId
    const id = selectedEstablishmentId.value
    const token = authority.capture()
    const epoch = operationEpoch.value
    const requestId = ++listEpoch.value
    const model = documentModel.value
    loading.value = true
    errorMessage.value = ''
    try {
      const reads: Array<{ key: FiscalReadKey, request: Promise<unknown> }> = []
      if (can('erp.establishments.index')) reads.push({ key: 'establishments', request: api('/erp/establishments?per_page=100') })
      const listGrants = { documents: actionGrants.documentRead, certificates: actionGrants.certificateRead, events: actionGrants.eventRead, invalidations: actionGrants.invalidationRead }
      for (const key of Object.keys(listGrants) as FiscalListKey[]) {
        const permitted = can(listGrants[key])
        listStates[key] = { ...listStates[key], permitted, loading: permitted, error: '' }
        if (permitted) reads.push({ key, request: api(`/erp/fiscal/${key}?per_page=100${key === 'documents' && model ? `&model=${model}` : ''}`) })
      }
      // Preserve the existing bounded candidate read separately from the model list.
      if (model && can(actionGrants.documentRead)) reads.push({ key: 'documentSources', request: api('/erp/fiscal/documents?per_page=100') })
      if (can(actionGrants.configurationRead) && id !== null) reads.push({ key: 'configuration', request: api(`/erp/fiscal/configuration/${id}`) })
      const settled = await Promise.allSettled(reads.map(read => read.request))
      if (!isCurrent(token, epoch) || requestId !== listEpoch.value) return
      settled.forEach((result, index) => {
        const key = reads[index]!.key
        if (result.status === 'rejected') {
          const message = apiErrorMessage(result.reason) ?? 'Não foi possível carregar os dados fiscais.'
          if (key !== 'establishments' && key !== 'configuration' && key !== 'documentSources') listStates[key] = { ...listStates[key], loading: false, error: message }
          if (!errorMessage.value) errorMessage.value = message
          return
        }
        if (key === 'establishments') {
          acceptRows(result.value as ApiEnvelope<Array<{ id: number, name: string, cnpj: string | null, status: string }>>, establishments)
        } else if (key === 'documentSources') {
          acceptRows(result.value as ApiEnvelope<ErpFiscalDocument[]>, documentSources)
        } else if (key === 'configuration') {
          configuration.value = rowData(result.value as ApiEnvelope<ErpFiscalConfiguration>)
          releaseMessage.value = configuration.value.release_gates.provider === 'unavailable'
            ? 'Emissão bloqueada: o serviço fiscal configurado está indisponível.'
            : configuration.value.release_gates.production_transmission_enabled
              ? ''
              : `Transmissão de produção bloqueada: ${configuration.value.release_gates.production_blocker ?? 'gate desabilitado'}`
          configurationForm.value = JSON.parse(JSON.stringify(configuration.value.issuer ? configuration.value : emptyConfigurationForm(configuration.value))) as Record<string, unknown>
        } else {
          if (key === 'documents') {
            acceptRows(result.value as ApiEnvelope<ErpFiscalDocument[]>, documents)
            if (!model) acceptRows(result.value as ApiEnvelope<ErpFiscalDocument[]>, documentSources)
          }
          if (key === 'certificates') acceptRows(result.value as ApiEnvelope<ErpFiscalCertificate[]>, certificates)
          if (key === 'events') acceptRows(result.value as ApiEnvelope<ErpFiscalEvent[]>, events)
          if (key === 'invalidations') acceptRows(result.value as ApiEnvelope<ErpFiscalInvalidation[]>, invalidations)
          listStates[key] = { confirmed: true, loading: false, error: '', permitted: true }
        }
      })
      const sourceReads: Array<{ key: 'sales' | 'returns', request: Promise<unknown> }> = []
      if (can('erp.sales.index')) sourceReads.push({ key: 'sales', request: api('/erp/sales?per_page=100&status=posted') })
      if (can('erp.sale-returns.index')) sourceReads.push({ key: 'returns', request: api('/erp/sale-returns?per_page=100&status=posted') })
      const sourceResults = await Promise.allSettled(sourceReads.map(entry => entry.request))
      if (!isCurrent(token, epoch) || requestId !== listEpoch.value) return
      sourceResults.forEach((result, sourceIndex) => {
        const key = sourceReads[sourceIndex]?.key
        if (result.status === 'fulfilled' && key) acceptRows(result.value as ApiEnvelope<ErpFiscalSale[]>, key === 'sales' ? sales : returns)
      })
      if (can('erp.operation-natures.index')) {
        const natureResult = await api<ErpPaginated<ErpOperationNatureRecord>>('/erp/tax-settings/operation-natures?per_page=100')
        if (!isCurrent(token, epoch) || requestId !== listEpoch.value) return
        operationNatures.value = Array.isArray(natureResult.data) ? natureResult.data : []
      } else {
        operationNatures.value = []
      }
      currentRecovery()
    } catch (error) {
      if (isCurrent(token, epoch) && requestId === listEpoch.value) errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível carregar os dados fiscais.'
    } finally {
      if (isCurrent(token, epoch) && requestId === listEpoch.value) loading.value = false
    }
  }

  function emptyConfigurationForm(config: ErpFiscalConfiguration | null) {
    const establishment = config?.establishment
    return {
      environment: config?.environment ?? 'homologation',
      issuer: { tax_regime: null, state_registration: '', uf: '', address: { street: '', number: '', district: '', city_code: '', city_name: '', zip_code: '', complement: '' } },
      models: Object.fromEntries((['55', '65', '57', '58'] as ErpFiscalModel[]).map(model => [model, {
        series: config?.models[model]?.series ?? 1,
        next_number: config?.models[model]?.next_number ?? config?.models[model]?.initial_next_number ?? 1,
        ...(model === '65' ? { nfce_qr: { version: 3, mode: 'online', consultation_url: '' } } : {}),
        ...(['57', '58'].includes(model) ? { transport: {} } : {})
      }])),
      erp_establishment_id: establishment?.id ?? selectedEstablishmentId.value
    }
  }

  async function loadDocument(documentId: number) {
    if (!can(actionGrants.documentShow)) return null
    const selection = ++detailSelectionEpoch
    saving.value = false
    const token = authority.capture()
    const epoch = operationEpoch.value
    try {
      const result = await api<ApiEnvelope<ErpFiscalDocument>>(`/erp/fiscal/documents/${documentId}`)
      if (!isCurrent(token, epoch) || selection !== detailSelectionEpoch) return null
      selectedDocument.value = result.data
      documents.value = documents.value.map(row => row.id === result.data.id ? result.data : row)
      return result.data
    } catch (error) {
      if (isCurrent(token, epoch) && selection === detailSelectionEpoch) errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível carregar o documento fiscal.'
      return null
    }
  }

  async function saveConfiguration(body: Record<string, unknown>) {
    if (!selectedEstablishmentId.value || !can(actionGrants.configurationWrite)) return
    const token = authority.capture()
    const epoch = operationEpoch.value
    saving.value = true
    fieldErrors.value = {}
    try {
      const result = await api<ApiEnvelope<ErpFiscalConfiguration>>(`/erp/fiscal/configuration/${selectedEstablishmentId.value}`, { method: 'PATCH', body })
      if (!isCurrent(token, epoch)) return
      configuration.value = result.data
      configurationForm.value = JSON.parse(JSON.stringify(body)) as Record<string, unknown>
    } catch (error) {
      if (!isCurrent(token, epoch)) return
      fieldErrors.value = fiscalApiFieldErrors(error)
      if (Object.keys(fieldErrors.value).length === 0) errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível salvar a configuração fiscal.'
    } finally {
      if (isCurrent(token, epoch)) saving.value = false
    }
  }

  async function uploadCertificate(input: { label: string, establishmentId: number, certificate: File, password: string, certificateId?: number }) {
    const permission = input.certificateId ? actionGrants.certificateRotate : actionGrants.certificateUpload
    if (!can(permission) || input.certificate.size > 2 * 1024 * 1024) return
    const token = authority.capture()
    const activeOwner = certificateOperationOwner.value
    if (activeOwner && activeOwner.actorId === actorId.value && activeOwner.companyId === companyId.value) {
      throw new Error('Outra alteração de certificado já está em andamento para esta empresa.')
    }
    const existingRecovery = readCertificateRecovery()
    if (existingRecovery) throw new Error('Há uma alteração de certificado sem resultado confirmado. Consulte os metadados antes de iniciar outra alteração.')
    if (!can(actionGrants.certificateRead)) throw new Error('Consulte os metadados de certificados antes de enviar ou substituir um arquivo.')
    const owner: CertificateOperationOwner = {
      operationId: crypto.randomUUID(),
      authorityKey: token.key,
      actorId: actorId.value,
      companyId: companyId.value
    }
    certificateOperationOwner.value = owner
    const epoch = operationEpoch.value
    saving.value = true
    fieldErrors.value = {}
    let certificateRecovery: CertificateRecovery | null = null
    try {
      const baseline = await api<ApiEnvelope<ErpFiscalCertificate[]>>('/erp/fiscal/certificates?per_page=100')
      if (!isCurrent(token, epoch) || certificateOperationOwner.value?.operationId !== owner.operationId) return
      if (readCertificateRecovery()) {
        throw new Error('Outra alteração de certificado foi iniciada neste contexto. Consulte os metadados antes de tentar novamente.')
      }
      certificates.value = baseline.data
      const previous = input.certificateId ? baseline.data.find(row => row.id === input.certificateId) : null
      if (input.certificateId && !previous) throw new Error('O certificado selecionado não foi encontrado nos metadados atuais.')
      certificateRecovery = {
        version: 1,
        actorId: actorId.value,
        companyId: companyId.value,
        establishmentId: input.establishmentId,
        mode: input.certificateId ? 'rotate' : 'upload',
        certificateId: input.certificateId ?? null,
        previousRevision: previous?.revision ?? null,
        baseline: baseline.data.filter(row => row.erp_establishment_id === input.establishmentId).map(row => ({ id: row.id, revision: row.revision })),
        label: input.label.trim(),
        savedAt: new Date().toISOString(),
        operationId: owner.operationId
      }
      if (!persistCertificateRecovery(certificateRecovery)) throw new Error(recoveryMessage.value)
      const body = buildErpFiscalCertificateUpload(input)
      const result = await api<ApiEnvelope<ErpFiscalCertificate>>(input.certificateId
        ? `/erp/fiscal/certificates/${input.certificateId}/rotate`
        : '/erp/fiscal/certificates', { method: 'POST', body })
      if (!isCurrent(token, epoch)) return
      const prior = certificates.value.findIndex(row => row.id === result.data.id)
      if (prior >= 0) certificates.value.splice(prior, 1, result.data)
      else certificates.value.unshift(result.data)
      clearCertificateRecovery(certificateRecovery)
    } catch (error) {
      if (!isCurrent(token, epoch)) return
      fieldErrors.value = fiscalApiFieldErrors(error)
      if (certificateRecovery && definitiveBeforeDispatch(apiStatus(error))) clearCertificateRecovery(certificateRecovery)
      else if (certificateRecovery) recoveryMessage.value = 'O resultado do certificado é incerto. Consulte os metadados do estabelecimento antes de tentar outra alteração.'
      if (Object.keys(fieldErrors.value).length === 0) errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível salvar o certificado fiscal.'
    } finally {
      if (isCurrent(token, epoch)) saving.value = false
      if (certificateOperationOwner.value?.operationId === owner.operationId) certificateOperationOwner.value = null
    }
  }

  function canStartProduction(environment: string): boolean {
    if (environment !== 'production') return true
    return configuration.value?.release_gates.production_transmission_enabled === true
  }

  async function createDocumentDraft(body: Record<string, unknown>) {
    if (!can(actionGrants.documentCreate)) return
    if (currentRecovery()) throw new Error('Há uma operação fiscal sem resultado confirmado. Recupere a mesma identidade antes de iniciar outra.')
    const token = authority.capture()
    const epoch = operationEpoch.value
    const key = String(body.idempotency_key ?? '')
    const record = makeRecovery('document:draft', '/erp/fiscal/documents', key, body)
    if (!persistRecovery(record)) {
      abandonUnsentRecovery(record)
      throw new Error(recoveryMessage.value)
    }
    let postAccepted = false
    saving.value = true
    fieldErrors.value = {}
    try {
      const result = await api<ApiEnvelope<ErpFiscalDocument>>('/erp/fiscal/documents', { method: 'POST', body })
      if (!isCurrent(token, epoch)) return
      postAccepted = true
      const document = result.data
      if (document?.id) {
        const detail = await api<ApiEnvelope<ErpFiscalDocument>>(`/erp/fiscal/documents/${document.id}`)
        if (!isCurrent(token, epoch)) return
        updateDocumentRow(detail.data)
        clearRecovery(record)
      } else {
        recoveryMessage.value = 'O servidor não confirmou o rascunho. Recupere esta mesma solicitação antes de criar outra.'
      }
    } catch (error) {
      if (!isCurrent(token, epoch)) return
      fieldErrors.value = fiscalApiFieldErrors(error)
      if (!postAccepted && definitiveBeforeDispatch(apiStatus(error))) clearRecovery(record)
      else recoveryMessage.value = 'O resultado do rascunho não foi confirmado. A solicitação original foi mantida para recuperação.'
      if (Object.keys(fieldErrors.value).length === 0) errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível criar o rascunho fiscal.'
      throw error
    } finally {
      if (isCurrent(token, epoch)) saving.value = false
    }
  }

  async function transmitDocument(document: Pick<ErpFiscalDocument, 'id' | 'model' | 'environment'>, idempotencyKey: string) {
    if (!can(actionGrants.transmit)) throw new Error('Seu acesso não permite transmitir este documento.')
    if (!configuration.value || !canStartProduction(document.environment) || configuration.value.release_gates.provider === 'unavailable') {
      releaseMessage.value = document.environment === 'production'
        ? `Transmissão de produção bloqueada: ${productionBlocker.value ?? 'provider_unavailable'}`
        : `Transmissão bloqueada: ${productionBlocker.value ?? 'provider_unavailable'}`
      throw new Error(releaseMessage.value)
    }
    const existing = currentRecovery()
    if (existing) throw new Error('Há uma tentativa fiscal sem resultado confirmado. Recupere essa mesma operação antes de iniciar outra.')
    const path = `/erp/fiscal/documents/${document.id}/transmit`
    const body = { idempotency_key: idempotencyKey }
    const recovery = makeRecovery('document:transmit', path, idempotencyKey, body, document.id)
    if (!persistRecovery(recovery)) {
      abandonUnsentRecovery(recovery)
      throw new Error(recoveryMessage.value || 'Não foi possível preservar a identidade da transmissão.')
    }
    const token = authority.capture()
    const epoch = operationEpoch.value
    const selection = detailSelectionEpoch
    let postAccepted = false
    saving.value = true
    try {
      await api<ApiEnvelope<ErpFiscalDocument>>(path, { method: 'POST', body })
      postAccepted = true
      if (!isCurrent(token, epoch)) return
      const detail = await api<ApiEnvelope<ErpFiscalDocument>>(`/erp/fiscal/documents/${document.id}`)
      if (!isCurrent(token, epoch)) return
      updateDocumentRow(detail.data)
      const confirmed = detail.data.attempts?.some(attempt => attempt.idempotency_key === idempotencyKey && attempt.action === 'document:transmit' && ['accepted', 'rejected'].includes(attempt.status))
      if (confirmed) clearRecovery(recovery)
      if (selection !== detailSelectionEpoch) return
      if (!confirmed) recoveryMessage.value = 'A transmissão continua sem desfecho confirmado. Consulte novamente esta mesma operação.'
    } catch (error) {
      if (!isCurrent(token, epoch)) return
      if (!postAccepted && definitiveBeforeDispatch(apiStatus(error))) clearRecovery(recovery)
      else if (selection === detailSelectionEpoch) recoveryMessage.value = 'A resposta da transmissão não chegou. A identidade original foi guardada para consulta e recuperação.'
      if (selection !== detailSelectionEpoch) return
      errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível concluir a operação fiscal.'
      throw error
    } finally {
      if (isCurrent(token, epoch) && selection === detailSelectionEpoch) saving.value = false
    }
  }

  async function runDocumentAction(document: ErpFiscalDocument, action: 'queue' | 'consult' | 'contingency' | 'reconcile-contingency') {
    const permission = action === 'queue' ? actionGrants.queue : action === 'consult' ? actionGrants.consult : action === 'contingency' ? actionGrants.contingency : actionGrants.contingencyReconcile
    if (!can(permission)) throw new Error('Seu acesso não permite esta operação fiscal.')
    if (['consult', 'reconcile-contingency'].includes(action) && (!configuration.value || configuration.value.release_gates.provider === 'unavailable' || !canStartProduction(document.environment))) {
      releaseMessage.value = `Operação bloqueada: ${productionBlocker.value ?? 'provider_unavailable'}`
      throw new Error(releaseMessage.value)
    }
    if (action === 'contingency' && document.model !== '65') throw new Error('A contingência local é suportada somente para NFC-e (65).')
    if (currentRecovery()) throw new Error('Há uma operação fiscal sem resultado confirmado. Recupere a mesma identidade antes de iniciar outra.')
    const path = `/erp/fiscal/documents/${document.id}/${action}`
    const idempotencyKey = crypto.randomUUID()
    const body = { idempotency_key: idempotencyKey }
    const recovery = makeRecovery(`document:${action}`, path, idempotencyKey, body, document.id)
    if (!persistRecovery(recovery)) {
      abandonUnsentRecovery(recovery)
      throw new Error(recoveryMessage.value)
    }
    const token = authority.capture()
    const epoch = operationEpoch.value
    const selection = detailSelectionEpoch
    let postAccepted = false
    saving.value = true
    try {
      const actionResult = await api<ApiEnvelope<ErpFiscalDocument>>(path, { method: 'POST', body })
      postAccepted = true
      if (!isCurrent(token, epoch)) return null
      const detail = await api<ApiEnvelope<ErpFiscalDocument>>(`/erp/fiscal/documents/${document.id}`)
      if (!isCurrent(token, epoch)) return null
      updateDocumentRow(detail.data)
      const expectedAction = action === 'reconcile-contingency' ? 'document:reconcile' : `document:${action}`
      const matchingAttempt = detail.data.attempts?.some(attempt => attempt.idempotency_key === idempotencyKey && attempt.action === expectedAction)
      const matchingResponseAttempt = actionResult.data.attempts?.some(attempt => attempt.idempotency_key === idempotencyKey && attempt.action === expectedAction)
        || (actionResult.data.attempt?.idempotency_key === idempotencyKey && actionResult.data.attempt.action === expectedAction)
      const confirmed = detail.data.attempts?.some(attempt => attempt.idempotency_key === idempotencyKey && attempt.action === expectedAction && ['accepted', 'rejected', 'blocked_authority'].includes(attempt.status))
        || (action === 'queue' && detail.data.status === 'queued' && actionResult.data.status === 'queued' && matchingResponseAttempt === true)
        || (action === 'contingency' && detail.data.status === 'contingency_local' && matchingAttempt === true)
      if (confirmed) clearRecovery(recovery)
      if (selection !== detailSelectionEpoch) return null
      if (!confirmed) recoveryMessage.value = 'A operação ainda não tem desfecho confirmado. Recupere a mesma tentativa antes de iniciar outra.'
      return detail.data
    } catch (error) {
      if (!isCurrent(token, epoch)) return null
      if (!postAccepted && definitiveBeforeDispatch(apiStatus(error))) clearRecovery(recovery)
      else if (selection === detailSelectionEpoch) recoveryMessage.value = 'A resposta da operação não chegou. A identidade original permanece guardada para recuperação.'
      if (selection !== detailSelectionEpoch) return null
      errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível concluir a operação fiscal.'
      throw error
    } finally {
      if (isCurrent(token, epoch) && selection === detailSelectionEpoch) saving.value = false
    }
  }

  async function recoverPendingOperations() {
    if (authorityNeedsRefresh.value) return
    const recoveryAuthority = authority.capture()
    const recoveryEpoch = operationEpoch.value
    await recoverCertificateMutation()
    if (!isCurrent(recoveryAuthority, recoveryEpoch)) return
    const record = currentRecovery()
    if (!record || noCompany.value || record.actorId !== actorId.value || record.companyId !== companyId.value) return
    const validRecoveryPath = (() => {
      const resource = record.resourceId
      switch (record.action) {
        case 'document:draft': return record.path === '/erp/fiscal/documents' && resource === null
        case 'document:queue': return resource !== null && record.path === `/erp/fiscal/documents/${resource}/queue`
        case 'document:transmit': return resource !== null && record.path === `/erp/fiscal/documents/${resource}/transmit`
        case 'document:consult': return resource !== null && record.path === `/erp/fiscal/documents/${resource}/consult`
        case 'document:contingency': return resource !== null && record.path === `/erp/fiscal/documents/${resource}/contingency`
        case 'document:reconcile-contingency': return resource !== null && record.path === `/erp/fiscal/documents/${resource}/reconcile-contingency`
        case 'event:draft': return record.path === '/erp/fiscal/events'
        case 'event:submit': return resource !== null && record.path === `/erp/fiscal/events/${resource}/submit`
        case 'invalidation:draft': return record.path === '/erp/fiscal/invalidations'
        case 'invalidation:submit': return resource !== null && record.path === `/erp/fiscal/invalidations/${resource}/submit`
        default: return false
      }
    })()
    if (!validRecoveryPath || !record.idempotencyKey) return
    const readPermission = record.action.startsWith('document:')
      ? actionGrants.documentShow
      : record.action.startsWith('event:')
        ? actionGrants.eventShow
        : record.action.startsWith('invalidation:') ? actionGrants.invalidationShow : actionGrants.certificateRead
    if (!can(readPermission)) return
    let replayRequest = record.recoveryMode === 'replay'
    if (record.recoveryMode === 'replay') {
      const permissionByAction: Record<string, string> = {
        'document:draft': actionGrants.documentCreate,
        'document:queue': actionGrants.queue,
        'document:transmit': actionGrants.transmit,
        'document:consult': actionGrants.consult,
        'document:contingency': actionGrants.contingency,
        'document:reconcile-contingency': actionGrants.contingencyReconcile,
        'event:draft': actionGrants.eventCreate,
        'invalidation:draft': actionGrants.invalidationCreate
      }
      const mutationPermission = permissionByAction[record.action]
      replayRequest = Boolean(mutationPermission && can(mutationPermission))
      if (!replayRequest && record.resourceId === null) return
    }
    const token = authority.capture()
    const epoch = operationEpoch.value
    recoveryMessage.value = ''
    try {
      let resourceId = record.resourceId
      if (replayRequest) {
        const replay = await api<ApiEnvelope<{ id?: number }>>(record.path, { method: 'POST', body: record.body })
        if (!isCurrent(token, epoch)) return
        resourceId = resourceId ?? replay.data?.id ?? null
      }
      let detailPath = record.path
      if (record.action.startsWith('document:')) detailPath = resourceId ? `/erp/fiscal/documents/${resourceId}` : record.path
      else if (record.action.startsWith('event:')) detailPath = resourceId ? `/erp/fiscal/events/${resourceId}` : record.path
      else if (record.action.startsWith('invalidation:')) detailPath = resourceId ? `/erp/fiscal/invalidations/${resourceId}` : record.path
      const detail = await api<ApiEnvelope<(ErpFiscalDocument | ErpFiscalEvent | ErpFiscalInvalidation) & { attempts?: ErpFiscalAttempt[], attempt?: Pick<ErpFiscalAttempt, 'id' | 'action' | 'status' | 'safe_result'>, idempotency_key?: string }>>(detailPath)
      if (!isCurrent(token, epoch)) return
      const value = detail.data
      const expectedAction = record.action === 'document:reconcile-contingency' ? 'document:reconcile' : record.action
      const matchingResourceKey = value?.idempotency_key === record.idempotencyKey
      const matchingAttempt = value?.attempts?.find(attempt => attempt.idempotency_key === record.idempotencyKey && attempt.action === expectedAction)
        ?? ('attempt' in (value ?? {}) && matchingResourceKey && value.attempt?.action === expectedAction ? value.attempt : null)
      if (!matchingAttempt && !matchingResourceKey) {
        recoveryMessage.value = 'A consulta ainda não confirma esta chave. A operação permanece bloqueada para evitar duplicidade.'
        return
      }
      if (record.action.startsWith('document:')) {
        updateDocumentRow(value as ErpFiscalDocument)
      } else if (record.action.startsWith('event:')) {
        const event = value as ErpFiscalEvent
        events.value = events.value.some(row => row.id === event.id) ? events.value.map(row => row.id === event.id ? event : row) : [event, ...events.value]
      } else if (record.action.startsWith('invalidation:')) {
        const invalidation = value as ErpFiscalInvalidation
        invalidations.value = invalidations.value.some(row => row.id === invalidation.id) ? invalidations.value.map(row => row.id === invalidation.id ? invalidation : row) : [invalidation, ...invalidations.value]
      }
      if (record.action === 'event:draft') {
        const event = value as ErpFiscalEvent
        if (['accepted', 'rejected'].includes(event.status)) {
          clearRecovery(record)
        } else {
          const resumable = { ...record, resourceId: event.id, recoveryMode: 'read' as const }
          persistRecovery(resumable)
          recoverableEventDraft.value = event
          recoveryMessage.value = 'Rascunho do evento recuperado. Confirme o envio deste mesmo evento para continuar.'
        }
      } else if (record.action === 'invalidation:draft') clearRecovery(record)
      else if (matchingAttempt && ['accepted', 'rejected', 'superseded', 'blocked_authority'].includes(matchingAttempt.status)) clearRecovery(record)
      else recoveryMessage.value = 'A operação ainda está em processamento. Ela permanece vinculada à mesma chave.'
    } catch (error) {
      if (!isCurrent(token, epoch)) return
      recoveryMessage.value = apiStatus(error) === 404
        ? 'A tentativa original ainda não pode ser localizada. A chave foi mantida e uma nova operação continua bloqueada.'
        : `Não foi possível consultar a tentativa original: ${apiErrorMessage(error) ?? 'erro inesperado'}`
    }
  }

  async function createAndSubmitEvent(document: ErpFiscalDocument, eventType: string, fields: Record<string, unknown>, idempotencyKey: string) {
    if (!document.supported_events?.includes(eventType) || !can(actionGrants.eventCreate) || !can(actionGrants.eventSubmit)) return
    if (!configuration.value || configuration.value.release_gates.provider === 'unavailable' || !canStartProduction(document.environment)) {
      releaseMessage.value = `Envio de evento bloqueado: ${productionBlocker.value ?? 'serviço fiscal indisponível'}`
      throw new Error(releaseMessage.value)
    }
    const draftBody = { erp_fiscal_document_id: document.id, event_type: eventType, idempotency_key: idempotencyKey, ...fields }
    const token = authority.capture()
    const epoch = operationEpoch.value
    const selection = detailSelectionEpoch
    const draftPath = '/erp/fiscal/events'
    if (currentRecovery()) throw new Error('Há uma operação fiscal sem resultado confirmado. Recupere a mesma identidade antes de iniciar outra.')
    let draftRecord = makeRecovery('event:draft', draftPath, idempotencyKey, draftBody)
    if (!persistRecovery(draftRecord)) {
      abandonUnsentRecovery(draftRecord)
      throw new Error(recoveryMessage.value)
    }
    saving.value = true
    fieldErrors.value = {}
    let submitAccepted = false
    let resumableDraftRecord: ErpFiscalRecoveryRecord | null = null
    let createdEvent: ErpFiscalEvent | null = null
    try {
      const created = await api<ApiEnvelope<ErpFiscalEvent>>(draftPath, { method: 'POST', body: draftBody })
      if (!isCurrent(token, epoch)) return
      const eventId = created.data.id
      createdEvent = created.data
      events.value.unshift(created.data)
      draftRecord = { ...draftRecord, resourceId: eventId, recoveryMode: 'read' }
      if (!persistRecovery(draftRecord)) {
        recoveryMessage.value = 'O evento foi criado, mas sua identidade recuperável não pôde ser atualizada. Nenhum envio foi iniciado; recupere a solicitação original pela mesma chave.'
        throw new Error(recoveryMessage.value)
      }
      resumableDraftRecord = draftRecord
      recoverableEventDraft.value = created.data
      if (selection !== detailSelectionEpoch || selectedDocument.value?.id !== document.id) return
      const submitPath = `/erp/fiscal/events/${eventId}/submit`
      const submitBody = { idempotency_key: idempotencyKey }
      const submitRecord = makeRecovery('event:submit', submitPath, idempotencyKey, { original: draftBody, submit: submitBody }, eventId, 'read')
      if (!persistRecovery(submitRecord)) {
        recoveryMessage.value = 'O rascunho do evento foi salvo, mas não foi possível preparar o envio. Recupere o mesmo rascunho antes de continuar.'
        return
      }
      draftRecord = submitRecord
      recoverableEventDraft.value = null
      const submitted = await api<ApiEnvelope<ErpFiscalEvent>>(submitPath, { method: 'POST', body: submitBody })
      submitAccepted = true
      if (!isCurrent(token, epoch)) return
      if (selection !== detailSelectionEpoch || selectedDocument.value?.id !== document.id) return
      const detail = await api<ApiEnvelope<ErpFiscalEvent>>(`/erp/fiscal/events/${eventId}`)
      if (!isCurrent(token, epoch)) return
      if (selection !== detailSelectionEpoch || selectedDocument.value?.id !== document.id) return
      const event = detail.data ?? submitted.data
      events.value = events.value.map(row => row.id === event.id ? event : row)
      if (['accepted', 'rejected'].includes(event.status)) clearRecovery(draftRecord)
      else recoveryMessage.value = 'O evento não tem resultado confirmado. A consulta preservará a tentativa sem reenviá-la.'
    } catch (error) {
      if (!isCurrent(token, epoch)) return
      if (selection !== detailSelectionEpoch || selectedDocument.value?.id !== document.id) return
      fieldErrors.value = fiscalApiFieldErrors(error)
      if (!submitAccepted && definitiveBeforeDispatch(apiStatus(error))) {
        if (resumableDraftRecord && createdEvent) {
          if (persistRecovery(resumableDraftRecord)) {
            recoverableEventDraft.value = createdEvent
            recoveryMessage.value = 'O envio foi recusado antes do registro da tentativa. Revise a condição e confirme novamente o mesmo rascunho e a mesma chave.'
          } else {
            recoverableEventDraft.value = null
            recoveryMessage.value = 'A recusa foi confirmada, mas não foi possível restaurar a identidade do rascunho. Consulte o mesmo evento antes de agir novamente.'
          }
        } else {
          clearRecovery(draftRecord)
        }
      } else recoveryMessage.value = 'O resultado do evento não foi confirmado. A tentativa original foi mantida para consulta.'
      errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível enviar o evento fiscal.'
      throw error
    } finally {
      if (isCurrent(token, epoch) && selection === detailSelectionEpoch) saving.value = false
    }
  }

  async function continueRecoveredEventDraft() {
    const event = recoverableEventDraft.value
    const record = currentRecovery()
    if (!event || !record || record.action !== 'event:draft' || record.resourceId !== event.id || record.idempotencyKey !== event.idempotency_key) return
    if (!can(actionGrants.eventSubmit)) throw new Error('Seu acesso não permite enviar este evento.')
    const token = authority.capture()
    const epoch = operationEpoch.value
    const selection = detailSelectionEpoch
    const related = await api<ApiEnvelope<ErpFiscalDocument>>(`/erp/fiscal/documents/${event.erp_fiscal_document_id}`)
    if (!isCurrent(token, epoch) || selection !== detailSelectionEpoch) return
    const relatedDocument = related.data
    if (relatedDocument.id !== event.erp_fiscal_document_id || !relatedDocument.supported_events?.includes(event.event_type)) {
      throw new Error('O documento atual não confirma mais este evento como permitido.')
    }
    if (!configuration.value || configuration.value.release_gates.provider === 'unavailable' || !canStartProduction(relatedDocument.environment)) {
      releaseMessage.value = relatedDocument.environment === 'production'
        ? `Envio de evento bloqueado: ${productionBlocker.value ?? 'serviço fiscal indisponível'}`
        : 'Envio de evento bloqueado: serviço fiscal indisponível.'
      throw new Error(releaseMessage.value)
    }
    const submitPath = `/erp/fiscal/events/${event.id}/submit`
    const submitBody = { idempotency_key: record.idempotencyKey }
    const submitRecord = makeRecovery('event:submit', submitPath, record.idempotencyKey, submitBody, event.id, 'read')
    if (!persistRecovery(submitRecord)) throw new Error(recoveryMessage.value)
    recoverableEventDraft.value = null
    saving.value = true
    let submitAccepted = false
    try {
      const submitted = await api<ApiEnvelope<ErpFiscalEvent>>(submitPath, { method: 'POST', body: submitBody })
      submitAccepted = true
      if (!isCurrent(token, epoch) || selection !== detailSelectionEpoch) return
      const detail = await api<ApiEnvelope<ErpFiscalEvent>>(`/erp/fiscal/events/${event.id}`)
      if (!isCurrent(token, epoch) || selection !== detailSelectionEpoch) return
      const updated = detail.data ?? submitted.data
      events.value = events.value.map(row => row.id === updated.id ? updated : row)
      if (['accepted', 'rejected'].includes(updated.status)) clearRecovery(submitRecord)
      else recoveryMessage.value = 'O envio do evento ainda não tem resultado final. Consulte a mesma tentativa antes de agir novamente.'
    } catch (error) {
      if (!isCurrent(token, epoch) || selection !== detailSelectionEpoch) return
      errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível enviar o evento recuperado.'
      if (!submitAccepted && definitiveBeforeDispatch(apiStatus(error))) {
        if (persistRecovery(record)) {
          recoverableEventDraft.value = event
          recoveryMessage.value = 'O envio foi recusado antes do registro da tentativa. Revise a condição e confirme novamente o mesmo evento e a mesma chave.'
        } else {
          recoverableEventDraft.value = null
          recoveryMessage.value = 'A recusa foi confirmada, mas o rascunho não pôde ser restaurado. Consulte o mesmo evento antes de agir novamente.'
        }
      } else {
        recoveryMessage.value = 'O envio do evento não foi confirmado. Consulte esta mesma tentativa; não envie outra chave.'
      }
      throw error
    } finally {
      if (isCurrent(token, epoch) && selection === detailSelectionEpoch) saving.value = false
    }
  }

  async function createInvalidation(body: Record<string, unknown>, idempotencyKey: string) {
    if (!can(actionGrants.invalidationCreate)) return
    if (currentRecovery()) throw new Error('Há uma operação fiscal sem resultado confirmado. Recupere a mesma identidade antes de iniciar outra.')
    const path = '/erp/fiscal/invalidations'
    const recovery = makeRecovery('invalidation:draft', path, idempotencyKey, body)
    if (!persistRecovery(recovery)) {
      abandonUnsentRecovery(recovery)
      throw new Error(recoveryMessage.value)
    }
    const token = authority.capture()
    const epoch = operationEpoch.value
    saving.value = true
    fieldErrors.value = {}
    try {
      const result = await api<ApiEnvelope<ErpFiscalInvalidation>>(path, { method: 'POST', body })
      if (!isCurrent(token, epoch)) return
      invalidations.value.unshift(result.data)
      clearRecovery(recovery)
    } catch (error) {
      if (!isCurrent(token, epoch)) return
      fieldErrors.value = fiscalApiFieldErrors(error)
      if (definitiveBeforeDispatch(apiStatus(error))) clearRecovery(recovery)
      else recoveryMessage.value = 'A faixa não teve confirmação. Recupere exatamente a mesma chave antes de criar outra.'
      errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível registrar a faixa de inutilização.'
    } finally {
      if (isCurrent(token, epoch)) saving.value = false
    }
  }

  async function submitInvalidation(invalidation: ErpFiscalInvalidation, idempotencyKey = invalidation.idempotency_key) {
    if (!can(actionGrants.invalidationSubmit)) throw new Error('Seu acesso não permite solicitar a inutilização.')
    if (!configuration.value || configuration.value.release_gates.provider === 'unavailable' || !canStartProduction(invalidation.environment)) {
      releaseMessage.value = `Inutilização bloqueada: ${productionBlocker.value ?? 'provider_unavailable'}`
      throw new Error(releaseMessage.value)
    }
    if (currentRecovery()) throw new Error('Há uma operação fiscal sem resultado confirmado. Recupere a mesma identidade antes de iniciar outra.')
    const path = `/erp/fiscal/invalidations/${invalidation.id}/submit`
    const body = { idempotency_key: idempotencyKey }
    const recovery = makeRecovery('invalidation:submit', path, idempotencyKey, body, invalidation.id)
    recovery.recoveryMode = 'read'
    if (!persistRecovery(recovery)) {
      abandonUnsentRecovery(recovery)
      throw new Error(recoveryMessage.value)
    }
    const token = authority.capture()
    const epoch = operationEpoch.value
    saving.value = true
    let postAccepted = false
    try {
      await api<ApiEnvelope<ErpFiscalInvalidation>>(path, { method: 'POST', body })
      postAccepted = true
      if (!isCurrent(token, epoch)) return
      const detail = await api<ApiEnvelope<ErpFiscalInvalidation>>(`/erp/fiscal/invalidations/${invalidation.id}`)
      if (!isCurrent(token, epoch)) return
      invalidations.value = invalidations.value.map(row => row.id === detail.data.id ? detail.data : row)
      if (['accepted', 'rejected'].includes(detail.data.status)) clearRecovery(recovery)
      else recoveryMessage.value = 'A inutilização continua pendente; consulte o mesmo protocolo antes de iniciar outra operação.'
    } catch (error) {
      if (!isCurrent(token, epoch)) return
      if (!postAccepted && definitiveBeforeDispatch(apiStatus(error))) clearRecovery(recovery)
      else recoveryMessage.value = 'A solicitação não teve confirmação. A tentativa permanece vinculada à mesma identidade.'
      errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível solicitar a inutilização.'
      throw error
    } finally {
      if (isCurrent(token, epoch)) saving.value = false
    }
  }

  async function downloadArtifact(document: ErpFiscalDocument, format: 'xml' | 'pdf') {
    const permission = format === 'xml' ? actionGrants.xml : actionGrants.pdf
    const verifiedLocalXml = format === 'xml' && document.status === 'contingency_local'
    if (!can(permission) || (!document.artifact_ready && !verifiedLocalXml)) {
      artifactError.value = format === 'pdf' ? 'PDF indisponível: o documento não tem artefato pronto.' : 'XML indisponível: o documento não tem artefato pronto.'
      throw new Error(artifactError.value)
    }
    const token = authority.capture()
    const epoch = operationEpoch.value
    const selection = detailSelectionEpoch
    artifactError.value = ''
    try {
      const blob = await api<Blob, 'blob'>(`/erp/fiscal/documents/${document.id}/${format}`, { responseType: 'blob' })
      if (!isCurrent(token, epoch)) return
      if (selection !== detailSelectionEpoch || selectedDocument.value?.id !== document.id) return
      const validMime = format === 'pdf'
        ? blob instanceof Blob && blob.type === 'application/pdf'
        : blob instanceof Blob && ['application/xml', 'text/xml'].includes(blob.type)
      if (!validMime || blob.size === 0) {
        throw new Error(format === 'pdf' ? 'PDF indisponível: o serviço de renderização não retornou um arquivo válido.' : 'XML indisponível: a resposta não contém um arquivo válido.')
      }
      if (typeof globalThis.document === 'undefined') throw new Error('O download fica disponível no navegador autenticado.')
      const url = URL.createObjectURL(blob)
      const anchor = globalThis.document.createElement('a')
      anchor.href = url
      anchor.download = `fiscal-${document.id}.${format}`
      anchor.click()
      URL.revokeObjectURL(url)
    } catch (error) {
      if (!isCurrent(token, epoch)) return
      if (selection !== detailSelectionEpoch || selectedDocument.value?.id !== document.id) return
      artifactError.value = apiErrorMessage(error) ?? 'O arquivo não pôde ser baixado.'
      throw error
    }
  }

  function clearDraftSources() {
    sourceSelectionEpoch++
    selectedSale.value = null
    selectedReturn.value = null
  }

  async function loadSaleSnapshot(saleId: number) {
    if (!can(actionGrants.documentCreate)) return null
    const selection = ++sourceSelectionEpoch
    selectedSale.value = null
    const token = authority.capture()
    const epoch = operationEpoch.value
    try {
      const result = await api<ApiEnvelope<ErpFiscalSale>>(`/erp/sales/${saleId}`)
      if (!isCurrent(token, epoch) || selection !== sourceSelectionEpoch) return null
      if (result.data.status !== 'posted' || result.data.erp_establishment_id !== selectedEstablishmentId.value) throw new Error('Selecione uma venda lançada do estabelecimento emissor atual.')
      selectedSale.value = result.data
      return result.data
    } catch (error) {
      if (isCurrent(token, epoch) && selection === sourceSelectionEpoch) errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível carregar a venda lançada.'
      return null
    }
  }

  async function loadReturnSnapshot(returnId: number) {
    if (!can(actionGrants.documentCreate)) return null
    const selection = ++sourceSelectionEpoch
    selectedReturn.value = null
    const token = authority.capture()
    const epoch = operationEpoch.value
    try {
      const result = await api<ApiEnvelope<ErpFiscalSale>>(`/erp/sale-returns/${returnId}`)
      if (!isCurrent(token, epoch) || selection !== sourceSelectionEpoch) return null
      if (result.data.status !== 'posted' || result.data.erp_establishment_id !== selectedEstablishmentId.value) throw new Error('Selecione uma devolução lançada do estabelecimento emissor atual.')
      selectedReturn.value = result.data
      return result.data
    } catch (error) {
      if (isCurrent(token, epoch) && selection === sourceSelectionEpoch) errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível carregar a devolução lançada.'
      return null
    }
  }

  return {
    establishments, selectedEstablishmentId, configuration, configurationForm, listStates, documents, documentSources, sales, returns, certificates, events, invalidations, operationNatures,
    selectedDocument, selectedSale, selectedReturn, documentForm, fieldErrors, loading, saving, errorMessage, releaseMessage,
    recoveryMessage, artifactError, pendingRecovery, pendingCertificateRecovery, certificateRecoveryReview, certificateMutationInFlight, recoverableEventDraft, noCompany, canRead, readOnly, canWriteConfiguration,
    authorityGeneration: operationEpoch, authorityNeedsRefresh,
    canUploadCertificates, canRotateCertificates,
    canCreateDocuments, productionBlocked, actionGrants, can, documentModel, setDocumentModel,
    canQueue: computed(() => can(actionGrants.queue)), canTransmit: computed(() => can(actionGrants.transmit)),
    canConsult: computed(() => can(actionGrants.consult)), canEnableContingency: computed(() => can(actionGrants.contingency)),
    canReconcileContingency: computed(() => can(actionGrants.contingencyReconcile)), canCreateEvents: computed(() => can(actionGrants.eventCreate) && can(actionGrants.eventSubmit)),
    canCreateInvalidations: computed(() => can(actionGrants.invalidationCreate)), canSubmitInvalidations: computed(() => can(actionGrants.invalidationSubmit)),
    canDownloadXml: computed(() => can(actionGrants.xml)), canDownloadPdf: computed(() => can(actionGrants.pdf)),
    load, loadDocument, saveConfiguration, uploadCertificate, recoverCertificateMutation, confirmCertificateRecoveryReview,
    createDocumentDraft, transmitDocument, runDocumentAction, recoverPendingOperations, createAndSubmitEvent, continueRecoveredEventDraft, createInvalidation, submitInvalidation,
    downloadArtifact, clearDraftSources, loadSaleSnapshot, loadReturnSnapshot
  }
}
