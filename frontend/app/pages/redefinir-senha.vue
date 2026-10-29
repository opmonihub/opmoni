<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { apiFailure, passwordLength, retryAfterSeconds } from '#shared/utils/access'
import { accessContinuationRouteCaptureVersion, captureAccessContinuationFromLocation, takeAccessContinuationFragment, takeInvalidAccessContinuationCapture } from '~/utils/accessContinuationFragment'

definePageMeta({ layout: 'access' })
useHead({ meta: [{ name: 'referrer', content: 'no-referrer' }, { name: 'robots', content: 'noindex,nofollow' }] })
if (import.meta.server) useResponseHeader('Cache-Control').value = 'no-store, no-cache, must-revalidate'
interface RecoveryState { state: 'missing' | 'valid' | 'invalid' | 'expired' | 'completed' | 'identity_mismatch' | 'retryable' }
const { api } = useAccessApi()
const { user, logout } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const identityStamp = computed(() => JSON.stringify([user.value?.id ?? null, user.value?.email?.toLowerCase() ?? null, sessionGeneration.value]))
let initialFragment = import.meta.client
  ? (() => {
      captureAccessContinuationFromLocation('password-recovery')
      return takeAccessContinuationFragment('password-recovery')
    })()
  : null
let initialFragmentInvalid = import.meta.client ? takeInvalidAccessContinuationCapture('password-recovery') : false
const state = ref<RecoveryState['state'] | 'loading' | 'recovering'>('loading')
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)
const retryAfter = ref(0)
const error = ref('')
const schema = z.object({ password: z.string().refine(value => passwordLength(value) >= 8, 'Use ao menos 8 caracteres.').refine(value => passwordLength(value) <= 128, 'Use no máximo 128 caracteres.'), password_confirmation: z.string().min(1, 'Confirme a senha.') }).refine(value => value.password === value.password_confirmation, { path: ['password_confirmation'], message: 'As senhas não coincidem.' })
type PasswordForm = z.output<typeof schema>
let pending: { token: string, delivery_id: string } | null = initialFragment?.kind === 'password-recovery' ? { token: initialFragment.token, delivery_id: initialFragment.delivery_id } : null
let requestGeneration = 0
let initialized = false
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
function apply(response: RecoveryState) {
  state.value = response.state
  if (response.state === 'completed') pending = null
}
async function resolve() {
  if (!pending) {
    await loadCurrent()
    return
  }
  busy.value = true
  const request = ++requestGeneration
  const identity = identityStamp.value
  error.value = ''
  try {
    const response = await api<RecoveryState>('/password-recovery/resolve', { method: 'POST', body: { ...pending } })
    if (request !== requestGeneration || identity !== identityStamp.value) return
    apply(response)
    pending = null
  } catch (caught) {
    if (request !== requestGeneration || identity !== identityStamp.value) return
    const failure = apiFailure(caught)
    state.value = failure.status === 0 || failure.status === 429 || failure.status >= 500 ? 'retryable' : failure.status === 410 ? 'expired' : 'invalid'
    error.value = failure.message
    if (failure.status === 429) setRetryAfter(caught)
    if (state.value !== 'retryable') pending = null
  } finally { if (request === requestGeneration) busy.value = false }
}
async function loadCurrent() {
  busy.value = true
  const request = ++requestGeneration
  const identity = identityStamp.value
  error.value = ''
  try {
    const response = await api<RecoveryState>('/password-recovery/current')
    if (request !== requestGeneration || identity !== identityStamp.value) return
    apply(response)
  } catch (caught) {
    if (request !== requestGeneration || identity !== identityStamp.value) return
    const failure = apiFailure(caught)
    state.value = failure.status === 0 || failure.status === 429 || failure.status >= 500 ? 'retryable' : failure.status === 404 ? 'missing' : 'invalid'
    error.value = failure.message
    if (failure.status === 429) setRetryAfter(caught)
  } finally { if (request === requestGeneration) busy.value = false }
}
async function retry() {
  if (busy.value || retryAfter.value > 0) return
  if (pending) await resolve()
  else await loadCurrent()
}
async function complete(event: FormSubmitEvent<PasswordForm>) {
  if (busy.value || state.value !== 'valid') return
  busy.value = true
  error.value = ''
  const request = ++requestGeneration
  const identity = identityStamp.value
  const body = { password: event.data.password, password_confirmation: event.data.password_confirmation }
  password.value = ''
  passwordConfirmation.value = ''
  state.value = 'recovering'
  try {
    const response = await api<RecoveryState>('/password-recovery/complete', { method: 'POST', body })
    if (request !== requestGeneration || identity !== identityStamp.value) return
    apply(response)
  } catch (caught) {
    if (request !== requestGeneration || identity !== identityStamp.value) return
    const failure = apiFailure(caught)
    error.value = failure.message || 'Não foi possível confirmar a recuperação.'
    const apiState = (caught as { data?: { state?: string } })?.data?.state
    state.value = apiState === 'identity_mismatch' ? 'identity_mismatch' : failure.status === 0 || failure.status === 429 || failure.status >= 500 ? 'recovering' : failure.status === 410 ? 'expired' : 'valid'
    if (failure.status === 429) setRetryAfter(caught)
  } finally {
    if (request === requestGeneration) {
      password.value = ''
      passwordConfirmation.value = ''
      busy.value = false
    }
  }
}
async function switchIdentity() {
  try {
    await logout()
  } catch (caught) {
    error.value = apiFailure(caught).message
  }
}
function start() {
  if (!import.meta.client || initialized) return
  initialized = true
  initialFragment = null
  if (initialFragmentInvalid) {
    initialFragmentInvalid = false
    state.value = 'invalid'
    return
  }
  if (pending) void resolve()
  else void loadCurrent()
}
watch(identityStamp, () => {
  requestGeneration++
  pending = null
  password.value = ''
  passwordConfirmation.value = ''
  if (initialized) void loadCurrent()
})
watch(accessContinuationRouteCaptureVersion('password-recovery'), () => {
  const fragment = takeAccessContinuationFragment('password-recovery')
  const invalidFragment = takeInvalidAccessContinuationCapture('password-recovery')
  requestGeneration++
  pending = fragment?.kind === 'password-recovery' ? { token: fragment.token, delivery_id: fragment.delivery_id } : null
  password.value = ''
  passwordConfirmation.value = ''
  busy.value = false
  error.value = ''
  state.value = 'loading'
  if (retryTimer) clearInterval(retryTimer)
  retryTimer = null
  retryAfter.value = 0
  if (invalidFragment) {
    state.value = 'invalid'
    return
  }
  if (!initialized) return
  if (pending) void resolve()
  else void loadCurrent()
})
onMounted(start)
onBeforeUnmount(() => {
  requestGeneration++
  if (retryTimer) clearInterval(retryTimer)
  initialFragment = null
  password.value = ''
  passwordConfirmation.value = ''
  pending = null
})
</script>

