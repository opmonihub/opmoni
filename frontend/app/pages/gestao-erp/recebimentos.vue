<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'

definePageMeta({ middleware: 'auth' })
interface InvoiceChoice { id: number, number: string | null, document_status: string, balance: { outstanding: string }, bill_to_snapshot: { client?: { name?: string }, company?: { name?: string } | null } }
interface Receipt { id: number, amount: string, received_on: string, reference: string | null, proof_id: string | null, allocations: Array<{ invoice_id: number, amount: string }>, reversal: { id: number, reason: string } | null }
interface ListResponse<T> { data: T[], meta: { current_page: number, last_page: number, per_page: number, total: number } }
const { api } = useAccessApi(), context = useOfficeErpContext(), command = useOfficeErpCommand()
const { user, currentAccount } = useAuth(), sessionGeneration = useState('access-session-generation', () => 0)
const rows = ref<Receipt[]>([]), invoices = ref<InvoiceChoice[]>([]), total = ref(0), lastPage = ref(1), page = ref(1), perPage = ref<25 | 50 | 100>(25)
const from = ref(''), to = ref(''), loading = ref(false), loadError = ref(''), empty = ref(false), open = ref(false), busy = ref(false), pending = ref(false), unknown = ref(false), recovering = ref(false), error = ref(''), errors = ref<Record<string, string[]>>({}), proofId = ref<string | null>(null), proofName = ref(''), proofError = ref('')
const proofUploadPending = ref(false), proofRequired = ref(false)
const key = computed(() => command.recoveryKey.value)
const recoverable = computed(() => !!command.pending.value || !!key.value)
const identity = computed(() => JSON.stringify([user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null, context.capabilities.value?.capability_key ?? null]))
const reversing = ref<Receipt | null>(null)
let generation = 0
let proofUploadGeneration = 0
const columns: TableColumn<Receipt>[] = [{ accessorKey: 'received_on', header: 'Data' }, { accessorKey: 'amount', header: 'Valor' }, { accessorKey: 'reference', header: 'Referência' }, { id: 'allocations', header: 'Alocações' }, { id: 'status', header: 'Situação' }, { id: 'actions', header: '' }]
async function load() {
  const n = ++generation
  loading.value = true
  loadError.value = ''
  empty.value = false
  await context.refresh()
  if (n !== generation) return
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
    const [r, i] = await Promise.all([api<ListResponse<Receipt>>('/erp-commercial/receipts', { params }), api<ListResponse<InvoiceChoice>>('/erp-commercial/invoices', { params: { document_status: 'issued', per_page: 100 } })])
    if (n !== generation || !context.isCurrent(token)) return
    if (!Array.isArray(r.data) || !r.meta) throw Error('Resposta de recebimentos incompleta.')
    rows.value = r.data
    total.value = r.meta.total
    lastPage.value = Math.max(1, r.meta.last_page)
    page.value = r.meta.current_page
    invoices.value = i.data.filter(x => x.document_status === 'issued' && x.balance.outstanding !== '0.00')
    empty.value = r.meta.total === 0
  } catch (e) {
    if (n === generation) loadError.value = e instanceof Error ? e.message : 'Não foi possível consultar recebimentos.'
  } finally {
    if (n === generation) loading.value = false
  }
}
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
function clearSelectedProof(event: Event) {
  const input = event.target as HTMLInputElement | null
  if (input?.type !== 'file' || input.getAttribute('aria-label') !== 'Selecionar comprovante') return
  proofId.value = null
  proofName.value = ''
  proofRequired.value = true
  proofError.value = ''
}
onMounted(() => {
  if (typeof window !== 'undefined') window.addEventListener('change', clearSelectedProof, true)
})
onBeforeUnmount(() => {
  if (typeof window !== 'undefined') window.removeEventListener('change', clearSelectedProof, true)
})
async function create(payload: { amount: string, received_on: string, reference?: string, proof_id?: string, allocations: Array<{ invoice_id: number, amount: string }> }) {
  if (busy.value || pending.value || unknown.value || recoverable.value || proofUploadPending.value) {
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
  recovering.value = true
  try {
    const r = await command.recover<unknown>(key.value)
    if (!context.isCurrent(token)) return
    if (r.status === 'confirmed') {
      unknown.value = false
      pending.value = false
      open.value = false
      reversing.value = null
      await load()
    } else if (r.status === 'failed')error.value = r.failure.message
  } finally { if (context.isCurrent(token)) recovering.value = false }
}
watch([page, from, to, perPage], () => void load(), { immediate: true })
watch([from, to], () => {
  page.value = 1
})
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
  <div class="flex min-h-0 flex-col gap-4">
    <DataTablePanelList title="Recebimentos" description="Registros manuais e suas alocações. Reversão é um ajuste interno, não uma confirmação bancária.">
      <template #action>
        <UButton @click="open=true">
          Registrar recebimento
        </UButton>
      </template><template #toolbar>
        <UFormField label="Recebido de">
          <UInput v-model="from" type="date" />
        </UFormField><UFormField label="Recebido até">
          <UInput v-model="to" type="date" />
        </UFormField>
      </template><UAlert
        v-if="loadError"
        color="error"
        title="Não foi possível carregar recebimentos"
        :description="loadError"
      >
        <template #description>
          <div class="flex justify-between">
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
        <UTable :data="rows" :columns="columns" :loading="loading">
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
        </UTable>
      </div><div v-if="total>0" class="flex items-center justify-between">
        <span>{{ total }} recebimentos · página {{ page }} de {{ lastPage }}</span><UPagination
          v-model:page="page"
          :total="total"
          :items-per-page="perPage"
          :disabled="loading"
        />
      </div>
    </DataTablePanelList>
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
      :busy="busy||recovering||proofUploadPending"
      :pending="pending"
      :unknown="recoverable||unknown"
      :recovery-key="key"
      @submit="create"
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
