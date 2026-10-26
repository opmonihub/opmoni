<script setup lang="ts">
import type {
  OfficeErpCommandResult,
  OfficeErpEnrollmentInput,
  OfficeErpEnrollmentOutcome,
  OfficeErpEnrollmentPlan,
  OfficeErpEnrollmentPlansResponse,
  OfficeErpInvitationOutcome,
  OfficeErpNewClientInput,
  OfficeErpOwnerSetupOutcome,
  OfficeErpPortfolioClient,
  OfficeErpPortfolioClientListResponse
} from '#shared/types/officeErpCommercial'
import { parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { companyTaxIdEntry, taxIdEditInvalidatesLookup } from '~/utils/taxId'
import { panelBodyClass } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

type SourceMode = 'new' | 'portfolio'
type RecoveryAction = 'enrollment' | 'owner_setup' | 'detect' | null
type OfficeErpClientOption = OfficeErpPortfolioClient & { label: string, description: string | undefined }
type OfficeErpPlanOption = OfficeErpEnrollmentPlan & { label: string }

const { api } = useAccessApi()
const clientsApi = useClients()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const command = useOfficeErpCommand()
const route = useRoute()

const source = ref<SourceMode>('new')
const newClient = reactive({
  tax_id: '',
  name: '',
  tax_regime: 'presumed_profit' as OfficeErpNewClientInput['tax_regime'],
  email: '',
  phone: ''
})
const lookupBusy = ref(false)
const lookupError = ref('')
const lookedUpTaxId = ref('')
let lookupRequest = 0

const clients = ref<OfficeErpPortfolioClient[]>([])
const selectedClient = ref<OfficeErpPortfolioClient | null>(null)
const clientQuery = ref(typeof route.query.q === 'string' ? route.query.q : '')
const clientSearch = refDebounced(clientQuery, 300)
const clientPage = ref(Math.max(1, Number(route.query.pagina) || 1))
const clientTotal = ref(0)
const clientLastPage = ref(1)
const clientsLoading = ref(false)
const clientsError = ref('')
let clientRequest = 0

const plans = ref<OfficeErpEnrollmentPlan[]>([])
const selectedPlan = ref<OfficeErpEnrollmentPlan | null>(null)
const plansLoading = ref(false)
const plansError = ref('')
const eligibility = ref<OfficeErpEnrollmentPlansResponse['eligibility'] | null>(null)
let plansRequest = 0

const price = ref('')
const intervalMonths = ref<1 | 3 | 6 | 12>(1)
const dueInDays = ref('7')
const startOn = ref(todayLocal())
const billingTimezone = ref('America/Sao_Paulo')
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const commandBusy = ref(false)
const enrollment = ref<OfficeErpEnrollmentOutcome | null>(null)
const recoveryAction = ref<RecoveryAction>(null)

const ownerName = ref('')
const ownerEmail = ref('')
const ownerSetup = ref<OfficeErpOwnerSetupOutcome | null>(null)
const legacyInvitation = ref<OfficeErpInvitationOutcome | null>(null)
const ownerSetupError = ref('')
const ownerSetupBusy = ref(false)
if (command.recoveryKey.value) recoveryAction.value = 'detect'
let authorityGeneration = 0

if (Number(route.query.cliente) > 0) source.value = 'portfolio'

const identityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null,
  context.capabilities.value?.capability_key ?? null
]))
const authorityStamp = computed(() => JSON.stringify([
  user.value?.id ?? null,
  sessionGeneration.value,
  currentAccount.value?.id ?? null,
  currentAccount.value?.status ?? null,
  currentAccount.value?.role ?? null
]))
let lastAuthorityStamp = authorityStamp.value
let lastCapabilityKey = context.capabilities.value?.capability_key ?? null
let mayApplyRoutePreselection = true
const recoveryKey = computed(() => command.recoveryKey.value)
const commandPending = computed(() => command.pending.value)
const canManage = computed(() => context.capabilities.value?.can_configure === true)
const canProvision = computed(() => canManage.value && eligibility.value?.can_provision === true
  && selectedPlan.value !== null && selectedPlan.value.module_keys.some(key => eligibility.value?.available_module_keys.includes(key)))
const clientOptions = computed<OfficeErpClientOption[]>(() => clients.value.map(client => ({
  ...client,
  label: client.trade_name || client.name,
  description: client.tax_id ?? undefined
})))
const sourceReady = computed(() => source.value === 'new'
  ? companyTaxIdEntry(newClient.tax_id) !== 'incomplete' && newClient.tax_regime.length > 0
  : selectedClient.value !== null)
