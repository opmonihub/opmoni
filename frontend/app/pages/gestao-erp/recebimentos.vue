<script setup lang="ts">
import OfficeErpDataTable from '~/components/office-erp/OfficeErpDataTable.vue'
import OfficeErpPagination from '~/components/office-erp/OfficeErpPagination.vue'
import OfficeErpPanel from '~/components/office-erp/OfficeErpPanel.vue'
import { listNavigationField } from '~/composables/useListNavigationState'
import { useOfficeErpListNavigation, officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import type { FilterPanelColumn } from '~/utils/filterPanel'
import type { DataTableFilterModel } from '~/components/data-table/filter-model'
import type { TableColumn } from '@nuxt/ui'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'

definePageMeta({ middleware: 'auth' })
interface InvoiceChoice { id: number, number: string | null, document_status: string, balance: { outstanding: string }, bill_to_snapshot: { client?: { name?: string }, company?: { name?: string } | null } }
interface Receipt { id: number, amount: string, received_on: string, reference: string | null, proof_id: string | null, allocations: Array<{ invoice_id: number, amount: string }>, reversal: { id: number, reason: string } | null }
interface ListResponse<T> { data: T[], meta: { current_page: number, last_page: number, per_page: number, total: number } }
const { api } = useAccessApi(), context = useOfficeErpContext(), command = useOfficeErpCommand()
const navigation = useOfficeErpListNavigation('receipts', { from: '', to: '', page: 1, perPage: 25 as 25 | 50 | 100 }, context)
const { user, currentAccount } = useAuth(), sessionGeneration = useState('access-session-generation', () => 0)
const rows = ref<Receipt[]>([]), invoices = ref<InvoiceChoice[]>([]), total = ref(0), lastPage = ref(1), page = listNavigationField(navigation, 'page'), perPage = listNavigationField(navigation, 'perPage')
const from = listNavigationField(navigation, 'from'), to = listNavigationField(navigation, 'to'), loading = ref(false), loadError = ref(''), empty = ref(false), open = ref(false), busy = ref(false), pending = ref(false), unknown = ref(false), recovering = ref(false), error = ref(''), errors = ref<Record<string, string[]>>({}), proofId = ref<string | null>(null), proofName = ref(''), proofError = ref('')
const receiptFilterColumns: FilterPanelColumn[] = [
  { id: 'received', label: 'Recebimento', icon: 'i-lucide-calendar', control: 'date-range', allowOpenRange: true }
]
const receiptFilters = computed<DataTableFilterModel[]>({
  get: (): DataTableFilterModel[] => from.value || to.value ? [{ columnId: 'received', type: 'date', operator: 'is between', values: [from.value, to.value] }] : [],
  set: (filters) => {
    const range = filters.find(filter => filter.columnId === 'received')?.values ?? []
    navigation.value = { ...navigation.value, from: String(range[0] ?? ''), to: String(range[1] ?? ''), page: 1 }
  }
})
const proofUploadPending = ref(false), proofRequired = ref(false), invoiceChoicesLoading = ref(false)
const key = computed(() => command.recoveryKey.value)
const recoverable = computed(() => !!command.pending.value || !!key.value)
const identity = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identity)
const reversing = ref<Receipt | null>(null)
let generation = 0
let proofUploadGeneration = 0
const columns: TableColumn<Receipt>[] = [{ accessorKey: 'received_on', header: 'Data' }, { accessorKey: 'amount', header: 'Valor' }, { accessorKey: 'reference', header: 'Referência' }, { id: 'allocations', header: 'Alocações' }, { id: 'status', header: 'Situação' }, { id: 'actions', header: '' }]
async function load() {
  const read = reads.begin('load')
  const n = ++generation
  loading.value = true
  loadError.value = ''
  empty.value = false
  await context.refresh()
  if (!read.current() || n !== generation) return
  if (!context.capabilities.value?.can_read) {
    rows.value = []
    loadError.value = 'A conta atual não pode consultar recebimentos.'
    loading.value = false
    return
  }
  const token = context.capture()
  try {
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value }
    if (from.value)params.received_from = from.value
    if (to.value)params.received_to = to.value
    const r = await api<ListResponse<Receipt>>('/erp-commercial/receipts', { signal: read.signal, params })
    if (!read.current() || n !== generation || !context.isCurrent(token)) return
    if (!Array.isArray(r.data) || !r.meta) throw Error('Resposta de recebimentos incompleta.')
    const availablePage = officeErpAvailablePage(r.meta)
    if (page.value !== availablePage) {
      page.value = availablePage
      return
    }
    rows.value = r.data
    total.value = r.meta.total
    lastPage.value = Math.max(1, r.meta.last_page)
    page.value = r.meta.current_page
    empty.value = r.meta.total === 0
  } catch (e) {
    if (read.current() && n === generation) loadError.value = e instanceof Error ? e.message : 'Não foi possível consultar recebimentos.'
  } finally {
    if (read.current() && n === generation) loading.value = false
  }
}
async function loadInvoiceChoices() {
  if (!open.value || recoverable.value) return
  const read = reads.begin('invoiceChoices')
  const stamp = identity.value
  invoiceChoicesLoading.value = true
  invoices.value = []
  try {
    await context.refresh()
    if (!read.current() || !open.value || stamp !== identity.value) return
    if (!context.capabilities.value?.can_read) throw new Error('A conta atual não pode consultar faturas.')
    const token = context.capture()
    const result = await api<ListResponse<InvoiceChoice>>('/erp-commercial/invoices', { signal: read.signal, params: { document_status: 'issued', per_page: 100 } })
    if (!read.current() || !open.value || !context.isCurrent(token)) return
    if (!Array.isArray(result.data)) throw new Error('As faturas disponíveis retornaram dados incompletos.')
    invoices.value = result.data.filter(value => value.document_status === 'issued' && value.balance.outstanding !== '0.00')
  } catch (failure) {
    if (read.current() && open.value && stamp === identity.value) error.value = failure instanceof Error ? failure.message : 'Não foi possível consultar as faturas disponíveis.'
  } finally {
    if (read.current() && stamp === identity.value) invoiceChoicesLoading.value = false
  }
}
watch(open, (value) => {
  if (value) void loadInvoiceChoices()
  else {
    reads.cancel('invoiceChoices')
    invoiceChoicesLoading.value = false
    invoices.value = []
  }
}, { flush: 'sync' })
async function uploadProof(file: File) {
  const request = ++proofUploadGeneration
  proofId.value = null
  proofName.value = ''
  proofRequired.value = true
  proofUploadPending.value = true
  proofError.value = ''
  if (!['application/pdf', 'image/png', 'image/jpeg'].includes(file.type) || file.size > 5 * 1024 * 1024) {
    proofError.value = 'Envie PDF, PNG ou JPEG de até 5 MiB.'
    proofUploadPending.value = false
    return
  }
  const token = context.capture()
  const data = new FormData()
  data.append('file', file)
  try {
    const response = await api<{ data: { id: string } }>('/erp-commercial/receipt-proofs', { method: 'POST', body: data })
    if (!context.isCurrent(token) || request !== proofUploadGeneration) return
    proofId.value = response.data.id
    proofName.value = file.name
  } catch (e) {
    if (context.isCurrent(token) && request === proofUploadGeneration) proofError.value = e instanceof Error ? e.message : 'Não foi possível enviar a evidência privada.'
  } finally {
    if (context.isCurrent(token) && request === proofUploadGeneration) proofUploadPending.value = false
  }
}
function selectProof() {
  proofId.value = null
  proofName.value = ''
  proofRequired.value = true
  proofError.value = ''
}
async function create(payload: { amount: string, received_on: string, reference?: string, proof_id?: string, allocations: Array<{ invoice_id: number, amount: string }> }) {
  if (busy.value || pending.value || unknown.value || recoverable.value || proofUploadPending.value || invoiceChoicesLoading.value) {
    if (proofUploadPending.value) proofError.value = 'Aguarde o envio do comprovante privado antes de registrar o recebimento.'
    return
  }
  if (proofRequired.value && !proofId.value) {
    proofError.value = 'Envie novamente o comprovante antes de registrar o recebimento.'
    return
  }
  busy.value = true
  error.value = ''
  errors.value = {}
  const token = context.capture()
  try {
    const r = await command.execute<unknown>('receipt.record', '/erp-commercial/receipts', payload)
    if (!context.isCurrent(token) || r.status === 'stale') return
    if (r.status === 'unknown') {
      pending.value = true
      unknown.value = true
      open.value = true
      return
    }
    if (r.status === 'failed') {
      error.value = r.failure.message
      errors.value = r.failure.errors ?? {}
      return
    }
    pending.value = false
    unknown.value = false
    open.value = false
    proofId.value = null
    proofName.value = ''
    proofRequired.value = false
    await load()
  } finally { if (context.isCurrent(token)) busy.value = false }
}
async function reverse(p: { reason: string }) {
  const receipt = reversing.value
  if (!receipt || busy.value || unknown.value || recoverable.value) return
  busy.value = true
  error.value = ''
  const token = context.capture()
  try {
    const r = await command.execute<unknown>('receipt.reverse', '/erp-commercial/receipts/' + receipt.id + '/reverse', p)
    if (!context.isCurrent(token) || r.status === 'stale') return
    if (r.status === 'unknown') {
      unknown.value = true
      pending.value = true
      return
    }
    if (r.status === 'failed') {
      error.value = r.failure.message
      return
    }
    unknown.value = false
    pending.value = false
    reversing.value = null
    await load()
  } finally { if (context.isCurrent(token)) busy.value = false }
}
async function recover() {
  if (!key.value || recovering.value) return
  const token = context.capture()
  const recoveringReceipt = open.value && unknown.value && !reversing.value
  recovering.value = true
  try {
    const r = await command.recover<unknown>(key.value)
    if (!context.isCurrent(token)) return
    if (r.status === 'confirmed') {
      unknown.value = false
      pending.value = false
      open.value = false
      reversing.value = null
      if (recoveringReceipt) {
        proofId.value = null
        proofName.value = ''
        proofRequired.value = false
        proofError.value = ''
      }
      await load()
    } else if (r.status === 'failed')error.value = r.failure.message
  } finally { if (context.isCurrent(token)) recovering.value = false }
}
watch([page, from, to, perPage], () => void load(), { immediate: true })
watch([from, to, perPage], () => {
  page.value = 1
}, { flush: 'sync' })
watch(identity, () => {
  generation++
  proofUploadGeneration++
  rows.value = []
  invoices.value = []
  total.value = 0
  empty.value = false
  loadError.value = ''
  open.value = false
  reversing.value = null
  proofId.value = null
  proofName.value = ''
  proofError.value = ''
  proofRequired.value = false
  proofUploadPending.value = false
  pending.value = false
  unknown.value = false
  recovering.value = false
  busy.value = false
  error.value = ''
  errors.value = {}
  void load()
}, { flush: 'sync' })
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-col gap-5">
    <OfficeErpPanel title="Recebimentos" description="Pagamentos registrados e suas alocações.">
      <template #action>
        <UButton icon="i-lucide-plus" @click="open=true">
          Registrar recebimento
        </UButton>
      </template>
      <template #toolbar>
        <DataTableFilterPanel v-model="receiptFilters" :columns="receiptFilterColumns" />
      </template>
      <div class="flex min-w-0 flex-col gap-4 p-4 sm:p-6">
        <UAlert
          v-if="loadError"
          color="error"
          title="Não foi possível carregar recebimentos"
          :description="loadError"
        >
          <template #description>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span>{{ loadError }}</span><UButton variant="outline" @click="load">
                Tentar novamente
              </UButton>
            </div>
          </template>
        </UAlert><UAlert
          v-else-if="error"
          color="error"
          title="A operação não foi concluída"
          :description="error"
        /><UAlert v-else-if="empty" title="Nenhum recebimento encontrado" /><div v-else class="overflow-x-auto">
          <OfficeErpDataTable :data="rows" :columns="columns" :loading="loading">
            <template #received_on-cell="{ row }">
              {{ row.original.received_on.split('-').reverse().join('/') }}
            </template>
            <template #reference-cell="{ row }">
              <span class="block max-w-64 min-w-40 whitespace-normal wrap-anywhere">{{ row.original.reference || '—' }}</span>
            </template>
            <template #amount-cell="{ row }">
              {{ formatOfficeErpMoney(row.original.amount) }}
            </template><template #allocations-cell="{ row }">
              <span v-for="(a, n) in row.original.allocations" :key="n" class="mr-2">Fatura {{ a.invoice_id }} · {{ formatOfficeErpMoney(a.amount) }}</span>
            </template><template #status-cell="{ row }">
              <UBadge :color="row.original.reversal?'warning':'success'" variant="subtle">
                {{ row.original.reversal?'Revertido':'Registrado' }}
              </UBadge>
            </template><template #actions-cell="{ row }">
              <UButton
                v-if="!row.original.reversal"
                size="sm"
                color="error"
                variant="outline"
                @click="reversing=row.original"
              >
                Reverter
              </UButton><span v-else>{{ row.original.reversal.reason }}</span>
            </template>
          </OfficeErpDataTable>
        </div>
        <OfficeErpPagination
          v-if="total > 0"
          v-model:page="page"
          v-model:per-page="perPage"
          :total="total"
          :loading="loading"
          label="recebimentos"
        />
      </div>
    </OfficeErpPanel>
    <UAlert v-if="recoverable && !unknown" color="warning" title="Confirmação pendente">
      <template #description>
        <div>
          Consulte a operação antes de tentar novamente. <UButton :disabled="recovering" @click="recover">
            Consultar operação
          </UButton>
        </div>
      </template>
    </UAlert>
    <OfficeErpReceiptModal
      :key="identity"
      v-model:open="open"
      :invoices="invoices.map(i => ({ id: i.id, label: 'Fatura '+(i.number||i.id)+' · '+(i.bill_to_snapshot.client?.name||i.bill_to_snapshot.company?.name||''), outstanding: i.balance.outstanding }))"
      :proof-id="proofId"
      :proof-name="proofName"
      :proof-error="proofError"
      :errors="errors"
      :error="error"
      :busy="busy||recovering||proofUploadPending||invoiceChoicesLoading"
      :pending="pending"
      :unknown="recoverable||unknown"
      :recovery-key="key"
      @submit="create"
      @proof-selection="selectProof"
      @upload-proof="uploadProof"
      @recover="recover"
    />
    <OfficeErpReversalModal
      :key="identity + (reversing?.id ?? '')"
      :open="!!reversing"
      :busy="busy||recovering"
      :unknown="recoverable||unknown"
      :recovery-key="key"
      :error="error"
      :errors="errors"
      @update:open="value => { if (!value)reversing=null }"
      @submit="reverse"
      @recover="recover"
    />
  </div>
</template>
