<script setup lang="ts">
import * as z from 'zod'
import type { AuthFormField, FormSubmitEvent } from '@nuxt/ui'
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

definePageMeta({ layout: 'access' })
useHead({ meta: [{ name: 'referrer', content: 'no-referrer' }] })

const { api } = useAccessApi()
const { user, refresh, logout } = useAuth()
const state = ref<ContinuationResponse['state'] | 'loading' | 'retryable'>('loading')
const invitation = ref<InvitationData | null>(null)
const operationKey = ref('')
const selectedCompanyId = ref<number | null>(null)
const busy = ref(false)
const error = ref('')
let pendingToken: string | null = null

const passwordFields: AuthFormField[] = [{
  name: 'password',
  type: 'password',
  label: 'Crie uma senha',
  placeholder: 'Use ao menos 8 caracteres',
  required: true,
  autocomplete: 'new-password',
  maxlength: 128
}]
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
  if (response.state !== 'ready' && response.state !== 'accepted')
    selectedCompanyId.value = null
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
  }
}

async function start() {
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
  busy.value = true
  error.value = ''
  try {
    const body: Record<string, unknown> = {}
    if (!user.value && password)
      body.password = password
    if (user.value && selectedCompanyId.value !== null)
      body.company_id = selectedCompanyId.value
    await api('/erp-invitations/accept', {
      method: 'POST',
      headers: { 'Idempotency-Key': operationKey.value },
      body
    })
    if (!user.value)
      await refresh()
    await loadCurrent()
  } catch (caught) {
    error.value = apiFailure(caught).message
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
          <UAuthForm
            :schema="passwordSchema"
            :fields="passwordFields"
            title="Crie sua senha"
            description="Sua conta será criada para o responsável identificado acima."
            icon="i-lucide-key-round"
            :loading="busy"
            :submit="{ label: 'Aceitar convite e criar empresa', disabled: busy }"
            @submit="submitNewOwner"
          />
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
