<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { apiFailure, passwordLength } from '#shared/utils/access'

type CompanyOption = { id: number, name: string, cnpj: string }
type InvitationData = {
  invitation: { id: number, owner_name: string, owner_email: string, expires_at: string | null }
  seller: { account_id: number, name: string }
  client: { name: string, trade_name: string | null, tax_id: string }
  offer: { name: string | null, price: string, currency: string, interval_months: number, due_in_days: number, module_keys: string[] }
  service: { billing_timezone: string }
  companies: CompanyOption[]
  accepted_company: { id: number, name: string } | null
}
type ContinuationResponse = {
  state: 'missing' | 'invalid' | 'expired' | 'valid' | 'ready' | 'accepted' | 'identity_mismatch' | 'login_required'
  operation_key?: string
  data?: InvitationData
}
type PendingAcceptance = { invitation_id: number, owner_email: string, expires_at: number }

const pendingAcceptanceStorageKey = 'office-erp.owner-acceptance.pending'
const pendingAcceptanceTtl = 15 * 60 * 1000

definePageMeta({ layout: 'access' })
useHead({ meta: [{ name: 'referrer', content: 'no-referrer' }] })

const { api } = useAccessApi()
const { user, refresh, logout } = useAuth()
const state = ref<ContinuationResponse['state'] | 'loading' | 'retryable' | 'recovering' | 'recovery_required' | 'identity_login_required' | 'forbidden'>('loading')
const invitation = ref<InvitationData | null>(null)
const pendingAcceptance = ref<PendingAcceptance | null>(null)
const operationKey = ref('')
const selectedCompanyId = ref<number | null>(null)
const passwordForm = ref<PasswordForm>({ password: '' })
const passwordError = ref('')
const busy = ref(false)
const error = ref('')
let pendingToken: string | null = null

const passwordSchema = z.object({
  password: z.string('Informe uma senha.')
    .refine(value => passwordLength(value) >= 8, 'Use ao menos 8 caracteres.')
    .refine(value => passwordLength(value) <= 128, 'Use no máximo 128 caracteres.')
})
type PasswordForm = z.output<typeof passwordSchema>

const companyOptions = computed(() => [
  { label: 'Criar uma nova empresa', value: null as number | null },
  ...(invitation.value?.companies ?? []).map(company => ({
    label: `${company.name} · CNPJ ${company.cnpj}`,
    value: company.id
  }))
])

function applyContinuation(response: ContinuationResponse) {
  state.value = response.state
  operationKey.value = response.operation_key ?? ''
  invitation.value = response.data ?? null
  const pending = pendingAcceptance.value
  if (pending) {
    const responseInvitation = response.data?.invitation
    const identityMatches = responseInvitation
      ? responseInvitation.id === pending.invitation_id && responseInvitation.owner_email.toLowerCase() === pending.owner_email
      : ['missing', 'login_required'].includes(response.state)
    if (!identityMatches || ['accepted', 'expired', 'invalid'].includes(response.state)) {
      clearPendingAcceptance()
    } else if (['valid', 'ready', 'missing'].includes(response.state)) {
      state.value = 'recovery_required'
    }
  }
  if (response.state !== 'ready' && response.state !== 'accepted')
    selectedCompanyId.value = null
}

function clearPendingAcceptance() {
  pendingAcceptance.value = null
  try {
    window.sessionStorage.removeItem(pendingAcceptanceStorageKey)
  } catch { /* Session storage can be unavailable in restricted browsers. */ }
}

function restorePendingAcceptance() {
  try {
    const stored = window.sessionStorage.getItem(pendingAcceptanceStorageKey)
    if (!stored)
      return
    const pending = JSON.parse(stored) as PendingAcceptance
    if (Number.isInteger(pending.invitation_id)
      && typeof pending.owner_email === 'string'
      && Number.isFinite(pending.expires_at)
      && pending.expires_at > Date.now()
      && pending.expires_at <= Date.now() + pendingAcceptanceTtl) {
      pendingAcceptance.value = pending
      return
    }
  } catch { /* Invalid or unavailable storage is ignored. */ }
  clearPendingAcceptance()
}

