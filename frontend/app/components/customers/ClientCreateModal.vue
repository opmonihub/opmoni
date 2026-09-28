<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { Client, ClientWritePayload, CnpjPreview } from '~/types/client'
import { companyTaxIdEntry, canRegisterTypedCnpj } from '~/utils/taxId'

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
  set: value => emit('update:open', value)
})

const { lookupCnpj, create } = useClients()
const toast = useToast()

const companySchema = z.object({
  person_type: z.literal('company'),
  tax_id: z.string().min(14, 'Informe um CNPJ válido'),
  name: z.string().max(255, 'Nome muito longo')
    .refine(value => !requiresTypedName.value || value.trim().length >= 2, { message: 'Informe a razão social' }),
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

const step = ref<1 | 2>(1)
const preview = ref<CnpjPreview | null>(null)
const lookingUp = ref(false)
const submitting = ref(false)

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
// A razão social é digitada quando não há consulta para trazer: a fonte pública não
// conhece documento alfanumérico, e o passo 2 só existe depois de uma escolha.
const typedName = ref(false)
// Uma única afirmação para as duas metades da mesma garantia: sem consulta
// bem-sucedida, o nome vem digitado. O schema exige a razão social exatamente
// quando o campo existe — as duas expressões eram o mesmo fato escrito duas
// vezes, e uma edição futura em uma delas podia devolver um erro de validação
// sem campo para ele aparecer.
const typedNameRequired = computed(() => state.person_type === 'company' && preview.value === null)
const requiresTypedName = computed(() => typedName.value && typedNameRequired.value)
const canLookup = computed(() => state.person_type === 'company' && entry.value === 'lookup')
const canRegisterTyped = computed(() => state.person_type === 'company' && canRegisterTypedCnpj(state.tax_id))

function resetForm() {
  state.person_type = 'company'
  state.tax_id = ''
  state.name = ''
  state.status = 'active'
  state.tax_regime = 'presumed_profit'
  state.email = ''
  state.phone = ''
  state.street_type = ''
  state.street = ''
  state.address_number = ''
  state.address_complement = ''
  state.district = ''
  state.postal_code = ''
  state.city = ''
  state.state = ''
  step.value = 1
  preview.value = null
  typedName.value = false
}

watch(() => props.open, () => {
  resetForm()
})

watch(() => state.person_type, (type) => {
  typedName.value = false

  if (type === 'individual') {
    state.tax_regime = 'not_applicable'
    step.value = 2
    preview.value = null
  } else {
    state.tax_regime = 'presumed_profit'
    step.value = 1
    preview.value = null
  }
})

async function onLookup() {
  if (typeof state.tax_id !== 'string') return
  lookingUp.value = true
  try {
    const data = await lookupCnpj(state.tax_id)
    preview.value = data
    typedName.value = false
    const locked = data.mei ? 'mei' : data.simple_national ? 'simple_national' : null
    state.tax_regime = locked ?? 'presumed_profit'
    state.email = data.email ?? state.email
    state.phone = data.phone ?? state.phone
    step.value = 2
    toast.add({ title: 'CNPJ localizado', description: data.name, color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível consultar o CNPJ', description: 'Confira o número e tente novamente.', color: 'error' })
  } finally {
    lookingUp.value = false
  }
}

function onRegisterTyped() {
  typedName.value = true
  preview.value = null
  step.value = 2
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  submitting.value = true
  try {
    const saved = await create(event.data as ClientWritePayload)
    toast.add({ title: 'Cliente cadastrado', color: 'success' })
    emit('saved', saved)
    isOpen.value = false
  } catch {
    toast.add({ title: 'Não foi possível salvar o cliente', color: 'error' })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <UModal
    v-bind="$attrs"
    v-model:open="isOpen"
    title="Novo cliente"
    description="Informe o documento e confirme os dados cadastrais"
    :ui="{ content: 'sm:max-w-lg' }"
  >
    <template #body>
      <div class="space-y-4">
        <UFormField label="Tipo de pessoa" name="person_type">
          <USelect
            v-model="state.person_type"
            :items="personTypeOptions"
            value-key="value"
            class="w-full"
          />
        </UFormField>

        <template v-if="step === 1 && state.person_type === 'company'">
          <UFormField label="CNPJ" name="tax_id" help="Letras e números; consulta automática apenas para CNPJ numérico">
            <UInput v-model="state.tax_id" placeholder="00.000.000/0000-00" class="w-full" />
          </UFormField>
          <div class="flex flex-wrap gap-2">
            <UButton
              label="Consultar CNPJ"
              icon="i-lucide-search"
              color="primary"
              :loading="lookingUp"
              :disabled="!canLookup"
              @click="onLookup"
            />
            <UButton
              v-if="canRegisterTyped"
              label="Cadastrar sem consulta"
              icon="i-lucide-pencil"
              color="neutral"
              variant="outline"
              :disabled="lookingUp"
              @click="onRegisterTyped"
            />
          </div>
          <UAlert
            v-if="canRegisterTyped"
            color="neutral"
            variant="subtle"
            title="A consulta pública pode não conhecer este CNPJ"
            :description="entry === 'manual'
              ? 'A fonte pública não consulta CNPJ alfanumérico. Cadastre com a razão social digitada; os dados públicos do CNPJ ficam vazios.'
              : 'Se a consulta não trouxer nada, cadastre com a razão social digitada; os dados públicos do CNPJ ficam vazios.'"
          />
        </template>

        <template v-else>
          <UAlert
            v-if="preview"
            color="success"
            variant="subtle"
            :title="preview.name"
            :description="`${preview.trade_name ?? ''} · ${preview.registration_status ?? 'Situação desconhecida'} · ${preview.primary_activity_description ?? ''}`.trim()"
          />

          <UCard v-if="preview" variant="subtle">
            <template #header>
              <span class="text-sm font-medium">Dados da Receita</span>
            </template>
            <dl class="space-y-1 text-sm">
              <div class="flex justify-between gap-4">
                <dt class="text-muted">
                  Razão social
                </dt>
                <dd class="text-right font-medium">
                  {{ preview.name }}
                </dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">
                  Fantasia
                </dt>
                <dd class="text-right">
                  {{ preview.trade_name ?? '—' }}
                </dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">
                  Situação
                </dt>
                <dd class="text-right">
                  {{ preview.registration_status ?? '—' }}
                </dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">
                  Atividade
                </dt>
                <dd class="text-right">
                  {{ preview.primary_activity_description ?? '—' }}
                </dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted">
                  Endereço
                </dt>
                <dd class="text-right">
                  {{ [preview.street, preview.address_number, preview.city, preview.state].filter(Boolean).join(', ') || '—' }}
                </dd>
              </div>
            </dl>
          </UCard>

          <UForm
            id="client-create-form"
            :schema="schema"
            :state="state as unknown as Partial<Schema>"
            class="space-y-4"
            @submit="onSubmit"
          >
            <UFormField
              v-if="typedNameRequired"
              label="Razão social"
              name="name"
              help="A consulta não trouxe os dados: informe a razão social"
            >
              <UInput v-model="state.name" placeholder="Nome da empresa" class="w-full" />
            </UFormField>

            <UFormField
              v-if="state.person_type === 'individual'"
              label="CPF"
              name="tax_id"
              help="Somente números"
            >
              <UInput v-model="state.tax_id" placeholder="000.000.000-00" class="w-full" />
            </UFormField>

            <UFormField
              v-if="state.person_type === 'individual'"
              label="Nome completo"
              name="name"
            >
              <UInput v-model="state.name" placeholder="Nome do cliente" class="w-full" />
            </UFormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <UFormField label="Situação" name="status">
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
                :help="regimeLocked ? 'Definido pela Receita (MEI/Simples)' : undefined"
              >
                <USelect
                  v-model="state.tax_regime"
                  :items="regimeOptions"
                  value-key="value"
                  class="w-full"
                  :disabled="!!regimeLocked"
                />
              </UFormField>
              <UFormField
                v-else
                label="Regime tributário"
                name="tax_regime"
              >
                <UInput value="Não aplicável" class="w-full" disabled />
              </UFormField>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <UFormField label="Email" name="email">
                <UInput
                  v-model="state.email"
                  type="email"
                  placeholder="contato@empresa.com"
                  class="w-full"
                />
              </UFormField>
              <UFormField label="Telefone" name="phone">
                <UInput v-model="state.phone" placeholder="(00) 00000-0000" class="w-full" />
              </UFormField>
            </div>

            <template v-if="state.person_type === 'individual'">
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <UFormField label="Tipo logradouro" name="street_type">
                  <UInput v-model="state.street_type" class="w-full" />
                </UFormField>
                <UFormField label="Logradouro" name="street" class="sm:col-span-2">
                  <UInput v-model="state.street" class="w-full" />
                </UFormField>
              </div>
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <UFormField label="Número" name="address_number">
                  <UInput v-model="state.address_number" class="w-full" />
                </UFormField>
                <UFormField label="Complemento" name="address_complement">
                  <UInput v-model="state.address_complement" class="w-full" />
                </UFormField>
              </div>
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <UFormField label="Bairro" name="district">
                  <UInput v-model="state.district" class="w-full" />
                </UFormField>
                <UFormField label="CEP" name="postal_code">
                  <UInput v-model="state.postal_code" class="w-full" />
                </UFormField>
              </div>
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <UFormField label="Cidade" name="city" class="sm:col-span-2">
                  <UInput v-model="state.city" class="w-full" />
                </UFormField>
                <UFormField label="UF" name="state">
                  <UInput v-model="state.state" maxlength="2" class="w-full" />
                </UFormField>
              </div>
            </template>
          </UForm>
        </template>
      </div>
    </template>

    <template #footer="{ close }">
      <UButton
        v-if="step === 2 && state.person_type === 'company'"
        label="Voltar"
        color="neutral"
        variant="subtle"
        type="button"
        @click="step = 1"
      />
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <UButton
        v-if="step === 2 || state.person_type === 'individual'"
        label="Cadastrar cliente"
        type="submit"
        form="client-create-form"
        :loading="submitting"
      />
    </template>
  </UModal>
</template>
