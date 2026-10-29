<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { OfficeErpCommandResult } from '#shared/types/officeErpCommercial'
import { apiFailure, passwordLength } from '#shared/utils/access'
import { apiErrorMessage } from '~/composables/useApiError'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'

definePageMeta({ middleware: 'auth' })
interface MemberDetail {
  kind: 'membership'
  id: number
  user: { id: number, name: string, email: string }
  access_auth_revision: number
  role: string
  status: string
  credential_capabilities: {
    can_direct_reset: boolean
    can_email_recovery: boolean
    direct_reset_unavailable_reason: null | 'protected_identity'
  }
}
interface AccessDelivery { id: number, state: 'prepared' | 'sending' | 'transport_accepted' | 'failed' | 'unknown' | 'expired', expires_at: string, authority_invalidated_at: string | null }
const route = useRoute()
const companyId = computed(() => typeof route.params.companyId === 'string' && /^[1-9]\d{0,14}$/.test(route.params.companyId) ? route.params.companyId : '')
const membershipId = computed(() => typeof route.params.membershipId === 'string' && /^[1-9]\d{0,14}$/.test(route.params.membershipId) ? route.params.membershipId : '')
const { api } = useAccessApi()
const context = useOfficeErpContext()
const command = useOfficeErpCommand()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null, companyId.value, membershipId.value], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
function roleLabel(role: string) {
  return ({ owner: 'Proprietário', manager: 'Gerente', operator: 'Operador', viewer: 'Leitor' } as Record<string, string>)[role] ?? role
}
const member = ref<MemberDetail | null>(null)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const delivery = ref<AccessDelivery | null>(null)
const deliveryLoading = ref(false)
const deliveryError = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)
const action = ref<'reset' | 'recovery' | null>(null)
let mutationGeneration = 0
const schema = z.object({ password: z.string().refine(value => passwordLength(value) >= 8, 'Use ao menos 8 caracteres.').refine(value => passwordLength(value) <= 128, 'Use no máximo 128 caracteres.'), password_confirmation: z.string().min(1, 'Confirme a senha.') }).refine(value => value.password === value.password_confirmation, { path: ['password_confirmation'], message: 'As senhas não coincidem.' })
type PasswordForm = z.output<typeof schema>
async function loadMember() {
  if (!companyId.value || !membershipId.value) {
    error.value = 'Identificador inválido.'
    return
  }
  const read = reads.begin('memberRecoveryTarget')
  const identity = identityStamp.value
  loading.value = true
  error.value = ''
  try {
    await context.refresh()
    if (!read.current() || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_maintain) {
      error.value = 'A conta selecionada não pode administrar acessos comerciais.'
      return
    }
    const token = context.capture()
    const response = await api<{ data: MemberDetail }>(`/erp-commercial/companies/${companyId.value}/members/${membershipId.value}`, { signal: read.signal })
    if (!read.current() || identity !== identityStamp.value || !context.isCurrent(token)) return
    const credentialCapabilities = response.data?.credential_capabilities
    if (response.data?.kind !== 'membership' || response.data.id !== Number(membershipId.value) || !Number.isSafeInteger(response.data.access_auth_revision) || typeof credentialCapabilities?.can_direct_reset !== 'boolean' || typeof credentialCapabilities.can_email_recovery !== 'boolean' || ![null, 'protected_identity'].includes(credentialCapabilities.direct_reset_unavailable_reason)) throw new Error('O detalhe do usuário não contém capacidades de credencial válidas.')
    member.value = response.data
  } catch (caught) {
    if (read.current() && identity === identityStamp.value) error.value = apiErrorMessage(caught) ?? 'Não foi possível consultar o usuário.'
  } finally {
    if (read.current() && identity === identityStamp.value) loading.value = false
  }
}
async function directReset(event: FormSubmitEvent<PasswordForm>) {
  if (!member.value?.credential_capabilities.can_direct_reset || busy.value || command.pending.value) return
  const target = member.value
  const targetCompanyId = companyId.value
  const targetMembershipId = membershipId.value
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  busy.value = true
  error.value = ''
  notice.value = ''
  const body = { expected_auth_revision: target.access_auth_revision, password: event.data.password, password_confirmation: event.data.password_confirmation }
  password.value = ''
  passwordConfirmation.value = ''
  try {
    const result = await command.execute<{ company_id: number, membership_id: number, auth_revision: number }>('member.password.reset', `/erp-commercial/companies/${targetCompanyId}/members/${targetMembershipId}/password-reset`, body) as OfficeErpCommandResult<{ company_id: number, membership_id: number, auth_revision: number }>
    if (mutation !== mutationGeneration || identity !== identityStamp.value || companyId.value !== targetCompanyId || membershipId.value !== targetMembershipId) return
    if (result.status === 'confirmed') {
      if (result.data.company_id === Number(targetCompanyId) && result.data.membership_id === Number(targetMembershipId) && Number.isSafeInteger(result.data.auth_revision)) {
        notice.value = 'Senha atualizada. O usuário precisará entrar novamente.'
        member.value = { ...target, access_auth_revision: result.data.auth_revision }
      } else {
        notice.value = 'A operação foi confirmada. Atualize os dados do usuário selecionado para conferir o estado atual.'
        await loadMember()
      }
    } else if (result.status === 'unknown') notice.value = `O resultado não foi confirmado. Consulte a operação ${result.key}; não reenvie a senha automaticamente.`
    else if (result.status === 'failed') error.value = result.failure.message
    else error.value = 'O contexto da empresa mudou. A operação não foi repetida.'
  } catch (caught) {
    if (mutation !== mutationGeneration || identity !== identityStamp.value || companyId.value !== targetCompanyId || membershipId.value !== targetMembershipId) return
    error.value = apiFailure(caught).message || 'Não foi possível atualizar a senha.'
  } finally {
    if (mutation === mutationGeneration) {
      password.value = ''
      passwordConfirmation.value = ''
      busy.value = false
    }
  }
}
async function sendRecovery() {
  if (!member.value?.credential_capabilities.can_email_recovery || busy.value || command.pending.value) return
  const targetCompanyId = companyId.value
  const targetMembershipId = membershipId.value
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  busy.value = true
  action.value = 'recovery'
  error.value = ''
  notice.value = ''
  try {
    const result = await command.execute<{ company_id: number, membership_id: number, delivery_id: number, delivery_state: AccessDelivery['state'] }>('member.password.recovery', `/erp-commercial/companies/${targetCompanyId}/members/${targetMembershipId}/recovery`, {}) as OfficeErpCommandResult<{ company_id: number, membership_id: number, delivery_id: number, delivery_state: AccessDelivery['state'] }>
    if (mutation !== mutationGeneration || identity !== identityStamp.value || companyId.value !== targetCompanyId || membershipId.value !== targetMembershipId) return
    if (result.status === 'confirmed') {
      if (result.data.company_id === Number(targetCompanyId) && result.data.membership_id === Number(targetMembershipId) && Number.isSafeInteger(result.data.delivery_id)) {
        delivery.value = { id: result.data.delivery_id, state: result.data.delivery_state, expires_at: '', authority_invalidated_at: null }
        notice.value = 'Solicitação criada para processamento. Consulte o estado da entrega antes de considerar o e-mail aceito.'
      } else {
        notice.value = 'A operação foi confirmada. Atualize o estado do usuário selecionado.'
        await loadMember()
      }
    } else if (result.status === 'unknown') notice.value = `O envio não foi confirmado. Consulte a operação ${result.key}; nenhuma nova mensagem será enviada automaticamente.`
    else if (result.status === 'failed') error.value = result.failure.message
    else error.value = 'O contexto da empresa mudou. A operação não foi repetida.'
  } finally {
    if (mutation === mutationGeneration) {
      busy.value = false
      action.value = null
    }
  }
}
function deliveryLabel(state: AccessDelivery['state']) {
  if (state === 'prepared') return 'Preparada, aguardando envio'
  if (state === 'sending') return 'Em envio'
  if (state === 'transport_accepted') return 'Aceita pelo serviço de e-mail; entrega final não confirmada'
  if (state === 'failed') return 'Falha no envio'
  if (state === 'unknown') return 'Resultado do envio desconhecido'
  return 'Expirada'
}
async function loadDelivery() {
  if (!delivery.value || deliveryLoading.value || !companyId.value) return
  const read = reads.begin('accessDelivery')
  const identity = identityStamp.value
  deliveryLoading.value = true
  deliveryError.value = ''
  try {
    await context.refresh()
    if (!read.current() || identity !== identityStamp.value || !context.capabilities.value?.can_maintain) return
    const token = context.capture()
    const response = await api<{ data: AccessDelivery }>(`/erp-commercial/companies/${companyId.value}/access-deliveries/${delivery.value.id}`, { signal: read.signal })
    if (!read.current() || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!response.data || response.data.id !== delivery.value.id) throw new Error('A resposta da entrega não corresponde à operação consultada.')
    delivery.value = response.data
  } catch (caught) {
    if (read.current() && identity === identityStamp.value) deliveryError.value = apiErrorMessage(caught) ?? 'Não foi possível consultar o estado da entrega.'
  } finally {
    if (read.current() && identity === identityStamp.value) deliveryLoading.value = false
  }
}
async function recover() {
  const key = command.recoveryKey.value
  if (!key || busy.value) return
  const identity = identityStamp.value
  const targetCompanyId = companyId.value
  const targetMembershipId = membershipId.value
  const mutation = ++mutationGeneration
  busy.value = true
  try {
    const result = await command.recover<unknown>(key)
    if (mutation !== mutationGeneration || identity !== identityStamp.value || companyId.value !== targetCompanyId || membershipId.value !== targetMembershipId) return
    if (result.status === 'confirmed') {
      const data = result.data as { company_id?: unknown, membership_id?: unknown, auth_revision?: unknown, delivery_id?: unknown, delivery_state?: unknown } | null
      if (data?.company_id === Number(targetCompanyId) && data.membership_id === Number(targetMembershipId) && Number.isSafeInteger(data.auth_revision)) {
        if (member.value) member.value = { ...member.value, access_auth_revision: data.auth_revision as number }
        notice.value = 'Senha atualizada. O usuário precisará entrar novamente.'
      } else if (data?.company_id === Number(targetCompanyId) && data.membership_id === Number(targetMembershipId) && Number.isSafeInteger(data.delivery_id)) {
        const state = data.delivery_state
        if (['prepared', 'sending', 'transport_accepted', 'failed', 'unknown', 'expired'].includes(String(state)))
          delivery.value = { id: data.delivery_id as number, state: state as AccessDelivery['state'], expires_at: '', authority_invalidated_at: null }
        notice.value = 'A operação deste usuário foi confirmada. Consulte o estado atual da entrega.'
      } else {
        notice.value = 'Uma operação foi confirmada. Atualize os dados do usuário selecionado para conferir o estado atual.'
        await loadMember()
      }
    } else if (result.status === 'unknown') notice.value = `A operação ${key} ainda não foi confirmada.`
    else error.value = result.status === 'failed' ? result.failure.message : 'A operação pertence a outro contexto.'
  } finally { if (mutation === mutationGeneration) busy.value = false }
}
watch(identityStamp, () => {
  mutationGeneration++
  busy.value = false
  action.value = null
  member.value = null
  delivery.value = null
  password.value = ''
  passwordConfirmation.value = ''
  error.value = ''
  notice.value = ''
  void loadMember()
}, { flush: 'sync' })
onMounted(() => {
  void loadMember()
})
onBeforeUnmount(() => {
  mutationGeneration++
  password.value = ''
  passwordConfirmation.value = ''
})
</script>

