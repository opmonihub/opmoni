<script setup lang="ts">
import { erpOperationalTableUi } from '~/components/erp/erpPresentation'
import type { ErpReportLedger, ErpReportSnapshot, ErpReportValue } from '#shared/types/erpReports'

const props = defineProps<{ report: ErpReportSnapshot }>()
const sections = computed(() => props.report.sections ?? { [props.report.category]: props.report.totals ?? {} })
const labels: Record<string, string> = {
  sales: 'Vendas', purchases: 'Compras', stock: 'Estoque', financial: 'Financeiro', fiscal: 'Fiscal',
  document_count: 'Documentos lançados', posted_total: 'Valor lançado', balance_lines: 'Saldos de estoque',
  quantity_on_hand: 'Quantidade disponível', inventory_value: 'Valor do estoque',
  obligations_amount: 'Obrigações', obligations_open_amount: 'Saldo em aberto', payments_posted_total: 'Pagamentos lançados',
  authorized_count: 'Autorizados', draft_count: 'Rascunhos', rejected_count: 'Rejeitados',
  queued_count: 'Na fila', submitting_count: 'Em envio', indeterminate_count: 'Indeterminados',
  canceled_count: 'Cancelados', contingency_local_count: 'Contingência local',
  documents: 'Documentos', returns: 'Devoluções lançadas', obligations: 'Obrigações e créditos atuais',
  obligation_activity: 'Obrigações lançadas no período', payments: 'Pagamentos', allocations: 'Alocações e compensações',
  cash: 'Movimentos de caixa', balances: 'Saldos atuais', lots: 'Lotes atuais', movements: 'Movimentos de estoque', cost_settlements: 'Acertos de custo (sem novo movimento físico)',
  amount: 'Valor', total_amount: 'Valor do documento', open_amount: 'Saldo em aberto atual', gross_original_due: 'Saldo original bruto atual',
  opposite_return_credit: 'Crédito de devolução (direção oposta)', open_return_credit: 'Crédito de devolução em aberto',
  net_exposure: 'Exposição líquida assinada (a receber − a pagar)', overdue: 'Vencido', not_yet_due: 'A vencer ou vence hoje', undated: 'Sem vencimento',
  known_cost_by_currency: 'Custo conhecido por moeda', unvalued_quantity: 'Quantidade sem custo conhecido', by_direction_status: 'Saldo por direção e status',
  quantity: 'Quantidade', remaining_quantity: 'Quantidade restante', remaining_cost: 'Custo restante', quantity_in: 'Entradas', quantity_out: 'Saídas', net_quantity: 'Quantidade líquida',
  net_amount: 'Valor líquido assinado', by_direction: 'Valor por direção', by_group: 'Moeda : estabelecimento : caixa : sessão : direção : tipo',
  net_posted_amount: 'Valor lançado assinado (a receber − a pagar)', original_principal: 'Principal original lançado',
  return_compensation_quantity: 'Quantidade de compensações vinculadas (não soma às saídas restantes)',
  known_direction_net_amount: 'Líquido apenas de direções comprovadas', unclassified_amount: 'Valor sem direção comprovada', unclassified_count: 'Pagamentos sem direção comprovada',
  id: 'Referência', erp_establishment_id: 'Estabelecimento', erp_product_variant_id: 'Variação', invoice_no: 'Número da venda', document_no: 'Número da compra',
  currency: 'Moeda', status: 'Status', direction: 'Direção', due_on: 'Vencimento', days_overdue: 'Dias vencidos', aging_bucket: 'Situação do vencimento',
  source_type: 'Tipo de origem', source_id: 'Referência da origem', source_line_id: 'Linha de origem', return_of_id: 'Obrigação original', reversal_of_id: 'Compensa referência',
  erp_sale_id: 'Venda original', erp_purchase_id: 'Compra original', erp_payment_id: 'Pagamento', erp_obligation_id: 'Obrigação',
  erp_cash_register_id: 'Caixa', erp_cash_session_id: 'Sessão de caixa', kind: 'Tipo', method: 'Forma',
  erp_stock_movement_id: 'Movimento', erp_stock_lot_id: 'Lote', erp_stock_allocation_id: 'Alocação', original_allocation_id: 'Alocação original',
  opening_balance_id: 'Saldo de abertura', root_lot_id: 'Lote raiz', parent_lot_id: 'Lote de origem', parent_allocation_id: 'Alocação de origem', parent_settlement_id: 'Acerto de origem',
  cost: 'Custo', total_cost: 'Custo total', known_cost: 'Custo conhecido', consumed_cost: 'Custo consumido', available_quantity: 'Quantidade disponível',
  returned_quantity: 'Quantidade devolvida', returned_cost: 'Custo devolvido', settled_quantity: 'Quantidade com custo acertado', cost_status: 'Situação do custo',
  cost_breakdown: 'Custos por moeda', posted_at: 'Lançado em', created_at: 'Criado em', updated_at: 'Atualizado em', received_at: 'Recebido em', issued_at: 'Alocado em',
  authorized_at: 'Autorizado em', ledger_initialized_at: 'Início do razão', model: 'Modelo', environment: 'Ambiente', series: 'Série', number: 'Número',
  signed_open_amount: 'Saldo assinado atual', needs_reconciliation: 'Exige conciliação',
  receivable: 'A receber', payable: 'A pagar', in: 'Entrada', out: 'Saída', unrecorded: 'Não registrado',
  open: 'Em aberto', settled: 'Liquidado', reversed: 'Compensado', posted: 'Lançado', draft: 'Rascunho', authorized: 'Autorizado', rejected: 'Rejeitado',
  queued: 'Na fila', submitting: 'Em envio', indeterminate: 'Indeterminado', canceled: 'Cancelado', contingency_local: 'Contingência local',
  known: 'Conhecido', unvalued: 'Sem avaliação', legacy_unknown: 'Legado sem custo comprovado', mixed_currency: 'Moedas distintas',
  erp_sale: 'Venda', erp_purchase: 'Compra', erp_sale_return: 'Devolução de venda', erp_purchase_return: 'Devolução de compra',
  payment: 'Pagamento', reversal: 'Compensação', deposit: 'Suprimento', withdrawal: 'Sangria', homologation: 'Homologação', production: 'Produção'
}
const ledgers = computed(() => Object.entries(props.report.ledgers ?? {}).flatMap(([key, value]) => {
  const entries: Array<[string, ErpReportLedger]> = 'basis' in value
    ? [[key, value as ErpReportLedger]]
    : Object.entries(value as Record<string, ErpReportLedger>).map(([name, ledger]) => [`${key}.${name}`, ledger])
  return entries.map(([name, ledger]) => ({
    name, title: name.split('.').map(part => labels[part] ?? part).join(' · '), ledger,
    links: ledger.rows.flatMap((row) => {
      const paths: Record<string, string> = {
        erp_sale: '/erp/vendas#sales', erp_sale_return: '/erp/vendas#returns',
        erp_purchase: '/erp/compras#purchases', erp_purchase_return: '/erp/compras#returns'
      }
      const source = String(row.source_type ?? '')
      const id = row.source_id
      return paths[source] && typeof id === 'number' && Number.isSafeInteger(id) && id > 0
        ? [{ to: paths[source], label: `${labels[source]} #${id}` }]
        : []
    }).filter((link, index, links) => links.findIndex(other => other.to === link.to && other.label === link.label) === index),
    columns: [...new Set(ledger.rows.flatMap(row => Object.keys(row)))].filter(field => field in labels)
      .map(field => ({ accessorKey: field, header: labels[field] })),
    rows: ledger.rows.map(row => Object.fromEntries(Object.entries(row).map(([field, value]) => [field, display(value)])))
  }))
}))