<template>
  <UPageCard class="w-full max-w-xl">
    <div class="space-y-5">
      <header>
        <h1 class="text-xl font-semibold text-highlighted">
          Redefinir senha
        </h1><p class="mt-1 text-sm text-muted">
          Defina uma nova senha para continuar usando sua conta.
        </p>
      </header>
      <USkeleton v-if="state === 'loading'" class="h-24 w-full" />
      <UAlert
        v-else-if="state === 'retryable' || state === 'recovering'"
        color="warning"
        title="Confirme o resultado antes de tentar novamente"
        :description="error || 'Não foi possível confirmar a operação.'"
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            :loading="busy"
            :disabled="retryAfter > 0"
            @click="retry"
          >
            {{ retryAfter > 0 ? `Consultar status em ${retryAfter}s` : 'Consultar status' }}
          </UButton>
        </template>
      </UAlert>
      <UAlert
        v-else-if="state === 'completed'"
        color="success"
        title="Senha atualizada"
        description="Sua senha foi redefinida. Entre com a nova senha para continuar."
      >
        <template #actions>
          <UButton to="/login">
            Ir para entrar
          </UButton>
        </template>
      </UAlert>
      <UAlert
        v-else-if="state === 'identity_mismatch'"
        color="warning"
        title="Entre com a conta destinatária"
        description="Este link foi criado para outra identidade. Saia e entre com o e-mail que recebeu a solicitação."
      >
        <template #actions>
          <UButton color="neutral" variant="outline" @click="switchIdentity">
            Sair e trocar de conta
          </UButton>
        </template>
      </UAlert>
      <UAlert
        v-else-if="state === 'invalid' || state === 'expired' || state === 'missing'"
        color="warning"
        title="Link indisponível"
        description="Este link expirou, já foi usado ou não pode ser validado. Solicite uma nova recuperação à administração da empresa."
      />
      <UForm
        v-else
        :schema="schema"
        :state="{ password, password_confirmation: passwordConfirmation }"
        class="space-y-4"
        @submit="complete"
      >
        <UFormField label="Nova senha" name="password">
          <UInput
            v-model="password"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Confirme a nova senha" name="password_confirmation">
          <UInput
            v-model="passwordConfirmation"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UAlert
          v-if="error"
          color="error"
          title="Não foi possível redefinir a senha"
          :description="error"
        />
        <p class="text-sm text-muted">
          A redefinição não cria uma sessão. Você precisará entrar novamente depois que a senha for atualizada.
        </p>
        <div class="flex justify-end gap-2">
          <UButton color="neutral" variant="outline" to="/login">
            Cancelar
          </UButton><UButton type="submit" :loading="busy" :disabled="busy">
            Salvar nova senha
          </UButton>
        </div>
      </UForm>
    </div>
  </UPageCard>
</template>
