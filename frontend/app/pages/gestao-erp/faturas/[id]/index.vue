<script setup lang="ts">
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { formatOfficeErpMoney, parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'

definePageMeta({ middleware: 'auth' })
interface Invoice { id: number, contract_id: number, kind: string, document_status: string, number: string | null, due_on: string, period_start: string, period_end: string, items_snapshot: Array<{ description: string, amount: string, discount: string }>, adjustments: Array<{ id: number, direction: string, amount: string, reason: string, corrects_adjustment_id: number | null }>, balance: { original_total: string, adjusted_charge: string, effective_received: string, outstanding: string }, seller_snapshot: { account?: { name?: string } }, bill_to_snapshot: { client?: { name?: string }, company?: { name?: string } | null } }
const route = useRoute(), router = useRouter(), { api } = useAccessApi(), context = useOfficeErpContext(), command = useOfficeErpCommand()
const { user, currentAccount } = useAuth(), sessionGeneration = useState('access-session-generation', () => 0)
const record = ref<Invoice | null>(null), contracts = ref<Array<{ id: number, client: { name: string } }>>([]), loadError = ref(''), error = ref(''), errors = ref<Record<string, string[]>>({}), busy = ref(false), unknown = ref(false), recovering = ref(false), open = ref(false), reason = ref('')
const draftItems = ref<Array<{ description: string, amount: string, discount: string }>>([{ description: '', amount: '', discount: '0,00' }])
const f = reactive({ contract_id: '', period_start: '', period_end: '', due_on: '' }), isNew = computed(() => String(route.params.id) === 'nova'), id = computed(() => String(route.params.id)), key = computed(() => command.recoveryKey.value), recoverable = computed(() => !!command.pending.value || !!key.value)
const identity = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identity)
let generation = 0
async function load() {
  const read = reads.begin('load')
  const n = ++generation
  record.value = null
  loadError.value = ''
  await context.refresh()
  if (!read.current() || n !== generation) return
  if (!context.capabilities.value?.can_read) {
    loadError.value = 'A conta atual não pode consultar faturas.'
    return
  }
  try {
    const token = context.capture()
    if (isNew.value) {
      const r = await api<{ data: Array<{ id: number, client: { name: string } }> }>('/erp-commercial/contracts', { signal: read.signal, params: { agreement_status: 'effective', per_page: 100 } })
      if (read.current() && n === generation && context.isCurrent(token))contracts.value = r.data
    } else {
      const r = await api<{ data: Invoice }>('/erp-commercial/invoices/' + encodeURIComponent(id.value), { signal: read.signal })
      if (!read.current() || n !== generation || !context.isCurrent(token)) return
      if (!r.data || r.data.id !== Number(id.value)) throw Error('Fatura indisponível nesta conta.')
      record.value = r.data
      if (r.data.document_status === 'draft') {
        f.contract_id = String(r.data.contract_id)
        draftItems.value = r.data.items_snapshot.map(item => ({ ...item }))
        f.period_start = r.data.period_start
        f.period_end = r.data.period_end
        f.due_on = r.data.due_on
      }
    }
  } catch (e) { if (read.current() && n === generation)loadError.value = e instanceof Error ? e.message : 'Não foi possível consultar a fatura.' }
}
function body() {
  if (!draftItems.value.length || draftItems.value.length > 100) throw Error('A fatura deve ter de uma a cem linhas.')
  const items = draftItems.value.map((item, index) => {
    const amount = parseOfficeErpMoney(item.amount)
    const discount = parseOfficeErpMoney(item.discount)
    if (!item.description.trim()) throw Error('Informe a descrição da linha ' + (index + 1) + '.')
    if (BigInt(discount.replace('.', '')) > BigInt(amount.replace('.', ''))) throw Error('O desconto não pode superar o valor da linha ' + (index + 1) + '.')
    return { description: item.description.trim(), amount, discount }
  })
  return { items, period_start: f.period_start, period_end: f.period_end, due_on: f.due_on }
}
async function mutate(action: string, path: string, data: Record<string, unknown>, method: 'POST' | 'PATCH' = 'POST') {
  if (busy.value || recovering.value || recoverable.value || unknown.value) return
  busy.value = true
  error.value = ''
  errors.value = {}
  const token = context.capture()
  try {
    const r = await command.execute<unknown>(action, path, data, method)
    if (!context.isCurrent(token) || r.status === 'stale') return
    if (r.status === 'unknown') {
      unknown.value = true
      return
    }
    if (r.status === 'failed') {
      error.value = r.failure.message
      errors.value = r.failure.errors ?? {}
      return
    }
    unknown.value = false
    if (isNew.value && r.data && typeof r.data === 'object' && 'id' in r.data) {
      await router.replace('/gestao-erp/faturas/' + (r.data as { id: number }).id)
    }
    await load()
  } finally { if (context.isCurrent(token)) busy.value = false }
}
async function save() {
  try {
    const b = body()
    if (isNew.value && !f.contract_id) throw Error('Selecione o contrato vigente.')
    await mutate('invoice.issue', isNew.value ? '/erp-commercial/invoices' : '/erp-commercial/invoices/' + id.value, isNew.value ? { contract_id: Number(f.contract_id), ...b } : b, isNew.value ? 'POST' : 'PATCH')
  } catch (e) { error.value = e instanceof Error ? e.message : 'Revise os campos.' }
}
async function issue() {
  await mutate('invoice.issue', '/erp-commercial/invoices/' + id.value + '/issue', {})
}
async function cancel() {
  await mutate('invoice.correct', '/erp-commercial/invoices/' + id.value + '/cancel', { reason: reason.value.trim() })
  if (!error.value) open.value = false
}
async function adjust(p: { direction: 'credit' | 'debit', amount: string, reason: string, corrects_adjustment_id?: number }) {
  await mutate('invoice.correct', '/erp-commercial/invoices/' + id.value + '/adjustments', p)
}
async function recover() {
  if (!key.value || recovering.value) return
  const token = context.capture()
  const pageId = id.value
  recovering.value = true
  try {
    const r = await command.recover<unknown>(key.value)
    if (!context.isCurrent(token) || pageId !== id.value) return
    if (r.status === 'confirmed') {
      unknown.value = false
      if (isNew.value) {
        const result = r.data && typeof r.data === 'object' ? r.data as { id?: unknown } : {}
        if (typeof result.id !== 'number' || !Number.isSafeInteger(result.id) || result.id < 1) {
          error.value = 'A operação foi confirmada, mas não retornou a fatura criada.'
          unknown.value = true
          return
        }
        await router.replace('/gestao-erp/faturas/' + result.id)
      }
      await load()
    } else if (r.status === 'failed')error.value = r.failure.message
  } finally {
    if (context.isCurrent(token)) recovering.value = false
  }
}
watch(() => route.params.id, () => void load(), { immediate: true })
watch(identity, () => {
  generation++
  record.value = null
  contracts.value = []
  loadError.value = ''
  error.value = ''
  errors.value = {}
  draftItems.value = [{ description: '', amount: '', discount: '0,00' }]
  f.contract_id = ''
  f.period_start = ''
  f.period_end = ''
  f.due_on = ''
  reason.value = ''
  open.value = false
  busy.value = false
  recovering.value = false
  unknown.value = false
  void load()
}, { flush: 'sync' })
</script>

