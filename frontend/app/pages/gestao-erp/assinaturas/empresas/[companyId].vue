<script setup lang="ts">
import { formatTaxId } from '~/utils/taxId'
import type { OfficeErpCommandResult, OfficeErpCompany, OfficeErpCompanyProfile } from '#shared/types/officeErpCommercial'
import { formatOfficeErpMoney, officeErpModuleOptions } from '#shared/utils/officeErpCommercial'
import { onBeforeUnmount, onMounted } from 'vue'
import { apiErrorMessage } from '~/composables/useApiError'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { formatOfficeErpCompanyDate, officeErpContractAmendmentAction, officeErpContractStatusLabel, officeErpTrialStatusLabel } from '~/utils/officeErpContractAmendment'

definePageMeta({ middleware: 'auth' })
const route = useRoute()
const router = useRouter()
const { api } = useAccessApi()
const context = useOfficeErpContext()
const command = useOfficeErpCommand()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const companyId = computed(() => typeof route.params.companyId === 'string' && /^[1-9]\d{0,14}$/.test(route.params.companyId) ? route.params.companyId : '')
const company = ref<OfficeErpCompany | null>(null)
const baseline = ref<OfficeErpCompanyProfile | null>(null)
const draft = reactive<OfficeErpCompanyProfile>({ name: '', trade_name: null, tax_regime: 'other', contact_person: null, email: null, phone: null, whatsapp: null, street_type: null, street: null, address_number: null, address_complement: null, district: null, postal_code: null, city: null, state: null, ie: null })
const profileVersion = ref('')
interface CompanySubscriptionDetail {
  company: { id: number, name: string }
  subscription: { id: number, status: string, erp_plan_id: number | null, erp_plan: { id: number, name: string } | null } | null
  current_contract: { id: number, agreement_status: string, current_revision: { id?: number, revision_number?: number, price: string, interval_months: number, due_in_days: number, module_keys: string[] } | null, pending_revision: { id?: number, revision_number: number, service_period_start: string | null, price: string, interval_months: number, due_in_days: number, module_keys: string[] } | null, service_anchor_on: string | null, next_billing_on: string | null, cancel_effective_on: string | null, manual_hold: boolean, invoice_ids?: number[], balance?: { outstanding: string, invoice_count: number } } | null
  trial: { ends_at: string, situation: string } | null
  allocation: unknown
  balance: { outstanding: string, invoice_count: number } | null
  action_capabilities: Record<string, boolean | string[]>
}
interface SubscriptionTerms {
  price: string
  interval_months: 1 | 3 | 6 | 12
  due_in_days: number
  module_keys: string[]
  expected_pending_revision_id: number | null
}
type SubscriptionManagementIntent
  = | { contractId: number, companyId: number | null, action: 'contract.amend', body: SubscriptionTerms }
    | { contractId: number, companyId: number | null, action: 'contract.renew' | 'contract.resume', body: Record<string, never> }
    | { contractId: number, companyId: number | null, action: 'contract.suspend', body: { reason: string } }
    | { contractId: number, companyId: number | null, action: 'contract.cancel', body: { mode: 'immediate' | 'period_end', reason: string } }
const subscription = ref<CompanySubscriptionDetail | null>(null)
interface ContractHistoryRow { id: number, agreement_status: string, licence_status?: string | null, erp_plan?: { name: string } | null, current_revision?: { revision_number?: number, price: string, interval_months: number, module_keys: string[] } | null, created_at?: string, balance?: { invoice_count: number, outstanding: string } }
interface CompanyInvoiceRow { id: number, contract_id: number, kind: string, document_status: 'draft' | 'issued' | 'canceled', number: string | null, period_start: string, period_end: string, due_on: string, currency: 'BRL', original_total: string, balance: { outstanding: string, invoice_count?: number } }
interface CompanyInvoiceResponse { data: CompanyInvoiceRow[], meta: { current_page: number, last_page: number, per_page: 25 | 50 | 100, total: number } }
const companyContracts = ref<ContractHistoryRow[]>([])
const contractHistoryLoading = ref(false)
const contractHistoryError = ref('')
const contractHistoryPage = ref(1)
const contractHistoryPerPage = ref<25 | 50 | 100>(25)
const contractHistoryTotal = ref(0)
const companyInvoices = ref<CompanyInvoiceRow[]>([])
const invoicePage = ref(1)
const invoicePerPage = ref<25 | 50 | 100>(25)
const invoiceStatus = ref<'all' | 'draft' | 'issued' | 'canceled'>('all')
const invoiceTotal = ref(0)
const invoiceLoading = ref(false)
const invoiceError = ref('')
const subscriptionLoading = ref(false)
const subscriptionError = ref('')
const assignmentOpen = ref(false)
const managementOpen = ref(false)
const modulesOpen = ref(false)
const managementSubmitting = ref(false)
const managementError = ref('')
const subscriptionNotice = ref('')
const ownerName = ref('')
const ownerEmail = ref('')
const ownerLoading = ref(false)
const ownerError = ref('')
const ownerInvite = ref<{ invitation_id: number, status: string, expires_at: string, url?: string } | null>(null)
const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const formError = ref('')
const profileFieldErrors = ref<Record<string, string[]>>({})
const notice = ref('')
const confirmedEmpty = ref(false)
let generation = 0
let lifecycleGeneration = 0
let mounted = false
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null, companyId.value], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const sections = ['subscription', 'members', 'invoices', 'history'] as const
type Section = typeof sections[number] | 'profile'
const activeSection = computed<Section>(() => route.query.secao === 'profile' ? 'profile' : sections.find(section => section === route.query.secao) ?? 'subscription')
const moduleKeysAvailable = computed(() => context.capabilities.value?.allowance?.module_keys ?? [])
const managementCapabilities = computed(() => ({
  can_manage_lifecycle: subscription.value?.action_capabilities.can_manage_lifecycle === true,
  can_amend_terms: subscription.value?.action_capabilities.can_amend_terms === true,
  can_cancel: subscription.value?.action_capabilities.can_cancel === true,
  can_suspend: subscription.value?.action_capabilities.can_suspend === true,
  can_resume: subscription.value?.action_capabilities.can_resume === true
}))
const hasChanges = computed(() => !!baseline.value && Object.keys(draft).some(key => draft[key as keyof OfficeErpCompanyProfile] !== baseline.value![key as keyof OfficeErpCompanyProfile]))
const profileFields: Array<{ key: keyof OfficeErpCompanyProfile, label: string, type?: string, wide?: boolean }> = [
  { key: 'name', label: 'Razão social', wide: true }, { key: 'trade_name', label: 'Nome fantasia', wide: true },
  { key: 'tax_regime', label: 'Regime tributário' }, { key: 'ie', label: 'Inscrição estadual' },
  { key: 'contact_person', label: 'Pessoa de contato' }, { key: 'email', label: 'E-mail', type: 'email' },
  { key: 'phone', label: 'Telefone', type: 'tel' }, { key: 'whatsapp', label: 'WhatsApp', type: 'tel' },
  { key: 'street_type', label: 'Tipo de logradouro' }, { key: 'street', label: 'Logradouro', wide: true },
  { key: 'address_number', label: 'Número' }, { key: 'address_complement', label: 'Complemento' },
  { key: 'district', label: 'Bairro' }, { key: 'postal_code', label: 'CEP' },
  { key: 'city', label: 'Município' }, { key: 'state', label: 'UF' }
]

