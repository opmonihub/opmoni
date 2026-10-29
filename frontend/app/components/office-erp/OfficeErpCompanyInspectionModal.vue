<script setup lang="ts">
import type { OfficeErpCommandResult, OfficeErpCompany, OfficeErpCompanyProfile } from '#shared/types/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { formatTaxId } from '~/utils/taxId'
import { formatOfficeErpCompanyDate } from '~/utils/officeErpContractAmendment'

const open = defineModel<boolean>('open', { required: true })
const restoreModalFocus = useModalFocusReturn(open)
const props = defineProps<{ companyId: number | null }>()
const emit = defineEmits<{ updated: [] }>()
const { api } = useAccessApi()
const command = useOfficeErpCommand()
const context = useOfficeErpContext()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null, props.companyId], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const company = ref<OfficeErpCompany | null>(null)
const baseline = ref<OfficeErpCompanyProfile | null>(null)
const draft = reactive<OfficeErpCompanyProfile>({ name: '', trade_name: null, tax_regime: 'other', contact_person: null, email: null, phone: null, whatsapp: null, street_type: null, street: null, address_number: null, address_complement: null, district: null, postal_code: null, city: null, state: null, ie: null })
const profileVersion = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const profileConflict = ref(false)
const editing = ref(false)
const discardOpen = ref(false)
const closeAfterDiscard = ref(false)
let generation = 0
let mutationGeneration = 0
const profileFields: Array<{ key: keyof OfficeErpCompanyProfile, label: string, type?: string, wide?: boolean }> = [
  { key: 'name', label: 'Razão social' }, { key: 'trade_name', label: 'Nome fantasia' },
  { key: 'tax_regime', label: 'Regime tributário' }, { key: 'ie', label: 'Inscrição estadual' },
  { key: 'contact_person', label: 'Pessoa de contato' }, { key: 'email', label: 'E-mail', type: 'email' },
  { key: 'phone', label: 'Telefone', type: 'tel' }, { key: 'whatsapp', label: 'WhatsApp', type: 'tel' },
  { key: 'street_type', label: 'Tipo de logradouro' }, { key: 'street', label: 'Logradouro', wide: true },
  { key: 'address_number', label: 'Número' }, { key: 'address_complement', label: 'Complemento' },
  { key: 'district', label: 'Bairro' }, { key: 'postal_code', label: 'CEP' },
  { key: 'city', label: 'Município' }, { key: 'state', label: 'UF' }
]
const profileSections = [
  { title: 'Dados cadastrais', icon: 'i-lucide-building-2', fields: profileFields.slice(0, 4) },
  { title: 'Contatos', icon: 'i-lucide-contact-round', fields: profileFields.slice(4, 8) },
  { title: 'Endereço', icon: 'i-lucide-map-pin', fields: profileFields.slice(8) }
]
const taxRegimes = [{ label: 'MEI', value: 'mei' }, { label: 'Simples Nacional', value: 'simple_national' }, { label: 'Lucro presumido', value: 'presumed_profit' }, { label: 'Lucro real', value: 'actual_profit' }, { label: 'Outro', value: 'other' }]
const hasChanges = computed(() => !!baseline.value && Object.keys(draft).some(key => draft[key as keyof OfficeErpCompanyProfile] !== baseline.value![key as keyof OfficeErpCompanyProfile]))
const profileFacts = computed(() => profileSections.map((section) => {
  const facts = section.fields.map(field => ({ label: field.label, value: profileValue(field.key), wide: false }))
  if (section.title === 'Dados cadastrais') {
    facts.splice(2, 0, { label: 'CNPJ', value: formatTaxId(company.value?.cnpj ?? ''), wide: false })
    facts.push({ label: 'Cadastro no ERP', value: formatOfficeErpCompanyDate(company.value?.registered_at ?? ''), wide: false })
  }
  if (section.title === 'Endereço') {
    const profile = baseline.value
    const street = [profile?.street_type, profile?.street].filter(Boolean).join(' ')
    return { ...section, facts: [
      { label: 'Logradouro e número', value: street ? `${street}, ${profile?.address_number || 's/n'}` : 'Não informado', wide: true },
      { label: 'Complemento', value: profileValue('address_complement'), wide: false },
      { label: 'Bairro', value: profileValue('district'), wide: false },
      { label: 'Cidade / UF', value: [profile?.city, profile?.state].filter(Boolean).join(' / ') || 'Não informado', wide: false },
      { label: 'CEP', value: profileValue('postal_code'), wide: false }
    ] }
  }
  return { ...section, facts }
}))

