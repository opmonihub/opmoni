<script setup lang="ts">
import * as z from 'zod'
import type { FormErrorEvent, FormSubmitEvent } from '@nuxt/ui'
import type { Client, ClientWritePayload, CnpjPreview, DeadlineStatus } from '~/types/client'
import type { ClientMonitoringModules } from '~/types/serpro'
import { companyTaxIdEntry, formatTaxId, taxIdEditInvalidatesLookup } from '~/utils/taxId'
import { deadlinePresentation } from '~/utils/portfolioLabels'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '~/composables/useApiError'
import {
  initialMonitoringModuleSelection,
  monitoringModuleGroups,
  monitoringModulesPayload
} from '~/utils/monitoringModules'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'saved': [client: Client]
}>()

const isOpen = computed({
  get: () => props.open,
  set: (value) => {
    if (!value) emitSaved()
    emit('update:open', value)
  }
})

const { lookupCnpj, create, monitoringModules, confirmMonitoringModules } = useClients()
const { create: createDispatchContact } = useDispatchContacts()
const { canManageClients } = useAuth()
const toast = useToast()
const form = useTemplateRef('form')

const companySchema = z.object({
  person_type: z.literal('company'),
  tax_id: z.string().min(14, 'Informe um CNPJ válido'),
  name: z.string().max(255, 'Nome muito longo')
    .refine(value => preview.value !== null || value.trim().length >= 2, { message: 'Informe a razão social' }),
  status: z.enum(['active', 'inactive']),
  tax_regime: z.enum(['mei', 'simple_national', 'presumed_profit', 'actual_profit', 'other'])
})

const individualSchema = z.object({
  person_type: z.literal('individual'),
  tax_id: z.string().min(11, 'Informe um CPF válido'),
  name: z.string().min(2, 'Informe o nome completo').max(255, 'Nome muito longo'),
  status: z.enum(['active', 'inactive']),
  tax_regime: z.literal('not_applicable'),
  street_type: z.string().max(40).optional(),
  street: z.string().max(255).optional(),
  address_number: z.string().max(30).optional(),
  address_complement: z.string().max(255).optional(),
  district: z.string().max(255).optional(),
  postal_code: z.string().max(9).optional(),
  city: z.string().max(255).optional(),
  state: z.string().length(2, 'UF deve ter 2 letras').or(z.literal('')).optional()
})

const schema = z.discriminatedUnion('person_type', [companySchema, individualSchema])
type Schema = z.output<typeof schema>

interface ClientFormState {
  person_type: 'company' | 'individual'
  tax_id: string
  name: string
  status: 'active' | 'inactive'
  tax_regime: string
  street_type: string
  street: string
  address_number: string
  address_complement: string
  district: string
  postal_code: string
  city: string
  state: string
}

const state = reactive<ClientFormState>({
  person_type: 'company',
  tax_id: '',
  name: '',
  status: 'active',
  tax_regime: 'presumed_profit',
  street_type: '',
  street: '',
  address_number: '',
  address_complement: '',
  district: '',
  postal_code: '',
  city: '',
  state: ''
})

const step = ref<'registration' | 'modules'>('registration')
const preview = ref<CnpjPreview | null>(null)
const lookingUp = ref(false)
const submitting = ref(false)
const lookupError = ref<string | null>(null)
const submitError = ref<string | null>(null)
let lookupGeneration = 0

// Etapa de módulos: o cliente já foi salvo quando ela abre, e o que ela faz é
// só associar obrigações. `savedClient` segura o 201 para o `saved` final —
// pular a etapa emite o mesmo evento, porque o cadastro já aconteceu.
const savedClient = shallowRef<Client | null>(null)
const modules = shallowRef<ClientMonitoringModules | null>(null)
const modulesLoading = ref(false)
const modulesError = ref(false)
const modulesSelected = ref<Set<string>>(new Set())
const modulesSubmitting = ref(false)
const dispatchContactsOutcome = ref<'none' | 'saved' | 'failed'>('none')
let formGeneration = 0

const modulesGroups = computed(() => monitoringModuleGroups({ obligations: modules.value?.obligations ?? [] }))
const moduleItems = computed(() => modulesGroups.value.flatMap(group => group.items))

interface RegistrationStep {
  id: string
  label: string
  icon: string
  done: boolean
  tone: 'success' | 'warning' | 'error' | 'neutral'
  statusLabel: string
}

