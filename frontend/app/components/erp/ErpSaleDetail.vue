<script setup lang="ts">
import type { ErpCommercialDocument, ErpCommercialLine, ErpFinanceReceipt, ErpObligation, ErpPayment, ErpSaleReturn } from '#shared/types/erpBusiness'

const props = defineProps<{
  area: 'quotes' | 'orders' | 'sales' | 'returns'
  document: ErpCommercialDocument | ErpSaleReturn
  payments: ErpPayment[]
  paymentIds: number[]
  obligation: ErpObligation | null
  receipt: ErpFinanceReceipt | null
  canEdit: boolean
  canConvert: boolean
  canPostSale: boolean
  canReturn: boolean
  canPostReturn: boolean
  canReceipt: boolean
  canRepresentation?: boolean
  canReadPayments: boolean
  canPaymentsIndex: boolean
  canObligationsIndex: boolean
  canFiscal: boolean
  busy: boolean
}>()
const emit = defineEmits<{ edit: [], convert: [], postSale: [], returnSale: [], postReturn: [], receipt: [paymentId: number], representation: [] }>()
const isReturn = computed(() => props.area === 'returns')
const commercial = computed(() => props.document as ErpCommercialDocument)
const returned = computed(() => props.document as ErpSaleReturn)
const lines = computed(() => (isReturn.value ? returned.value.lines : commercial.value.lines) as Array<ErpCommercialLine & { quantity: string, line_total?: string, unit_price?: string, description?: string }>)
function lineDescription(line: ErpCommercialLine & { source_line_id?: number }) {
  const snapshot = line.product_snapshot && typeof line.product_snapshot === 'object' ? line.product_snapshot : {}
  if (typeof snapshot.description === 'string' && snapshot.description.trim()) return snapshot.description
  const product = snapshot.product && typeof snapshot.product === 'object' ? snapshot.product as Record<string, unknown> : {}
  const variant = snapshot.variant && typeof snapshot.variant === 'object' ? snapshot.variant as Record<string, unknown> : {}
  const names = [product.name, variant.name].filter((value): value is string => typeof value === 'string' && value.trim().length > 0)
  if (names.length) return names.join(' / ')
  return `Linha de origem #${line.source_line_id ?? line.id ?? 'não disponível'}`
}
const numberLabel = computed(() => props.area === 'quotes' ? commercial.value.quote_no : props.area === 'orders' ? commercial.value.order_no : commercial.value.invoice_no)
const paid = computed(() => props.area === 'sales' && props.obligation?.status === 'settled' && props.obligation.open_amount === '0.0000')
function fiscalStatusLabel(value: string | null | undefined) {
  const labels: Record<string, string> = { none: 'Não iniciada', authorized: 'Autorizada' }
  return value ? labels[value] ?? `Situação fiscal não reconhecida (${value})` : 'Situação fiscal não informada'
}
function paymentStatusLabel(value: string | null | undefined) {
  const labels: Record<string, string> = { posted: 'Lançado', reversed: 'Estornado', compensation: 'Compensação' }
  return value ? labels[value] ?? `Situação do pagamento não reconhecida (${value})` : 'Situação do pagamento não informada'
}
const statusLabel = computed(() => isReturn.value
  ? returned.value.status === 'posted' ? 'Devolução lançada' : 'Rascunho de devolução'
  : commercial.value.status === 'posted'
    ? 'Venda lançada'
    : commercial.value.status === 'converted'
      ? props.area === 'quotes' ? 'Orçamento convertido' : 'Pedido convertido'
      : commercial.value.status === 'confirmed' ? 'Pedido confirmado' : 'Rascunho')
</script>