function profileValue(key: keyof OfficeErpCompanyProfile) {
  const value = baseline.value?.[key]
  return (key === 'tax_regime' ? taxRegimes.find(item => item.value === value)?.label : value) || 'Não informado'
}
function requestClose(closeModal = true) {
  if (saving.value || command.pending.value) return
  if (editing.value && hasChanges.value && !command.recoveryKey.value) {
    closeAfterDiscard.value = closeModal
    discardOpen.value = true
    return
  }
  if (closeModal) close()
  else editing.value = false
}
function discardChanges() {
  if (baseline.value) assignProfile(baseline.value)
  discardOpen.value = false
  editing.value = false
  if (closeAfterDiscard.value) close()
}

function assignProfile(profile: OfficeErpCompanyProfile) {
  baseline.value = { ...profile }
  Object.assign(draft, profile)
}
function updateField(key: keyof OfficeErpCompanyProfile, value: string | number | null) {
  if (key === 'tax_regime') return
  Object.assign(draft, { [key]: value === '' ? null : value })
  fieldErrors.value = Object.fromEntries(Object.entries(fieldErrors.value).filter(([field]) => field !== key))
  profileConflict.value = false
}
function changes() {
  const patch: Partial<OfficeErpCompanyProfile> = {}
  for (const key of Object.keys(draft) as Array<keyof OfficeErpCompanyProfile>) {
    const value = draft[key]
    const normalized = typeof value === 'string' && value.trim() === '' ? null : value
    if (normalized !== baseline.value?.[key]) Object.assign(patch, { [key]: normalized })
  }
  return patch
}
async function load() {
  if (!open.value || !props.companyId) return
  const read = reads.begin('companyInspection')
  const request = ++generation
  const identity = identityStamp.value
  loading.value = true
  error.value = ''
  notice.value = ''
  fieldErrors.value = {}
  profileConflict.value = false
  try {
    await context.refresh()
    if (!read.current() || request !== generation || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) throw new Error('A conta selecionada não pode consultar empresas comerciais.')
    const token = context.capture()
    const response = await api<{ data: OfficeErpCompany & { profile: OfficeErpCompanyProfile, profile_version: string } }>('/erp-commercial/companies/' + props.companyId, { signal: read.signal })
    if (!read.current() || request !== generation || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (response.data?.id !== props.companyId || !response.data.profile || !/^[a-f\d]{64}$/i.test(response.data.profile_version)) throw new Error('O detalhe cadastral da empresa está incompleto.')
    company.value = response.data
    profileVersion.value = response.data.profile_version
    assignProfile(response.data.profile)
  } catch (caught) {
    if (read.current() && request === generation && identity === identityStamp.value) error.value = apiErrorMessage(caught) ?? (caught instanceof Error ? caught.message : 'Não foi possível consultar a empresa.')
  } finally {
    if (read.current() && request === generation && identity === identityStamp.value) loading.value = false
  }
}
async function save() {
  if (!company.value || !profileVersion.value || saving.value || command.pending.value) return
  const targetCompanyId = company.value.id
  const mutation = ++mutationGeneration
  const expectedVersion = profileVersion.value
  error.value = ''
  fieldErrors.value = {}
  profileConflict.value = false
  notice.value = ''
  const patch = changes()
  if (!Object.keys(patch).length) return
  if (Object.hasOwn(patch, 'name') && (typeof patch.name !== 'string' || patch.name.trim().length < 10 || patch.name.trim().length > 255)) {
    error.value = 'A razão social deve ter entre 10 e 255 caracteres.'
    return
  }
  saving.value = true
  try {
    const response = await command.execute<OfficeErpCompany & { profile: OfficeErpCompanyProfile, profile_version: string }>('company.profile.update', '/erp-commercial/companies/' + targetCompanyId, { expected_profile_version: expectedVersion, ...patch }, 'PATCH') as OfficeErpCommandResult<OfficeErpCompany & { profile: OfficeErpCompanyProfile, profile_version: string }>
    if (mutation !== mutationGeneration || !open.value || props.companyId !== targetCompanyId) return
    if (response.status === 'confirmed') {
      if (response.data?.id !== targetCompanyId || !response.data.profile || !/^[a-f\d]{64}$/i.test(response.data.profile_version)) {
        error.value = 'A operação foi confirmada. Atualize a empresa selecionada para conferir o estado atual.'
        await load()
        return
      }
      company.value = response.data
      profileVersion.value = response.data.profile_version
      assignProfile(response.data.profile)
      editing.value = false
      notice.value = 'Cadastro atualizado.'
      emit('updated')
    } else if (response.status === 'unknown') notice.value = 'O resultado não foi confirmado. Consulte a operação ' + response.key + ' antes de reenviar.'
    else if (response.status === 'failed') {
      profileConflict.value = response.failure.http_status === 409
      fieldErrors.value = response.failure.errors ?? {}
      error.value = profileConflict.value ? 'Os dados foram alterados em outra sessão. Seu rascunho foi preservado; recarregue explicitamente antes de reaplicar as mudanças.' : response.failure.message
    } else error.value = 'O contexto do escritório mudou. A alteração não foi repetida.'
  } finally {
    if (mutation === mutationGeneration) saving.value = false
  }
}
async function recover() {
  const key = command.recoveryKey.value
  const targetCompanyId = company.value?.id
  if (!key || !targetCompanyId || saving.value) return
  const mutation = ++mutationGeneration
  saving.value = true
  try {
    const result = await command.recover<unknown>(key)
    if (mutation !== mutationGeneration || !open.value || props.companyId !== targetCompanyId) return
    if (result.status === 'confirmed') {
      const recovered = result.data as (OfficeErpCompany & { profile?: OfficeErpCompanyProfile, profile_version?: string }) | null
      if (recovered?.id === targetCompanyId && recovered.profile && typeof recovered.profile_version === 'string' && /^[a-f\d]{64}$/i.test(recovered.profile_version)) {
        company.value = recovered
        profileVersion.value = recovered.profile_version
        assignProfile(recovered.profile)
        notice.value = 'Alteração confirmada e recuperada.'
      } else {
        notice.value = 'A operação foi confirmada. Atualize a empresa selecionada para conferir os dados atuais.'
        await load()
      }
      emit('updated')
    } else if (result.status === 'unknown') notice.value = 'A operação ' + key + ' ainda não está disponível para confirmação.'
    else error.value = result.status === 'failed' ? result.failure.message : 'A operação pertence a outro contexto.'
  } finally {
    if (mutation === mutationGeneration) saving.value = false
  }
}
function close() {
  mutationGeneration++
  saving.value = false
  open.value = false
  company.value = null
  baseline.value = null
  profileVersion.value = ''
  error.value = ''
  fieldErrors.value = {}
  profileConflict.value = false
  notice.value = ''
  editing.value = false
  discardOpen.value = false
}
watch([open, () => props.companyId], ([isOpen, currentCompanyId], [wasOpen, previousCompanyId]) => {
  mutationGeneration++
  if (wasOpen && isOpen && currentCompanyId !== previousCompanyId) {
    editing.value = false
    discardOpen.value = false
    company.value = null
    baseline.value = null
    profileVersion.value = ''
    saving.value = false
    fieldErrors.value = {}
    profileConflict.value = false
  }
  if (isOpen) void load()
  else {
    editing.value = false
    discardOpen.value = false
    generation++
    reads.cancel('companyInspection')
    company.value = null
    baseline.value = null
    profileVersion.value = ''
    error.value = ''
    notice.value = ''
    fieldErrors.value = {}
    profileConflict.value = false
    saving.value = false
  }
}, { immediate: true })
watch(identityStamp, () => {
  editing.value = false
  discardOpen.value = false
  generation++
  mutationGeneration++
  saving.value = false
  company.value = null
  baseline.value = null
  profileVersion.value = ''
  error.value = ''
  notice.value = ''
  fieldErrors.value = {}
  profileConflict.value = false
  if (open.value) void load()
}, { flush: 'sync' })
onScopeDispose(() => {
  generation++
  mutationGeneration++
  reads.cancel('companyInspection')
  loading.value = false
  saving.value = false
  company.value = null
  baseline.value = null
  profileVersion.value = ''
  Object.assign(draft, { name: '', trade_name: null, tax_regime: 'other', contact_person: null, email: null, phone: null, whatsapp: null, street_type: null, street: null, address_number: null, address_complement: null, district: null, postal_code: null, city: null, state: null, ie: null })
  error.value = ''
  notice.value = ''
  fieldErrors.value = {}
  profileConflict.value = false
})
</script>

<template>
  <UModal
    :open="open"
    :title="editing ? 'Editar cadastro da empresa' : 'Cadastro da empresa'"
    :description="company?.name ?? 'Consulte os dados cadastrais, contatos e endereço.'"
    :dismissible="!saving && !command.pending.value"
    :close="!saving && !command.pending.value"
    :ui="{
      content: 'max-w-4xl max-h-[calc(100dvh-2rem)] flex flex-col overflow-hidden',
      header: 'shrink-0',
      body: 'min-h-0 flex-1 overflow-y-auto',
      footer: 'shrink-0 flex-wrap justify-end'
    }"
    @update:open="value => { if (!value) requestClose() }"
    @after:leave="restoreModalFocus"
  >
    <template #body>
      <div class="space-y-4">
        <USkeleton v-if="loading && !company" class="h-24 w-full" />
        <UAlert
          v-if="error"
          color="error"
          title="Não foi possível concluir"
          :description="error"
        >
          <template #actions>
            <UButton
              v-if="profileConflict"
              color="neutral"
              variant="outline"
              :loading="loading"
              @click="load"
            >
              Recarregar dados
            </UButton>
          </template>
        </UAlert>
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
              @click="recover"
            >
              Recuperar operação
            </UButton>
          </template>
        </UAlert>
        <template v-if="company">
          <UForm
            v-if="editing"
            id="company-profile"
            :state="draft"
            class="divide-y divide-default"
            @submit="save"
          >
            <section v-for="section in profileSections" :key="section.title" class="space-y-4 py-6 first:pt-0 last:pb-0">
              <h3 class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon :name="section.icon" class="size-4 text-muted" />
                {{ section.title }}
              </h3>
              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField
                  v-for="field in section.fields"
                  :key="field.key"
                  :label="field.label"
                  :name="field.key"
                  :required="field.key === 'name'"
                  :error="fieldErrors[field.key]?.[0]"
                  :class="field.wide ? 'sm:col-span-2' : ''"
                >
                  <USelect
                    v-if="field.key === 'tax_regime'"
                    v-model="draft.tax_regime"
                    :items="taxRegimes"
                    :disabled="!company.capabilities.can_edit_profile || saving"
                    class="w-full"
                  />
                  <UInput
                    v-else
                    :model-value="draft[field.key] ?? ''"
                    :type="field.type ?? 'text'"
                    :maxlength="field.key === 'name' || field.key === 'trade_name' ? 255 : undefined"
                    :disabled="!company.capabilities.can_edit_profile || saving"
                    class="w-full"
                    @update:model-value="value => updateField(field.key, value)"
                  />
                </UFormField>
                <UFormField v-if="section.title === 'Dados cadastrais'" label="CNPJ" description="O CNPJ identifica a empresa e não pode ser alterado aqui.">
                  <UInput :model-value="formatTaxId(company.cnpj)" readonly class="w-full" />
                </UFormField>
              </div>
            </section>
          </UForm>
          <div v-else class="divide-y divide-default">
            <section v-for="section in profileFacts" :key="section.title" class="space-y-5 py-6 first:pt-0 last:pb-0">
              <h3 class="flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon :name="section.icon" class="size-4 text-muted" />
                {{ section.title }}
              </h3>
              <dl class="grid grid-cols-2 gap-x-5 gap-y-6 text-sm sm:grid-cols-3">
                <div v-for="fact in section.facts" :key="fact.label" :class="fact.wide ? 'col-span-2 sm:col-span-3' : 'min-w-0'">
                  <dt class="text-xs text-muted">
                    {{ fact.label }}
                  </dt>
                  <dd class="mt-1 font-medium wrap-anywhere text-highlighted">
                    {{ fact.value }}
                  </dd>
                </div>
              </dl>
            </section>
          </div>
        </template>
      </div>
    </template>
    <template #footer>
      <template v-if="editing">
        <UButton
          color="neutral"
          variant="outline"
          :disabled="saving"
          @click="requestClose(false)"
        >
          Cancelar edição
        </UButton>
        <UButton
          form="company-profile"
          type="submit"
          :disabled="!company?.capabilities.can_edit_profile || !hasChanges || saving || command.pending.value"
          :loading="saving"
        >
          Salvar perfil
        </UButton>
      </template>
      <template v-else>
        <UButton color="neutral" variant="outline" @click="requestClose()">
          Fechar
        </UButton>
        <UButton
          v-if="company?.capabilities.can_edit_profile"
          color="neutral"
          variant="outline"
          icon="i-lucide-pencil"
          @click="editing = true"
        >
          Editar cadastro
        </UButton>
        <UButton
          v-if="company && company.capabilities.relationship !== 'unassigned'"
          :to="`/gestao-erp/assinaturas/empresas/${company.id}`"
          trailing-icon="i-lucide-arrow-right"
          @click="close"
        >
          Ver assinatura
        </UButton>
      </template>
    </template>
  </UModal>
  <UModal
    v-model:open="discardOpen"
    title="Descartar alterações?"
    description="As alterações no cadastro ainda não foram salvas."
    :ui="{ footer: 'justify-end' }"
  >
    <template #footer>
      <UButton color="neutral" variant="outline" @click="discardOpen = false">
        Continuar editando
      </UButton>
      <UButton color="error" @click="discardChanges">
        Descartar alterações
      </UButton>
    </template>
  </UModal>
</template>
