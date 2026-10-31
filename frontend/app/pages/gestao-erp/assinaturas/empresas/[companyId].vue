<script setup lang="ts">
import { formatTaxId } from '~/utils/taxId'
import { focusFormError } from '~/utils/formPresentation'
import type { ContextToken, OfficeErpCommandResult, OfficeErpCompany, OfficeErpCompanyProfile } from '#shared/types/officeErpCommercial'
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
const profile = computed(() => company.value?.profile ?? null)
const inspectionOpen = ref(false)
const companyReadContext = ref<ContextToken | null>(null)
interface CompanyRevision {
  id?: number
  revision_number?: number
  price: string
  currency: 'BRL'
  interval_months: number
  due_in_days: number
  module_keys: string[]
  service_period_start: string | null
  service_period_end: string | null
}
interface CompanySubscriptionDetail {
  company: { id: number, name: string }
  subscription: { id: number, status: string, erp_plan_id: number | null, erp_plan: { id: number, name: string } | null } | null
  current_contract: { id: number, agreement_status: string, current_revision: CompanyRevision | null, pending_revision: CompanyRevision | null, billing_timezone: string, service_anchor_on: string | null, next_billing_on: string | null, cancel_effective_on: string | null, manual_hold: boolean, invoice_ids?: number[], balance?: { outstanding: string, invoice_count: number } } | null
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
const managementFieldErrors = ref<Record<string, string[]>>({})
const ownerFieldErrors = ref<Record<string, string[]>>({})
watch(ownerFieldErrors, () => {
  void focusFormError(undefined, 'office-erp-owner-setup')
})
const subscriptionNotice = ref('')
const ownerName = ref('')
const ownerEmail = ref('')
const ownerLoading = ref(false)
const ownerError = ref('')
const ownerInvite = ref<{ invitation_id: number, status: string, expires_at: string, url?: string } | null>(null)
const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const notice = ref('')
const confirmedEmpty = ref(false)
let generation = 0
let lifecycleGeneration = 0
let mounted = false
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null, companyId.value], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const sections = ['company', 'subscription', 'members', 'invoices', 'history'] as const
type Section = typeof sections[number]
const activeSection = computed<Section>(() => route.query.secao === 'profile' ? 'company' : sections.find(section => section === route.query.secao) ?? 'subscription')
function sectionLocation(section: Section) {
  const query = { ...route.query }
  if (section === 'subscription') delete query.secao
  else query.secao = section
  return { path: `/gestao-erp/assinaturas/empresas/${companyId.value}`, query }
}
const sectionItems = computed(() => [
  { id: 'company', label: 'Empresa' }, { id: 'subscription', label: 'Assinatura' },
  { id: 'members', label: 'Usuários e acessos' }, { id: 'invoices', label: 'Faturas' }, { id: 'history', label: 'Histórico' }
].map(item => ({ ...item, value: item.id, active: activeSection.value === item.id, to: sectionLocation(item.id as Section) })))
const moduleKeysAvailable = computed(() => context.capabilities.value?.allowance?.module_keys ?? [])
const managementCapabilities = computed(() => ({
  can_manage_lifecycle: subscription.value?.action_capabilities.can_manage_lifecycle === true,
  can_amend_terms: subscription.value?.action_capabilities.can_amend_terms === true,
  can_cancel: subscription.value?.action_capabilities.can_cancel === true,
  can_suspend: subscription.value?.action_capabilities.can_suspend === true,
  can_resume: subscription.value?.action_capabilities.can_resume === true
}))
const taxRegimeLabels: Record<string, string> = { mei: 'MEI', simple_national: 'Simples Nacional', presumed_profit: 'Lucro presumido', actual_profit: 'Lucro real', other: 'Outro' }
const companyFactSections = computed(() => [
  { title: 'Dados cadastrais', facts: [
    { label: 'Razão social', value: profile.value?.name }, { label: 'Nome fantasia', value: profile.value?.trade_name },
    { label: 'CNPJ', value: company.value ? formatTaxId(company.value.cnpj) : null },
    { label: 'Cadastro no ERP', value: company.value ? date(company.value.registered_at) : null },
    { label: 'Regime tributário', value: profile.value?.tax_regime ? taxRegimeLabels[profile.value.tax_regime] : null },
    { label: 'Inscrição estadual', value: profile.value?.ie }
  ] },
  { title: 'Contato comercial', facts: [
    { label: 'Pessoa de contato', value: profile.value?.contact_person }, { label: 'E-mail', value: profile.value?.email },
    { label: 'Telefone', value: profile.value?.phone }, { label: 'WhatsApp', value: profile.value?.whatsapp }
  ] },
  { title: 'Endereço cadastral', facts: [
    { label: 'Logradouro', value: [profile.value?.street_type, profile.value?.street].filter(Boolean).join(' ') },
    { label: 'Número', value: profile.value?.address_number }, { label: 'Complemento', value: profile.value?.address_complement },
    { label: 'Bairro', value: profile.value?.district }, { label: 'CEP', value: profile.value?.postal_code },
    { label: 'Município', value: profile.value?.city }, { label: 'UF', value: profile.value?.state }
  ] }
])
const subscriptionStatusLabel = computed(() => ({ active: 'Ativa', pending: 'Pendente', suspended: 'Suspensa', past_due: 'Em atraso', canceled: 'Cancelada', trialing: 'Em teste', trial_expired: 'Teste encerrado' } as Record<string, string>)[subscription.value?.subscription?.status ?? ''] ?? 'Situação indisponível')
const subscriptionFacts = computed(() => {
  const contract = subscription.value?.current_contract
  if (!contract) return []
  const revision = contract.current_revision
  return [
    { label: 'Plano', value: subscription.value?.subscription?.erp_plan?.name ?? 'Plano não informado' },
    { label: 'Situação do contrato', value: officeErpContractStatusLabel(contract.agreement_status) },
    ...(subscription.value?.subscription ? [{ label: 'Situação da assinatura', value: subscriptionStatusLabel.value }] : []),
    ...(revision
      ? [
          { label: 'Valor por período', value: formatOfficeErpMoney(revision.price) },
          { label: 'Periodicidade', value: intervalLabel(revision.interval_months) },
          { label: 'Prazo de pagamento', value: `${revision.due_in_days} dias` },
          ...(revision.currency ? [{ label: 'Moeda da cobrança', value: revision.currency }] : []),
          ...(revision.service_period_start ? [{ label: 'Início do período', value: date(revision.service_period_start) }] : []),
          ...(revision.service_period_end ? [{ label: 'Fim do período', value: date(revision.service_period_end) }] : [])
        ]
      : []),
    ...(subscription.value?.balance ? [{ label: 'Saldo em aberto', value: formatOfficeErpMoney(subscription.value.balance.outstanding) }] : []),
    ...(contract.billing_timezone ? [{ label: 'Fuso da cobrança', value: contract.billing_timezone }] : []),
    ...(contract.service_anchor_on ? [{ label: 'Início do serviço', value: date(contract.service_anchor_on) }] : []),
    ...(contract.next_billing_on ? [{ label: 'Próxima cobrança', value: date(contract.next_billing_on) }] : []),
    ...(contract.cancel_effective_on ? [{ label: 'Cancelamento efetivo', value: date(contract.cancel_effective_on) }] : [])
  ]
})
function profileUpdated(updated?: OfficeErpCompany & { profile: OfficeErpCompanyProfile, profile_version: string }) {
  if (!mounted || !company.value || updated?.id !== company.value.id || String(updated.id) !== companyId.value || !companyReadContext.value || !context.isCurrent(companyReadContext.value)) return
  company.value = updated
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
  managementFieldErrors.value = {}
  ownerFieldErrors.value = {}
  subscriptionNotice.value = ''
  ownerInvite.value = null
  ownerName.value = ''
  ownerEmail.value = ''
  ownerError.value = ''
  ownerLoading.value = false
  saving.value = false
  inspectionOpen.value = false
  companyReadContext.value = null
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
  void router.replace(sectionLocation(section))
}
async function assignmentConfirmed(result: { company_id: number, contract_id: number }) {
  const targetCompanyId = company.value?.id
  if (!mounted || !targetCompanyId || result.company_id !== targetCompanyId || companyId.value !== String(targetCompanyId)) return
  setSection('subscription')
  await loadCompany()
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
    if (response?.data?.id !== Number(companyId.value) || !response.data.profile || !/^[a-f\d]{64}$/i.test(response.data.profile_version)) throw new Error('Os dados de perfil da empresa estão incompletos.')
    company.value = response.data
    companyReadContext.value = token
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
  managementFieldErrors.value = {}
  ownerFieldErrors.value = {}
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
      managementFieldErrors.value = result.failure.errors ?? {}
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
      managementFieldErrors.value = result.failure.errors ?? {}
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
  if (!contractId || !company.value || subscription.value?.company.id !== company.value.id || ownerLoading.value || command.pending.value) return
  ownerFieldErrors.value = {}
  if (ownerName.value.trim().length < 2) ownerFieldErrors.value['owner.full_name'] = ['Informe o nome do responsável.']
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ownerEmail.value.trim())) ownerFieldErrors.value['owner.email'] = ['Informe um e-mail válido.']
  if (Object.keys(ownerFieldErrors.value).length) {
    void focusFormError(undefined, 'office-erp-owner-setup')
    return
  }
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
    else if (result.status === 'failed') {
      ownerFieldErrors.value = result.failure.errors ?? {}
      ownerError.value = result.failure.message
    } else ownerError.value = 'O contexto do escritório mudou. A operação não foi repetida.'
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
    <UButton
      to="/gestao-erp/assinaturas"
      color="neutral"
      variant="ghost"
      icon="i-lucide-arrow-left"
      size="sm"
      class="-ms-2.5 w-fit"
    >
      Voltar para assinaturas
    </UButton>
    <UPageCard
      :title="company?.name ?? 'Empresa'"
      :description="company ? [formatTaxId(company.cnpj), profile?.trade_name && profile.trade_name !== company.name ? profile.trade_name : null].filter(Boolean).join(' · ') : 'Consulta do cadastro e da situação comercial.'"
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
      <template #title>
        <h1 class="text-base font-semibold wrap-anywhere text-highlighted">
          {{ company?.name ?? 'Empresa' }}
        </h1>
      </template>
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
        <UDashboardToolbar class="overflow-visible px-0 sm:px-0" :ui="{ root: 'min-w-0 border-b border-default pb-2', left: 'min-w-0 flex-1' }">
          <USelect
            :model-value="activeSection"
            :items="sectionItems"
            aria-label="Seção da empresa"
            class="w-full xl:hidden"
            @update:model-value="value => setSection(value as Section)"
          />
          <UNavigationMenu
            :items="sectionItems"
            highlight
            aria-label="Seções da empresa"
            class="hidden min-w-0 flex-1 xl:flex"
          />
        </UDashboardToolbar>

        <section v-if="activeSection === 'company'" aria-labelledby="company-overview-title" class="space-y-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="company-overview-title" class="text-base font-semibold text-highlighted">
              Empresa e cadastro
            </h2>
            <UButton
              color="neutral"
              variant="outline"
              :icon="company.capabilities.can_edit_profile ? 'i-lucide-pencil' : 'i-lucide-file-search'"
              @click="inspectionOpen = true"
            >
              {{ company.capabilities.can_edit_profile ? 'Editar cadastro' : 'Ver cadastro' }}
            </UButton>
          </div>
          <div class="space-y-4">
            <UCard
              v-for="group in companyFactSections"
              :key="group.title"
              :aria-label="group.title"
              variant="subtle"
              :ui="{ body: 'space-y-3 p-4 sm:p-5' }"
            >
              <h3 class="text-sm font-semibold text-highlighted">
                {{ group.title }}
              </h3>
              <p v-if="group.title === 'Contato comercial'" class="text-xs text-muted">
                Contato do cadastro do cliente no escritório; não identifica o proprietário da empresa.
              </p>
              <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="fact in group.facts" :key="fact.label" class="min-w-0">
                  <dt class="text-xs text-muted">
                    {{ fact.label }}
                  </dt>
                  <dd class="mt-1 wrap-anywhere text-highlighted">
                    {{ fact.value || 'Não informado' }}
                  </dd>
                </div>
              </dl>
            </UCard>
          </div>
          <UCard aria-label="Assinatura e cobrança" variant="subtle">
            <template #header>
              <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-highlighted">
                  Assinatura e cobrança
                </h3>
                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  :to="sectionLocation('subscription')"
                >
                  Ver assinatura
                </UButton>
              </div>
            </template>
            <UAlert
              v-if="subscriptionError"
              color="error"
              title="Não foi possível carregar a assinatura"
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
            <div v-else-if="subscription?.current_contract" class="space-y-4">
              <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="fact in subscriptionFacts" :key="fact.label" class="min-w-0">
                  <dt class="text-xs text-muted">
                    {{ fact.label }}
                  </dt>
                  <dd class="mt-1 wrap-anywhere text-highlighted">
                    {{ fact.value }}
                  </dd>
                </div>
              </dl>
              <div v-if="subscription.current_contract.current_revision" class="space-y-2 border-t border-default pt-4">
                <h4 class="text-xs font-medium text-muted">
                  Módulos do contrato
                </h4>
                <div class="flex flex-wrap gap-2">
                  <UBadge
                    v-for="label in moduleLabels(subscription.current_contract.current_revision.module_keys)"
                    :key="label"
                    color="neutral"
                    variant="subtle"
                  >
                    {{ label }}
                  </UBadge>
                  <span v-if="!subscription.current_contract.current_revision.module_keys.length" class="text-sm text-muted">Nenhum módulo habilitado.</span>
                </div>
              </div>
              <UAlert
                v-if="subscription.current_contract.pending_revision"
                color="warning"
                title="Alteração agendada"
                :description="`Revisão ${subscription.current_contract.pending_revision.revision_number ?? '—'} · aplicação em ${date(subscription.current_contract.pending_revision.service_period_start ?? '')}. Os termos vigentes permanecem até a aplicação.`"
              />
              <p v-if="subscription.trial" class="text-sm text-muted">
                {{ officeErpTrialStatusLabel(subscription.trial.situation) }} · término {{ date(subscription.trial.ends_at) }}
              </p>
            </div>
            <UAlert
              v-else-if="subscription"
              color="neutral"
              :title="company.capabilities.relationship === 'historical' ? 'Histórico comercial' : 'Sem assinatura'"
              :description="company.capabilities.relationship === 'historical' ? 'Não há assinatura atual deste escritório para exibir. Consulte os contratos preservados no histórico.' : 'O cadastro da empresa não ocupa licença. A assinatura depende da confirmação de plano e termos.'"
            />
          </UCard>
          <UCard aria-label="Usuários e acessos" variant="subtle" :ui="{ body: 'flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5' }">
            <div>
              <h3 class="text-sm font-semibold text-highlighted">
                Usuários e acessos
              </h3>
              <p class="mt-1 text-xs text-muted">
                {{ company.capabilities.can_manage_members ? 'Consulte as pessoas autorizadas e administre seus acessos nesta empresa.' : 'A gestão de usuários não está disponível para o vínculo atual com este escritório.' }}
              </p>
            </div>
            <UButton
              v-if="company.capabilities.can_manage_members"
              color="neutral"
              variant="outline"
              icon="i-lucide-users-round"
              :to="sectionLocation('members')"
            >
              Gerenciar usuários
            </UButton>
          </UCard>
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
                <UForm
                  id="office-erp-owner-setup"
                  :state="{ owner: { full_name: ownerName, email: ownerEmail } }"
                  :disabled="ownerLoading || command.pending.value"
                  class="space-y-3"
                  @submit="inviteOwner"
                  @error="event => focusFormError(event, 'office-erp-owner-setup')"
                >
                  <div class="grid gap-3">
                    <UFormField
                      name="owner.full_name"
                      :error="ownerFieldErrors['owner.full_name']?.[0]"
                      label="Nome do responsável"
                      required
                    >
                      <UInput v-model="ownerName" autocomplete="name" class="w-full" />
                    </UFormField><UFormField
                      name="owner.email"
                      :error="ownerFieldErrors['owner.email']?.[0]"
                      label="E-mail do responsável"
                      required
                    >
                      <UInput
                        v-model="ownerEmail"
                        type="email"
                        autocomplete="email"
                        class="w-full"
                      />
                    </UFormField>
                  </div>
                  <UButton type="submit" :loading="ownerLoading" :disabled="ownerLoading || command.pending.value">
                    Preparar acesso do responsável
                  </UButton>
                </UForm>
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
            :to="sectionLocation('history')"
          >
            Ver histórico
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
    <OfficeErpCompanyInspectionModal
      v-if="company && companyReadContext"
      v-model:open="inspectionOpen"
      :company-id="company.id"
      :initial-company="company"
      :initial-context="companyReadContext"
      @updated="profileUpdated"
    />
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
      :field-errors="managementFieldErrors"
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
      :field-errors="managementFieldErrors"
      :recovery-key="command.recoveryKey.value"
      @submit="submitModuleTerms"
      @recover="recoverManagement"
    />
  </div>
  <NuxtPage v-else />
</template>