function deadlineStep(id: string, label: string, icon: string, status: DeadlineStatus | undefined): RegistrationStep {
  const resolved = status ?? 'missing'
  const tone = resolved === 'valid'
    ? 'success'
    : resolved === 'expiring'
      ? 'warning'
      : resolved === 'expired'
        ? 'error'
        : 'neutral'

  return {
    id,
    label,
    icon,
    done: resolved === 'valid' || resolved === 'expiring',
    tone,
    statusLabel: deadlinePresentation[resolved].label
  }
}

const registrationSteps = computed<RegistrationStep[]>(() => {
  const steps: RegistrationStep[] = [
    { id: 'cadastro', label: 'Cadastro do cliente', icon: 'i-lucide-building-2', done: true, tone: 'success', statusLabel: 'Concluído' }
  ]

  if (dispatchContactsOutcome.value === 'saved') {
    steps.push({ id: 'contacts', label: 'Contatos de envio', icon: 'i-lucide-send', done: true, tone: 'success', statusLabel: 'Concluído' })
  } else if (dispatchContactsOutcome.value === 'failed') {
    steps.push({ id: 'contacts', label: 'Contatos de envio', icon: 'i-lucide-send', done: false, tone: 'error', statusLabel: 'Não concluído' })
  }

  steps.push(
    { id: 'xml', label: 'Captura de XML', icon: 'i-lucide-file-code-2', done: true, tone: 'success', statusLabel: 'Incluída' },
    deadlineStep('certificate', 'Certificado digital', 'i-lucide-key-round', savedClient.value?.certificate_status),
    deadlineStep('poa', 'Procuração e-CAC', 'i-lucide-file-badge', savedClient.value?.ecac_power_of_attorney_status)
  )

  return steps
})

const registrationProgress = computed(() => {
  const steps = registrationSteps.value
  if (steps.length === 0) return 0
  return Math.round((steps.filter(item => item.done).length / steps.length) * 100)
})

const registrationComplete = computed(() => registrationProgress.value === 100)

const registrationProgressLabel = computed(() =>
  registrationComplete.value ? 'Todas as etapas finalizadas' : 'Etapas do cadastro'
)

const registrationSummary = computed(() =>
  registrationComplete.value
    ? 'Todas as etapas deste cadastro foram concluídas.'
    : 'O cliente foi salvo. Confira as etapas e as obrigações de monitoramento.'
)

const registrationStatusClass: Record<RegistrationStep['tone'], string> = {
  success: 'text-success',
  warning: 'text-warning',
  error: 'text-error',
  neutral: 'text-muted'
}

const regimeLocked = computed(() => {
  if (preview.value?.mei) return 'mei' as const
  if (preview.value?.simple_national) return 'simple_national' as const
  return null
})

const regimeOptions = computed(() => {
  if (regimeLocked.value === 'mei') return [{ label: 'MEI', value: 'mei' }]
  if (regimeLocked.value === 'simple_national') return [{ label: 'Simples Nacional', value: 'simple_national' }]
  return [
    { label: 'Presumido', value: 'presumed_profit' },
    { label: 'Real', value: 'actual_profit' },
    { label: 'Outro', value: 'other' }
  ]
})

const statusOptions = [
  { label: 'Ativo', value: 'active' },
  { label: 'Inativo', value: 'inactive' }
]

const personTypeOptions = [
  { label: 'Pessoa jurídica', value: 'company' },
  { label: 'Pessoa física', value: 'individual' }
]

const entry = computed(() => companyTaxIdEntry(state.tax_id))
const canLookup = computed(() => state.person_type === 'company' && entry.value === 'lookup')

interface ActivityRow {
  key: number
  code: string
  description: string
}

// A consulta pública e o cadastro guardam uma atividade principal. Linhas a
// mais ficam só neste formulário: o payload de criação não tem campo para elas.
const activities = ref<ActivityRow[]>([])
let activityKey = 0

function createActivity(code = '', description = ''): ActivityRow {
  activityKey += 1
  return { key: activityKey, code, description }
}

function activityRowsFromPreview(data: CnpjPreview): ActivityRow[] {
  const code = data.primary_activity_code?.trim() ?? ''
  const description = data.primary_activity_description?.trim() ?? ''
  if (code === '' && description === '') return []
  return [createActivity(code, description)]
}