function assignProfile(value: OfficeErpCompanyProfile) {
  baseline.value = structuredClone(value)
  Object.assign(draft, structuredClone(value))
}
function clearCompanyDetailState() {
  generation++
  for (const key of ['loadCompany', 'loadSubscription', 'loadCompanyContracts', 'loadCompanyInvoices']) reads.cancel(key)
  company.value = null
  subscription.value = null
  assignmentOpen.value = false
  managementOpen.value = false
  modulesOpen.value = false
  managementSubmitting.value = false
  managementError.value = ''
  subscriptionNotice.value = ''
  ownerInvite.value = null
  ownerName.value = ''
  ownerEmail.value = ''
  ownerError.value = ''
  ownerLoading.value = false
  saving.value = false
  baseline.value = null
  profileVersion.value = ''
  Object.assign(draft, { name: '', trade_name: null, tax_regime: 'other', contact_person: null, email: null, phone: null, whatsapp: null, street_type: null, street: null, address_number: null, address_complement: null, district: null, postal_code: null, city: null, state: null, ie: null })
  companyContracts.value = []
  contractHistoryTotal.value = 0
  contractHistoryLoading.value = false
  contractHistoryError.value = ''
  companyInvoices.value = []
  invoiceTotal.value = 0
  invoiceLoading.value = false
  invoiceError.value = ''
  loading.value = false
  subscriptionLoading.value = false
  subscriptionError.value = ''
  loadError.value = ''
  formError.value = ''
  profileFieldErrors.value = {}
  notice.value = ''
  confirmedEmpty.value = false
}
function date(value: string) {
  return formatOfficeErpCompanyDate(value)
}
function moduleLabels(keys: string[]) {
  return keys.map(key => officeErpModuleOptions.find(option => option.value === key)?.label ?? key)
}
function intervalLabel(months: number) {
  return ({ 1: 'Mensal', 3: 'Trimestral', 6: 'Semestral', 12: 'Anual' } as Record<number, string>)[months] ?? 'Periodicidade indisponível'
}
function openSubscriptionManagement() {
  if (!company.value || subscription.value?.company.id !== company.value.id || !subscription.value.current_contract) return
  managementError.value = ''
  managementOpen.value = true
}
function openModuleManagement() {
  if (!company.value || subscription.value?.company.id !== company.value.id || !subscription.value.current_contract || !managementCapabilities.value.can_amend_terms) return
  managementError.value = ''
  modulesOpen.value = true
}
function setSection(section: Section) {
  const query = { ...route.query }
  if (section === 'subscription') delete query.secao
  else query.secao = section
  void router.replace({ query })
}
async function assignmentConfirmed(result: { company_id: number, contract_id: number }) {
  const targetCompanyId = company.value?.id
  if (!mounted || !targetCompanyId || result.company_id !== targetCompanyId || companyId.value !== String(targetCompanyId)) return
  setSection('subscription')
  await loadCompany()
}
function changeSet(): Partial<OfficeErpCompanyProfile> {
  const changes: Partial<OfficeErpCompanyProfile> = {}
  for (const field of Object.keys(draft) as Array<keyof OfficeErpCompanyProfile>) {
    const value = draft[field]
    const normalized = typeof value === 'string' && value.trim() === '' ? null : value
    if (normalized !== baseline.value?.[field]) Object.assign(changes, { [field]: normalized })
  }
  return changes
}
function updateProfileField(key: keyof OfficeErpCompanyProfile, value: string | number | null) {
  profileFieldErrors.value = Object.fromEntries(Object.entries(profileFieldErrors.value).filter(([field]) => field !== key))
  if (key === 'tax_regime') return
  Object.assign(draft, { [key]: value === '' ? null : value })
}
function failureCode(error: unknown) {
  if (!error || typeof error !== 'object') return ''
  const candidate = error as { data?: { code?: unknown, message?: unknown }, response?: { _data?: { code?: unknown, message?: unknown } } }
  return String(candidate.data?.code ?? candidate.response?._data?.code ?? '')
}

async function loadCompany() {
  if (route.path.includes('/membros')) return
  if (!companyId.value) {
    loadError.value = 'Identificador de empresa inválido.'
    return
  }
  const read = reads.begin('loadCompany')
  const request = ++generation
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (!read.current() || request !== generation || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) {
      loadError.value = 'A conta selecionada não tem permissão para consultar esta empresa.'
      return
    }
    const token = context.capture()
    const response = await api<{ data: OfficeErpCompany & { profile: OfficeErpCompanyProfile, profile_version: string } }>(`/erp-commercial/companies/${companyId.value}`, { signal: read.signal })
    if (!read.current() || request !== generation || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!response?.data?.profile || !/^[a-f\d]{64}$/i.test(response.data.profile_version)) throw new Error('Os dados de perfil da empresa estão incompletos.')
    company.value = response.data
    profileVersion.value = response.data.profile_version
    assignProfile(response.data.profile)
    confirmedEmpty.value = false
    void loadSubscription()
  } catch (error) {
    if (read.current() && request === generation && identity === identityStamp.value) loadError.value = apiErrorMessage(error) ?? 'Não foi possível carregar a empresa agora.'
  } finally { if (read.current() && request === generation && identity === identityStamp.value) loading.value = false }
}