const canSubmit = computed(() => sourceReady.value && canProvision.value && price.value.trim() !== ''
  && selectedPlan.value !== null && Number.isInteger(Number(dueInDays.value))
  && Number(dueInDays.value) >= 0 && Number(dueInDays.value) <= 65535 && startOn.value !== '' && !formError.value
  && !commandBusy.value && !commandPending.value && enrollment.value === null)

const planItems = computed<OfficeErpPlanOption[]>(() => plans.value.map(plan => ({ ...plan, label: plan.name })))
const selectedClientModel = computed<OfficeErpClientOption | undefined>({
  get: () => selectedClient.value
    ? clientOptions.value.find(client => client.id === selectedClient.value?.id)
    : undefined,
  set: (option) => { selectedClient.value = option ? clients.value.find(client => client.id === option.id) ?? null : null }
})
const selectedPlanModel = computed<OfficeErpPlanOption | undefined>({
  get: () => selectedPlan.value
    ? planItems.value.find(plan => plan.id === selectedPlan.value?.id)
    : undefined,
  set: (option) => { selectedPlan.value = option ? plans.value.find(plan => plan.id === option.id) ?? null : null }
})
const intervals = [
  { label: 'Mensal', value: 1 },
  { label: 'A cada 3 meses', value: 3 },
  { label: 'A cada 6 meses', value: 6 },
  { label: 'Anual', value: 12 }
]
const taxRegimes = [
  { label: 'MEI', value: 'mei' },
  { label: 'Simples Nacional', value: 'simple_national' },
  { label: 'Lucro presumido', value: 'presumed_profit' },
  { label: 'Lucro real', value: 'actual_profit' },
  { label: 'Outro', value: 'other' }
]
const eligibilityReasons: Record<string, string> = {
  subscription_inactive: 'A assinatura do escritório está inativa.',
  licence_capacity_reached: 'Não há licenças disponíveis para outra matrícula.',
  commercial_module_not_allowed: 'O plano do escritório não permite os módulos ERP necessários.',
  trial_registration_disabled: 'A abertura de novos testes está desativada para este escritório.'
}

function eligibilityGuidance(reasons: string[]) {
  const explanations = [...new Set(reasons.map(reason => eligibilityReasons[reason]).filter((reason): reason is string => !!reason))]
  const message = explanations.length ? explanations.join(' ') : 'A elegibilidade atual do escritório não permite novas matrículas.'
  const nextStep = reasons.includes('subscription_inactive')
    ? 'Próximo passo: peça à administradora do escritório para regularizar a assinatura.'
    : reasons.includes('licence_capacity_reached')
      ? 'Próximo passo: aumente a capacidade do escritório ou libere uma licença.'
      : reasons.includes('commercial_module_not_allowed')
        ? 'Próximo passo: revise os módulos ERP permitidos no plano do escritório.'
        : 'Próximo passo: peça à administradora para revisar a configuração comercial do escritório.'
  return `${message} ${nextStep}`
}

function todayLocal() {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function errorMessage(error: unknown, fallback: string) {
  return apiErrorMessage(error) ?? fallback
}

async function loadClients() {
  const request = ++clientRequest
  const identity = identityStamp.value
  clientsLoading.value = true
  clientsError.value = ''
  try {
    await context.refresh()
    if (request !== clientRequest || identity !== identityStamp.value) return
    if (!canManage.value) throw new Error('A conta selecionada não pode consultar a carteira do escritório.')
    const token = context.capture()
    const params: Record<string, string | number> = { page: clientPage.value, per_page: 25 }
    if (clientSearch.value.trim()) params.q = clientSearch.value.trim()
    const response = await api<OfficeErpPortfolioClientListResponse>('/erp-commercial/clients', { params })
    if (request !== clientRequest || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total))
      throw new Error('A resposta da carteira está incompleta.')
    clients.value = response.data
    clientTotal.value = response.meta.total
    clientLastPage.value = Math.max(1, response.meta.last_page)
    clientPage.value = response.meta.current_page
    const requestedId = Number(route.query.cliente)
    if (mayApplyRoutePreselection && !selectedClient.value && Number.isSafeInteger(requestedId) && requestedId > 0)
      selectedClient.value = clientOptions.value.find(client => client.id === requestedId) ?? null
  } catch (error) {
    if (request === clientRequest && identity === identityStamp.value)
      clientsError.value = errorMessage(error, 'Não foi possível consultar a carteira agora.')
  } finally {
    if (request === clientRequest && identity === identityStamp.value) clientsLoading.value = false
  }
}