function rememberPendingAcceptance() {
  const pending = invitation.value?.invitation
  if (!pending)
    return
  const identity: PendingAcceptance = {
    invitation_id: pending.id,
    owner_email: pending.owner_email.toLowerCase(),
    expires_at: Date.now() + pendingAcceptanceTtl
  }
  pendingAcceptance.value = identity
  try {
    window.sessionStorage.setItem(pendingAcceptanceStorageKey, JSON.stringify(identity))
  } catch { /* In-memory recovery still prevents another password submission this page visit. */ }
}

function stripSecretFromAddress() {
  const url = new URL(window.location.href)
  url.hash = ''
  url.searchParams.delete('token')
  window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}`)
}

function setRequestError(caught: unknown) {
  const failure = apiFailure(caught)
  error.value = failure.message
  state.value = failure.status === 410 ? 'expired' : failure.status === 422 ? 'invalid' : 'retryable'
}

async function exchangeToken(token: string) {
  error.value = ''
  try {
    const response = await api<ContinuationResponse>('/erp-invitations/resolve', {
      method: 'POST',
      body: { token }
    })
    pendingToken = null
    applyContinuation(response)
  } catch (caught) {
    setRequestError(caught)
    if (state.value === 'invalid' || state.value === 'expired')
      pendingToken = null
  }
}

async function loadCurrent() {
  error.value = ''
  try {
    const response = await api<ContinuationResponse>('/erp-invitations/current')
    applyContinuation(response)
  } catch (caught) {
    setRequestError(caught)
    if (pendingAcceptance.value)
      state.value = 'recovery_required'
  }
}

async function start() {
  restorePendingAcceptance()
  const fragment = window.location.hash
  const token = new URLSearchParams(fragment.slice(1)).get('token')
  if (fragment)
    stripSecretFromAddress()
  if (token) {
    pendingToken = token
    await exchangeToken(token)
  } else {
    await loadCurrent()
  }
}

async function retry() {
  if (busy.value)
    return
  busy.value = true
  try {
    if (pendingToken)
      await exchangeToken(pendingToken)
    else
      await loadCurrent()
  } finally {
    busy.value = false
  }
}

async function accept(password?: string) {
  if (busy.value || !operationKey.value)
    return
  const isNewOwner = !user.value
  if (isNewOwner) {
    rememberPendingAcceptance()
    state.value = 'recovering'
  }
  busy.value = true
  error.value = ''
  passwordError.value = ''
  try {
    const body: Record<string, unknown> = {}
    if (!user.value && password)
      body.password = password
    if (user.value && selectedCompanyId.value !== null)
      body.company_id = selectedCompanyId.value
    if (isNewOwner)
      passwordForm.value.password = ''
    await api('/erp-invitations/accept', {
      method: 'POST',
      headers: { 'Idempotency-Key': operationKey.value },
      body
    })
    if (isNewOwner)
      await refresh()
    await loadCurrent()
  } catch (caught) {
    const failure = apiFailure(caught)
    error.value = failure.message
    if (isNewOwner && failure.status > 0 && failure.status < 500) {
      clearPendingAcceptance()
      if (failure.status === 409 && failure.code === 'login_required') {
        state.value = 'identity_login_required'
      } else if (failure.status === 422) {
        state.value = 'valid'
        passwordError.value = failure.errors?.password?.[0] ?? failure.message
      } else if (failure.status === 410) {
        state.value = 'expired'
      } else if (failure.status === 403) {
        state.value = 'forbidden'
      } else {
        state.value = 'valid'
      }
    } else if (isNewOwner) {
      try {
        await refresh()
      } catch { /* Reconcile through the session continuation even if identity refresh is unavailable. */ }
      await loadCurrent()
      if (pendingAcceptance.value && !['accepted', 'login_required', 'identity_mismatch'].includes(state.value)) {
        state.value = 'recovery_required'
        error.value = 'A aceitação ainda não foi confirmada. Não envie a senha novamente; verifique o status ou entre com a conta do responsável.'
      }
    }
  } finally {
    busy.value = false
  }
}

function submitNewOwner(event: FormSubmitEvent<PasswordForm>) {
  return accept(event.data.password)
}

onMounted(() => {
  void start()
})
onBeforeUnmount(() => {
  pendingToken = null
})
</script>

<template>
  <UPageCard class="w-full" variant="subtle">
    <div class="space-y-6">
      <header class="space-y-2">
        <h1 class="text-2xl font-semibold text-default">
          Ativar acesso ao ERP
        </h1>
        <p class="text-sm leading-6 text-muted">
          Confira o convite enviado ao responsável da empresa antes de aceitar.
        </p>
      </header>

      <div v-if="state === 'loading'" role="status" class="space-y-3">
        <USkeleton class="h-5 w-2/3" />
        <USkeleton class="h-24 w-full" />
        <span class="sr-only">Validando convite…</span>
      </div>

      <div v-else-if="state === 'recovering'" role="status" class="space-y-3">
        <p class="text-sm text-muted">
          Confirmando a criação da conta e o resultado do convite…
        </p>
      </div>

      <UAlert
        v-else-if="state === 'recovery_required'"
        color="warning"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        title="Confirme o resultado antes de continuar"
        :description="error || 'Não foi possível confirmar o resultado do envio. Não envie a senha novamente; verifique o status ou entre com a conta do responsável.'"
      >
        <template #actions>
          <UButton label="Verificar novamente" :loading="busy" @click="retry" />
          <UButton
            v-if="!user"
            to="/login"
            label="Entrar com a conta do responsável"
            color="neutral"
            variant="outline"
          />
        </template>
      </UAlert>

      <UAlert
        v-else-if="state === 'retryable'"
        color="warning"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        title="Não foi possível validar o convite agora"
        :description="error || 'Tente novamente em instantes. O convite continua disponível para uma nova tentativa.'"
      />

      <template v-else-if="state === 'invalid' || state === 'expired' || state === 'missing'">
        <UAlert
          :color="state === 'expired' ? 'warning' : 'error'"
          variant="subtle"
          icon="i-lucide-circle-alert"
          :title="state === 'expired' ? 'Este convite expirou' : 'Este convite não é válido'"
          :description="state === 'expired' ? 'Peça ao escritório que envie um novo convite.' : 'Abra o link original ou solicite um novo convite ao escritório.'"
        />
        <UButton
          v-if="error"
          label="Tentar validar novamente"
          :loading="busy"
          @click="retry"
        />
      </template>

      <template v-else-if="state === 'identity_mismatch'">
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-user-round-x"
          title="Entre com a conta indicada no convite"
          description="A conta aberta neste navegador não corresponde ao responsável convidado. Saia e entre com a conta correta para continuar."
        />
        <UButton
          label="Sair e trocar de conta"
          color="neutral"
          variant="outline"
          @click="logout"
        />
      </template>

      <UAlert
        v-else-if="state === 'login_required'"
        color="info"
        variant="subtle"
        icon="i-lucide-key-round"
        title="Entre com a conta do responsável"
        description="Este convite já foi aceito por uma conta. Entre com essa conta para recuperar o acesso à empresa."
      >
        <template #actions>
          <UButton
            to="/login"
            label="Entrar"
            color="neutral"
            variant="outline"
          />
        </template>
      </UAlert>

      <UAlert
        v-else-if="state === 'identity_login_required'"
        color="info"
        variant="subtle"
        icon="i-lucide-key-round"
        title="Entre para continuar o convite"
        :description="error || 'Já existe uma conta com o e-mail indicado. Entre com essa conta para continuar a aceitação do convite.'"
      >
        <template #actions>
          <UButton
            to="/login"
            label="Entrar"
            color="neutral"
            variant="outline"
          />
        </template>
      </UAlert>

      <UAlert
        v-else-if="state === 'forbidden'"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-alert"
        title="A aceitação não foi autorizada"
        :description="error || 'Peça ao escritório responsável para verificar as permissões do convite.'"
      />

      <template v-else-if="invitation">
        <section aria-labelledby="invitation-summary-title" class="space-y-4">
          <h2 id="invitation-summary-title" class="text-base font-semibold text-default">
            Resumo do convite
          </h2>
          <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-muted">
                Escritório responsável
              </dt>
              <dd class="font-medium text-default">
                {{ invitation.seller.name }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Empresa convidada
              </dt>
              <dd class="font-medium text-default">
                {{ invitation.client.name }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Responsável
              </dt>
              <dd class="font-medium text-default">
                {{ invitation.invitation.owner_name }}
              </dd>
              <dd class="text-muted">
                {{ invitation.invitation.owner_email }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                CNPJ
              </dt>
              <dd class="font-medium text-default">
                {{ invitation.client.tax_id }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Plano
              </dt>
              <dd class="font-medium text-default">
                {{ invitation.offer.name || 'Plano ERP' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Valor e ciclo
              </dt>
              <dd class="font-medium text-default">
                R$ {{ invitation.offer.price.replace('.', ',') }} / {{ invitation.offer.interval_months }} {{ invitation.offer.interval_months === 1 ? 'mês' : 'meses' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Vencimento após ativação
              </dt>
              <dd class="font-medium text-default">
                {{ invitation.offer.due_in_days }} dias
              </dd>
            </div>
          </dl>
          <p class="text-sm leading-6 text-muted">
            Ao aceitar, você autoriza a criação ou associação explícita da empresa ao seu usuário. O contrato ficará pendente de ativação; este convite não confirma pagamento nem emissão fiscal.
          </p>
        </section>

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          :title="error"
        />

        <template v-if="state === 'accepted'">
          <UAlert
            color="success"
            variant="subtle"
            icon="i-lucide-circle-check"
            title="Convite aceito"
            :description="`A empresa ${invitation.accepted_company?.name || invitation.client.name} está vinculada à sua conta e aguarda a ativação do contrato.`"
          />
        </template>

        <template v-else-if="state === 'ready' && user">
          <UFormField name="company_id" label="Como deseja vincular a empresa?" hint="Empresas existentes aparecem somente quando você já é proprietário direto e o CNPJ corresponde.">
            <USelect
              v-model="selectedCompanyId"
              :items="companyOptions"
              value-key="value"
              class="w-full"
              aria-label="Escolha criar uma empresa nova ou associar uma empresa existente"
            />
          </UFormField>
          <UButton
            :label="selectedCompanyId === null ? 'Aceitar convite e criar empresa' : 'Aceitar convite e associar empresa'"
            :loading="busy"
            :disabled="busy"
            @click="accept()"
          />
        </template>

        <template v-else-if="state === 'valid' && !user">
          <p class="text-sm text-muted">
            Já tem uma conta? <NuxtLink to="/login" class="font-medium text-primary underline underline-offset-4">Entre para continuar</NuxtLink>.
          </p>
          <UForm
            :schema="passwordSchema"
            :state="passwordForm"
            class="space-y-4"
            @submit="submitNewOwner"
          >
            <UFormField
              label="Crie sua senha"
              name="password"
              :error="passwordError || undefined"
              hint="Use ao menos 8 caracteres."
            >
              <UInput
                v-model="passwordForm.password"
                type="password"
                autocomplete="new-password"
                maxlength="128"
                placeholder="Use ao menos 8 caracteres"
                class="w-full"
                @update:model-value="passwordError = ''"
              />
            </UFormField>
            <p class="text-sm text-muted">
              Sua conta será criada para o responsável identificado acima.
            </p>
            <UButton
              type="submit"
              label="Aceitar convite e criar empresa"
              :loading="busy"
              :disabled="busy"
            />
          </UForm>
        </template>
      </template>

      <UButton
        v-if="state === 'retryable'"
        label="Tentar novamente"
        color="neutral"
        variant="outline"
        :loading="busy"
        @click="retry"
      />
    </div>
  </UPageCard>
</template>
