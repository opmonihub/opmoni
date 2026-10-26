<script setup lang="ts">
import { listNavigationField } from '~/composables/useListNavigationState'
import { useOfficeErpListNavigation, officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import type { TableColumn } from '@nuxt/ui'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'
import { apiErrorMessage, apiStatus } from '~/composables/useApiError'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelTableUi } from '~/components/data-table/panel'

definePageMeta({ middleware: 'auth' })

interface InvoiceRow {
  id: number
  contract_id: number
  number: string | null
  document_status: 'draft' | 'issued' | 'canceled'
  due_on: string
  original_total: string
  balance: { adjusted_charge: string, effective_received: string, outstanding: string }
  bill_to_snapshot: { client?: { name?: string }, company?: { name?: string } | null }
}
interface PageResponse { data: InvoiceRow[], meta: { current_page: number, last_page: number, per_page: number, total: number } }

const { api } = useAccessApi()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const context = useOfficeErpContext()
const navigation = useOfficeErpListNavigation('invoices', { q: '', status: 'all' as 'all' | 'draft' | 'issued' | 'canceled', from: '', to: '', dueFrom: '', dueTo: '', page: 1, perPage: 25 as 25 | 50 | 100 }, context, { status: ['all', 'draft', 'issued', 'canceled'] })
const query = listNavigationField(navigation, 'q')
const status = listNavigationField(navigation, 'status')
const from = listNavigationField(navigation, 'from')
const to = listNavigationField(navigation, 'to')
const dueFrom = listNavigationField(navigation, 'dueFrom')
const dueTo = listNavigationField(navigation, 'dueTo')
const page = listNavigationField(navigation, 'page')
const perPage = listNavigationField(navigation, 'perPage')
const rows = ref<InvoiceRow[]>([])
const total = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const loadError = ref('')
const confirmedEmpty = ref(false)
const confirmedQueryKey = ref<string | null>(null)
let generation = 0
const identity = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identity)
const queryKey = computed(() => JSON.stringify([identity.value, query.value.trim(), status.value, from.value, to.value, dueFrom.value, dueTo.value, page.value, perPage.value]))
const hasConfirmedResults = computed(() => confirmedQueryKey.value !== null && confirmedQueryKey.value === queryKey.value)
const columns: TableColumn<InvoiceRow>[] = [{ accessorKey: 'number', header: 'Fatura' }, { id: 'client', header: 'Cliente' }, { accessorKey: 'document_status', header: 'Situação' }, { accessorKey: 'due_on', header: 'Vencimento' }, { id: 'total', header: 'Cobrança ajustada' }, { id: 'balance', header: 'Em aberto' }, { id: 'actions', header: '' }]

function clearConfirmedResults() {
  confirmedQueryKey.value = null
  rows.value = []
  total.value = 0
  lastPage.value = 1
  confirmedEmpty.value = false
}

function isAccountContextChanged(error: unknown): boolean {
  if (typeof error !== 'object' || error === null) return false
  const record = error as { data?: unknown, response?: unknown }
  const response = typeof record.response === 'object' && record.response !== null
    ? record.response as { _data?: unknown, data?: unknown }
    : null
  const responseData = record.data ?? response?._data ?? response?.data
  const data = typeof responseData === 'object' && responseData !== null && '_data' in responseData
    ? (responseData as { _data?: unknown })._data
    : responseData
  if (typeof data !== 'object' || data === null) return false
  return (data as { code?: unknown }).code === 'account_context_changed'
}

async function loadInvoices() {
  const read = reads.begin('loadInvoices')
  const request = ++generation
  const stamp = identity.value
  const requestedQueryKey = queryKey.value
  if (confirmedQueryKey.value !== requestedQueryKey) clearConfirmedResults()
  loading.value = true
  loadError.value = ''
  try {
    await context.refresh()
    if (!read.current() || request !== generation || stamp !== identity.value) return
    if (!context.capabilities.value?.can_read) {
      clearConfirmedResults()
      throw new Error('A conta selecionada não tem permissão para consultar faturas.')
    }
    const token = context.capture()
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value }
    if (query.value.trim()) params.q = query.value.trim()
    if (status.value !== 'all') params.status = status.value
    if (from.value) params.from_on = from.value
    if (to.value) params.to_on = to.value
    if (dueFrom.value) params.due_from = dueFrom.value
    if (dueTo.value) params.due_to = dueTo.value
    const response = await api<PageResponse>('/erp-commercial/invoices', { signal: read.signal, params })
    if (!read.current() || request !== generation || stamp !== identity.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)) throw new Error('A resposta de faturas está incompleta.')
    const availablePage = officeErpAvailablePage(response.meta)
    if (page.value !== availablePage) {
      page.value = availablePage
      return
    }
    rows.value = response.data
    total.value = response.meta.total
    lastPage.value = Math.max(1, response.meta.last_page)
    page.value = response.meta.current_page
    confirmedEmpty.value = response.meta.total === 0
    confirmedQueryKey.value = requestedQueryKey
  } catch (error) {
    if (read.current() && request === generation && stamp === identity.value) {
      const statusCode = apiStatus(error)
      if ([401, 403].includes(statusCode ?? 0) || (statusCode === 409 && isAccountContextChanged(error))) clearConfirmedResults()
      loadError.value = apiErrorMessage(error) ?? (error instanceof Error ? error.message : 'Não foi possível consultar faturas.')
    }
  } finally {
    if (read.current() && request === generation && stamp === identity.value) loading.value = false
  }
}
watch([query, status, from, to, dueFrom, dueTo, page, perPage], () => {
  void loadInvoices()
}, { immediate: true })
watch([query, status, from, to, dueFrom, dueTo, perPage], () => {
  page.value = 1
}, { flush: 'sync' })
watch(identity, () => {
  generation++
  clearConfirmedResults()
  loadError.value = ''
  void loadInvoices()
}, { flush: 'sync' })
</script>