async function loadPlans() {
  const request = ++plansRequest
  const identity = identityStamp.value
  plansLoading.value = true
  plansError.value = ''
  try {
    await context.refresh()
    if (request !== plansRequest || identity !== identityStamp.value) return
    if (!canManage.value) throw new Error('A conta selecionada não pode matricular clientes ERP.')
    const token = context.capture()
    const response = await api<OfficeErpEnrollmentPlansResponse>('/erp-commercial/enrollment-plans')
    if (request !== plansRequest || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.eligibility || typeof response.eligibility.can_provision !== 'boolean')
      throw new Error('A resposta dos planos ERP está incompleta.')
    plans.value = response.data
    eligibility.value = response.eligibility
    if (selectedPlan.value && !response.data.some(plan => plan.id === selectedPlan.value?.id)) selectedPlan.value = null
  } catch (error) {
    if (request === plansRequest && identity === identityStamp.value)
      plansError.value = errorMessage(error, 'Não foi possível consultar os planos técnicos agora.')
  } finally {
    if (request === plansRequest && identity === identityStamp.value) plansLoading.value = false
  }
}

async function lookupCompany() {
  if (source.value !== 'new' || companyTaxIdEntry(newClient.tax_id) !== 'lookup' || lookupBusy.value) return
  const request = ++lookupRequest
  const generation = authorityGeneration
  const identity = identityStamp.value
  const taxId = newClient.tax_id
  lookupBusy.value = true
  lookupError.value = ''
  try {
    const result = await clientsApi.lookupCnpj(taxId)
    if (request !== lookupRequest || identity !== identityStamp.value || taxIdEditInvalidatesLookup(taxId, newClient.tax_id)) return
    if (result.tax_id.replace(/[./-]/g, '') !== taxId.replace(/[./-]/g, '')) throw new Error('A consulta retornou outro CNPJ.')
    lookedUpTaxId.value = taxId.replace(/[./-]/g, '')
    newClient.name = result.name
    if (result.mei) newClient.tax_regime = 'mei'
    else if (result.simple_national) newClient.tax_regime = 'simple_national'
  } catch (error) {
    if (request === lookupRequest && identity === identityStamp.value)
      lookupError.value = errorMessage(error, 'Não foi possível consultar o CNPJ agora. Os dados digitados foram mantidos.')
  } finally {
    if (request === lookupRequest && generation === authorityGeneration) lookupBusy.value = false
  }
}

function newClientInput(): OfficeErpNewClientInput {
  const taxId = newClient.tax_id.replace(/[./-]/g, '').toUpperCase()
  const input: OfficeErpNewClientInput = {
    tax_id: taxId,
    tax_regime: newClient.tax_regime
  }
  if (newClient.name.trim()) input.name = newClient.name.trim()
  if (newClient.email.trim()) input.email = newClient.email.trim()
  if (newClient.phone.trim()) input.phone = newClient.phone.trim()
  return input
}

function enrollmentInput(): OfficeErpEnrollmentInput {
  if (!selectedPlan.value) throw new Error('Selecione um plano ERP.')
  let canonicalPrice: string
  try {
    canonicalPrice = parseOfficeErpMoney(price.value.trim())
  } catch {
    throw new Error('Informe um valor em reais com até duas casas decimais.')
  }
  const due = Number(dueInDays.value)
  if (!Number.isInteger(due) || due < 0 || due > 65535) throw new Error('O vencimento deve ficar entre 0 e 65.535 dias.')
  if (startOn.value > todayLocal()) throw new Error('A data de início não pode ser futura.')
  try {
    new Intl.DateTimeFormat('pt-BR', { timeZone: billingTimezone.value }).format(new Date())
  } catch {
    throw new Error('Informe um fuso horário IANA válido.')
  }
  const terms = {
    erp_plan_id: selectedPlan.value.id,
    price: canonicalPrice,
    currency: 'BRL' as const,
    interval_months: intervalMonths.value,
    due_in_days: due,
    start_on: startOn.value,
    billing_timezone: billingTimezone.value
  }
  return source.value === 'portfolio'
    ? { ...terms, client_id: selectedClient.value!.id }
    : { ...terms, new_client: newClientInput() }
}

function validEnrollment(value: unknown): OfficeErpEnrollmentOutcome | null {
  const payload = value && typeof value === 'object' && 'data' in value ? (value as { data: unknown }).data : value
  if (!payload || typeof payload !== 'object') return null
  const candidate = payload as Partial<OfficeErpEnrollmentOutcome>
  if (![candidate.client_id, candidate.company_id, candidate.subscription_id, candidate.contract_id, candidate.invoice_id].every(id => Number.isSafeInteger(id) && Number(id) > 0)
    || !['unclaimed', 'claimed'].includes(String(candidate.owner_setup_status))) return null
  return candidate as OfficeErpEnrollmentOutcome
}

function acceptEnrollment(value: unknown, recovered = false) {
  const outcome = validEnrollment(value)
  if (!outcome) {
    formError.value = recovered
      ? 'O resultado consultado não corresponde a uma matrícula. Nenhuma nova cobrança foi solicitada.'
      : 'A confirmação não trouxe uma matrícula válida.'
    return false
  }
  enrollment.value = outcome
  recoveryAction.value = null
  formError.value = ''
  fieldErrors.value = {}
  return true
}

