<script setup lang="ts">
import { formatOfficeErpMoney, parseOfficeErpMoney } from '#shared/utils/officeErpCommercial'

definePageMeta({ middleware: 'auth', layout: 'erp' })
interface Invoice { id: number, contract_id: number, kind: string, document_status: string, number: string | null, due_on: string, period_start: string, period_end: string, items_snapshot: Array<{ description: string, amount: string, discount: string }>, adjustments: Array<{ id: number, direction: string, amount: string, reason: string, corrects_adjustment_id: number | null }>, balance: { original_total: string, adjusted_charge: string, effective_received: string, outstanding: string }, seller_snapshot: { account?: { name?: string } }, bill_to_snapshot: { client?: { name?: string }, company?: { name?: string } | null } }
const route = useRoute(), router = useRouter(), { api } = useAccessApi(), context = useOfficeErpContext(), command = useOfficeErpCommand()
const { user, currentAccount } = useAuth(), sessionGeneration = useState('access-session-generation', () => 0)
const record = ref<Invoice | null>(null), contracts = ref<Array<{ id: number, client: { name: string } }>>([]), loadError = ref(''), error = ref(''), errors = ref<Record<string, string[]>>({}), busy = ref(false), unknown = ref(false), recovering = ref(false), open = ref(false), reason = ref('')
const f = reactive({ contract_id: '', description: '', amount: '', discount: '0,00', period_start: '', period_end: '', due_on: '' }), isNew = computed(() => String(route.params.id) === 'nova'), id = computed(() => String(route.params.id)), key = computed(() => command.recoveryKey.value)
const identity = computed(() => JSON.stringify([user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null, context.capabilities.value?.capability_key ?? null]))
let generation = 0
async function load() {
  const n = ++generation
  record.value = null
  loadError.value = ''
  await context.refresh()
  if (n !== generation) return
  if (!context.capabilities.value?.can_read) {
    loadError.value = 'A conta atual não pode consultar faturas.'
    return
  }
  try {
    const token = context.capture()
    if (isNew.value) {
      const r = await api<{ data: Array<{ id: number, client: { name: string } }> }>('/erp-commercial/contracts', { params: { agreement_status: 'effective', per_page: 100 } })
      if (n === generation && context.isCurrent(token))contracts.value = r.data
    } else {
      const r = await api<{ data: Invoice }>('/erp-commercial/invoices/' + encodeURIComponent(id.value))
      if (n !== generation || !context.isCurrent(token)) return
      if (!r.data || r.data.id !== Number(id.value)) throw Error('Fatura indisponível nesta conta.')
      record.value = r.data
      if (r.data.document_status === 'draft') {
        const i = r.data.items_snapshot[0]
        f.contract_id = String(r.data.contract_id)
        f.description = i?.description ?? ''
        f.amount = i?.amount ?? ''
        f.discount = i?.discount ?? '0.00'
        f.period_start = r.data.period_start
        f.period_end = r.data.period_end
        f.due_on = r.data.due_on
      }
    }
  } catch (e) { if (n === generation)loadError.value = e instanceof Error ? e.message : 'Não foi possível consultar a fatura.' }
}
function body() {
  const a = parseOfficeErpMoney(f.amount), d = parseOfficeErpMoney(f.discount)
  if (!f.description.trim()) throw Error('Informe a descrição.')
  if (BigInt(a.replace('.', '')) <= 0n || BigInt(d.replace('.', '')) > BigInt(a.replace('.', ''))) throw Error('Revise os valores da linha.')
  return { items: [{ description: f.description.trim(), amount: a, discount: d }], period_start: f.period_start, period_end: f.period_end, due_on: f.due_on }
}
async function mutate(action: string, path: string, data: Record<string, unknown>, method: 'POST' | 'PATCH' = 'POST') {
  if (busy.value || unknown.value) return
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
  } finally { busy.value = false }
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
  recovering.value = true
  try {
    const r = await command.recover<unknown>(key.value)
    if (r.status === 'confirmed') {
      unknown.value = false
      await load()
    } else if (r.status === 'failed')error.value = r.failure.message
  } finally { recovering.value = false }
}
watch(() => route.params.id, () => void load(), { immediate: true })
watch(identity, () => {
  generation++
  record.value = null
  contracts.value = []
  loadError.value = ''
  error.value = ''
  unknown.value = false
  void load()
}, { flush: 'sync' })
</script>

<template>
  <main class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4">
    <header class="flex justify-between">
      <div>
        <NuxtLink to="/gestao-erp/faturas">Faturas</NuxtLink><h1 class="text-2xl font-semibold">
          {{ isNew?'Novo rascunho':'Fatura '+(record?.number||record?.id||'') }}
        </h1>
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
    <UAlert v-if="unknown" color="warning" title="Confirmação pendente">
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
          <USelect v-model="f.contract_id" :items="contracts.map(c => ({ label: c.client.name+' · '+c.id, value: String(c.id) }))" />
        </UFormField><UFormField label="Descrição" :error="errors['items.0.description']?.[0]">
          <UInput v-model="f.description" />
        </UFormField><UFormField label="Valor" :error="errors['items.0.amount']?.[0]">
          <UInput v-model="f.amount" inputmode="decimal" />
        </UFormField><UFormField label="Desconto" :error="errors['items.0.discount']?.[0]">
          <UInput v-model="f.discount" inputmode="decimal" />
        </UFormField><UFormField label="Início do período">
          <UInput v-model="f.period_start" type="date" />
        </UFormField><UFormField label="Fim do período">
          <UInput v-model="f.period_end" type="date" />
        </UFormField><UFormField label="Vencimento">
          <UInput v-model="f.due_on" type="date" />
        </UFormField>
      </div><template #footer>
        <UButton :disabled="busy||unknown" :loading="busy" @click="save">
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
        <UButton v-if="record.document_status==='draft'" :disabled="busy||unknown" @click="issue">
          Emitir fatura
        </UButton><UButton
          v-if="record.document_status!=='canceled'"
          color="error"
          variant="outline"
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
        :unknown="unknown"
        :recovery-key="key"
        @submit="adjust"
        @recover="recover"
      /><OfficeErpAdjustmentForm
        direction="debit"
        :errors="errors"
        :busy="busy"
        :unknown="unknown"
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
          :unknown="unknown"
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
        <UButton color="error" :disabled="busy||unknown" @click="cancel">
          Confirmar cancelamento
        </UButton>
      </template>
    </UModal>
  </main>
</template>