async function loadSubscription() {
  if (!companyId.value) return
  const read = reads.begin('loadSubscription')
  const identity = identityStamp.value
  subscriptionLoading.value = true
  subscriptionError.value = ''
  try {
    await context.refresh()
    if (!read.current() || identity !== identityStamp.value) return
    const token = context.capture()
    const response = await api<{ data: CompanySubscriptionDetail }>(`/erp-commercial/companies/${companyId.value}/subscription`, { signal: read.signal })
    if (!read.current() || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!response?.data || response.data.company.id !== Number(companyId.value)) throw new Error('A resposta da assinatura da empresa está incompleta.')
    subscription.value = response.data
  } catch (error) {
    if (read.current() && identity === identityStamp.value) subscriptionError.value = apiErrorMessage(error) ?? 'Não foi possível consultar os termos da assinatura.'
  } finally {
    if (read.current() && identity === identityStamp.value) subscriptionLoading.value = false
  }
}
async function loadCompanyContracts() {
  if (!companyId.value) return
  const read = reads.begin('loadCompanyContracts')
  const identity = identityStamp.value
  contractHistoryLoading.value = true
  contractHistoryError.value = ''
  try {
    await context.refresh()
    if (!read.current() || identity !== identityStamp.value) return
    const token = context.capture()
    const response = await api<{ data: ContractHistoryRow[], meta: { current_page: number, last_page: number, per_page: number, total: number } }>(`/erp-commercial/companies/${companyId.value}/contracts`, { signal: read.signal, params: { page: contractHistoryPage.value, per_page: contractHistoryPerPage.value } })
    if (!read.current() || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || response.data.some(contract => !Number.isSafeInteger(contract.id))) throw new Error('A resposta do histórico de contratos está incompleta.')
    companyContracts.value = response.data
    contractHistoryPage.value = response.meta.current_page
    contractHistoryTotal.value = response.meta.total
  } catch (error) {
    if (read.current() && identity === identityStamp.value) contractHistoryError.value = apiErrorMessage(error) ?? 'Não foi possível consultar os contratos relacionados.'
  } finally {
    if (read.current() && identity === identityStamp.value) contractHistoryLoading.value = false
  }
}

async function loadCompanyInvoices() {
  if (!companyId.value || activeSection.value !== 'invoices') return
  const read = reads.begin('loadCompanyInvoices')
  const identity = identityStamp.value
  invoiceLoading.value = true
  invoiceError.value = ''
  try {
    await context.refresh()
    if (!read.current() || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) throw new Error('A conta selecionada não pode consultar as faturas desta empresa.')
    const token = context.capture()
    const params: Record<string, string | number> = { page: invoicePage.value, per_page: invoicePerPage.value }
    if (invoiceStatus.value !== 'all') params.status = invoiceStatus.value
    const response = await api<CompanyInvoiceResponse>(`/erp-commercial/companies/${companyId.value}/invoices`, { signal: read.signal, params })
    if (!read.current() || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)) throw new Error('A resposta das faturas da empresa está incompleta.')
    const available = officeErpAvailablePage(response.meta)
    if (invoicePage.value !== available) {
      invoicePage.value = available
      return
    }
    companyInvoices.value = response.data
    invoicePage.value = response.meta.current_page
    invoiceTotal.value = response.meta.total
  } catch (error) {
    if (read.current() && identity === identityStamp.value) invoiceError.value = apiErrorMessage(error) ?? (error instanceof Error ? error.message : 'Não foi possível consultar as faturas da empresa.')
  } finally {
    if (read.current() && identity === identityStamp.value) invoiceLoading.value = false
  }
}

async function activateSubscription() {
  const contractId = subscription.value?.current_contract?.id ?? company.value?.current_contract_id
  if (!company.value || subscription.value?.company.id !== company.value.id || !contractId || !subscription.value?.action_capabilities?.can_activate || command.pending.value) return
  const targetCompanyId = company.value.id
  const identity = identityStamp.value
  const lifecycle = lifecycleGeneration
  const result = await command.execute('contract.activate', `/erp-commercial/contracts/${contractId}/activate`, {})
  if (!mounted || lifecycle !== lifecycleGeneration || route.path.includes('/membros') || identity !== identityStamp.value || companyId.value !== String(targetCompanyId) || subscription.value?.company.id !== targetCompanyId) return
  if (result.status === 'confirmed') await loadSubscription()
  else if (result.status === 'unknown') subscriptionError.value = `A ativação ainda não foi confirmada. Recupere a operação ${result.key} antes de tentar outra vez.`
  else if (result.status === 'failed') subscriptionError.value = result.failure.message
}

async function runManagementCommand(intent: SubscriptionManagementIntent) {
  const selectedCompany = company.value
  const detail = subscription.value
  const contract = detail?.current_contract
  if (!mounted || !selectedCompany || !detail || detail.company.id !== selectedCompany.id || !contract
    || contract.id !== intent.contractId || intent.companyId !== selectedCompany.id || companyId.value !== String(selectedCompany.id)
    || managementSubmitting.value || command.pending.value) return

  const identity = identityStamp.value
  const lifecycle = lifecycleGeneration
  const targetCompanyId = selectedCompany.id
  let action: string = intent.action
  let path = `/erp-commercial/contracts/${contract.id}`
  let method: 'POST' | 'PATCH' = 'POST'
  if (intent.action === 'contract.amend') {
    const currentModules = contract.current_revision?.module_keys ?? []
    action = officeErpContractAmendmentAction(currentModules, intent.body.module_keys)
    path += '/terms'
    method = 'PATCH'
  } else if (intent.action === 'contract.renew') path += '/renew'
  else if (intent.action === 'contract.suspend') path += '/suspend'
  else if (intent.action === 'contract.resume') path += '/resume'
  else path += '/cancel'

  managementError.value = ''
  subscriptionNotice.value = ''
  managementSubmitting.value = true
  try {
    const result = await command.execute<unknown>(action, path, { ...intent.body }, method)
    if (!mounted || lifecycle !== lifecycleGeneration || route.path.includes('/membros') || identity !== identityStamp.value
      || companyId.value !== String(targetCompanyId) || company.value?.id !== targetCompanyId || subscription.value?.company.id !== targetCompanyId
      || subscription.value.current_contract?.id !== contract.id) return
    if (result.status === 'confirmed') {
      managementOpen.value = false
      modulesOpen.value = false
      await loadSubscription()
    } else if (result.status === 'unknown') {
      managementError.value = `O resultado ainda não foi confirmado. Recupere a operação ${result.key} antes de tentar outra alteração.`
    } else if (result.status === 'failed') {
      managementError.value = result.failure.message
    }
  } finally {
    if (mounted && lifecycle === lifecycleGeneration && identity === identityStamp.value && companyId.value === String(targetCompanyId))
      managementSubmitting.value = false
  }
}

async function submitModuleTerms(request: { contractId: number, companyId: number | null, terms: SubscriptionTerms }) {
  await runManagementCommand({ contractId: request.contractId, companyId: request.companyId, action: 'contract.amend', body: request.terms })
}

async function recoverManagement(key: string) {
  const selectedCompanyId = company.value?.id
  const contractId = subscription.value?.current_contract?.id
  if (!key || key !== command.recoveryKey.value || managementSubmitting.value || !selectedCompanyId || !contractId) return
  const identity = identityStamp.value
  const lifecycle = lifecycleGeneration
  managementSubmitting.value = true
  try {
    const result = await command.recover<unknown>(key)
    if (!mounted || lifecycle !== lifecycleGeneration || route.path.includes('/membros') || identity !== identityStamp.value
      || companyId.value !== String(selectedCompanyId) || company.value?.id !== selectedCompanyId
      || subscription.value?.company.id !== selectedCompanyId || subscription.value.current_contract?.id !== contractId) return
    if (result.status === 'confirmed') {
      managementOpen.value = false
      modulesOpen.value = false
      managementError.value = ''
      subscriptionNotice.value = 'Operação confirmada. Os dados atuais da empresa foram atualizados.'
      await loadSubscription()
    } else if (result.status === 'unknown') {
      managementError.value = 'A operação ainda não foi confirmada. Consulte novamente antes de reenviar.'
    } else if (result.status === 'failed') {
      managementError.value = result.failure.message
    } else {
      managementError.value = 'O contexto comercial mudou. Consulte os dados atuais antes de continuar.'
    }
  } finally {
    if (mounted && lifecycle === lifecycleGeneration && identity === identityStamp.value && companyId.value === String(selectedCompanyId))
      managementSubmitting.value = false
  }
}