function collectFailure(result: Extract<OfficeErpCommandResult<unknown>, { status: 'failed' }>) {
  formError.value = result.failure.message
  fieldErrors.value = result.failure.errors ?? {}
}

async function enroll() {
  if (!canSubmit.value) return
  const identity = identityStamp.value
  const generation = authorityGeneration
  const token = context.capture()
  let input: OfficeErpEnrollmentInput
  try {
    input = enrollmentInput()
  } catch (error) {
    formError.value = errorMessage(error, 'Revise os termos da matrícula.')
    return
  }
  commandBusy.value = true
  formError.value = ''
  fieldErrors.value = {}
  try {
    const result = await command.execute<OfficeErpEnrollmentOutcome>('customer.enroll', '/erp-commercial/enrollments', { ...input }) as OfficeErpCommandResult<OfficeErpEnrollmentOutcome>
    if (identity !== identityStamp.value || !context.isCurrent(token)) return
    if (result.status === 'confirmed') acceptEnrollment(result.data)
    else if (result.status === 'unknown') {
      recoveryAction.value = 'enrollment'
      formError.value = 'A resposta não confirmou a matrícula. Consulte o resultado antes de tentar novamente.'
    } else if (result.status === 'failed') collectFailure(result)
    else if (result.status === 'stale') formError.value = 'O contexto do escritório mudou. Atualize a página antes de continuar.'
  } finally {
    if (generation === authorityGeneration) commandBusy.value = false
  }
}

function validOwnerSetup(value: unknown): OfficeErpOwnerSetupOutcome | null {
  const payload = value && typeof value === 'object' && 'data' in value ? (value as { data: unknown }).data : value
  if (!payload || typeof payload !== 'object') return null
  const candidate = payload as Partial<OfficeErpOwnerSetupOutcome>
  if (![candidate.invitation_id, candidate.contract_id, candidate.company_id].every(id => Number.isSafeInteger(id) && Number(id) > 0)
    || typeof candidate.expires_at !== 'string' || !['unclaimed', 'claimed'].includes(String(candidate.owner_setup_status))) return null
  return candidate as OfficeErpOwnerSetupOutcome
}

function validLegacyInvitation(value: unknown): OfficeErpInvitationOutcome | null {
  const payload = value && typeof value === 'object' && 'data' in value ? (value as { data: unknown }).data : value
  if (!payload || typeof payload !== 'object') return null
  const candidate = payload as Partial<OfficeErpInvitationOutcome>
  if (!Number.isSafeInteger(candidate.invitation_id) || typeof candidate.expires_at !== 'string'
    || !['pending', 'accepted', 'revoked', 'expired'].includes(String(candidate.status))) return null
  return { invitation_id: candidate.invitation_id!, expires_at: candidate.expires_at, status: candidate.status as OfficeErpInvitationOutcome['status'] }
}

function safeOwnerSetupUrl(value: unknown) {
  if (typeof value !== 'string' || typeof window === 'undefined') return null
  try {
    const expectedOrigin = new URL(window.location.origin)
    const url = new URL(value)
    const localHttp = expectedOrigin.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(expectedOrigin.hostname)
    if (url.origin !== expectedOrigin.origin || (url.protocol !== 'https:' && !localHttp)
      || url.pathname !== '/erp/ativar' || url.search !== '' || !/^#token=[a-f\d]{64}$/i.test(url.hash)) return null
    return url.href
  } catch {
    return null
  }
}

async function setupOwner() {
  if (!enrollment.value || ownerName.value.trim().length < 2 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ownerEmail.value.trim()) || commandBusy.value || commandPending.value) return
  const identity = identityStamp.value
  const generation = authorityGeneration
  const token = context.capture()
  ownerSetup.value = null
  ownerSetupBusy.value = true
  ownerSetupError.value = ''
  try {
    const result = await command.execute<OfficeErpOwnerSetupOutcome>(
      'owner.setup.issue',
      `/erp-commercial/contracts/${enrollment.value.contract_id}/owner-setup`,
      { owner: { full_name: ownerName.value.trim(), email: ownerEmail.value.trim() } }
    ) as OfficeErpCommandResult<OfficeErpOwnerSetupOutcome>
    if (identity !== identityStamp.value || !context.isCurrent(token)) return
    if (result.status === 'confirmed') {
      const outcome = validOwnerSetup(result.data)
      if (!outcome) ownerSetupError.value = 'A resposta do preparo de acesso está incompleta.'
      else ownerSetup.value = { ...outcome, url: safeOwnerSetupUrl(outcome.url) ?? undefined }
    } else if (result.status === 'unknown') {
      recoveryAction.value = 'owner_setup'
      ownerSetupError.value = 'A resposta não confirmou o preparo do acesso. Consulte o resultado original.'
    } else if (result.status === 'failed') ownerSetupError.value = result.failure.message
    else if (result.status === 'stale') ownerSetupError.value = 'O contexto do escritório mudou. A operação não foi repetida.'
  } finally {
    if (generation === authorityGeneration) ownerSetupBusy.value = false
  }
}