<template>
  <div class="flex min-h-0 flex-col gap-4">
    <DataTablePanelList title="Faturas" description="Documentos internos do escritório, com ajustes e saldos confirmados pelo servidor.">
      <template #action>
        <div class="flex flex-wrap gap-2">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            :loading="loading"
            :disabled="loading"
            @click="loadInvoices"
          >
            Atualizar
          </UButton>
          <UButton to="/gestao-erp/faturas/nova" icon="i-lucide-plus">
            Nova fatura
          </UButton>
        </div>
      </template>
      <template #toolbar>
        <UFormField label="Buscar fatura" class="w-full max-w-sm">
          <UInput v-model="query" aria-label="Buscar fatura" placeholder="Número, cliente ou empresa" />
        </UFormField>
        <UFormField label="Situação">
          <USelect v-model="status" aria-label="Filtrar situação" :items="[{ label: 'Todas', value: 'all' }, { label: 'Rascunho', value: 'draft' }, { label: 'Emitida', value: 'issued' }, { label: 'Cancelada', value: 'canceled' }]" />
        </UFormField>
        <UFormField label="Emissão de">
          <UInput v-model="from" type="date" aria-label="Emissão de" />
        </UFormField>
        <UFormField label="Emissão até">
          <UInput v-model="to" type="date" aria-label="Emissão até" />
        </UFormField>
        <UFormField label="Vencimento de">
          <UInput v-model="dueFrom" type="date" aria-label="Vencimento de" />
        </UFormField>
        <UFormField label="Vencimento até">
          <UInput v-model="dueTo" type="date" aria-label="Vencimento até" />
        </UFormField>
      </template>
      <div :class="panelBodyClass">
        <UAlert
          v-if="loadError"
          color="error"
          :title="hasConfirmedResults ? 'Não foi possível atualizar as faturas' : 'Não foi possível carregar as faturas'"
          :description="loadError"
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div class="space-y-1">
                <p>{{ loadError }}</p>
                <p v-if="hasConfirmedResults" class="text-sm text-muted">
                  Os resultados abaixo são da última consulta confirmada.
                </p>
              </div>
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                @click="loadInvoices"
              >
                Tentar novamente
              </UButton>
            </div>
          </template>
        </UAlert>
        <UAlert
          v-if="confirmedEmpty"
          color="neutral"
          :title="loadError ? 'Nenhuma fatura na última consulta confirmada' : 'Nenhuma fatura encontrada'"
          :description="loadError ? 'A atualização falhou; este resultado vazio pertence à última consulta confirmada.' : 'Altere os filtros ou crie um novo rascunho.'"
        />
        <div v-if="!confirmedEmpty && (!loadError || hasConfirmedResults)" class="overflow-x-auto">
          <UTable
            :data="rows"
            :columns="columns"
            :loading="loading"
            :ui="panelTableUi"
          >
            <template #client-cell="{ row }">
              <span>{{ row.original.bill_to_snapshot.client?.name || row.original.bill_to_snapshot.company?.name || 'Cliente' }}</span>
            </template>
            <template #document_status-cell="{ row }">
              <UBadge :color="row.original.document_status === 'issued' ? 'success' : 'neutral'" variant="subtle">
                {{ row.original.document_status === 'issued' ? 'Emitida' : row.original.document_status === 'draft' ? 'Rascunho' : 'Cancelada' }}
              </UBadge>
            </template>
            <template #total-cell="{ row }">
              <span>{{ formatOfficeErpMoney(row.original.balance.adjusted_charge) }}</span>
            </template>
            <template #balance-cell="{ row }">
              <span>{{ formatOfficeErpMoney(row.original.balance.outstanding) }}</span>
            </template>
            <template #actions-cell="{ row }">
              <UButton
                :to="`/gestao-erp/faturas/${row.original.id}`"
                color="neutral"
                variant="ghost"
                size="sm"
              >
                Abrir
              </UButton>
            </template>
          </UTable>
        </div>
        <div v-if="hasConfirmedResults && !confirmedEmpty && total > 0" :class="panelFooterClass">
          <span :class="panelFooterCountClass">{{ total }} faturas · página {{ page }} de {{ lastPage }}</span><UPagination
            v-model:page="page"
            :total="total"
            :items-per-page="perPage"
            :disabled="loading"
            :class="panelPaginationClass"
          />
        </div>
      </div>
    </DataTablePanelList>
  </div>
</template>