function display(value: ErpReportValue | undefined): string {
  if (value === null || value === undefined) return 'Não informado'
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não'
  if (Array.isArray(value)) return value.map(display).join(' · ')
  if (typeof value === 'object') return Object.entries(value).map(([key, item]) => `${key.split(':').map(part => labels[part] ?? part).join(' : ')}: ${display(item)}`).join(' · ')
  return labels[String(value)] ?? String(value)
}
</script>

<template>
  <div class="space-y-4">
    <p v-if="report.generated_at" class="text-muted text-sm">
      Gerado em {{ report.generated_at }}. Posição atual não representa saldo histórico até o fim do período.
    </p>
    <UCard v-for="(totals, category) in sections" :key="category" variant="subtle">
      <template #header>
        {{ labels[category] ?? category }}
      </template>
      <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="(value, metric) in totals" :key="metric">
          <dt class="text-muted text-sm">
            {{ labels[metric] ?? metric }}
          </dt>
          <dd class="text-highlighted mt-1 text-xl font-semibold tabular-nums">
            {{ value === null ? 'Indisponível: consulte custos conhecidos por moeda e quantidades sem avaliação' : value }}
          </dd>
        </div>
      </dl>
    </UCard>
    <section
      v-for="item in ledgers"
      :key="item.name"
      class="min-w-0 space-y-3 border-t border-muted pt-4"
      :aria-label="item.title"
    >
      <h3 class="text-highlighted font-semibold">
        {{ item.title }}
      </h3>
      <p class="text-muted text-sm">
        <template v-if="item.ledger.basis === 'current_position'">
          Posição atual em {{ item.ledger.position_at }}. As datas de atividade não limitam os saldos atuais.
        </template>
        <template v-else-if="item.ledger.basis === 'current_status_for_created_cohort'">
          Estado fiscal atual dos documentos criados no período {{ item.ledger.period?.date_from }} — {{ item.ledger.period?.date_to }}. Não representa histórico de transições fiscais.
        </template>
        <template v-else>
          Atividade no período {{ item.ledger.period?.date_from }} — {{ item.ledger.period?.date_to }}.
        </template>
        Origem temporal: {{ labels[item.ledger.timestamp_field] ?? item.ledger.timestamp_field }}.
        <template v-if="item.ledger.currency">
          Moeda: {{ item.ledger.currency }}.
        </template>
        <template v-if="item.ledger.status_filter">
          Status: {{ display(item.ledger.status_filter) }}.
        </template>
        <template v-if="item.ledger.status_basis === 'current_at_generation'">
          Status observado em {{ item.ledger.status_observed_at }}; não reconstrói o status na data do lançamento.
        </template>
        <template v-if="item.ledger.direction_filter">
          Direção: {{ display(item.ledger.direction_filter) }}.
        </template>
      </p>
      <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div v-for="(value, key) in item.ledger.totals" :key="key">
          <dt class="text-muted">
            {{ labels[key] ?? key }}
          </dt>
          <dd class="text-highlighted break-words font-medium tabular-nums">
            {{ display(value) }}
          </dd>
        </div>
      </dl>
      <p class="text-muted text-sm">
        Exibindo {{ item.ledger.rows.length }} de {{ item.ledger.row_count }} registros. Totais incluem todos os registros do mesmo recorte.<template v-if="item.ledger.detail_truncated">
          Detalhe limitado a {{ item.ledger.detail_limit }} registros; reduza o período ou o estabelecimento para consultar outros recortes.
        </template>
      </p>
      <UTable
        :ui="erpOperationalTableUi"
        :data="item.rows"
        :columns="item.columns"
        :caption="item.title"
        empty="Sem registros neste recorte"
        class="max-w-full"
      />
      <div v-if="item.links.length" class="space-y-2 text-sm">
        <p class="text-muted">
          Origens vinculadas: abra o módulo e consulte a referência indicada. O módulo verifica seu acesso novamente.
        </p>
        <div class="flex flex-wrap gap-2">
          <UButton
            v-for="link in item.links"
            :key="`${link.to}:${link.label}`"
            :to="link.to"
            :label="link.label"
            variant="ghost"
            size="sm"
          />
        </div>
      </div>
    </section>
    <p v-if="report.historical_position === 'unavailable'" class="text-muted text-sm">
      Posição histórica e avaliação histórica indisponíveis. Devoluções geram crédito separado, sem liquidar a obrigação original, devolver pagamentos ou alterar o caixa automaticamente.
    </p>
  </div>
</template>