async function recoverOperation() {
  const key = recoveryKey.value
  const action = recoveryAction.value
  if (!key || !action || commandBusy.value) return
  const identity = identityStamp.value
  const generation = authorityGeneration
  const token = context.capture()
  commandBusy.value = true
  try {
    if (action === 'enrollment') {
      const result = await command.recover<OfficeErpEnrollmentOutcome>(key) as OfficeErpCommandResult<OfficeErpEnrollmentOutcome>
      if (identity !== identityStamp.value || !context.isCurrent(token)) return
      if (result.status === 'confirmed') acceptEnrollment(result.data, true)
      else if (result.status === 'failed') collectFailure(result)
      else if (result.status === 'stale') formError.value = 'Não foi possível confirmar a matrícula neste contexto. A cobrança não foi repetida.'
    } else if (action === 'owner_setup') {
      const result = await command.recover<OfficeErpOwnerSetupOutcome>(key) as OfficeErpCommandResult<OfficeErpOwnerSetupOutcome>
      if (identity !== identityStamp.value || !context.isCurrent(token)) return
      if (result.status === 'confirmed') {
        const outcome = validOwnerSetup(result.data)
        if (outcome) ownerSetup.value = outcome
        else ownerSetupError.value = 'O resultado consultado não corresponde ao preparo de acesso.'
      } else if (result.status === 'failed') ownerSetupError.value = result.failure.message
      else if (result.status === 'stale') ownerSetupError.value = 'Não foi possível confirmar o preparo do acesso neste contexto.'
    } else {
      const result = await command.recover<unknown>(key)
      if (identity !== identityStamp.value || !context.isCurrent(token)) return
      if (result.status === 'confirmed') {
        const outcome = validEnrollment(result.data)
        const owner = validOwnerSetup(result.data)
        const invitation = validLegacyInvitation(result.data)
        if (outcome) acceptEnrollment(result.data, true)
        else if (owner) {
          ownerSetup.value = owner
          ownerSetupError.value = 'Operação de acesso confirmada. O link não é incluído na recuperação; emita uma nova configuração se necessário.'
          recoveryAction.value = null
        } else if (invitation) {
          legacyInvitation.value = invitation
          formError.value = 'O convite anterior foi confirmado sem gerar outro link. Use a gestão do contrato para continuar.'
          recoveryAction.value = null
        } else {
          formError.value = 'O resultado consultado pertence a outra operação comercial. Nenhuma solicitação foi repetida.'
          recoveryAction.value = null
        }
      } else if (result.status === 'failed') {
        formError.value = result.failure.message
        recoveryAction.value = null
      } else if (result.status === 'stale') {
        formError.value = 'Não foi possível consultar esta operação neste contexto.'
      }
    }
  } finally {
    if (generation === authorityGeneration) commandBusy.value = false
  }
}

function changeSource(next: SourceMode) {
  if (enrollment.value || commandBusy.value || commandPending.value) return
  source.value = next
  selectedClient.value = null
  formError.value = ''
  fieldErrors.value = {}
  if (next === 'portfolio') void loadClients()
}

