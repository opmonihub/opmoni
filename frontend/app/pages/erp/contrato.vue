<script setup lang="ts">
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { useErpReadScope } from '~/composables/useErpReadScope'
import { listNavigationField, useListNavigationState } from '~/composables/useListNavigationState'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Contrato ERP · Opmoni' })

interface RetailContract {
  agreement_status: 'accepted' | 'effective' | 'canceled'
  terms: {
    price: string
    currency: string
    interval_months: number
    due_in_days: number
    module_keys: string[]
  }
  service_anchor_on: string | null
  billing_timezone: string
}

interface ErpSubscriptionPayload {
  data: {
    status: string
    plan: { name: string, modules: Array<{ module_key: string }> }
    company: { name: string, status: string }
    trial?: { started_at: string, ends_at: string, converted_at: string | null, expired_at: string | null, situation: 'trialing' | 'expired' | 'converted' }
    retail_contract?: RetailContract
  }
}

interface CompanyInvoice {
  id: number
  kind: string
  document_status: 'issued' | 'canceled'
  number: string | null
  issued_at: string | null
  period_start: string
  period_end: string
  due_on: string
  currency: string
  items: Array<{ description: string, amount: string, discount?: string }>
  original_total: string
  adjustments: Array<{ direction: 'debit' | 'credit', amount: string, reason: string, posted_at: string | null }>
  balance: {
    original_total: string
    adjusted_charge: string
    effective_received: string
    outstanding: string
    settlement_status: 'unpaid' | 'partial' | 'settled'
    overdue: boolean
  }
}

