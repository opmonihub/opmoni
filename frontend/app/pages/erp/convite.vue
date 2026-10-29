<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { apiFailure, passwordLength, retryAfterSeconds } from '#shared/utils/access'
import { accessContinuationRouteCaptureVersion, captureAccessContinuationFromLocation, takeAccessContinuationFragment, takeInvalidAccessContinuationCapture } from '~/utils/accessContinuationFragment'

definePageMeta({ layout: 'access' })
useHead({ meta: [{ name: 'referrer', content: 'no-referrer' }, { name: 'robots', content: 'noindex,nofollow' }] })
if (import.meta.server) useResponseHeader('Cache-Control').value = 'no-store, no-cache, must-revalidate'

interface InvitationData {
  invitation: { id: number, name: string, email: string, role: string, expires_at: string }
  company: { id: number, name: string }
}
interface ContinuationResponse { state: 'missing' | 'invalid' | 'expired' | 'valid' | 'ready' | 'accepted' | 'identity_mismatch' | 'login_required' | 'retryable', purpose?: 'member_access', operation_key?: string, data?: InvitationData }
const { api } = useAccessApi()
const { user, logout } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const identityStamp = computed(() => JSON.stringify([user.value?.id ?? null, user.value?.email?.toLowerCase() ?? null, sessionGeneration.value]))
let initialFragment = import.meta.client
  ? (() => {
      captureAccessContinuationFromLocation('member-invitation')
      return takeAccessContinuationFragment('member-invitation')
    })()
  : null
let initialFragmentInvalid = import.meta.client ? takeInvalidAccessContinuationCapture('member-invitation') : false
const state = ref<ContinuationResponse['state'] | 'loading' | 'retryable' | 'recovering'>('loading')
const invitation = ref<InvitationData | null>(null)
const operationKey = ref('')
const name = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)
const retryAfter = ref(0)
const error = ref('')
const validationError = ref('')
let pendingToken: string | null = null
let initialized = false
let requestId = 0
let outcomeUnknown = false
let retryTimer: ReturnType<typeof setInterval> | null = null
function setRetryAfter(caught: unknown) {
  const seconds = retryAfterSeconds(caught)
  if (retryTimer) clearInterval(retryTimer)
  retryAfter.value = seconds
  retryTimer = seconds > 0
    ? setInterval(() => {
        retryAfter.value = Math.max(0, retryAfter.value - 1)
        if (retryAfter.value === 0 && retryTimer) {
          clearInterval(retryTimer)
          retryTimer = null
        }
      }, 1000)
    : null
}
const schema = z.object({ password: z.string().refine(value => passwordLength(value) >= 8, 'Use ao menos 8 caracteres.').refine(value => passwordLength(value) <= 128, 'Use no máximo 128 caracteres.'), password_confirmation: z.string().min(1, 'Confirme a senha.') }).refine(value => value.password === value.password_confirmation, { path: ['password_confirmation'], message: 'As senhas não coincidem.' })
type PasswordForm = z.output<typeof schema>