watch([clientSearch], () => {
  clientPage.value = 1
})
watch([clientPage, clientSearch, identityStamp], () => {
  if (source.value === 'portfolio') void loadClients()
}, { immediate: true, flush: 'post' })
watch(identityStamp, () => {
  const currentAuthority = authorityStamp.value
  const currentCapabilityKey = context.capabilities.value?.capability_key ?? null
  const authorityChanged = currentAuthority !== lastAuthorityStamp
  const rightsChanged = lastCapabilityKey !== null && currentCapabilityKey !== lastCapabilityKey
  lastAuthorityStamp = currentAuthority
  lastCapabilityKey = currentCapabilityKey
  if (!authorityChanged && !rightsChanged) return
  mayApplyRoutePreselection = false
  authorityGeneration++
  clientRequest++
  plansRequest++
  lookupRequest++
  clients.value = []
  selectedClient.value = null
  selectedPlan.value = null
  plans.value = []
  eligibility.value = null
  enrollment.value = null
  ownerSetup.value = null
  recoveryAction.value = null
  legacyInvitation.value = null
  lookupBusy.value = false
  commandBusy.value = false
  ownerSetupBusy.value = false
  clientsLoading.value = false
  plansLoading.value = false
  source.value = 'new'
  newClient.tax_id = ''
  newClient.name = ''
  newClient.tax_regime = 'presumed_profit'
  newClient.email = ''
  newClient.phone = ''
  lookedUpTaxId.value = ''
  ownerName.value = ''
  ownerEmail.value = ''
  clientQuery.value = ''
  clientPage.value = 1
  price.value = ''
  intervalMonths.value = 1
  dueInDays.value = '7'
  startOn.value = todayLocal()
  billingTimezone.value = 'America/Sao_Paulo'
  clientsError.value = ''
  plansError.value = ''
  lookupError.value = ''
  formError.value = ''
  ownerSetupError.value = ''
  void loadPlans()
}, { flush: 'sync' })
watch(recoveryKey, (key) => {
  if (!key) recoveryAction.value = null
  else if (!recoveryAction.value) recoveryAction.value = 'detect'
}, { flush: 'post' })
watch(() => [
  source.value,
  selectedClient.value?.id,
  selectedPlan.value?.id,
  newClient.tax_id,
  newClient.name,
  newClient.tax_regime,
  newClient.email,
  newClient.phone,
  price.value,
  intervalMonths.value,
  dueInDays.value,
  startOn.value,
  billingTimezone.value
], () => {
  if (commandPending.value) return
  formError.value = ''
  fieldErrors.value = {}
}, { flush: 'sync' })
watch(() => newClient.tax_id, (next, previous) => {
  if (taxIdEditInvalidatesLookup(previous ?? '', next)) {
    lookupRequest++
    lookupBusy.value = false
    lookedUpTaxId.value = ''
    lookupError.value = ''
  }
}, { flush: 'sync' })
watch(() => context.capabilities.value?.capability_key, () => {
  void loadPlans()
}, { immediate: true })
</script>