function activityLineName(row: ActivityRow, index: number): string {
  const code = row.code.trim()
  const description = row.description.trim()
  if (code !== '' && description !== '') return `${code} — ${description}`
  if (code !== '') return code
  if (description !== '') return description
  return `linha ${index + 1}`
}

function addActivity() {
  activities.value.push(createActivity())
}

function removeActivity(key: number) {
  activities.value = activities.value.filter(row => row.key !== key)
}

function activityIndex(row: ActivityRow) {
  const index = activities.value.findIndex(item => item.key === row.key)
  return index === -1 ? 0 : index
}

function activityRowId(row: ActivityRow) {
  return String(row.key)
}

const activityColumns = [
  { accessorKey: 'code', header: 'Código' },
  { accessorKey: 'description', header: 'Descrição' },
  { id: 'actions', header: 'Ações' }
]

interface ContactDraft {
  key: number
  channel: 'email' | 'whatsapp'
  name: string
  value: string
}

const contactChannel = ref<'email' | 'whatsapp'>('email')
const contacts = ref<ContactDraft[]>([])
let contactKey = 0

function contactCount(channel: 'email' | 'whatsapp') {
  return contacts.value.filter(row => row.channel === channel).length
}

const contactTabItems = computed(() => [
  { label: 'E-mail', value: 'email' as const, badge: contactCount('email') },
  { label: 'WhatsApp', value: 'whatsapp' as const, badge: contactCount('whatsapp') }
])

const addContactLabel = computed(() => contactChannel.value === 'email'
  ? 'Novo contato de e-mail'
  : 'Novo contato de WhatsApp')

const visibleContacts = computed(() => contacts.value.filter(row => row.channel === contactChannel.value))

function addContact() {
  contactKey += 1
  contacts.value.push({ key: contactKey, channel: contactChannel.value, name: '', value: '' })
}

function removeContact(key: number) {
  contacts.value = contacts.value.filter(row => row.key !== key)
}

function contactLineName(row: ContactDraft, index: number) {
  const name = row.name.trim()
  return name || `linha ${index + 1}`
}

function dispatchContactPayloads(): Array<{ label: string, channel: 'email' | 'whatsapp', value: string }> | string {
  const payloads: Array<{ label: string, channel: 'email' | 'whatsapp', value: string }> = []
  for (const row of contacts.value) {
    const name = row.name.trim()
    const raw = row.value.trim()
    if (!name && !raw) continue
    if (!name || !raw) {
      return row.channel === 'email'
        ? 'Informe o nome e o e-mail de cada contato.'
        : 'Informe o nome e o telefone de cada contato.'
    }
    if (row.channel === 'whatsapp') {
      const digits = raw.replace(/\D+/g, '')
      if (digits.length < 10 || digits.length > 13) return 'O telefone está fora do padrão para envio por WhatsApp.'
      payloads.push({ label: name, channel: 'whatsapp', value: digits })
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw)) {
      return 'O e-mail informado não é válido.'
    } else {
      payloads.push({ label: name, channel: 'email', value: raw.toLowerCase() })
    }
  }
  return payloads
}

function invalidateLookup() {
  lookupGeneration++
  lookingUp.value = false
  lookupError.value = null
  const hadPreview = preview.value !== null
  preview.value = null
  if (hadPreview) activities.value = []
  if (state.tax_regime === 'mei' || state.tax_regime === 'simple_national') {
    state.tax_regime = state.person_type === 'individual' ? 'not_applicable' : 'presumed_profit'
  }
}

function resetForm() {
  formGeneration++
  state.person_type = 'company'
  state.tax_id = ''
  state.name = ''
  state.status = 'active'
  state.tax_regime = 'presumed_profit'
  state.street_type = ''
  state.street = ''
  state.address_number = ''
  state.address_complement = ''
  state.district = ''
  state.postal_code = ''
  state.city = ''
  state.state = ''
  activities.value = []
  contacts.value = []
  contactChannel.value = 'email'
  step.value = 'registration'
  invalidateLookup()
  submitError.value = null
  form.value?.clear()
  submitting.value = false
  savedClient.value = null
  modules.value = null
  modulesLoading.value = false
  modulesError.value = false
  modulesSelected.value = new Set()
  modulesSubmitting.value = false
  dispatchContactsOutcome.value = 'none'
}