function apply(response: ContinuationResponse) {
  state.value = response.state
  invitation.value = response.data ?? null
  operationKey.value = response.operation_key ?? ''
  if (response.state === 'ready' && user.value && invitation.value && invitation.value.invitation.email.toLowerCase() !== user.value.email.toLowerCase()) {
    state.value = 'identity_mismatch'
    invitation.value = null
  }
  if (response.state === 'accepted') outcomeUnknown = false
  if (outcomeUnknown && !['accepted', 'invalid', 'expired'].includes(response.state)) {
    state.value = 'recovering'
    error.value = 'A resposta anterior ainda não foi confirmada. Consulte o status antes de tentar outra vez.'
    operationKey.value = ''
  }
  name.value = invitation.value?.invitation.name ?? ''
}
function setError(caught: unknown) {
  const failure = apiFailure(caught)
  error.value = failure.message
  state.value = failure.status >= 500 || failure.status === 0 || failure.status === 429 ? 'retryable' : failure.status === 410 ? 'expired' : 'invalid'
  if (failure.status === 429) setRetryAfter(caught)
}
async function resolve(token: string) {
  const request = ++requestId
  const identity = identityStamp.value
  error.value = ''
  try {
    const response = await api<ContinuationResponse>('/erp-member-invitations/resolve', { method: 'POST', body: { token } })
    if (request !== requestId || identity !== identityStamp.value) return
    pendingToken = null
    apply(response)
  } catch (caught) {
    if (request !== requestId || identity !== identityStamp.value) return
    const failure = apiFailure(caught)
    if (failure.status === 429) {
      error.value = failure.message || 'Muitas tentativas. Aguarde antes de consultar novamente.'
      state.value = 'retryable'
      setRetryAfter(caught)
    } else setError(caught)
    if (state.value === 'invalid' || state.value === 'expired') pendingToken = null
  } finally {
    if (request === requestId && identity === identityStamp.value && state.value !== 'retryable') pendingToken = null
  }
}
async function loadCurrent() {
  const request = ++requestId
  const identity = identityStamp.value
  error.value = ''
  try {
    const response = await api<ContinuationResponse>('/erp-member-invitations/current')
    if (request === requestId && identity === identityStamp.value) apply(response)
  } catch (caught) {
    if (request === requestId && identity === identityStamp.value) setError(caught)
  }
}
async function start() {
  if (!import.meta.client || initialized) return
  initialized = true
  if (initialFragmentInvalid) {
    initialFragmentInvalid = false
    state.value = 'invalid'
    return
  }
  const token = initialFragment?.kind === 'member-invitation' ? initialFragment.token : null
  initialFragment = null
  if (token) {
    pendingToken = token
    await resolve(token)
  } else await loadCurrent()
}
async function retry() {
  if (busy.value || retryAfter.value > 0) return
  busy.value = true
  try {
    if (outcomeUnknown) await loadCurrent()
    else if (pendingToken) await resolve(pendingToken)
    else await loadCurrent()
  } finally {
    busy.value = false
  }
}
async function accept(event: FormSubmitEvent<PasswordForm>) {
  if (busy.value || !operationKey.value) return
  busy.value = true
  const request = ++requestId
  const identity = identityStamp.value
  error.value = ''
  validationError.value = ''
  const body = !user.value ? { name: name.value.trim(), password: event.data.password, password_confirmation: event.data.password_confirmation } : {}
  password.value = ''
  passwordConfirmation.value = ''
  outcomeUnknown = true
  state.value = 'recovering'
  try {
    await api('/erp-member-invitations/accept', { method: 'POST', headers: { 'Idempotency-Key': operationKey.value }, body })
    if (request !== requestId || identity !== identityStamp.value) return
    outcomeUnknown = false
    await loadCurrent()
  } catch (caught) {
    if (request !== requestId || identity !== identityStamp.value) return
    const failure = apiFailure(caught)
    if (failure.status === 429) {
      error.value = failure.message
      state.value = 'recovering'
      setRetryAfter(caught)
    } else if (failure.status > 0 && failure.status < 500) {
      outcomeUnknown = false
      error.value = failure.message
      state.value = failure.status === 410 ? 'expired' : failure.status === 422 ? 'valid' : failure.status === 401 ? 'login_required' : 'invalid'
      validationError.value = failure.errors?.password?.[0] ?? ''
    } else {
      error.value = 'Não foi possível confirmar o resultado. Consulte o status da operação antes de reenviar.'
      state.value = 'recovering'
    }
  } finally {
    if (request === requestId) {
      password.value = ''
      passwordConfirmation.value = ''
      busy.value = false
    }
  }
}
function passwordSubmit(event: FormSubmitEvent<PasswordForm>) {
  return accept(event)
}
function openLogin() {
  return navigateTo('/login', { replace: true })
}
async function switchIdentity() {
  try {
    await logout()
  } catch (caught) {
    error.value = apiFailure(caught).message
  }
}
watch(identityStamp, () => {
  requestId++
  outcomeUnknown = false
  pendingToken = null
  operationKey.value = ''
  invitation.value = null
  state.value = 'loading'
  password.value = ''
  passwordConfirmation.value = ''
  if (initialized) void loadCurrent()
})
watch(accessContinuationRouteCaptureVersion('member-invitation'), () => {
  const fragment = takeAccessContinuationFragment('member-invitation')
  const invalidFragment = takeInvalidAccessContinuationCapture('member-invitation')
  requestId++
  outcomeUnknown = false
  pendingToken = fragment?.kind === 'member-invitation' ? fragment.token : null
  operationKey.value = ''
  invitation.value = null
  name.value = ''
  error.value = ''
  validationError.value = ''
  password.value = ''
  passwordConfirmation.value = ''
  busy.value = false
  state.value = 'loading'
  if (retryTimer) clearInterval(retryTimer)
  retryTimer = null
  retryAfter.value = 0
  if (invalidFragment) {
    state.value = 'invalid'
    return
  }
  if (!initialized) return
  if (pendingToken) void resolve(pendingToken)
  else void loadCurrent()
})
onMounted(() => {
  void start()
})
onBeforeUnmount(() => {
  requestId++
  if (retryTimer) clearInterval(retryTimer)
  initialFragment = null
  password.value = ''
  passwordConfirmation.value = ''
  pendingToken = null
})
</script>