<template>
  <OfficeErpPanel title="Recuperar acesso" description="Altere uma senha diretamente ou envie uma solicitação ao endereço já cadastrado.">
    <template #action>
      <UButton color="neutral" variant="outline" :to="`/gestao-erp/assinaturas/empresas/${companyId}/membros`">
        Voltar aos usuários
      </UButton>
    </template>
    <div class="space-y-4">
      <USkeleton v-if="loading && !member" class="h-20 w-full" />
      <UAlert
        v-if="error"
        color="error"
        title="Não foi possível concluir"
        :description="error"
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            :loading="loading"
            @click="loadMember"
          >
            Tentar novamente
          </UButton>
        </template>
      </UAlert>
      <template v-if="member">
        <section class="rounded-lg border border-default p-4">
          <h2 class="font-medium text-highlighted">
            {{ member.user.name }}
          </h2><p class="text-sm text-muted">
            {{ member.user.email }} · {{ roleLabel(member.role) }}
          </p>
        </section>
        <UAlert
          v-if="!member.credential_capabilities.can_direct_reset"
          color="neutral"
          title="Alteração direta indisponível"
          description="Por proteção, esta identidade não pode receber uma senha definida diretamente nesta tela."
        />
        <UAlert
          v-if="notice || command.recoveryKey.value"
          color="warning"
          title="Estado da operação"
          :description="notice || 'Há uma operação comercial sem confirmação. Consulte o resultado antes de iniciar outra ação.'"
        >
          <template #actions>
            <UButton
              v-if="command.recoveryKey.value"
              color="neutral"
              variant="outline"
              :loading="busy"
              @click="recover"
            >
              Consultar resultado
            </UButton>
          </template>
        </UAlert>
        <section v-if="member.credential_capabilities.can_direct_reset" class="space-y-4 rounded-lg border border-default p-4">
          <div>
            <h2 class="font-medium text-highlighted">
              Definir nova senha
            </h2><p class="mt-1 text-sm text-muted">
              A senha vale para a conta global deste usuário em todas as empresas. A alteração encerra as sessões da conta e exige novo acesso.
            </p>
          </div>
          <UForm
            :schema="schema"
            :state="{ password, password_confirmation: passwordConfirmation }"
            class="space-y-4"
            @submit="directReset"
          >
            <UFormField label="Nova senha" name="password">
              <UInput
                v-model="password"
                type="password"
                autocomplete="new-password"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Confirmar senha" name="password_confirmation">
              <UInput
                v-model="passwordConfirmation"
                type="password"
                autocomplete="new-password"
                class="w-full"
              />
            </UFormField>
            <UButton type="submit" :loading="busy" :disabled="busy || command.pending.value">
              Atualizar senha
            </UButton>
          </UForm>
        </section>
        <section v-if="member.credential_capabilities.can_email_recovery" class="space-y-3 rounded-lg border border-default p-4">
          <div>
            <h2 class="font-medium text-highlighted">
              Enviar recuperação por e-mail
            </h2><p class="mt-1 text-sm text-muted">
              A solicitação usa o endereço já vinculado ao usuário. O sistema não exibe destinatário nem link nesta carteira. A resposta inicial confirma apenas que a solicitação foi preparada.
            </p>
          </div><UButton
            color="neutral"
            variant="outline"
            :loading="busy && action === 'recovery'"
            :disabled="busy || command.pending.value"
            @click="sendRecovery"
          >
            Enviar recuperação
          </UButton>
          <UAlert
            v-if="delivery"
            color="neutral"
            title="Estado da entrega"
            :description="`${deliveryLabel(delivery.state)}${delivery.authority_invalidated_at ? ' · acesso revogado antes do envio' : ''}`"
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="outline"
                :loading="deliveryLoading"
                @click="loadDelivery"
              >
                Consultar estado
              </UButton>
            </template>
          </UAlert>
          <UAlert
            v-if="deliveryError"
            color="error"
            title="Falha ao consultar entrega"
            :description="deliveryError"
          />
        </section>
      </template>
    </div>
  </OfficeErpPanel>
</template>