watch(() => props.open, (open) => {
  if (!open) emitSaved()
  resetForm()
}, { flush: 'sync' })

watch(() => state.person_type, (type) => {
  invalidateLookup()
  if (type !== 'company') activities.value = []
  state.tax_regime = type === 'individual' ? 'not_applicable' : 'presumed_profit'
  form.value?.clear()
  submitError.value = null
}, { flush: 'sync' })

watch(() => state.tax_id, (next, previous) => {
  if (taxIdEditInvalidatesLookup(previous ?? '', next)) invalidateLookup()
}, { flush: 'sync' })

async function onLookup() {
  if (!canLookup.value || !isOpen.value || submitting.value || step.value !== 'registration') return
  const request = ++lookupGeneration
  const generation = formGeneration
  const taxId = state.tax_id
  const isCurrent = () => request === lookupGeneration
    && generation === formGeneration && isOpen.value
    && state.person_type === 'company' && step.value === 'registration'
    && !taxIdEditInvalidatesLookup(taxId, state.tax_id)

  lookingUp.value = true
  lookupError.value = null
  try {
    const data = await lookupCnpj(taxId)
    if (!isCurrent() || taxIdEditInvalidatesLookup(taxId, data.tax_id)) return
    preview.value = data
    activities.value = activityRowsFromPreview(data)
    const locked = data.mei ? 'mei' : data.simple_national ? 'simple_national' : null
    if (locked) state.tax_regime = locked
    else if (state.tax_regime === 'mei' || state.tax_regime === 'simple_national') state.tax_regime = 'presumed_profit'
    form.value?.clear('name')
    toast.add({ title: 'CNPJ localizado', description: data.name, color: 'success' })
  } catch (error) {
    if (!isCurrent()) return
    const message = apiErrorMessage(error) ?? 'Confira o número e tente novamente.'
    lookupError.value = apiStatus(error) === 404
      ? `${message} Informe a razão social e continue o cadastro.`
      : `${message} Os dados digitados foram mantidos. A consulta também é validada ao cadastrar CNPJ numérico.`
    toast.add({ title: 'Não foi possível consultar o CNPJ', description: lookupError.value, color: 'error' })
  } finally {
    if (isCurrent()) lookingUp.value = false
  }
}

function onValidationError(event: FormErrorEvent) {
  const id = event.errors[0]?.id
  if (!id) return
  document.getElementById(id)?.focus()
  document.getElementById(id)?.scrollIntoView({ block: 'nearest' })
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (submitting.value || lookingUp.value || !isOpen.value) return
  const drafts = dispatchContactPayloads()
  if (typeof drafts === 'string') {
    submitError.value = drafts
    toast.add({ title: 'Não foi possível salvar o cliente', description: drafts, color: 'error' })
    return
  }
  const generation = formGeneration
  lookupGeneration++
  submitting.value = true
  submitError.value = null
  try {
    const saved = await create(event.data as ClientWritePayload)
    toast.add({ title: 'Cliente cadastrado', color: 'success' })

    if (drafts.length > 0 && generation === formGeneration) {
      try {
        for (const payload of drafts) {
          if (generation !== formGeneration) break
          await createDispatchContact(saved.id, payload)
        }
        if (generation === formGeneration) dispatchContactsOutcome.value = 'saved'
      } catch (error) {
        if (generation === formGeneration) dispatchContactsOutcome.value = 'failed'
        const message = apiErrorMessage(error) ?? 'O cliente foi cadastrado, mas um contato de envio não pôde ser salvo.'
        toast.add({ title: 'Contato de envio não cadastrado', description: message, color: 'error' })
      }
    }

    if (generation !== formGeneration || !isOpen.value) {
      emit('saved', saved)
      return
    }

    savedClient.value = saved

    // Pessoa física e `user` não têm etapa de módulos — a spec fecha o fluxo
    // aqui para os dois. Para `admin`/`operador` com pessoa jurídica, o
    // cadastro continua na conferência das obrigações sugeridas.
    if (saved.person_type === 'individual' || !canManageClients.value) {
      finishSaved()
      return
    }

    step.value = 'modules'
    await loadModules(saved.id)
  } catch (error) {
    if (generation !== formGeneration || !isOpen.value) return
    const errors = Object.entries(apiFieldErrors(error)).map(([name, message]) => ({ name, message }))
    if (errors.length > 0) form.value?.setErrors(errors)
    submitError.value = apiErrorMessage(error) ?? 'Os dados digitados foram mantidos. Confira os campos e tente novamente.'
    toast.add({ title: 'Não foi possível salvar o cliente', description: submitError.value, color: 'error' })
  } finally {
    if (generation === formGeneration) submitting.value = false
  }
}