<template>
  <UPageCard class="w-full max-w-xl">
    <div class="space-y-5">
      <header>
        <h1 class="text-xl font-semibold text-highlighted">
          Acesso à empresa
        </h1><p class="mt-1 text-sm text-muted">
          Aceite o convite para acessar uma empresa do ERP.
        </p>
      </header>
      <USkeleton v-if="state === 'loading'" class="h-28 w-full" />
      <UAlert
        v-else-if="state === 'retryable'"
        color="error"
        title="Não foi possível validar o convite"
        :description="error || 'Confira sua conexão e tente novamente.'"
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            :loading="busy"
            :disabled="retryAfter > 0"
            @click="retry"
          >
            {{ retryAfter > 0 ? `Tentar novamente em ${retryAfter}s` : 'Tentar novamente' }}
          </UButton>
        </template>
      </UAlert>
      <UAlert
        v-else-if="state === 'invalid' || state === 'expired' || state === 'missing'"
        color="warning"
        title="Convite indisponível"
        description="Este convite expirou, foi revogado ou não pode mais ser usado. Solicite um novo convite à administração da empresa."
      />
      <UAlert
        v-else-if="state === 'identity_mismatch'"
        color="warning"
        title="Entre com o e-mail convidado"
        description="O convite pertence a outra identidade. Entre com a conta que recebeu o convite ou troque de conta antes de continuar."
      >
        <template #actions>
          <div class="flex flex-wrap gap-2">
            <UButton color="neutral" variant="outline" @click="openLogin">
              Entrar com a conta convidada
            </UButton><UButton color="neutral" variant="ghost" @click="switchIdentity">
              Sair e trocar de conta
            </UButton>
          </div>
        </template>
      </UAlert>
      <UAlert
        v-else-if="state === 'login_required'"
        color="info"
        title="Entre para continuar"
        :description="invitation ? `Este convite é para ${invitation.invitation.email}. Entre com essa conta; o convite continuará disponível por tempo limitado.` : 'Entre com o endereço que recebeu o convite; a continuação será retomada após o login.'"
      >
        <template #actions>
          <UButton @click="openLogin">
            Entrar
          </UButton>
        </template>
      </UAlert>
      <UAlert
        v-else-if="state === 'accepted'"
        color="success"
        title="Convite aceito"
        description="O acesso foi associado à sua identidade. Entre para abrir a empresa."
      >
        <template #actions>
          <UButton to="/login">
            Ir para entrar
          </UButton>
        </template>
      </UAlert>
      <UAlert
        v-else-if="state === 'recovering'"
        color="warning"
        title="Confirmando o convite"
        :description="error || 'Não envie novamente os dados. Consulte o resultado no servidor.'"
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            :loading="busy"
            @click="retry"
          >
            Consultar status
          </UButton>
        </template>
      </UAlert>
      <UForm
        v-else-if="state === 'valid' || state === 'ready'"
        :schema="state === 'valid' ? schema : undefined"
        :state="{ password, password_confirmation: passwordConfirmation }"
        class="space-y-4"
        @submit="passwordSubmit"
      >
        <section v-if="invitation" class="rounded-lg border border-default p-4">
          <p class="font-medium text-highlighted">
            {{ invitation.company.name }}
          </p><p class="mt-1 text-sm text-muted">
            {{ invitation.invitation.name }} · {{ invitation.invitation.email }}
          </p><p class="mt-1 text-sm text-muted">
            Papel: {{ invitation.invitation.role }} · expira {{ new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(new Date(invitation.invitation.expires_at)) }}
          </p>
        </section>
        <template v-if="!user">
          <UFormField label="Seu nome" name="name" required>
            <UInput
              v-model="name"
              autocomplete="name"
              maxlength="255"
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="Crie uma senha"
            name="password"
            required
            :error="validationError"
          >
            <UInput
              v-model="password"
              type="password"
              autocomplete="new-password"
              class="w-full"
            />
          </UFormField>
          <UFormField label="Confirme a senha" name="password_confirmation" required>
            <UInput
              v-model="passwordConfirmation"
              type="password"
              autocomplete="new-password"
              class="w-full"
            />
          </UFormField>
        </template>
        <UAlert
          v-if="error"
          color="error"
          title="Não foi possível aceitar o convite"
          :description="error"
        />
        <p class="text-sm text-muted">
          O convite não altera sua senha ou dados de outras empresas. A conta será criada somente para o destinatário indicado.
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <UButton color="neutral" variant="outline" to="/login">
            Cancelar
          </UButton><UButton type="submit" :loading="busy" :disabled="busy || !operationKey">
            {{ user ? 'Aceitar convite' : 'Criar conta e aceitar convite' }}
          </UButton>
        </div>
      </UForm>
    </div>
  </UPageCard>
</template>