<template>
  <section class="min-w-0 space-y-5">
    <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-xs uppercase tracking-wide text-muted">
          {{ isReturn ? 'Devolução' : area === 'quotes' ? 'Orçamento' : area === 'orders' ? 'Pedido' : 'Venda' }}
        </p>
        <h2 class="break-words text-lg font-semibold text-highlighted">
          {{ numberLabel || `Registro #${document.id}` }}
        </h2>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <UBadge color="neutral" variant="subtle">
          {{ statusLabel }}
        </UBadge>
        <UBadge v-if="area === 'sales' && !isReturn" color="info" variant="subtle">
          Fiscal: {{ fiscalStatusLabel(commercial.fiscal_status) }}
        </UBadge>
        <UBadge v-if="paid" color="success" variant="subtle">
          Recebível liquidado
        </UBadge>
      </div>
    </div>

    <UAlert
      v-if="!isReturn && commercial.needs_reconciliation"
      color="warning"
      title="Conferência necessária"
      description="Os dados atuais exigem conciliação. Ações dependentes estão indisponíveis."
    />
    <UAlert
      v-if="area === 'orders' && !isReturn"
      color="info"
      title="Pedido comercial"
      description="Este pedido não reserva estoque. A disponibilidade é conferida ao lançar a venda."
    />

    <dl v-if="!isReturn" class="grid min-w-0 gap-3 rounded-lg border border-muted p-3 sm:grid-cols-2">
      <div>
        <dt class="text-xs text-muted">
          Documento
        </dt><dd class="break-all text-sm text-highlighted">
          {{ numberLabel || 'Atribuído pelo servidor' }} · #{{ commercial.id }}
        </dd>
      </div>
      <div>
        <dt class="text-xs text-muted">
          Total confirmado
        </dt><dd class="font-medium text-highlighted">
          {{ commercial.currency }} {{ commercial.total_amount }}
        </dd>
      </div>
      <div>
        <dt class="text-xs text-muted">
          Estabelecimento
        </dt><dd class="text-sm text-highlighted">
          #{{ commercial.erp_establishment_id }}
        </dd>
      </div>
      <div>
        <dt class="text-xs text-muted">
          Cliente
        </dt><dd class="text-sm text-highlighted">
          {{ commercial.erp_contact_id ? `#${commercial.erp_contact_id}` : 'Não vinculado' }}
        </dd>
      </div>
      <div>
        <dt class="text-xs text-muted">
          Subtotal
        </dt><dd class="text-sm text-highlighted">
          {{ commercial.currency }} {{ commercial.subtotal_amount }}
        </dd>
      </div>
      <div>
        <dt class="text-xs text-muted">
          Descontos / impostos
        </dt><dd class="text-sm text-highlighted">
          {{ commercial.discount_amount }} / {{ commercial.tax_amount }}
        </dd>
      </div>
    </dl>
    <dl v-else class="grid min-w-0 gap-3 rounded-lg border border-muted p-3 sm:grid-cols-2">
      <div>
        <dt class="text-xs text-muted">
          Devolução
        </dt><dd class="text-sm text-highlighted">
          #{{ returned.id }} · venda original #{{ returned.erp_sale_id }}
        </dd>
      </div>
      <div>
        <dt class="text-xs text-muted">
          Crédito oposto
        </dt><dd class="font-medium text-highlighted">
          {{ returned.currency }} {{ returned.total_amount }}
        </dd>
      </div>
      <div v-if="returned.credit_id">
        <dt class="text-xs text-muted">
          Obrigação de crédito
        </dt><dd class="text-sm text-highlighted">
          #{{ returned.credit_id }}
        </dd>
      </div>
      <div>
        <dt class="text-xs text-muted">
          Motivo
        </dt><dd class="text-sm text-highlighted">
          {{ returned.reason }}
        </dd>
      </div>
    </dl>

    <section class="min-w-0 space-y-2" aria-label="Itens do documento">
      <h3 class="text-sm font-semibold text-highlighted">
        Itens confirmados
      </h3>
      <div v-for="(line, index) in lines" :key="line.id ?? `${line.source_line_id ?? 'line'}-${index}`" class="grid min-w-0 gap-2 rounded-lg border border-muted p-3 sm:grid-cols-[minmax(0,1fr)_7rem_10rem]">
        <div class="min-w-0">
          <p class="break-words text-sm font-medium text-highlighted">
            {{ lineDescription(line) }}
          </p><p class="text-xs text-muted">
            {{ isReturn ? `Linha de venda #${line.source_line_id}` : `Linha #${line.id}` }}
          </p>
        </div>
        <p class="text-sm text-muted">
          Qtd. {{ line.quantity }}<span v-if="line.unit_price" class="block">Unitário {{ line.unit_price }}</span>
        </p>
        <p class="text-sm font-medium text-highlighted">
          {{ line.line_total ? `${commercial.currency} ${line.line_total}` : 'Valor confirmado no detalhe' }}<span v-if="line.discount_amount" class="block text-xs font-normal text-muted">Desconto {{ line.discount_amount }}</span><span v-if="line.tax_amount" class="block text-xs font-normal text-muted">Imposto informado {{ line.tax_amount }}</span>
        </p>
      </div>
    </section>

    <section v-if="area === 'sales' && !isReturn && commercial.status === 'posted' && commercial.finance" class="min-w-0 space-y-3 rounded-lg border border-muted p-3">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 class="text-sm font-semibold text-highlighted">
            Financeiro da venda
          </h3><p class="text-xs text-muted">
            Venda #{{ commercial.id }} · obrigação original #{{ commercial.finance.original_obligation_id ?? 'não disponível' }}
          </p>
        </div>
        <NuxtLink v-if="canObligationsIndex" to="/erp/financeiro#obligations" class="text-sm text-primary underline">Abrir obrigações no Financeiro</NuxtLink>
      </div>
      <dl class="grid gap-2 text-sm sm:grid-cols-3">
        <div>
          <dt class="text-xs text-muted">
            Principal original
          </dt><dd>{{ commercial.currency }} {{ commercial.finance.original_principal ?? 'Indisponível' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted">
            Saldo aberto original
          </dt><dd>{{ commercial.currency }} {{ commercial.finance.gross_original_due ?? 'Indisponível' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted">
            Crédito oposto total
          </dt><dd>{{ commercial.currency }} {{ commercial.finance.opposite_return_credit ?? 'Indisponível' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted">
            Créditos ainda abertos
          </dt><dd>{{ commercial.currency }} {{ commercial.finance.open_return_credit ?? 'Indisponível' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted">
            Exposição líquida
          </dt><dd>{{ commercial.currency }} {{ commercial.finance.net_exposure ?? 'Indisponível' }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted">
            Vencimento original
          </dt><dd>{{ commercial.finance.original_due_on ?? 'Não informado' }}</dd>
        </div>
      </dl>
      <div v-if="commercial.finance.return_credits?.length" class="space-y-2">
        <h4 class="text-xs font-semibold text-highlighted">
          Créditos vinculados às devoluções
        </h4>
        <div v-for="credit in commercial.finance.return_credits" :key="credit.id" class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-elevated px-3 py-2 text-sm">
          <span>Obrigação #{{ credit.id }} · devolução #{{ credit.source_id }} · aberto {{ credit.open_amount }} {{ commercial.currency }} · vencimento {{ credit.due_on ?? 'não informado' }}</span>
          <NuxtLink v-if="canObligationsIndex" to="/erp/financeiro#obligations" class="text-primary underline">Consultar no Financeiro</NuxtLink>
        </div>
      </div>
      <UAlert
        color="warning"
        variant="subtle"
        title="Sem compensação automática"
        description="A devolução não estorna o recebimento nem gera reembolso. Qualquer pagamento posterior é uma operação separada no Financeiro."
      />
      <div v-if="paymentIds.length" class="space-y-2">
        <h4 class="text-xs font-semibold text-highlighted">
          Recebimentos vinculados
        </h4>
        <div v-for="paymentId in paymentIds" :key="paymentId" class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-md bg-elevated px-3 py-2 text-sm">
          <span v-if="canReadPayments && payments.find(payment => payment.id === paymentId)" class="break-words">Pagamento #{{ paymentId }} · {{ payments.find(payment => payment.id === paymentId)?.amount }} {{ payments.find(payment => payment.id === paymentId)?.currency }} · {{ paymentStatusLabel(payments.find(payment => payment.id === paymentId)?.effective_status) }}</span>
          <span v-else>Pagamento #{{ paymentId }}</span>
          <UButton
            v-if="canReceipt"
            type="button"
            size="xs"
            color="neutral"
            variant="outline"
            icon="i-lucide-receipt"
            label="Abrir recibo"
            @click="emit('receipt', paymentId)"
          />
        </div>
      </div>
      <UAlert
        v-if="receipt"
        color="success"
        title="Recibo privado"
        :description="`${receipt.reference} · pagamento #${receipt.id} · ${receipt.amount} ${receipt.currency}`"
      />
      <div class="flex flex-wrap gap-3 text-sm">
        <NuxtLink v-if="canPaymentsIndex" to="/erp/financeiro#payments" class="text-primary underline">Abrir pagamentos no Financeiro · venda #{{ commercial.id }}</NuxtLink>
        <NuxtLink v-if="canFiscal" to="/erp/fiscal#nfe" class="text-primary underline">Abrir Fiscal · venda #{{ commercial.id }}</NuxtLink>
      </div>
    </section>

    <div class="flex flex-wrap justify-end gap-2 border-t border-muted pt-4">
      <UButton
        v-if="area === 'sales' && canRepresentation"
        type="button"
        color="neutral"
        variant="outline"
        icon="i-lucide-printer"
        label="Representação comercial"
        :disabled="busy"
        @click="emit('representation')"
      />
      <UButton
        v-if="canEdit"
        type="button"
        color="neutral"
        variant="outline"
        icon="i-lucide-pencil"
        label="Editar rascunho"
        :disabled="busy"
        @click="emit('edit')"
      />
      <UButton
        v-if="canConvert"
        type="button"
        color="neutral"
        variant="outline"
        label="Converter"
        :disabled="busy || commercial.needs_reconciliation === true"
        @click="emit('convert')"
      />
      <UButton
        v-if="canPostSale"
        type="button"
        color="primary"
        label="Lançar venda"
        :disabled="busy || commercial.needs_reconciliation === true"
        @click="emit('postSale')"
      />
      <UButton
        v-if="canReturn"
        type="button"
        color="neutral"
        variant="outline"
        label="Iniciar devolução"
        :disabled="busy || commercial.needs_reconciliation"
        @click="emit('returnSale')"
      />
      <UButton
        v-if="canPostReturn"
        type="button"
        color="primary"
        label="Confirmar lançamento da devolução"
        :disabled="busy"
        @click="emit('postReturn')"
      />
    </div>
  </section>
</template>