interface CompanyInvoiceListPayload {
  data: CompanyInvoice[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

interface CompanyInvoicePayload {
  data: CompanyInvoice
}

const { $api } = useNuxtApp()
const context = useErpAdministrationContext()
const reads = useErpReadScope()
const navigation = useListNavigationState('erp-contract-invoices', {
  defaults: () => ({ page: 1 }),
  authority: () => context.key.value,
  normalize(value: unknown) {
    const page = typeof value === 'object' && value !== null ? (value as { page?: unknown }).page : undefined
    return { page: typeof page === 'number' && Number.isSafeInteger(page) && page > 0 ? page : 1 }
  }
})
const loading = ref(true)
const error = ref<unknown>(null)
const subscription = ref<ErpSubscriptionPayload['data'] | null>(null)
const invoices = ref<CompanyInvoice[]>([])
const invoiceMeta = ref<CompanyInvoiceListPayload['meta'] | null>(null)
const invoicePage = listNavigationField(navigation, 'page')
const invoicesLoading = ref(false)
const invoicesError = ref<unknown>(null)
const invoiceDetail = ref<CompanyInvoice | null>(null)
const invoiceDetailLoading = ref(false)
const invoiceDetailError = ref<unknown>(null)
let generation = 0
let detailGeneration = 0
let listGeneration = 0

function clearProtectedState() {
  reads.cancel()
  generation++
  listGeneration++
  detailGeneration++
  subscription.value = null
  invoices.value = []
  invoiceMeta.value = null
  invoicePage.value = 1
  invoiceDetail.value = null
  error.value = null
  invoicesError.value = null
  invoiceDetailError.value = null
  loading.value = false
  invoicesLoading.value = false
  invoiceDetailLoading.value = false
}

watch(context.key, clearProtectedState, { flush: 'sync' })

onScopeDispose(() => {
  generation++
  listGeneration++
  detailGeneration++
})

const captureAuthority = context.capture
function authorityIsCurrent(snapshot: ReturnType<typeof captureAuthority>, version: number) {
  return version === generation && context.isCurrent(snapshot)
}

function isAuthorityLoss(error: unknown) {
  if (!error || typeof error !== 'object')
    return false

  const failure = error as {
    status?: number
    statusCode?: number
    response?: { status?: number, _data?: unknown }
    data?: unknown
  }
  const status = failure.status ?? failure.statusCode ?? failure.response?.status
  let data = failure.data ?? failure.response?._data
  if (typeof data === 'string') {
    try {
      data = JSON.parse(data)
    } catch {
      return status === 401 || status === 403
    }
  }
  const code = typeof data === 'object' && data !== null ? (data as { code?: unknown }).code : undefined
  return status === 401 || status === 403 || (status === 409 && code === 'company_context_changed')
}

const statusLabel = computed(() => {
  const map: Record<string, string> = {
    pending: 'Pendente de habilitação',
    active: 'Ativo',
    past_due: 'Em atraso',
    suspended: 'Suspenso',
    canceled: 'Cancelado',
    trialing: 'Em teste',
    trial_expired: 'Teste encerrado',
    converted: 'Ativo'
  }
  return subscription.value ? map[subscription.value.status] ?? subscription.value.status : ''
})

const retailContractStatusLabel = computed(() => {
  const map: Record<RetailContract['agreement_status'], string> = {
    accepted: 'Aceito, aguardando habilitação',
    effective: 'Em vigor',
    canceled: 'Cancelado'
  }
  return subscription.value?.retail_contract ? map[subscription.value.retail_contract.agreement_status] : ''
})

function formatPeriod(intervalMonths: number) {
  return intervalMonths === 1 ? 'Mensal' : `A cada ${intervalMonths} meses`
}

function formatDate(value: string) {
  const date = new Date(value)
  return Number.isNaN(date.valueOf())
    ? 'Data indisponível'
    : new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long', timeStyle: 'short' }).format(date)
}

async function load() {
  const version = ++generation
  const signal = reads.start('subscription')
  reads.cancel('invoices')
  reads.cancel('detail')
  listGeneration++
  detailGeneration++
  const authority = captureAuthority()
  if (subscription.value?.retail_contract)
    subscription.value = null
  loading.value = true
  error.value = null
  invoices.value = []
  invoiceMeta.value = null
  invoicesLoading.value = false
  invoicesError.value = null
  invoiceDetail.value = null
  invoiceDetailLoading.value = false
  invoiceDetailError.value = null
  try {
    const response = await $api<ErpSubscriptionPayload>('/erp/subscription', { signal })
    if (!authorityIsCurrent(authority, version))
      return

    subscription.value = response.data
    const retailContract = response.data.retail_contract
    if (!retailContract)
      return

    await loadInvoices(invoicePage.value, authority, version)
  } catch (loadError) {
    if (authorityIsCurrent(authority, version)) {
      if (isAuthorityLoss(loadError))
        clearProtectedState()
      error.value = loadError
    }
  } finally {
    if (authorityIsCurrent(authority, version))
      loading.value = false
  }
}

async function loadInvoices(page: number, authority = captureAuthority(), version = generation): Promise<void> {
  if (!Number.isSafeInteger(page) || page < 1) return
  const listVersion = ++listGeneration
  const signal = reads.start('invoices')
  reads.cancel('detail')
  const current = () => authorityIsCurrent(authority, version) && listVersion === listGeneration
  invoicesLoading.value = true
  invoicesError.value = null
  invoiceDetail.value = null
  invoiceDetailLoading.value = false
  invoiceDetailError.value = null
  detailGeneration++
  try {
    const response = await $api<CompanyInvoiceListPayload>('/erp/commercial-invoices', {
      query: { page, per_page: 25 }, signal
    })
    if (current()) {
      const availablePage = Math.max(1, response.meta.last_page)
      if (page > availablePage) return await loadInvoices(availablePage, authority, version)
      invoices.value = response.data
      invoiceMeta.value = response.meta
      invoicePage.value = response.meta.current_page
    }
  } catch (invoiceLoadError) {
    if (current()) {
      if (isAuthorityLoss(invoiceLoadError)) {
        clearProtectedState()
        error.value = invoiceLoadError
      } else invoicesError.value = invoiceLoadError
    }
  } finally {
    if (current())
      invoicesLoading.value = false
  }
}

async function showInvoice(invoiceId: number) {
  const version = generation
  const detailVersion = ++detailGeneration
  const signal = reads.start('detail')
  const authority = captureAuthority()
  invoiceDetailLoading.value = true
  invoiceDetail.value = null
  invoiceDetailError.value = null
  try {
    const response = await $api<CompanyInvoicePayload>(`/erp/commercial-invoices/${invoiceId}`, { signal })
    if (authorityIsCurrent(authority, version) && detailVersion === detailGeneration)
      invoiceDetail.value = response.data
  } catch (loadError) {
    if (authorityIsCurrent(authority, version) && detailVersion === detailGeneration) {
      if (isAuthorityLoss(loadError)) {
        clearProtectedState()
        error.value = loadError
      } else invoiceDetailError.value = loadError
    }
  } finally {
    if (authorityIsCurrent(authority, version) && detailVersion === detailGeneration)
      invoiceDetailLoading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-6 p-6">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">
          Contrato ERP
        </h1>
        <p class="text-muted text-sm">
          Consulte a situação do ERP e, quando houver revenda vinculada, os termos e as faturas da sua empresa.
        </p>
      </div>
      <UButton
        color="neutral"
        variant="outline"
        :loading="loading"
        @click="load"
      >
        Atualizar
      </UButton>
    </div>
    <UAlert v-if="error" color="error" title="Não foi possível carregar o contrato">
      <template #description>
        <UButton variant="link" @click="load">
          Tentar novamente
        </UButton>
      </template>
    </UAlert>
    <UCard v-if="subscription">
      <dl class="grid gap-3 text-sm">
        <div class="flex justify-between gap-4">
          <dt class="text-muted">
            Empresa
          </dt>
          <dd>{{ subscription.company.name }}</dd>
        </div>
        <div class="flex justify-between gap-4">
          <dt class="text-muted">
            Plano
          </dt>
          <dd>{{ subscription.plan.name }}</dd>
        </div>
        <div class="flex justify-between gap-4">
          <dt class="text-muted">
            Situação
          </dt>
          <dd>{{ statusLabel }}</dd>
        </div>
        <div>
          <dt class="text-muted mb-1">
            Módulos do plano
          </dt>
          <dd class="flex flex-wrap gap-1">
            <UBadge v-for="mod in subscription.plan.modules" :key="mod.module_key" variant="subtle">
              {{ mod.module_key }}
            </UBadge>
          </dd>
        </div>
      </dl>
    </UCard>
    <UAlert
      v-if="subscription?.trial"
      :color="subscription.trial.situation === 'trialing' ? 'success' : 'neutral'"
      :title="subscription.trial.situation === 'trialing' ? 'Em teste' : subscription.trial.situation === 'expired' ? 'Teste encerrado' : 'Teste convertido'"
    >
      <template #description>
        <div class="space-y-1">
          <p>Início: {{ formatDate(subscription.trial.started_at) }}</p>
          <p>Fim original do teste: {{ formatDate(subscription.trial.ends_at) }}</p>
          <p v-if="subscription.trial.situation === 'expired'">
            O período de teste terminou. Seus dados continuam disponíveis para consulta.
          </p>
        </div>
      </template>
    </UAlert>
    <USkeleton v-else-if="loading" class="h-40 w-full" />

    <template v-if="subscription?.retail_contract">
      <UAlert
        v-if="subscription.status === 'pending'"
        color="warning"
        title="Pendente de habilitação"
        description="O contrato foi aceito e aguarda a habilitação do ERP. A ativação não pode ser feita por esta consulta."
      />
      <UCard>
        <div class="space-y-4">
          <div>
            <h2 class="text-lg font-semibold">
              Contrato de revenda
            </h2>
            <p class="text-muted text-sm">
              Termos comerciais vinculados à empresa selecionada.
            </p>
          </div>
          <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-muted">
                Situação do contrato
              </dt>
              <dd>{{ retailContractStatusLabel }}</dd>
            </div>
            <div>
              <dt class="text-muted">
                Valor contratado
              </dt>
              <dd>{{ formatOfficeErpMoney(subscription.retail_contract.terms.price) }}</dd>
            </div>
            <div>
              <dt class="text-muted">
                Cobrança
              </dt>
              <dd>{{ formatPeriod(subscription.retail_contract.terms.interval_months) }}</dd>
            </div>
            <div>
              <dt class="text-muted">
                Vencimento após início do período
              </dt>
              <dd>{{ subscription.retail_contract.terms.due_in_days }} dias</dd>
            </div>
            <div>
              <dt class="text-muted">
                Início do serviço
              </dt>
              <dd>{{ subscription.retail_contract.service_anchor_on ?? 'A definir na habilitação' }}</dd>
            </div>
            <div>
              <dt class="text-muted">
                Fuso da cobrança
              </dt>
              <dd>{{ subscription.retail_contract.billing_timezone }}</dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-muted mb-1">
                Recursos incluídos
              </dt>
              <dd class="flex flex-wrap gap-1">
                <UBadge v-for="module in subscription.retail_contract.terms.module_keys" :key="module" variant="subtle">
                  {{ module }}
                </UBadge>
              </dd>
            </div>
          </dl>
        </div>
      </UCard>

      <UCard>
        <div class="space-y-4">
          <div>
            <h2 class="text-lg font-semibold">
              Faturas do contrato
            </h2>
            <p class="text-muted text-sm">
              Valores e saldos internos do contrato. Esta tela não registra pagamentos.
            </p>
          </div>
          <UAlert v-if="invoicesError" color="error" title="Não foi possível carregar as faturas">
            <template #description>
              <UButton variant="link" @click="loadInvoices(invoicePage)">
                Tentar novamente
              </UButton>
            </template>
          </UAlert>
          <USkeleton v-else-if="invoicesLoading" class="h-24 w-full" />
          <p v-else-if="invoices.length === 0" class="text-muted text-sm">
            Nenhuma fatura emitida para este contrato.
          </p>
          <ul v-else class="divide-y divide-default">
            <li v-for="invoice in invoices" :key="invoice.id" class="flex flex-wrap items-center justify-between gap-4 py-3">
              <div class="min-w-0">
                <p class="font-medium">
                  {{ invoice.number ?? 'Fatura sem número' }}
                </p>
                <p class="text-muted text-sm">
                  Período {{ invoice.period_start }} a {{ invoice.period_end }} · Vencimento {{ invoice.due_on }}
                </p>
              </div>
              <div class="flex items-center gap-4">
                <div class="text-right text-sm">
                  <p>Saldo {{ formatOfficeErpMoney(invoice.balance.outstanding) }}</p>
                  <p class="text-muted">
                    Emitido {{ formatOfficeErpMoney(invoice.original_total) }}
                  </p>
                </div>
                <UButton
                  size="sm"
                  color="neutral"
                  variant="outline"
                  @click="showInvoice(invoice.id)"
                >
                  Ver detalhes
                </UButton>
              </div>
            </li>
          </ul>
          <div v-if="invoiceMeta && invoiceMeta.last_page > 1" class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-muted text-sm">
              Página {{ invoiceMeta.current_page }} de {{ invoiceMeta.last_page }} · {{ invoiceMeta.total }} faturas
            </p>
            <div class="flex gap-2">
              <UButton
                color="neutral"
                variant="outline"
                :disabled="invoiceMeta.current_page <= 1 || invoicesLoading"
                @click="loadInvoices(invoiceMeta.current_page - 1)"
              >
                Anterior
              </UButton>
              <UButton
                color="neutral"
                variant="outline"
                :disabled="invoiceMeta.current_page >= invoiceMeta.last_page || invoicesLoading"
                @click="loadInvoices(invoiceMeta.current_page + 1)"
              >
                Próxima
              </UButton>
            </div>
          </div>
          <UAlert v-if="invoiceDetailError" color="error" title="Não foi possível carregar os detalhes da fatura" />
          <USkeleton v-else-if="invoiceDetailLoading" class="h-32 w-full" />
          <section v-else-if="invoiceDetail" class="rounded-lg border border-default p-4" aria-label="Detalhes da fatura">
            <h3 class="font-semibold">
              Detalhes de {{ invoiceDetail.number ?? 'fatura' }}
            </h3>
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
              <div>
                <dt class="text-muted">
                  Valor original
                </dt><dd>{{ formatOfficeErpMoney(invoiceDetail.balance.original_total) }}</dd>
              </div>
              <div>
                <dt class="text-muted">
                  Valor ajustado
                </dt><dd>{{ formatOfficeErpMoney(invoiceDetail.balance.adjusted_charge) }}</dd>
              </div>
              <div>
                <dt class="text-muted">
                  Total recebido
                </dt><dd>{{ formatOfficeErpMoney(invoiceDetail.balance.effective_received) }}</dd>
              </div>
              <div>
                <dt class="text-muted">
                  Saldo em aberto
                </dt><dd>{{ formatOfficeErpMoney(invoiceDetail.balance.outstanding) }}</dd>
              </div>
            </dl>
            <ul v-if="invoiceDetail.items.length" class="mt-4 space-y-2 text-sm">
              <li v-for="(item, index) in invoiceDetail.items" :key="`${item.description}-${index}`" class="flex justify-between gap-4">
                <span>{{ item.description }}</span>
                <span>{{ formatOfficeErpMoney(item.amount) }}</span>
              </li>
            </ul>
            <ul v-if="invoiceDetail.adjustments.length" class="mt-4 space-y-2 text-sm">
              <li v-for="(adjustment, index) in invoiceDetail.adjustments" :key="`${adjustment.posted_at}-${index}`">
                {{ adjustment.direction === 'credit' ? 'Crédito' : 'Débito' }} de {{ formatOfficeErpMoney(adjustment.amount) }} · {{ adjustment.reason }}
              </li>
            </ul>
          </section>
        </div>
      </UCard>
    </template>
  </div>
</template>
