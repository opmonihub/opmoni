<script setup lang="ts">
import * as z from 'zod'
import type { FormErrorEvent, FormSubmitEvent } from '@nuxt/ui'
import type { Client, ClientWritePayload, CnpjPreview } from '~/types/client'
import type { ClientMonitoringModules } from '~/types/serpro'
import { companyTaxIdEntry, taxIdEditInvalidatesLookup } from '~/utils/taxId'
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
const { canManageClients } = useAuth()
const toast = useToast()
const form = useTemplateRef('form')

const companySchema = z.object({
  person_type: z.literal('company'),
  tax_id: z.string().min(14, 'Informe um CNPJ válido'),
  name: z.string().max(255, 'Nome muito longo')
    .refine(value => preview.value !== null || value.trim().length >= 2, { message: 'Informe a razão social' }),
  status: z.enum(['active', 'inactive']),
  tax_regime: z.enum(['mei', 'simple_national', 'presumed_profit', 'actual_profit', 'other']),
  email: z.email('Email inválido').or(z.literal('')).optional(),
  phone: z.string().max(20, 'Telefone muito longo').optional()
})

const individualSchema = z.object({
  person_type: z.literal('individual'),
  tax_id: z.string().min(11, 'Informe um CPF válido'),
  name: z.string().min(2, 'Informe o nome completo').max(255, 'Nome muito longo'),
  status: z.enum(['active', 'inactive']),
  tax_regime: z.literal('not_applicable'),
  email: z.email('Email inválido').or(z.literal('')).optional(),
  phone: z.string().max(20, 'Telefone muito longo').optional(),
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
  email: string
  phone: string
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
  email: '',
  phone: '',
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
const contactEdited = reactive({ email: false, phone: false })
let applyingContacts = false
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
let formGeneration = 0

const modulesGroups = computed(() => monitoringModuleGroups({ obligations: modules.value?.obligations ?? [] }))

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
  { label: 'Pessoa jurídica (CNPJ)', value: 'company' },
  { label: 'Pessoa física (CPF)', value: 'individual' }
]

const entry = computed(() => companyTaxIdEntry(state.tax_id))
const canLookup = computed(() => state.person_type === 'company' && entry.value === 'lookup')

// A consulta só preenche contato ainda não editado, inclusive quando o usuário
// digitou e apagou um valor. Alterações programáticas não tornam o campo manual.
for (const field of ['email', 'phone'] as const) {
  watch(() => state[field], () => {
    if (!applyingContacts) contactEdited[field] = true
  }, { flush: 'sync' })
}

function invalidateLookup() {
  lookupGeneration++
  lookingUp.value = false
  lookupError.value = null
  const previous = preview.value
  if (previous) {
    applyingContacts = true
    for (const field of ['email', 'phone'] as const) {
      if (!contactEdited[field] && state[field] === (previous[field] ?? '')) state[field] = ''
    }
    applyingContacts = false
  }
  preview.value = null
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
  applyingContacts = true
  state.email = ''
  state.phone = ''
  applyingContacts = false
  contactEdited.email = false
  contactEdited.phone = false
  state.street_type = ''
  state.street = ''
  state.address_number = ''
  state.address_complement = ''
  state.district = ''
  state.postal_code = ''
  state.city = ''
  state.state = ''
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
}

watch(() => props.open, (open) => {
  if (!open) emitSaved()
  resetForm()
}, { flush: 'sync' })

watch(() => state.person_type, (type) => {
  invalidateLookup()
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
    const locked = data.mei ? 'mei' : data.simple_national ? 'simple_national' : null
    if (locked) state.tax_regime = locked
    else if (state.tax_regime === 'mei' || state.tax_regime === 'simple_national') state.tax_regime = 'presumed_profit'
    applyingContacts = true
    for (const field of ['email', 'phone'] as const) {
      if (!contactEdited[field]) state[field] = data[field] ?? state[field]
    }
    applyingContacts = false
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
  const generation = formGeneration
  lookupGeneration++
  submitting.value = true
  submitError.value = null
  try {
    const saved = await create(event.data as ClientWritePayload)
    toast.add({ title: 'Cliente cadastrado', color: 'success' })

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
    :title="step === 'modules' ? 'Obrigações de monitoramento' : 'Novo cliente'"
    :description="step === 'modules'
      ? (savedClient ? `Associe ${savedClient.name} às obrigações que a integração acompanha` : 'Associe o cliente às obrigações que a integração acompanha')
      : 'Preencha o cadastro. A consulta de CNPJ ajuda a conferir os dados oficiais.'"
    :ui="{ content: 'sm:max-w-3xl', header: 'shrink-0', body: 'min-h-0 overflow-y-auto', footer: 'shrink-0 flex-wrap justify-end' }"
  >
    <template #body>
      <div v-if="step === 'modules'" class="space-y-4">
        <div v-if="modulesLoading" class="space-y-2">
          <USkeleton v-for="index in 5" :key="index" class="h-9 w-full" />
        </div>

        <template v-else-if="modules">
          <p class="text-xs text-muted">
            As sugeridas para o regime do cliente já vêm marcadas. Confirmar não
            chama o provedor — a primeira leitura acontece na próxima
            sincronização. Sem opção de captura de XML: todo cliente entra nela.
          </p>

          <ErrorRetryAlert
            v-if="modulesError"
            title="Não foi possível associar as obrigações"
            description="A seleção foi mantida e o cliente continua cadastrado. Tente novamente ou conclua sem associar."
            :loading="modulesSubmitting"
            @retry="confirmModules"
          />

          <div v-for="group in modulesGroups" :key="group.id" class="space-y-1.5">
            <p class="text-xs font-semibold text-muted">
              {{ group.label }}
            </p>
            <ul class="divide-y divide-default rounded-lg ring ring-default">
              <li
                v-for="item in group.items"
                :key="item.slug"
                class="flex items-center gap-3 px-3 py-2"
              >
                <UCheckbox
                  :model-value="modulesSelected.has(item.slug)"
                  :aria-label="`Associar ${item.label}`"
                  @update:model-value="toggleModule(item.slug, $event)"
                />
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm text-default">
                    {{ item.label }}
                  </p>
                  <p v-if="item.associated" class="text-xs text-muted">
                    Já associada
                  </p>
                </div>
              </li>
            </ul>
          </div>

          <p v-if="modulesGroups.length === 0" class="text-sm text-muted">
            Nenhuma obrigação servida pela integração para este cliente.
          </p>
        </template>

        <template v-else-if="modulesError">
          <ErrorRetryAlert
            title="Não foi possível carregar as obrigações"
            description="O cliente continua cadastrado. Tente carregar de novo ou conclua sem associar — dá para associar depois pelo Monitoramento."
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

        <fieldset class="space-y-4">
          <legend class="mb-3 text-sm font-semibold text-highlighted">
            Identificação
          </legend>
          <UFormField label="Tipo de pessoa" name="person_type" required>
            <USelect
              v-model="state.person_type"
              :items="personTypeOptions"
              value-key="value"
              class="w-full sm:max-w-sm"
            />
          </UFormField>

          <UFormField
            v-if="state.person_type === 'company'"
            label="CNPJ"
            name="tax_id"
            help="Letras e números. A consulta pública está disponível somente para CNPJ numérico."
            required
          >
            <div class="flex flex-col gap-2 sm:flex-row">
              <UInput
                v-model="state.tax_id"
                placeholder="00.000.000/0000-00"
                autocomplete="off"
                class="w-full sm:flex-1"
              />
              <UButton
                label="Consultar CNPJ"
                icon="i-lucide-search"
                color="neutral"
                variant="outline"
                type="button"
                :loading="lookingUp"
                :disabled="!canLookup || submitting"
                class="justify-center sm:shrink-0"
                @click="onLookup"
              />
            </div>
          </UFormField>
          <UFormField
            v-else
            label="CPF"
            name="tax_id"
            help="Somente números; pode informar com pontuação."
            required
          >
            <UInput
              v-model="state.tax_id"
              placeholder="000.000.000-00"
              inputmode="numeric"
              class="w-full"
            />
          </UFormField>

          <p v-if="state.person_type === 'company'" class="text-xs text-muted">
            {{ entry === 'manual'
              ? 'CNPJ alfanumérico: informe a razão social. Os dados oficiais ficam vazios até uma consulta disponível.'
              : 'Pode preencher sem consultar aqui. Ao cadastrar CNPJ numérico, o sistema consulta a fonte pública; a razão social oficial prevalece. Se o CNPJ não for encontrado, será usada a razão social digitada.' }}
          </p>
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
            :help="preview ? 'Razão social confirmada pela fonte pública; não é editável.' : undefined"
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
        </fieldset>

        <fieldset class="space-y-4">
          <legend class="mb-3 text-sm font-semibold text-highlighted">
            Cadastro na carteira
          </legend>
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
              :help="regimeLocked ? 'Definido pela Receita (MEI/Simples).' : 'MEI e Simples Nacional são confirmados pela consulta.'"
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
        </fieldset>

        <fieldset class="space-y-4">
          <legend class="mb-3 text-sm font-semibold text-highlighted">
            Contato
          </legend>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <UFormField label="E-mail" name="email" hint="Opcional">
              <UInput
                v-model="state.email"
                type="email"
                autocomplete="email"
                placeholder="contato@empresa.com"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Telefone" name="phone" hint="Opcional">
              <UInput
                v-model="state.phone"
                type="tel"
                autocomplete="tel"
                placeholder="(00) 00000-0000"
                class="w-full"
              />
            </UFormField>
          </div>
        </fieldset>

        <fieldset v-if="state.person_type === 'individual'" class="space-y-4">
          <legend class="mb-3 text-sm font-semibold text-highlighted">
            Endereço <span class="font-normal text-muted">(opcional)</span>
          </legend>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <UFormField label="Tipo de logradouro" name="street_type">
              <UInput v-model="state.street_type" placeholder="Rua, avenida…" class="w-full" />
            </UFormField>
            <UFormField label="Logradouro" name="street" class="sm:col-span-2">
              <UInput v-model="state.street" autocomplete="address-line1" class="w-full" />
            </UFormField>
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <UFormField label="Cidade" name="city" class="sm:col-span-2">
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

        <section v-else class="space-y-3" aria-labelledby="client-create-official-title">
          <h3 id="client-create-official-title" class="text-sm font-semibold text-highlighted">
            Dados oficiais da empresa
          </h3>
          <p class="text-xs text-muted">
            Endereço, nome fantasia, situação na Receita e atividade vêm da consulta e não são editáveis aqui.
          </p>
          <dl v-if="preview" class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
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
            <div class="sm:col-span-2">
              <dt class="text-xs text-muted">
                Atividade principal
              </dt>
              <dd class="break-words">
                {{ [preview.primary_activity_code, preview.primary_activity_description].filter(Boolean).join(' — ') || '—' }}
              </dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-xs text-muted">
                Endereço
              </dt>
              <dd class="break-words">
                {{ [preview.street_type, preview.street, preview.address_number, preview.address_complement, preview.district, preview.city, preview.state, preview.postal_code].filter(Boolean).join(', ') || '—' }}
              </dd>
            </div>
          </dl>
          <p v-else class="text-sm text-muted">
            Nenhuma consulta exibida. Se a fonte encontrar o CNPJ ao cadastrar, seus dados oficiais serão usados.
          </p>
        </section>
      </UForm>
    </template>

    <template #footer="{ close }">
      <template v-if="step === 'modules'">
        <UButton
          label="Concluir sem associar"
          color="neutral"
          variant="outline"
          :disabled="modulesSubmitting"
          @click="finishSaved"
        />
        <UButton
          label="Confirmar obrigações"
          color="neutral"
          variant="solid"
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
