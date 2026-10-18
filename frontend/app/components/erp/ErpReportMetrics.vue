<script setup lang="ts">
import type { ErpReportPayload } from '~/utils/erpModulesNav'

const props = defineProps<{ report: ErpReportPayload }>()
const sections = computed(() => props.report.sections ?? { [props.report.category]: props.report.totals ?? {} })
const labels: Record<string, string> = {
  sales: 'Vendas', purchases: 'Compras', stock: 'Estoque', financial: 'Financeiro', fiscal: 'Fiscal',
  document_count: 'Documentos lançados', posted_total: 'Valor lançado', balance_lines: 'Saldos de estoque',
  quantity_on_hand: 'Quantidade disponível', inventory_value: 'Valor do estoque',
  obligations_amount: 'Obrigações', obligations_open_amount: 'Saldo em aberto', payments_posted_total: 'Pagamentos lançados',
  authorized_count: 'Autorizados', draft_count: 'Rascunhos', rejected_count: 'Rejeitados'
}
</script>

<template>
  <div class="space-y-4">
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
            {{ value }}
          </dd>
        </div>
      </dl>
    </UCard>
  </div>
</template>