<template>
  <div class="mx-auto flex w-full min-w-0 max-w-5xl flex-col gap-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <NuxtLink to="/gestao-erp/faturas" class="text-sm text-muted underline underline-offset-4">Faturas</NuxtLink><h2 class="mt-1 text-base font-semibold text-highlighted">
          {{ isNew?'Novo rascunho':'Fatura '+(record?.number||record?.id||'') }}
        </h2>
      </div><UButton v-if="record" :to="'/gestao-erp/faturas/'+record.id+'/imprimir'" variant="outline">
        Imprimir
      </UButton>
    </header>
    <UAlert
      v-if="loadError"
      color="error"
      title="Fatura indisponível"
      :description="loadError"
    /><UAlert
      v-if="error"
      color="error"
      title="Não foi possível concluir"
      :description="error"
    />
    <UAlert v-if="recoverable || unknown" color="warning" title="Confirmação pendente">
      <template #description>
        <div>
          Consulte a operação antes de tentar novamente. <UButton :disabled="recovering" @click="recover">
            Consultar operação
          </UButton>
        </div>
      </template>
    </UAlert>
    <UCard v-if="isNew||record?.document_status==='draft'">
      <template #header>
        <h2>Dados do rascunho</h2>
      </template><div class="grid gap-3 sm:grid-cols-2">
        <UFormField v-if="isNew" label="Contrato">
          <USelect v-model="f.contract_id" :items="contracts.map(c => ({ label: c.client.name+' · '+c.id, value: String(c.id) }))" class="w-full" />
        </UFormField><UFormField label="Início do período">
          <UInput v-model="f.period_start" type="date" class="w-full" />
        </UFormField><UFormField label="Fim do período">
          <UInput v-model="f.period_end" type="date" class="w-full" />
        </UFormField><UFormField label="Vencimento">
          <UInput v-model="f.due_on" type="date" class="w-full" />
        </UFormField>
      </div>
      <div v-for="(item, index) in draftItems" :key="index" class="mt-4 grid gap-3 border-t border-default pt-4 sm:grid-cols-3">
        <UFormField :label="'Descrição da linha ' + (index + 1)" :error="errors['items.' + index + '.description']?.[0]">
          <UInput v-model="item.description" class="w-full" />
        </UFormField>
        <UFormField :label="'Valor da linha ' + (index + 1)" :error="errors['items.' + index + '.amount']?.[0]">
          <UInput v-model="item.amount" inputmode="decimal" class="w-full" />
        </UFormField>
        <UFormField :label="'Desconto da linha ' + (index + 1)" :error="errors['items.' + index + '.discount']?.[0]">
          <UInput v-model="item.discount" inputmode="decimal" class="w-full" />
        </UFormField>
        <UButton
          v-if="draftItems.length > 1"
          type="button"
          color="neutral"
          variant="outline"
          @click="draftItems.splice(index, 1)"
        >
          Remover linha
        </UButton>
      </div>
      <UButton
        v-if="draftItems.length < 100"
        class="mt-3"
        type="button"
        color="neutral"
        variant="outline"
        @click="draftItems.push({ description: '', amount: '', discount: '0,00' })"
      >
        Adicionar linha
      </UButton>
      <template #footer>
        <UButton :disabled="busy||recovering||recoverable||unknown" :loading="busy||recovering" @click="save">
          Salvar rascunho
        </UButton>
      </template>
    </UCard>
    <UCard v-if="record">
      <template #header>
        <h2>Documento e saldo</h2>
      </template><dl class="grid gap-3 sm:grid-cols-2">
        <div><dt>Vendedor</dt><dd>{{ record.seller_snapshot.account?.name||'—' }}</dd></div><div><dt>Cliente</dt><dd>{{ record.bill_to_snapshot.client?.name||record.bill_to_snapshot.company?.name||'—' }}</dd></div><div><dt>Situação</dt><dd>{{ record.document_status==='issued'?'Emitida':record.document_status==='draft'?'Rascunho':'Cancelada' }}</dd></div><div><dt>Período</dt><dd>{{ record.period_start }} a {{ record.period_end }}</dd></div><div><dt>Vencimento</dt><dd>{{ record.due_on }}</dd></div><div><dt>Total original</dt><dd>{{ formatOfficeErpMoney(record.balance.original_total) }}</dd></div><div><dt>Cobrança ajustada</dt><dd>{{ formatOfficeErpMoney(record.balance.adjusted_charge) }}</dd></div><div><dt>Recebido efetivo</dt><dd>{{ formatOfficeErpMoney(record.balance.effective_received) }}</dd></div><div><dt>Em aberto</dt><dd>{{ formatOfficeErpMoney(record.balance.outstanding) }}</dd></div>
      </dl><h3 class="mt-4 font-medium">
        Itens congelados
      </h3><p v-for="(i, n) in record.items_snapshot" :key="n">
        {{ i.description }} · {{ formatOfficeErpMoney(i.amount) }} · desconto {{ formatOfficeErpMoney(i.discount) }}
      </p><h3 class="mt-4 font-medium">
        Ajustes
      </h3><p v-for="a in record.adjustments" :key="a.id">
        {{ a.direction==='credit'?'Crédito':'Débito' }} · {{ formatOfficeErpMoney(a.amount) }} · {{ a.reason }} <span v-if="a.corrects_adjustment_id">(corrige {{ a.corrects_adjustment_id }})</span>
      </p><div class="mt-4 flex gap-2">
        <UButton v-if="record.document_status==='draft'" :disabled="busy||recoverable" @click="issue">
          Emitir fatura
        </UButton><UButton
          v-if="record.document_status!=='canceled'"
          color="error"
          variant="outline"
          :disabled="busy||recoverable"
          @click="open=true"
        >
          Cancelar
        </UButton>
      </div>
    </UCard>
    <UCard v-if="record?.document_status==='issued'">
      <template #header>
        <h2>Ajustes</h2>
      </template><OfficeErpAdjustmentForm
        direction="credit"
        :errors="errors"
        :busy="busy"
        :unknown="recoverable || unknown"
        :recovery-key="key"
        @submit="adjust"
        @recover="recover"
      /><OfficeErpAdjustmentForm
        direction="debit"
        :errors="errors"
        :busy="busy"
        :unknown="recoverable || unknown"
        :recovery-key="key"
        @submit="adjust"
        @recover="recover"
      />
      <div v-for="adjustment in record.adjustments.filter(item => item.corrects_adjustment_id === null)" :key="'correction-' + adjustment.id" class="mt-5 border-t border-default pt-4">
        <h3 class="mb-3 font-medium">
          Corrigir ajuste {{ adjustment.id }}
        </h3>
        <OfficeErpAdjustmentForm
          :direction="adjustment.direction === 'credit' ? 'debit' : 'credit'"
          :corrects-adjustment-id="adjustment.id"
          :initial-amount="adjustment.amount"
          :initial-reason="adjustment.reason"
          :errors="errors"
          :busy="busy"
          :unknown="recoverable || unknown"
          :recovery-key="key"
          @submit="adjust"
          @recover="recover"
        />
      </div>
    </UCard>
    <UModal v-model:open="open" title="Cancelar fatura" description="Informe o motivo do cancelamento.">
      <template #body>
        <UFormField label="Motivo" :error="errors.reason?.[0]">
          <UTextarea v-model="reason" />
        </UFormField>
      </template><template #footer>
        <UButton color="error" :disabled="busy||recoverable" @click="cancel">
          Confirmar cancelamento
        </UButton>
      </template>
    </UModal>
  </div>
</template>