async function loadModules(clientId: number) {
  const generation = formGeneration
  modulesLoading.value = true
  modulesError.value = false
  try {
    const data = await monitoringModules(clientId)
    if (generation !== formGeneration || savedClient.value?.id !== clientId) return
    modules.value = data
    modulesSelected.value = initialMonitoringModuleSelection(data)
  } catch {
    if (generation === formGeneration && savedClient.value?.id === clientId) modulesError.value = true
  } finally {
    if (generation === formGeneration && savedClient.value?.id === clientId) modulesLoading.value = false
  }
}

function toggleModule(slug: string, checked: boolean | 'indeterminate') {
  const next = new Set(modulesSelected.value)
  if (checked === true) next.add(slug)
  else next.delete(slug)
  modulesSelected.value = next
}

/**
 * Fechar a etapa sem confirmar não descarta o cliente: o 201 já aconteceu, e
 * a spec é explícita — pular mantém o cadastro sem nenhuma associação e emite
 * o mesmo `saved` do confirmar.
 */
function emitSaved() {
  const saved = savedClient.value
  if (!saved) return
  savedClient.value = null
  emit('saved', saved)
}

function finishSaved() {
  emitSaved()
  isOpen.value = false
}

async function confirmModules() {
  if (!savedClient.value || !modules.value) return
  const clientId = savedClient.value.id
  const generation = formGeneration
  const obligations = monitoringModulesPayload(modules.value, modulesSelected.value)
  // `obligations min:1` no backend: postar `[]` é um 422 garantido. Nada
  // marcado segue o mesmo caminho de "Concluir sem associar" — o 201 já
  // aconteceu, e pular mantém o cadastro sem nenhuma associação.
  if (obligations.length === 0) {
    finishSaved()
    return
  }
  modulesSubmitting.value = true
  try {
    await confirmMonitoringModules(clientId, obligations)
    if (generation !== formGeneration || savedClient.value?.id !== clientId) return
    toast.add({ title: 'Obrigações de monitoramento associadas', color: 'success' })
    finishSaved()
  } catch {
    if (generation === formGeneration && savedClient.value?.id === clientId) modulesError.value = true
  } finally {
    if (generation === formGeneration && savedClient.value?.id === clientId) modulesSubmitting.value = false
  }
}
</script>

