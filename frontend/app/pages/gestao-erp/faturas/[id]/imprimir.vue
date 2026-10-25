<script setup lang="ts">
import { formatOfficeErpMoney } from '#shared/utils/officeErpCommercial'

definePageMeta({ middleware: 'auth', layout: 'erp' })
interface PrintInvoice { id: number, number: string | null, document_status: string, issued_at: string | null, period_start: string, period_end: string, due_on: string, items_snapshot: Array<{ description: string, amount: string, discount: string }>, seller_snapshot: { account?: { name?: string } }, bill_to_snapshot: { client?: { name?: string, tax_id?: string }, company?: { name?: string, cnpj?: string } | null }, balance: { original_total: string, adjusted_charge: string, effective_received: string, outstanding: string }, adjustments: Array<{ direction: string, amount: string, reason: string }> }
const route = useRoute(), { api } = useAccessApi(), context = useOfficeErpContext()
const { user, currentAccount } = useAuth(), sessionGeneration = useState('access-session-generation', () => 0)
const invoice = ref<PrintInvoice | null>(null), error = ref(''), loading = ref(false), printing = ref(false)
const identity = computed(() => JSON.stringify([user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null, context.capabilities.value?.capability_key ?? null]))
let generation = 0
async function load() {
  const n = ++generation
  invoice.value = null
  error.value = ''
  loading.value = true
  await context.refresh()
  if (n !== generation) return
  try {
    if (!context.capabilities.value?.can_read) throw Error('Acesso comercial indisponível.')
    const token = context.capture()
    const result = await api<{ data: PrintInvoice }>('/erp-commercial/invoices/' + encodeURIComponent(String(route.params.id)) + '/print')
    if (n !== generation || !context.isCurrent(token)) return
    if (!result?.data || result.data.id !== Number(route.params.id) || result.data.document_status !== 'issued') throw Error('A fatura emitida não está disponível para impressão.')
    invoice.value = result.data
  } catch (e) {
    if (n === generation) error.value = e instanceof Error ? e.message : 'Não foi possível carregar a fatura.'
  } finally {
    if (n === generation) loading.value = false
  }
}
async function print() {
  if (!invoice.value || printing.value) return
  printing.value = true
  await nextTick()
  if (invoice.value)window.print()
  printing.value = false
}
watch(() => route.params.id, () => void load(), { immediate: true })
watch(identity, () => {
  generation++
  invoice.value = null
  error.value = ''
  void load()
}, { flush: 'sync' })
</script>

<template>
  <main class="mx-auto w-full max-w-4xl p-4">
    <div class="mb-5 flex flex-wrap justify-between gap-3 print:hidden">
      <NuxtLink :to="'/gestao-erp/faturas/'+route.params.id">Voltar à fatura</NuxtLink><UButton :disabled="!invoice||printing" @click="print">
        Imprimir ou salvar em PDF
      </UButton>
    </div>
    <UAlert v-if="loading" title="Carregando documento" description="O conteúdo protegido será exibido após a confirmação da conta." />
    <UAlert
      v-else-if="error"
      color="error"
      title="Documento indisponível"
      :description="error"
    >
      <template #description>
        <div class="space-y-2">
          <p>{{ error }}</p><UButton variant="outline" @click="load">
            Tentar novamente
          </UButton>
        </div>
      </template>
    </UAlert>
    <article v-else-if="invoice" class="space-y-6 rounded-lg border border-default bg-default p-6 print:border-0 print:p-0">
      <header class="flex justify-between border-b border-default pb-5">
        <div>
          <p class="text-sm text-muted">
            Documento interno · sem valor fiscal
          </p><h1 class="text-2xl font-bold">
            Fatura {{ invoice.number||invoice.id }}
          </h1><p>{{ invoice.seller_snapshot.account?.name }}</p>
        </div><dl class="text-right">
          <dt>Situação</dt><dd>Emitida</dd><dt class="mt-2">
            Vencimento
          </dt><dd>{{ invoice.due_on }}</dd>
        </dl>
      </header><section>
        <h2 class="font-semibold">
          Destinatário
        </h2><p>{{ invoice.bill_to_snapshot.client?.name||invoice.bill_to_snapshot.company?.name }}</p><p>{{ invoice.bill_to_snapshot.client?.tax_id||invoice.bill_to_snapshot.company?.cnpj }}</p>
      </section><section>
        <h2 class="font-semibold">
          Período de serviço
        </h2><p>{{ invoice.period_start }} a {{ invoice.period_end }}</p>
      </section><section>
        <h2 class="font-semibold">
          Itens
        </h2><div v-for="(item, n) in invoice.items_snapshot" :key="n" class="flex justify-between border-b border-default py-2">
          <span>{{ item.description }} · desconto {{ formatOfficeErpMoney(item.discount) }}</span><span>{{ formatOfficeErpMoney(item.amount) }}</span>
        </div>
      </section><section>
        <h2 class="font-semibold">
          Resumo
        </h2><dl class="grid grid-cols-2 gap-2">
          <dt>Total original</dt><dd>{{ formatOfficeErpMoney(invoice.balance.original_total) }}</dd><dt>Ajustes</dt><dd>{{ invoice.adjustments.map(a => (a.direction==='credit'?'Crédito ':'Débito ')+formatOfficeErpMoney(a.amount)+' · '+a.reason).join('; ')||'Nenhum' }}</dd><dt>Cobrança ajustada</dt><dd>{{ formatOfficeErpMoney(invoice.balance.adjusted_charge) }}</dd><dt>Recebido efetivo</dt><dd>{{ formatOfficeErpMoney(invoice.balance.effective_received) }}</dd><dt>Em aberto</dt><dd>{{ formatOfficeErpMoney(invoice.balance.outstanding) }}</dd>
        </dl>
      </section><p class="border-t border-default pt-4 text-sm text-muted">
        Documento interno do escritório. Use a impressão do navegador para imprimir ou salvar como PDF.
      </p>
    </article>
  </main>
</template>
