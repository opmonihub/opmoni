<script setup lang="ts">
import type { OfficeErpCommandResult, OfficeErpCompany, OfficeErpCompanyProfile } from '#shared/types/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'

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
let generation = 0
let mutationGeneration = 0
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
const hasChanges = computed(() => !!baseline.value && Object.keys(draft).some(key => draft[key as keyof OfficeErpCompanyProfile] !== baseline.value![key as keyof OfficeErpCompanyProfile]))

function assignProfile(profile: OfficeErpCompanyProfile) {
  baseline.value = structuredClone(profile)
  Object.assign(draft, structuredClone(profile))
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
}
watch([open, () => props.companyId], ([isOpen, currentCompanyId], [wasOpen, previousCompanyId]) => {
  mutationGeneration++
  if (wasOpen && isOpen && currentCompanyId !== previousCompanyId) {
    company.value = null
    baseline.value = null
    profileVersion.value = ''
    saving.value = false
    fieldErrors.value = {}
    profileConflict.value = false
  }
  if (isOpen) void load()
  else {
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
    v-model:open="open"
    title="Cadastro da empresa"
    description="Consulte e edite somente os dados próprios da empresa."
    :ui="{ content: 'sm:max-w-4xl', header: 'pr-12' }"
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
          <div class="flex flex-wrap items-center gap-2">
            <UBadge color="neutral" variant="subtle">
              CNPJ {{ company.cnpj }}
            </UBadge>
            <UBadge :color="company.capabilities.relationship === 'current' ? 'success' : company.capabilities.relationship === 'historical' ? 'warning' : 'neutral'" variant="subtle">
              {{ company.capabilities.relationship === 'current' ? 'Com vínculo atual' : company.capabilities.relationship === 'historical' ? 'Com histórico' : 'Sem assinatura' }}
            </UBadge>
          </div>
          <UAlert color="neutral" title="Dados protegidos" description="CNPJ, identidade de usuários e configurações fiscais não são editáveis nesta tela." />
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField
              v-for="field in profileFields"
              :key="field.key"
              :label="field.label"
              :name="field.key"
              :error="fieldErrors[field.key]?.[0]"
              :class="field.wide ? 'sm:col-span-2' : ''"
            >
              <USelect
                v-if="field.key === 'tax_regime'"
                v-model="draft.tax_regime"
                :items="[{ label: 'MEI', value: 'mei' }, { label: 'Simples Nacional', value: 'simple_national' }, { label: 'Lucro presumido', value: 'presumed_profit' }, { label: 'Lucro real', value: 'actual_profit' }, { label: 'Outro', value: 'other' }]"
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
          </div>
        </template>
      </div>
    </template>
    <template #footer>
      <div v-if="company" class="flex flex-wrap justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          :disabled="saving"
          @click="close"
        >
          Fechar
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          :disabled="!hasChanges || saving"
          @click="baseline && assignProfile(baseline)"
        >
          Descartar alterações
        </UButton>
        <UButton :disabled="!company.capabilities.can_edit_profile || !hasChanges || saving || command.pending.value" :loading="saving" @click="save">
          Salvar perfil
        </UButton>
      </div>
    </template>
  </UModal>
</template>