<template>
  <UModal
    v-bind="$attrs"
    v-model:open="isOpen"
    :title="step === 'modules' ? 'Cliente cadastrado com sucesso.' : 'Novo cliente'"
    :description="step === 'modules' ? registrationSummary : 'Cadastre o cliente na carteira do escritório.'"
    :ui="{ content: 'sm:max-w-4xl', header: 'shrink-0', body: 'min-h-0 overflow-y-auto', footer: 'shrink-0 flex-wrap justify-end' }"
  >
    <template #body>
      <div v-if="step === 'modules'" class="space-y-4">
        <div class="rounded-md bg-success/10 px-3 py-2 text-center text-sm font-semibold text-success">
          {{ registrationComplete ? 'Concluído' : 'Cadastro salvo' }}
        </div>

        <div class="space-y-1">
          <p class="text-sm text-muted">
            {{ registrationProgressLabel }}
          </p>
          <p class="text-sm font-semibold text-highlighted">
            {{ registrationProgress }}%
          </p>
          <UProgress
            :model-value="registrationProgress"
            color="success"
            size="xs"
            :get-value-label="() => registrationProgressLabel"
          />
        </div>

        <div v-if="savedClient" class="flex items-center gap-3 rounded-lg bg-elevated px-4 py-3">
          <UIcon name="i-lucide-building-2" class="size-5 shrink-0 text-muted" />
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-highlighted">
              {{ savedClient.name }}
            </p>
            <p class="text-sm text-muted">
              {{ formatTaxId(savedClient.tax_id) }}
            </p>
          </div>
        </div>

        <ul class="divide-y divide-default overflow-hidden rounded-lg bg-elevated/60 ring ring-default">
          <li
            v-for="item in registrationSteps"
            :key="item.id"
            class="flex items-center gap-3 px-3 py-2.5"
          >
            <UIcon
              :name="item.done ? 'i-lucide-check' : item.tone === 'error' ? 'i-lucide-circle-alert' : 'i-lucide-circle'"
              class="size-4 shrink-0"
              :class="registrationStatusClass[item.tone]"
            />
            <UIcon :name="item.icon" class="size-4 shrink-0 text-muted" />
            <span class="min-w-0 flex-1 truncate text-sm text-default">
              {{ item.label }}
            </span>
            <span class="shrink-0 text-sm" :class="registrationStatusClass[item.tone]">
              {{ item.statusLabel }}
            </span>
          </li>
        </ul>

        <div v-if="modulesLoading" class="space-y-2">
          <USkeleton v-for="index in 4" :key="index" class="h-9 w-full" />
        </div>

        <template v-else-if="modules">
          <ErrorRetryAlert
            v-if="modulesError"
            title="Não foi possível associar as obrigações"
            description="A seleção foi mantida e o cliente continua cadastrado. Tente novamente ou feche sem associar."
            :loading="modulesSubmitting"
            @retry="confirmModules"
          />

          <section class="space-y-3" aria-labelledby="client-create-modules-title">
            <h3 id="client-create-modules-title" class="text-sm font-semibold text-highlighted">
              Obrigações de monitoramento
            </h3>
            <p class="text-xs text-muted">
              As sugeridas para o regime do cliente já vêm marcadas.
            </p>
            <div v-if="moduleItems.length > 0" class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
              <UCheckbox
                v-for="item in moduleItems"
                :key="item.slug"
                :model-value="modulesSelected.has(item.slug)"
                :label="item.label"
                :description="item.associated ? 'Já associada' : undefined"
                @update:model-value="toggleModule(item.slug, $event)"
              />
            </div>
            <p v-else class="text-sm text-muted">
              Nenhuma obrigação servida pela integração para este cliente.
            </p>
          </section>

          <div class="flex gap-2 rounded-lg bg-elevated px-3 py-2.5 text-sm text-muted ring ring-default">
            <UIcon name="i-lucide-info" class="mt-0.5 size-4 shrink-0" />
            <p>
              Confirmar as obrigações não dispara a leitura agora. A primeira
              sincronização acontece no próximo ciclo e não envia documentos
              automaticamente. A captura de XML já inclui este cliente.
            </p>
          </div>
        </template>

        <template v-else-if="modulesError">
          <ErrorRetryAlert
            title="Não foi possível carregar as obrigações"
            description="O cliente continua cadastrado. Tente carregar de novo ou feche sem associar — dá para associar depois pelo Monitoramento."
            :loading="modulesLoading"
            @retry="savedClient && loadModules(savedClient.id)"
          />
        </template>
      </div>

      <UForm
        v-else
        id="client-create-form"
        ref="form"
        :schema="schema"
        :state="state as unknown as Partial<Schema>"
        :disabled="submitting"
        class="space-y-6"
        @submit="onSubmit"
        @error="onValidationError"
      >
        <UAlert
          v-if="submitError"
          color="error"
          variant="subtle"
          title="Não foi possível cadastrar o cliente"
          :description="submitError"
          role="alert"
        />

        <div class="grid grid-cols-1 items-start gap-6 sm:grid-cols-2">
          <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-highlighted">
              Cadastro
            </legend>
            <URadioGroup
              v-model="state.person_type"
              name="person_type"
              legend="Tipo de pessoa"
              :items="personTypeOptions"
              orientation="horizontal"
              variant="table"
              indicator="hidden"
              color="primary"
              class="w-full"
              :ui="{ legend: 'sr-only', fieldset: 'w-full', item: 'min-w-0 flex-1 items-center justify-center px-3 py-2 has-data-[state=checked]:border-primary has-data-[state=checked]:bg-primary' }"
            >
              <template #label="{ item }">
                <span :class="state.person_type === item.value ? 'text-inverted' : undefined">{{ item.label }}</span>
              </template>
            </URadioGroup>

            <UFormField
              v-if="state.person_type === 'company'"
              label="CNPJ"
              name="tax_id"
              required
            >
              <div class="flex flex-col gap-2 lg:flex-row">
                <UInput
                  v-model="state.tax_id"
                  placeholder="00.000.000/0000-00"
                  autocomplete="off"
                  class="w-full lg:flex-1"
                />
                <UButton
                  label="Consultar CNPJ"
                  icon="i-lucide-search"
                  color="neutral"
                  variant="outline"
                  type="button"
                  :loading="lookingUp"
                  :disabled="!canLookup || submitting"
                  class="justify-center lg:shrink-0"
                  @click="onLookup"
                />
              </div>
            </UFormField>
            <UFormField
              v-else
              label="CPF"
              name="tax_id"
              required
            >
              <UInput
                v-model="state.tax_id"
                placeholder="000.000.000-00"
                inputmode="numeric"
                class="w-full"
              />
            </UFormField>

            <UAlert
              v-if="lookupError"
              color="warning"
              variant="subtle"
              title="Consulta de CNPJ não concluída"
              :description="lookupError"
              role="alert"
            />

            <UFormField
              :label="state.person_type === 'company' ? 'Razão social' : 'Nome completo'"
              name="name"
              required
            >
              <UInput
                v-if="preview"
                :model-value="preview.name"
                readonly
                class="w-full"
              />
              <UInput
                v-else
                v-model="state.name"
                :placeholder="state.person_type === 'company' ? 'Nome da empresa' : 'Nome do cliente'"
                :autocomplete="state.person_type === 'company' ? 'organization' : 'name'"
                class="w-full"
              />
            </UFormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <UFormField label="Situação na carteira" name="status" required>
                <USelect
                  v-model="state.status"
                  :items="statusOptions"
                  value-key="value"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                v-if="state.person_type === 'company'"
                label="Regime tributário"
                name="tax_regime"
                required
              >
                <USelect
                  v-model="state.tax_regime"
                  :items="regimeOptions"
                  value-key="value"
                  class="w-full"
                  :disabled="!!regimeLocked"
                />
              </UFormField>
              <UFormField v-else label="Regime tributário" name="tax_regime">
                <UInput value="Não aplicável" class="w-full" disabled />
              </UFormField>
            </div>

            <dl v-if="preview" class="grid gap-2 rounded-lg bg-elevated/50 p-3 text-sm">
              <div>
                <dt class="text-xs text-muted">
                  Nome fantasia
                </dt>
                <dd class="break-words">
                  {{ preview.trade_name ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-muted">
                  Situação na Receita
                </dt>
                <dd class="break-words">
                  {{ preview.registration_status ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs text-muted">
                  Endereço
                </dt>
                <dd class="break-words">
                  {{ [preview.street_type, preview.street, preview.address_number, preview.address_complement, preview.district, preview.city, preview.state, preview.postal_code].filter(Boolean).join(', ') || '—' }}
                </dd>
              </div>
            </dl>

            <fieldset v-if="state.person_type === 'individual'" class="space-y-3">
              <legend class="text-sm font-semibold text-highlighted">
                Endereço <span class="font-normal text-muted">(opcional)</span>
              </legend>
              <UFormField label="Tipo de logradouro" name="street_type">
                <UInput v-model="state.street_type" placeholder="Rua, avenida…" class="w-full" />
              </UFormField>
              <UFormField label="Logradouro" name="street">
                <UInput v-model="state.street" autocomplete="address-line1" class="w-full" />
              </UFormField>
              <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <UFormField label="Número" name="address_number">
                  <UInput v-model="state.address_number" class="w-full" />
                </UFormField>
                <UFormField label="Complemento" name="address_complement">
                  <UInput v-model="state.address_complement" autocomplete="address-line2" class="w-full" />
                </UFormField>
                <UFormField label="Bairro" name="district">
                  <UInput v-model="state.district" class="w-full" />
                </UFormField>
                <UFormField label="CEP" name="postal_code">
                  <UInput
                    v-model="state.postal_code"
                    autocomplete="postal-code"
                    inputmode="numeric"
                    class="w-full"
                  />
                </UFormField>
              </div>
              <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_5rem]">
                <UFormField label="Cidade" name="city">
                  <UInput v-model="state.city" autocomplete="address-level2" class="w-full" />
                </UFormField>
                <UFormField label="UF" name="state">
                  <UInput
                    v-model="state.state"
                    autocomplete="address-level1"
                    maxlength="2"
                    class="w-full"
                  />
                </UFormField>
              </div>
            </fieldset>
          </fieldset>

          <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-highlighted">
              Contatos
            </legend>
            <UTabs
              v-model="contactChannel"
              :items="contactTabItems"
              variant="link"
              :content="false"
            />
            <div v-if="visibleContacts.length === 0" class="rounded-lg bg-elevated/50 p-4">
              <UButton
                :label="addContactLabel"
                icon="i-lucide-plus"
                color="neutral"
                variant="outline"
                type="button"
                @click="addContact"
              />
            </div>
            <template v-else>
              <UButton
                :label="addContactLabel"
                icon="i-lucide-plus"
                color="neutral"
                variant="outline"
                type="button"
                @click="addContact"
              />
              <div
                v-for="(row, index) in visibleContacts"
                :key="row.key"
                class="grid grid-cols-1 items-end gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
              >
                <UFormField label="Nome">
                  <UInput
                    v-model="row.name"
                    :aria-label="`Nome do contato ${contactLineName(row, index)}`"
                    autocomplete="name"
                    class="w-full"
                  />
                </UFormField>
                <UFormField :label="contactChannel === 'email' ? 'E-mail' : 'Telefone'">
                  <UInput
                    v-model="row.value"
                    :type="contactChannel === 'email' ? 'email' : 'tel'"
                    :inputmode="contactChannel === 'email' ? 'email' : 'tel'"
                    :autocomplete="contactChannel === 'email' ? 'email' : 'tel'"
                    :aria-label="`${contactChannel === 'email' ? 'E-mail' : 'Telefone'} do contato ${contactLineName(row, index)}`"
                    class="w-full"
                  />
                </UFormField>
                <UButton
                  label="Remover"
                  color="neutral"
                  variant="ghost"
                  type="button"
                  :aria-label="`Remover contato ${contactLineName(row, index)}`"
                  @click="removeContact(row.key)"
                />
              </div>
            </template>
          </fieldset>
        </div>

        <section v-if="state.person_type === 'company'" class="space-y-3" aria-labelledby="client-create-activities-title">
          <h3 id="client-create-activities-title" class="text-sm font-semibold text-highlighted">
            Atividades
          </h3>
          <UTable
            :data="activities"
            :columns="activityColumns"
            :get-row-id="activityRowId"
            class="w-full"
          >
            <template #code-cell="{ row }">
              <UInput
                v-model="row.original.code"
                :aria-label="`Código da atividade ${activityLineName(row.original, activityIndex(row.original))}`"
                autocomplete="off"
                class="w-full"
              />
            </template>
            <template #description-cell="{ row }">
              <UInput
                v-model="row.original.description"
                :aria-label="`Descrição da atividade ${activityLineName(row.original, activityIndex(row.original))}`"
                autocomplete="off"
                class="w-full"
              />
            </template>
            <template #actions-cell="{ row }">
              <UButton
                label="Remover"
                color="neutral"
                variant="ghost"
                type="button"
                :aria-label="`Remover atividade ${activityLineName(row.original, activityIndex(row.original))}`"
                @click="removeActivity(row.original.key)"
              />
            </template>
            <template #empty>
              Nenhuma atividade.
            </template>
          </UTable>
          <UButton
            label="Adicionar atividade"
            color="neutral"
            variant="outline"
            type="button"
            @click="addActivity"
          />
        </section>
      </UForm>
    </template>

    <template #footer="{ close }">
      <template v-if="step === 'modules'">
        <UButton
          label="Fechar"
          color="neutral"
          variant="outline"
          :disabled="modulesSubmitting"
          @click="finishSaved"
        />
        <UButton
          label="Confirmar obrigações"
          color="primary"
          :loading="modulesSubmitting"
          :disabled="!modules || modulesLoading"
          @click="confirmModules"
        />
      </template>
      <template v-else>
        <UButton
          label="Cancelar"
          color="neutral"
          variant="outline"
          @click="close"
        />
        <UButton
          label="Cadastrar cliente"
          type="submit"
          form="client-create-form"
          :loading="submitting"
          :disabled="lookingUp || submitting"
        />
      </template>
    </template>
  </UModal>
</template>