<template>
  <div class="flex min-h-0 flex-col gap-4">
    <DataTablePanelList title="Cadastrar cliente ERP" description="Escolha um cliente, defina os termos e ative a assinatura em uma única operação.">
      <div :class="panelBodyClass">
        <UAlert
          v-if="!canManage"
          color="warning"
          variant="subtle"
          title="Ação indisponível"
          description="Selecione um escritório ativo no qual você seja administradora."
        />

        <template v-else-if="enrollment">
          <UAlert color="success" title="Assinatura cadastrada" description="O cliente foi vinculado e a primeira fatura foi registrada. A configuração de acesso do responsável é opcional." />
          <section class="mt-5 grid gap-4 rounded-lg border border-default bg-elevated p-4 sm:grid-cols-2">
            <div>
              <p class="text-sm text-muted">
                Cliente
              </p><p class="font-medium text-highlighted">
                {{ selectedClient?.trade_name || selectedClient?.name || newClient.name || `Cliente ${enrollment.client_id}` }}
              </p>
            </div>
            <div>
              <p class="text-sm text-muted">
                Plano ERP
              </p><p class="font-medium text-highlighted">
                {{ selectedPlan?.name ?? 'Plano técnico' }}
              </p>
            </div>
            <div>
              <p class="text-sm text-muted">
                Contrato
              </p><p class="font-medium text-highlighted">
                {{ enrollment.contract_id }}
              </p>
            </div>
            <div>
              <p class="text-sm text-muted">
                Acesso do responsável
              </p><p class="font-medium text-highlighted">
                {{ enrollment.owner_setup_status === 'claimed' ? 'Responsável já tem acesso' : 'Ainda não configurado' }}
              </p>
            </div>
          </section>
          <section v-if="enrollment.owner_setup_status === 'unclaimed'" class="mt-5 space-y-4 border-t border-default pt-5">
            <div>
              <h2 class="font-semibold text-highlighted">
                Preparar acesso do responsável
              </h2><p class="mt-1 text-sm text-muted">
                Opcional. O responsável define a própria senha ao abrir o link; a matrícula e a fatura já estão concluídas.
              </p>
            </div>
            <template v-if="!ownerSetup">
              <div class="grid gap-4 md:grid-cols-2">
                <UFormField label="Nome do responsável" name="owner.full_name" required>
                  <UInput v-model="ownerName" class="w-full" />
                </UFormField>
                <UFormField label="E-mail do responsável" name="owner.email" required>
                  <UInput v-model="ownerEmail" type="email" class="w-full" />
                </UFormField>
              </div>
              <UAlert
                v-if="ownerSetupError"
                color="error"
                variant="subtle"
                :description="ownerSetupError"
              />
              <div class="flex flex-wrap gap-3">
                <UButton
                  color="primary"
                  :disabled="ownerName.trim().length < 2 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ownerEmail.trim()) || commandBusy || commandPending"
                  :loading="ownerSetupBusy"
                  @click="setupOwner"
                >
                  Preparar acesso do responsável
                </UButton>
                <UButton
                  v-if="recoveryKey && recoveryAction === 'owner_setup'"
                  color="neutral"
                  variant="outline"
                  :disabled="commandBusy"
                  @click="recoverOperation"
                >
                  Verificar resultado
                </UButton>
              </div>
            </template>
            <UAlert
              v-else
              :color="ownerSetup.url ? 'success' : 'warning'"
              title="Acesso preparado"
              :description="ownerSetup.url
                ? `Link válido até ${ownerSetup.expires_at}. O responsável ainda precisa concluir a ativação.`
                : `O resultado foi confirmado sem recuperar o link único. Se necessário, emita outro link; isso substituirá o anterior.`"
            >
              <template v-if="ownerSetup.url" #description>
                <a
                  class="break-all font-medium text-primary underline"
                  :href="ownerSetup.url"
                  target="_blank"
                  rel="noopener"
                >Abrir link de acesso</a>
              </template>
            </UAlert>
            <UButton
              v-if="ownerSetup && !ownerSetup.url"
              color="neutral"
              variant="outline"
              :disabled="commandBusy || commandPending"
              :loading="ownerSetupBusy"
              @click="setupOwner"
            >
              Emitir novo link de acesso
            </UButton>
          </section>
        </template>

        <template v-else>
          <UAlert
            v-if="plansError"
            class="mb-5"
            color="error"
            variant="subtle"
            title="Planos técnicos indisponíveis"
            :description="plansError"
          >
            <template #description>
              <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ plansError }}</span>
                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  @click="loadPlans"
                >
                  Tentar novamente
                </UButton>
              </div>
            </template>
          </UAlert>
          <UAlert
            v-if="eligibility && !eligibility.can_provision"
            class="mb-5"
            color="warning"
            variant="subtle"
            title="Matrícula indisponível"
            :description="eligibilityGuidance(eligibility.reasons)"
          />
          <UAlert
            v-if="formError"
            class="mb-5"
            color="error"
            variant="subtle"
            title="Não foi possível concluir"
            :description="formError"
          />
          <UAlert
            v-if="recoveryKey && recoveryAction === 'enrollment'"
            class="mb-5"
            color="warning"
            variant="subtle"
            title="Resultado pendente"
            description="Consulte a operação original. A matrícula não será enviada novamente."
          />
          <UAlert
            v-if="legacyInvitation"
            class="mb-5"
            color="info"
            variant="subtle"
            title="Convite legado confirmado"
            :description="`Convite ${legacyInvitation.invitation_id}: ${legacyInvitation.status}. A consulta não gerou um novo link.`"
          />

          <div class="mb-6 flex flex-wrap gap-2" aria-label="Origem do cliente">
            <UButton
              :color="source === 'new' ? 'primary' : 'neutral'"
              :variant="source === 'new' ? 'soft' : 'outline'"
              :disabled="commandBusy || commandPending"
              @click="changeSource('new')"
            >
              Cadastrar novo cliente
            </UButton>
            <UButton
              :color="source === 'portfolio' ? 'primary' : 'neutral'"
              :variant="source === 'portfolio' ? 'soft' : 'outline'"
              :disabled="commandBusy || commandPending"
              @click="changeSource('portfolio')"
            >
              Selecionar da carteira
            </UButton>
          </div>

          <section v-if="source === 'new'" class="grid gap-4 md:grid-cols-2">
            <div class="space-y-2">
              <UFormField
                label="CNPJ"
                name="new_client.tax_id"
                required
                help="Consulte a fonte pública para conferir a razão social; a falha de consulta mantém os dados digitados."
              >
                <UInput
                  v-model="newClient.tax_id"
                  class="w-full"
                  inputmode="text"
                  autocomplete="off"
                />
              </UFormField>
              <UButton
                v-if="companyTaxIdEntry(newClient.tax_id) === 'lookup'"
                color="neutral"
                variant="outline"
                size="sm"
                :disabled="lookupBusy"
                :loading="lookupBusy"
                @click="lookupCompany"
              >
                Consultar CNPJ
              </UButton>
              <UAlert
                v-if="lookupError"
                color="error"
                variant="subtle"
                title="Consulta de CNPJ não concluída"
                :description="lookupError"
              />
            </div>
            <UFormField label="Razão social" name="new_client.name" :required="companyTaxIdEntry(newClient.tax_id) !== 'lookup' || !!lookupError">
              <UInput v-model="newClient.name" class="w-full" />
            </UFormField>
            <UFormField label="Regime tributário" name="new_client.tax_regime" required>
              <USelectMenu
                v-model="newClient.tax_regime"
                :items="taxRegimes"
                value-key="value"
                label-key="label"
                class="w-full"
              />
            </UFormField>
            <UFormField label="E-mail do cliente" name="new_client.email" hint="Opcional">
              <UInput v-model="newClient.email" type="email" class="w-full" />
            </UFormField>
            <UFormField label="Telefone" name="new_client.phone" hint="Opcional">
              <UInput v-model="newClient.phone" class="w-full" />
            </UFormField>
          </section>

          <section v-else class="space-y-4">
            <UFormField label="Buscar cliente" name="client-search">
              <UInput
                v-model="clientQuery"
                icon="i-lucide-search"
                placeholder="Nome ou CNPJ"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Cliente da carteira" name="client_id" required>
              <USelectMenu
                v-model="selectedClientModel"
                :items="clientOptions"
                :loading="clientsLoading"
                label-key="label"
                description-key="description"
                :search-input="{ placeholder: 'Buscar cliente pelo nome ou CNPJ' }"
                placeholder="Selecione um cliente"
                class="w-full"
              />
            </UFormField>
            <p v-if="selectedClient" class="text-sm text-muted">
              {{ selectedClient.trade_name || selectedClient.name }}<span v-if="selectedClient.tax_id"> · {{ selectedClient.tax_id }}</span>
            </p>
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
                    color="neutral"
                    variant="outline"
                    size="sm"
                    @click="loadClients"
                  >
                    Tentar novamente
                  </UButton>
                </div>
              </template>
            </UAlert>
            <div v-if="clientTotal > 25" class="flex items-center justify-between text-sm text-muted">
              <span>{{ clientTotal }} clientes · página {{ clientPage }} de {{ clientLastPage }}</span>
              <div class="flex gap-2">
                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  :disabled="clientPage <= 1 || clientsLoading"
                  @click="clientPage--"
                >
                  Anterior
                </UButton><UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  :disabled="clientPage >= clientLastPage || clientsLoading"
                  @click="clientPage++"
                >
                  Próxima
                </UButton>
              </div>
            </div>
          </section>

          <section class="mt-7 space-y-4 border-t border-default pt-5">
            <div>
              <h2 class="font-semibold text-highlighted">
                Plano e termos da assinatura
              </h2><p class="mt-1 text-sm text-muted">
                Os módulos disponíveis são definidos pelo plano técnico e pela capacidade do escritório.
              </p>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
              <UFormField label="Plano ERP" name="erp_plan_id" required>
                <USelectMenu
                  v-model="selectedPlanModel"
                  :items="planItems"
                  :loading="plansLoading"
                  label-key="label"
                  placeholder="Selecione um plano técnico"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Valor por ciclo (R$)"
                name="price"
                required
                help="O valor será enviado sem arredondamento, com duas casas decimais."
              >
                <UInput
                  v-model="price"
                  inputmode="decimal"
                  placeholder="0,00"
                  class="w-full"
                />
              </UFormField>
              <UFormField label="Ciclo de cobrança" name="interval_months" required>
                <USelectMenu
                  v-model="intervalMonths"
                  :items="intervals"
                  value-key="value"
                  label-key="label"
                  class="w-full"
                />
              </UFormField>
              <UFormField label="Dias até o vencimento" name="due_in_days" required>
                <UInput
                  v-model="dueInDays"
                  type="number"
                  min="0"
                  max="65535"
                  inputmode="numeric"
                  class="w-full"
                />
              </UFormField>
              <UFormField label="Início da assinatura" name="start_on" required>
                <UInput
                  v-model="startOn"
                  type="date"
                  :max="todayLocal()"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Fuso horário da cobrança"
                name="billing_timezone"
                required
                help="Use um identificador IANA, como America/Sao_Paulo."
              >
                <UInput v-model="billingTimezone" class="w-full" />
              </UFormField>
            </div>
          </section>

          <UAlert
            v-if="fieldErrors && Object.keys(fieldErrors).length"
            class="mt-5"
            color="error"
            variant="subtle"
            title="Revise os campos"
            :description="Object.values(fieldErrors).flat().join(' ')"
          />
          <div class="mt-6 flex flex-wrap gap-3">
            <UButton
              color="primary"
              :disabled="!canSubmit"
              :loading="commandBusy"
              @click="enroll"
            >
              Cadastrar cliente ERP
            </UButton>
            <UButton
              v-if="recoveryKey && recoveryAction"
              color="neutral"
              variant="outline"
              :disabled="commandBusy"
              @click="recoverOperation"
            >
              Verificar resultado
            </UButton>
            <UButton to="/gestao-erp/clientes" color="neutral" variant="ghost">
              Voltar para clientes
            </UButton>
          </div>
        </template>
      </div>
    </DataTablePanelList>
  </div>
</template>