async function inviteOwner() {
  const contractId = subscription.value?.current_contract?.id ?? company.value?.current_contract_id
  if (!contractId || !company.value || subscription.value?.company.id !== company.value.id || ownerName.value.trim().length < 2 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ownerEmail.value.trim()) || command.pending.value) return
  ownerLoading.value = true
  ownerError.value = ''
  const identity = identityStamp.value
  const targetCompanyId = company.value.id
  const targetContractId = contractId
  const lifecycle = lifecycleGeneration
  try {
    const result = await command.execute<{ invitation_id: number, status: string, expires_at: string, url?: string }>('owner.setup.issue', `/erp-commercial/contracts/${contractId}/owner-setup`, { owner: { full_name: ownerName.value.trim(), email: ownerEmail.value.trim() } }) as OfficeErpCommandResult<{ invitation_id: number, status: string, expires_at: string, url?: string }>
    if (!mounted || lifecycle !== lifecycleGeneration || route.path.includes('/membros') || identity !== identityStamp.value || companyId.value !== String(targetCompanyId) || subscription.value?.company.id !== targetCompanyId || subscription.value.current_contract?.id !== targetContractId) return
    if (result.status === 'confirmed') ownerInvite.value = { ...result.data, url: safeOwnerSetupUrl(result.data.url) ?? undefined }
    else if (result.status === 'unknown') ownerError.value = `O preparo do acesso não foi confirmado. Recupere a operação ${result.key}.`
    else if (result.status === 'failed') ownerError.value = result.failure.message
    else ownerError.value = 'O contexto do escritório mudou. A operação não foi repetida.'
  } finally { if (mounted && lifecycle === lifecycleGeneration && identity === identityStamp.value && companyId.value === String(targetCompanyId)) ownerLoading.value = false }
}
function safeOwnerSetupUrl(value: unknown): string | null {
  if (typeof value !== 'string' || typeof window === 'undefined') return null
  try {
    const expected = new URL(window.location.origin)
    const target = new URL(value)
    const localHttp = expected.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(expected.hostname)
    if (target.origin !== expected.origin || (target.protocol !== 'https:' && !localHttp) || target.pathname !== '/erp/ativar' || target.search !== '' || !/^#token=[a-f\d]{64}$/i.test(target.hash)) return null
    return target.href
  } catch { return null }
}
async function copyOwnerSetupLink() {
  if (!ownerInvite.value?.url) return
  const lifecycle = lifecycleGeneration
  await navigator.clipboard.writeText(ownerInvite.value.url)
  if (!mounted || lifecycle !== lifecycleGeneration) return
  notice.value = 'Link de configuração copiado. Ele fica disponível somente nesta tela.'
}

async function saveProfile() {
  if (!company.value || !profileVersion.value || saving.value || command.pending.value) return
  const targetCompanyId = company.value.id
  const identity = identityStamp.value
  const lifecycle = lifecycleGeneration
  formError.value = ''
  profileFieldErrors.value = {}
  notice.value = ''
  const changes = changeSet()
  if (!Object.keys(changes).length) return
  if (Object.hasOwn(changes, 'name') && (typeof changes.name !== 'string' || changes.name.trim().length < 10 || changes.name.trim().length > 255)) {
    formError.value = 'A razão social deve ter entre 10 e 255 caracteres.'
    return
  }
  saving.value = true
  try {
    const body = { expected_profile_version: profileVersion.value, ...changes }
    const result = await command.execute<OfficeErpCompany & { profile: OfficeErpCompanyProfile, profile_version: string }>('company.profile.update', `/erp-commercial/companies/${targetCompanyId}`, body, 'PATCH') as OfficeErpCommandResult<OfficeErpCompany & { profile: OfficeErpCompanyProfile, profile_version: string }>
    if (!mounted || lifecycle !== lifecycleGeneration || route.path.includes('/membros') || identity !== identityStamp.value || companyId.value !== String(targetCompanyId)) return
    if (result.status === 'stale') {
      formError.value = 'O contexto da empresa mudou. Revise a conta selecionada antes de salvar.'
      return
    }
    if (result.status === 'unknown') {
      notice.value = `O resultado não foi confirmado. Recupere a operação ${result.key} antes de enviar outra vez.`
      return
    }
    if (result.status === 'failed') {
      profileFieldErrors.value = result.failure.errors ?? {}
      if (result.failure.http_status === 409 || result.failure.code === 'profile_changed') formError.value = 'Os dados foram alterados em outra sessão. Seu rascunho foi preservado; atualize a empresa antes de reaplicar as mudanças.'
      else formError.value = result.failure.message || 'Não foi possível salvar os dados da empresa.'
      return
    }
    if (result.data?.id !== targetCompanyId || !result.data.profile || !/^[a-f\d]{64}$/i.test(result.data.profile_version)) {
      notice.value = 'A operação foi confirmada. Atualize a empresa selecionada para conferir o estado atual.'
      await loadCompany()
      return
    }
    company.value = result.data
    profileVersion.value = result.data.profile_version
    assignProfile(result.data.profile)
    notice.value = 'Dados da empresa atualizados.'
  } catch (error) {
    if (!mounted || lifecycle !== lifecycleGeneration || route.path.includes('/membros') || identity !== identityStamp.value || companyId.value !== String(targetCompanyId)) return
    formError.value = failureCode(error) === 'profile_changed' ? 'Os dados foram alterados em outra sessão. Seu rascunho foi preservado; atualize a empresa antes de reaplicar as mudanças.' : apiErrorMessage(error) ?? 'Não foi possível salvar os dados da empresa.'
  } finally { if (mounted && lifecycle === lifecycleGeneration && identity === identityStamp.value && companyId.value === String(targetCompanyId)) saving.value = false }
}
async function recoverProfile() {
  const key = command.recoveryKey.value
  const targetCompanyId = company.value?.id
  const identity = identityStamp.value
  const lifecycle = lifecycleGeneration
  if (!key || saving.value || !targetCompanyId) return
  saving.value = true
  try {
    const result = await command.recover<unknown>(key)
    if (!mounted || lifecycle !== lifecycleGeneration || route.path.includes('/membros') || identity !== identityStamp.value || companyId.value !== String(targetCompanyId)) return
    if (result.status === 'confirmed') {
      const recovered = result.data as (OfficeErpCompany & { profile?: OfficeErpCompanyProfile, profile_version?: string }) | null
      if (recovered?.id === targetCompanyId && recovered.profile && typeof recovered.profile_version === 'string' && /^[a-f\d]{64}$/i.test(recovered.profile_version)) {
        company.value = recovered
        profileVersion.value = recovered.profile_version
        assignProfile(recovered.profile)
        notice.value = 'Alteração confirmada e recuperada.'
      } else {
        notice.value = 'A operação foi confirmada. Atualize a empresa selecionada para conferir os dados atuais.'
        await loadCompany()
      }
    } else if (result.status === 'unknown') notice.value = `A operação ${key} ainda não está disponível para confirmação.`
    else formError.value = result.status === 'failed' ? result.failure.message : 'A operação não pertence ao contexto atual.'
  } finally { if (mounted && lifecycle === lifecycleGeneration && identity === identityStamp.value && companyId.value === String(targetCompanyId)) saving.value = false }
}
async function recoverCommercial() {
  const key = command.recoveryKey.value
  if (!key || saving.value || ownerLoading.value) return
  const targetCompanyId = companyId.value
  const identity = identityStamp.value
  const lifecycle = lifecycleGeneration
  saving.value = true
  try {
    const result = await command.recover<unknown>(key)
    if (!mounted || lifecycle !== lifecycleGeneration || route.path.includes('/membros') || identity !== identityStamp.value || companyId.value !== targetCompanyId) return
    if (result.status === 'confirmed') {
      notice.value = 'Operação confirmada. Os dados atuais foram atualizados.'
      await loadCompany()
    } else if (result.status === 'unknown') notice.value = `A operação ${key} ainda não está confirmada.`
    else ownerError.value = result.status === 'failed' ? result.failure.message : 'A operação pertence a outro contexto comercial.'
  } finally { if (mounted && lifecycle === lifecycleGeneration && identity === identityStamp.value && companyId.value === targetCompanyId) saving.value = false }
}
onMounted(() => {
  mounted = true
  lifecycleGeneration++
  if (route.path.includes('/membros')) return
  void loadCompany()
  void loadCompanyContracts()
})
watch(() => route.path, (current, previous) => {
  lifecycleGeneration++
  if (current.includes('/membros')) {
    clearCompanyDetailState()
    return
  }
  if (previous.includes('/membros')) {
    void loadCompany()
    void loadCompanyContracts()
  }
})
watch([contractHistoryPage, contractHistoryPerPage, companyId, activeSection], () => {
  if (activeSection.value === 'history') void loadCompanyContracts()
})
watch([invoicePage, invoicePerPage, invoiceStatus, companyId, activeSection], () => {
  if (activeSection.value === 'invoices') void loadCompanyInvoices()
})
watch(identityStamp, () => {
  lifecycleGeneration++
  clearCompanyDetailState()
  if (!route.path.includes('/membros')) void loadCompany()
}, { flush: 'sync' })
onBeforeUnmount(() => {
  mounted = false
  lifecycleGeneration++
  clearCompanyDetailState()
})
</script>

<template>
  <div v-if="!route.path.includes('/membros')" class="flex min-w-0 flex-col gap-5">
    <UBreadcrumb :items="[{ label: 'Assinaturas', to: '/gestao-erp/assinaturas' }, { label: company?.name ?? 'Empresa' }]" :ui="{ link: 'text-xs', item: 'min-w-0' }" />
    <UPageCard
      :title="company?.name ?? 'Empresa'"
      :description="company ? `${baseline?.name ?? company.name} · ${formatTaxId(company.cnpj)}` : 'Consulta do cadastro e da situação comercial.'"
      icon="i-lucide-building-2"
      variant="naked"
      orientation="horizontal"
      :ui="{
        container: 'flex flex-col gap-4 p-0 sm:p-0 lg:flex lg:flex-row lg:items-start lg:justify-between',
        wrapper: 'min-w-0 flex-1 flex-row items-center gap-4',
        leading: 'mb-0 size-11 shrink-0 items-center justify-center rounded-lg border border-default',
        body: 'min-w-0',
        leadingIcon: 'size-5 text-muted',
        title: 'text-base font-semibold',
        description: 'text-xs'
      }"
    >
      <div class="flex flex-wrap justify-end gap-2">
        <UBadge v-if="subscription?.current_contract" :color="subscription.current_contract.agreement_status === 'effective' ? 'success' : subscription.current_contract.agreement_status === 'canceled' ? 'neutral' : 'warning'" variant="subtle">
          {{ officeErpContractStatusLabel(subscription.current_contract.agreement_status) }}
        </UBadge>
        <UBadge v-else-if="company" color="neutral" variant="subtle">
          {{ company.capabilities.relationship === 'unassigned' ? 'Sem assinatura' : 'Histórico' }}
        </UBadge>
        <UButton
          v-if="subscription?.current_contract"
          color="neutral"
          variant="outline"
          icon="i-lucide-settings-2"
          @click="openSubscriptionManagement"
        >
          Gerenciar assinatura
        </UButton>
        <UButton
          v-if="subscription?.action_capabilities.can_activate === true"
          color="neutral"
          variant="solid"
          :loading="command.pending.value"
          :disabled="command.pending.value"
          @click="activateSubscription"
        >
          Ativar assinatura
        </UButton>
      </div>
    </UPageCard>
    <div class="space-y-6">
      <UAlert
        v-if="loadError"
        color="error"
        title="Não foi possível carregar a empresa"
        :description="loadError"
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            :loading="loading"
            @click="loadCompany"
          >
            Tentar novamente
          </UButton>
        </template>
      </UAlert>
      <USkeleton v-else-if="loading && !company" class="h-32 w-full" />
      <template v-else-if="company">
        <div v-if="company.capabilities.can_assign" class="flex flex-wrap items-center justify-between gap-3">
          <span v-if="company.capabilities.relationship === 'unassigned'" class="text-sm text-muted">O cadastro não ocupa licença.</span>
          <UButton icon="i-lucide-plus" color="neutral" @click="assignmentOpen = true">
            Adicionar assinatura
          </UButton>
        </div>
        <UDashboardToolbar class="overflow-visible px-0 sm:px-0">
          <USelect
            :model-value="activeSection"
            :items="[{ label: 'Assinatura', value: 'subscription' }, { label: 'Usuários e acessos', value: 'members' }, { label: 'Faturas', value: 'invoices' }, { label: 'Histórico', value: 'history' }]"
            aria-label="Seção da empresa"
            class="w-full sm:hidden"
            @update:model-value="value => setSection(value as Section)"
          />
          <UNavigationMenu
            :items="[{ id: 'subscription', label: 'Assinatura' }, { id: 'members', label: 'Usuários e acessos' }, { id: 'invoices', label: 'Faturas' }, { id: 'history', label: 'Histórico' }].map(item => ({ label: item.label, active: activeSection === item.id, onSelect: () => setSection(item.id as Section) }))"
            highlight
            aria-label="Seções da empresa"
            class="hidden min-w-0 flex-1 sm:flex"
          />
        </UDashboardToolbar>

        <section v-if="activeSection === 'profile'" aria-labelledby="company-profile-title" class="space-y-4">
          <div>
            <h2 id="company-profile-title" class="text-base font-semibold text-highlighted">
              Perfil cadastral
            </h2><p class="mt-1 text-sm text-muted">
              Edite dados de contato e cadastro do escritório. CNPJ, identidade de usuários e configurações fiscais não podem ser alterados aqui.
            </p>
          </div>
          <UAlert
            v-if="formError"
            color="error"
            title="Revise os dados antes de salvar"
            :description="formError"
          />
          <UAlert
            v-if="notice || command.recoveryKey.value"
            color="warning"
            title="Estado da operação"
            :description="notice || 'Há uma operação comercial sem confirmação. Consulte o resultado antes de iniciar outra alteração.'"
          >
            <template #actions>
              <UButton
                v-if="command.recoveryKey.value"
                color="neutral"
                variant="outline"
                :loading="saving"
                @click="recoverProfile"
              >
                Recuperar operação
              </UButton>
            </template>
          </UAlert>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              v-for="field in profileFields"
              :key="field.key"
              :label="field.label"
              :name="field.key"
              :error="profileFieldErrors[field.key]?.[0]"
              :class="field.wide ? 'sm:col-span-2' : ''"
            >
              <USelect
                v-if="field.key === 'tax_regime'"
                v-model="draft.tax_regime"
                :items="[{ label: 'MEI', value: 'mei' }, { label: 'Simples Nacional', value: 'simple_national' }, { label: 'Lucro presumido', value: 'presumed_profit' }, { label: 'Lucro real', value: 'actual_profit' }, { label: 'Outro', value: 'other' }]"
                class="w-full"
                @update:model-value="value => updateProfileField(field.key, value)"
              />
              <UInput
                v-else
                :model-value="draft[field.key] ?? ''"
                :type="field.type ?? 'text'"
                :maxlength="field.key === 'name' || field.key === 'trade_name' ? 255 : undefined"
                :disabled="!company.capabilities.can_edit_profile"
                :autocomplete="field.key === 'email' ? 'email' : field.key === 'phone' ? 'tel' : 'off'"
                class="w-full"
                @update:model-value="value => updateProfileField(field.key, value)"
              />
            </UFormField>
          </div>
          <div class="flex flex-wrap gap-2 border-t border-default pt-4">
            <UButton
              color="neutral"
              variant="outline"
              :disabled="!hasChanges || saving"
              @click="baseline && assignProfile(baseline)"
            >
              Descartar alterações
            </UButton>
            <UButton :loading="saving" :disabled="!company.capabilities.can_edit_profile || !hasChanges || saving || command.pending.value" @click="saveProfile">
              Salvar perfil
            </UButton>
          </div>
        </section>
        <section v-else-if="activeSection === 'subscription'" class="space-y-4">
          <UAlert
            v-if="subscriptionError"
            color="error"
            title="Não foi possível carregar os termos"
            :description="subscriptionError"
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="outline"
                :loading="subscriptionLoading"
                @click="loadSubscription"
              >
                Tentar novamente
              </UButton>
            </template>
          </UAlert>
          <USkeleton v-else-if="subscriptionLoading && !subscription" class="h-24 w-full" />
          <template v-else-if="subscription">
            <UAlert
              v-if="subscriptionNotice"
              color="success"
              title="Operação confirmada"
              :description="subscriptionNotice"
            />
            <div class="grid gap-6 lg:grid-cols-3 lg:gap-8">
              <div class="min-w-0 space-y-4 lg:col-span-2">
                <UAlert
                  v-if="!subscription.current_contract"
                  color="neutral"
                  title="Sem assinatura"
                  description="O registro da empresa não ocupa licença. Uma assinatura só será criada após confirmar plano e termos."
                />
                <UPageCard v-else variant="subtle" :ui="{ wrapper: 'items-stretch' }">
                  <div class="space-y-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <h2 class="text-sm font-semibold text-highlighted">
                          Assinatura atual
                        </h2>
                        <p class="mt-1 text-xs text-muted">
                          Termos aplicados a esta empresa.
                        </p>
                      </div>
                      <UBadge
                        v-if="subscription.current_contract.current_revision"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                      >
                        {{ intervalLabel(subscription.current_contract.current_revision.interval_months) }}
                      </UBadge>
                    </div>
                    <div v-if="subscription.current_contract.current_revision" class="space-y-5 text-sm">
                      <dl class="grid grid-cols-2 gap-x-5 gap-y-6 xl:grid-cols-3">
                        <div>
                          <dt class="text-xs text-muted">
                            Plano
                          </dt><dd class="mt-1 font-medium text-highlighted">
                            {{ subscription.subscription?.erp_plan?.name ?? 'Plano ERP' }}
                          </dd>
                        </div>
                        <div>
                          <dt class="text-xs text-muted">
                            Valor por período
                          </dt><dd class="mt-1 font-medium text-highlighted">
                            {{ formatOfficeErpMoney(subscription.current_contract.current_revision.price) }}
                          </dd>
                        </div>
                        <div>
                          <dt class="text-xs text-muted">
                            Situação
                          </dt><dd class="mt-1 font-medium text-highlighted">
                            {{ officeErpContractStatusLabel(subscription.current_contract.agreement_status) }}
                          </dd>
                        </div>
                        <div>
                          <dt class="text-xs text-muted">
                            Início
                          </dt><dd class="mt-1 font-medium text-highlighted">
                            {{ date(subscription.current_contract.service_anchor_on ?? '') }}
                          </dd>
                        </div>
                        <div>
                          <dt class="text-xs text-muted">
                            Próxima cobrança
                          </dt><dd class="mt-1 font-medium text-highlighted">
                            {{ date(subscription.current_contract.next_billing_on ?? '') }}
                          </dd>
                        </div>
                        <div>
                          <dt class="text-xs text-muted">
                            Prazo de pagamento
                          </dt>
                          <dd class="mt-1 font-medium text-highlighted">
                            {{ subscription.current_contract.current_revision.due_in_days }} dias
                          </dd>
                        </div>
                      </dl>
                      <div class="space-y-3 border-t border-default pt-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                          <div>
                            <h3 class="text-sm font-semibold text-highlighted">
                              Módulos incluídos
                            </h3>
                            <p class="mt-1 text-xs text-muted">
                              Recursos vigentes nesta assinatura.
                            </p>
                          </div>
                          <UButton
                            v-if="managementCapabilities.can_amend_terms"
                            color="neutral"
                            variant="outline"
                            size="sm"
                            icon="i-lucide-sliders-horizontal"
                            :disabled="command.pending.value"
                            @click="openModuleManagement"
                          >
                            Gerenciar módulos
                          </UButton>
                        </div>
                        <div class="flex flex-wrap gap-2">
                          <UBadge
                            v-for="label in moduleLabels(subscription.current_contract.current_revision.module_keys)"
                            :key="label"
                            color="neutral"
                            variant="outline"
                            size="md"
                          >
                            {{ label }}
                          </UBadge>
                          <span v-if="subscription.current_contract.current_revision.module_keys.length === 0" class="text-muted">Nenhum módulo habilitado.</span>
                        </div>
                      </div>
                      <UAlert
                        v-if="subscription.current_contract.pending_revision"
                        class="sm:col-span-2"
                        color="warning"
                        title="Alteração agendada para o próximo ciclo"
                        :description="`Revisão ${subscription.current_contract.pending_revision.revision_number} · aplicação em ${date(subscription.current_contract.pending_revision.service_period_start ?? '')}.`"
                      />
                    </div>
                    <p v-if="subscription.current_contract.cancel_effective_on" class="text-sm text-muted">
                      Cancelamento efetivo em {{ subscription.current_contract.cancel_effective_on }}
                    </p>
                    <p v-if="subscription.trial" class="text-sm text-muted">
                      {{ officeErpTrialStatusLabel(subscription.trial.situation) }} · término {{ date(subscription.trial.ends_at) }}
                    </p>
                    <p v-if="subscription.balance" class="text-xs text-muted">
                      {{ subscription.balance.invoice_count }} faturas · em aberto {{ formatOfficeErpMoney(subscription.balance.outstanding) }}
                    </p>
                    <div v-if="subscription.trial" class="flex flex-wrap gap-2">
                      <UButton
                        v-if="subscription.trial"
                        color="neutral"
                        variant="outline"
                        :to="`/gestao-erp/assinaturas/${subscription.current_contract.id}`"
                      >
                        Ver opções do período de teste
                      </UButton>
                    </div>
                    <div v-if="subscription.current_contract.invoice_ids?.length" class="space-y-2 border-t border-default pt-3">
                      <h3 class="text-sm font-medium text-highlighted">
                        Faturas da assinatura
                      </h3>
                      <div class="flex flex-wrap gap-2">
                        <UButton
                          v-for="invoiceId in subscription.current_contract.invoice_ids"
                          :key="invoiceId"
                          color="neutral"
                          variant="outline"
                          size="sm"
                          :to="`/gestao-erp/faturas/${invoiceId}`"
                        >
                          Fatura {{ invoiceId }}
                        </UButton>
                      </div>
                    </div>
                  </div>
                </UPageCard>
                <div v-if="company.capabilities.can_assign && !subscription.current_contract" class="space-y-3 rounded-lg border border-default p-4">
                  <div>
                    <h3 class="font-medium text-highlighted">
                      Iniciar assinatura
                    </h3><p class="mt-1 text-sm text-muted">
                      A reserva de licença e o contrato são confirmados nesta etapa; a primeira fatura só é emitida na ativação.
                    </p>
                  </div>
                  <UButton
                    :disabled="command.pending.value"
                    icon="i-lucide-plus"
                    @click="assignmentOpen = true"
                  >
                    Escolher plano e termos
                  </UButton>
                </div>
              </div>
              <aside v-if="company.owner_setup_status === 'unclaimed' && subscription.current_contract" class="space-y-4 border-t border-default pt-5 lg:border-t-0 lg:border-l lg:pl-6 lg:pt-0">
                <div>
                  <h3 class="text-sm font-semibold text-highlighted">
                    Configurar acesso do responsável
                  </h3><p class="mt-1 text-sm text-muted">
                    O acesso do responsável é separado da assinatura e será confirmado pelo destinatário.
                  </p>
                </div>
                <UAlert
                  v-if="ownerError || command.recoveryKey.value"
                  color="error"
                  title="Não foi possível preparar o acesso"
                  :description="ownerError || 'Há uma operação comercial sem confirmação. Consulte o resultado antes de preparar outro acesso.'"
                >
                  <template #actions>
                    <UButton
                      v-if="command.recoveryKey.value"
                      color="neutral"
                      variant="outline"
                      :loading="saving"
                      @click="recoverCommercial"
                    >
                      Recuperar operação
                    </UButton>
                  </template>
                </UAlert>
                <UAlert
                  v-if="ownerInvite"
                  color="success"
                  title="Convite preparado"
                  :description="`Identificação ${ownerInvite.invitation_id} · expira em ${date(ownerInvite.expires_at)}. O convite não foi reenviado automaticamente.`"
                >
                  <template #actions>
                    <UButton
                      v-if="ownerInvite.url"
                      color="neutral"
                      variant="outline"
                      @click="copyOwnerSetupLink"
                    >
                      Copiar link de configuração
                    </UButton>
                  </template>
                </UAlert>
                <div class="grid gap-3">
                  <UFormField label="Nome do responsável" required>
                    <UInput v-model="ownerName" autocomplete="name" class="w-full" />
                  </UFormField><UFormField label="E-mail do responsável" required>
                    <UInput
                      v-model="ownerEmail"
                      type="email"
                      autocomplete="email"
                      class="w-full"
                    />
                  </UFormField>
                </div>
                <UButton :loading="ownerLoading" :disabled="ownerName.trim().length < 2 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ownerEmail.trim()) || command.pending.value" @click="inviteOwner">
                  Preparar acesso do responsável
                </UButton>
              </aside>
              <aside v-else-if="subscription.current_contract" class="space-y-4 border-t border-default pt-5 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6">
                <h3 class="text-sm font-semibold text-highlighted">
                  Acesso do responsável
                </h3>
                <dl class="space-y-3 text-sm">
                  <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted">
                      Situação
                    </dt>
                    <dd class="font-medium text-highlighted">
                      {{ company.owner_setup_status === 'claimed' ? 'Configurado' : 'Não configurado' }}
                    </dd>
                  </div>
                </dl>
                <p class="text-xs leading-5 text-muted">
                  Consulte os usuários e as opções de acesso desta empresa.
                </p>
                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  icon="i-lucide-users-round"
                  @click="setSection('members')"
                >
                  Gerenciar usuários
                </UButton>
              </aside>
            </div>
          </template>
          <UButton
            color="neutral"
            variant="link"
            icon="i-lucide-history"
            class="px-0"
            to="/gestao-erp/assinaturas/contratos"
          >
            Ver contratos e histórico
          </UButton>
        </section>
        <section v-else-if="activeSection === 'members'" class="space-y-4">
          <p v-if="!company.capabilities.can_manage_members" class="text-sm text-muted">
            A gestão de usuários fica disponível para empresas sob administração comercial atual.
          </p>
          <OfficeErpCompanyMembers v-else embedded />
        </section>
        <section v-else-if="activeSection === 'invoices'" class="space-y-4">
          <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 class="text-base font-semibold text-highlighted">
                Faturas
              </h2>
              <p class="mt-1 text-sm text-muted">
                Documentos de todos os contratos desta empresa, com os mesmos identificadores da carteira de faturas.
              </p>
            </div>
            <UFormField label="Situação">
              <USelect v-model="invoiceStatus" :items="[{ label: 'Todas', value: 'all' }, { label: 'Rascunho', value: 'draft' }, { label: 'Emitidas', value: 'issued' }, { label: 'Canceladas', value: 'canceled' }]" />
            </UFormField>
          </div>
          <UAlert
            v-if="invoiceError"
            color="error"
            title="Não foi possível carregar as faturas"
            :description="invoiceError"
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="outline"
                :loading="invoiceLoading"
                @click="loadCompanyInvoices"
              >
                Tentar novamente
              </UButton>
            </template>
          </UAlert>
          <USkeleton v-else-if="invoiceLoading && !companyInvoices.length" class="h-24 w-full" />
          <UAlert
            v-else-if="invoiceTotal === 0"
            color="neutral"
            title="Nenhuma fatura encontrada"
            description="Os documentos relacionados a esta empresa aparecerão aqui."
          />
          <div v-else class="divide-y divide-default rounded-lg border border-default">
            <article v-for="invoice in companyInvoices" :key="invoice.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="font-medium text-highlighted">
                  {{ invoice.number ?? `Fatura ${invoice.id}` }} · {{ formatOfficeErpMoney(invoice.original_total) }}
                </p>
                <p class="text-sm text-muted">
                  Contrato {{ invoice.contract_id }} · {{ invoice.document_status === 'issued' ? 'Emitida' : invoice.document_status === 'draft' ? 'Rascunho' : 'Cancelada' }} · vencimento {{ invoice.due_on }}
                </p>
                <p class="text-xs text-muted">
                  Período {{ invoice.period_start }} a {{ invoice.period_end }}
                </p>
              </div>
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                :to="`/gestao-erp/faturas/${invoice.id}`"
              >
                Abrir fatura
              </UButton>
            </article>
          </div>
          <OfficeErpPagination
            v-if="invoiceTotal > 0"
            v-model:page="invoicePage"
            v-model:per-page="invoicePerPage"
            :total="invoiceTotal"
            label="faturas"
            :loading="invoiceLoading"
          />
        </section>
        <section v-else class="space-y-4">
          <h2 class="text-base font-semibold text-highlighted">
            Histórico
          </h2>
          <p class="text-sm text-muted">
            Contratos e revisões preservam seus identificadores e snapshots originais.
          </p>
          <UAlert
            v-if="contractHistoryError"
            color="error"
            title="Não foi possível carregar o histórico"
            :description="contractHistoryError"
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="outline"
                :loading="contractHistoryLoading"
                @click="loadCompanyContracts"
              >
                Tentar novamente
              </UButton>
            </template>
          </UAlert>
          <USkeleton v-else-if="contractHistoryLoading && !companyContracts.length" class="h-24 w-full" />
          <UAlert
            v-else-if="!companyContracts.length"
            color="neutral"
            title="Nenhum contrato relacionado"
            description="Os contratos desta empresa aparecerão aqui sem alterar seus identificadores."
          />
          <div v-else class="divide-y divide-default rounded-lg border border-default">
            <div v-for="contract in companyContracts" :key="contract.id" class="flex flex-wrap items-center justify-between gap-3 p-3">
              <div>
                <p class="font-medium text-highlighted">
                  Contrato {{ contract.id }} · {{ contract.erp_plan?.name ?? 'Plano ERP' }}
                </p>
                <p class="text-sm text-muted">
                  {{ contract.agreement_status }}<span v-if="contract.current_revision"> · revisão {{ contract.current_revision.revision_number ?? 'atual' }} · {{ contract.current_revision.interval_months }} mês(es)</span>
                </p>
              </div>
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                :to="`/gestao-erp/assinaturas/${contract.id}`"
              >
                Abrir contrato
              </UButton>
            </div>
          </div>
          <OfficeErpPagination
            v-if="contractHistoryTotal > 0"
            v-model:page="contractHistoryPage"
            v-model:per-page="contractHistoryPerPage"
            :total="contractHistoryTotal"
            label="contratos"
            :loading="contractHistoryLoading"
          />
        </section>
      </template>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-default pt-5 text-xs text-muted">
      <UButton
        color="neutral"
        variant="link"
        icon="i-lucide-arrow-left"
        size="xs"
        to="/gestao-erp/assinaturas"
      >
        Voltar para assinaturas
      </UButton>
      <span>Empresa vinculada ao escritório selecionado</span>
    </div>
    <OfficeErpCompanyAssignmentModal
      v-model:open="assignmentOpen"
      :company-id="company?.id ?? null"
      :company-name="company?.name ?? ''"
      :can-assign="company?.capabilities.can_assign ?? false"
      :current-contract-id="company?.current_contract_id ?? null"
      @assigned="assignmentConfirmed"
    />
    <OfficeErpSubscriptionManagementModal
      v-model:open="managementOpen"
      :company-id="company?.id ?? null"
      :company-name="company?.name ?? ''"
      :plan-name="subscription?.subscription?.erp_plan?.name ?? 'Plano ERP'"
      :contract="subscription?.current_contract ?? null"
      :subscription-status="subscription?.subscription?.status ?? null"
      :trial="subscription?.trial ?? null"
      :balance="subscription?.balance ?? null"
      :capabilities="managementCapabilities"
      :pending="managementSubmitting"
      :error="managementError"
      :recovery-key="command.recoveryKey.value"
      @manage-modules="openModuleManagement"
      @command="runManagementCommand"
      @recover="recoverManagement"
    />
    <OfficeErpCompanyModulesModal
      v-model:open="modulesOpen"
      :company-id="company?.id ?? null"
      :company-name="company?.name ?? ''"
      :plan-name="subscription?.subscription?.erp_plan?.name ?? 'Plano ERP'"
      :contract-id="subscription?.current_contract?.id ?? 0"
      :current-revision="subscription?.current_contract?.current_revision ?? null"
      :pending-revision="subscription?.current_contract?.pending_revision ?? null"
      :available-module-keys="moduleKeysAvailable"
      :can-amend="managementCapabilities.can_amend_terms"
      :pending="managementSubmitting"
      :error="managementError"
      :recovery-key="command.recoveryKey.value"
      @submit="submitModuleTerms"
      @recover="recoverManagement"
    />
  </div>
  <NuxtPage v-else />
</template>
