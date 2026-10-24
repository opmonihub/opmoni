<script setup lang="ts">
const workspace = useErpCompanyInitialization()

const itemLabels: Record<string, string> = {
  'unit': 'Unidade padrão',
  'customer': 'Cliente padrão',
  'sale_scheme': 'Sequência de vendas',
  'sale_layout': 'Modelo de venda',
  'currency': 'Moeda',
  'sale_numbering': 'Numeração de vendas',
  'operating_settings.time_format': 'Formato de horário',
  'operating_settings.date_format': 'Formato de data',
  'operating_settings.time_zone': 'Fuso horário',
  'operating_settings.fy_start_month': 'Início do ano fiscal',
  'operating_settings.accounting_method': 'Método contábil',
  'operating_settings.stock_overselling_enabled': 'Permitir venda sem estoque suficiente'
}

const reasonLabels: Record<string, string> = {
  missing_preference: 'Preferência ainda não definida',
  explicit_value_preserved: 'Valor atual será preservado',
  compatible_native_identity: 'Cadastro compatível já existe',
  missing_native_currency: 'Moeda padrão BRL será criada',
  missing_native_record: 'Cadastro padrão será criado',
  unresolved_legacy_namespace: 'Cadastro existente precisa de conferência',
  unresolved_reference_requires_review: 'Há um cadastro existente que precisa ser revisado',
  unowned_unit_requires_review: 'A unidade existente com sigla UN precisa ser revisada',
  existing_native_selection: 'Cadastro padrão atual será mantido',
  selected_record_missing: 'Cadastro selecionado não foi encontrado',
  selected_record_incompatible: 'Cadastro selecionado precisa de conferência',
  conflicting_native_identity: 'Há cadastros que precisam de conferência'
}

function formatReason(key: string, reason: string) {
  if (reason === 'native_record_customized_or_archived') {
    const item = key === 'unit' ? 'Unidade' : key === 'customer' ? 'Cliente' : key === 'sale_scheme' ? 'Sequência de vendas' : 'Modelo de venda'
    return `${item} existente foi personalizada ou arquivada`
  }
  if (reason === 'selected_reference_unavailable_or_customized') {
    const item = key === 'unit' ? 'unidade' : key === 'customer' ? 'cliente' : key === 'sale_scheme' ? 'sequência de vendas' : 'modelo de venda'
    return `O ${item} selecionado não está disponível ou foi personalizado`
  }
  return reasonLabels[reason] ?? 'Revise este item antes de continuar'
}

function formatValue(key: string, status: string, value: unknown, side: 'before' | 'after') {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não'
  if (typeof value === 'object') {
    const record = value as Record<string, unknown>
    if (record.preserved === true) return 'Valor estruturado atual preservado'
    if (side === 'after' && status === 'create') {
      if (key === 'unit' && record.short_name === 'UN' && record.actual_name === 'Unidade' && record.allow_decimal === false)
        return 'UN · Unidade · unidade inteira'
      if (key === 'customer' && record.name === 'Consumidor final' && record.is_customer === true)
        return 'Consumidor final'
      if (key === 'sale_scheme' && record.name === 'Vendas' && record.start_number === 1 && record.total_digits === 4)
        return `Vendas · ${record.prefix === '' ? 'sem prefixo' : 'prefixo definido'} · inicia em 1 · 4 dígitos`
      if (key === 'sale_layout' && record.name === 'Venda comercial') return 'Venda comercial · modelo padrão'
    }
    if (['unit', 'customer', 'sale_scheme', 'sale_layout'].includes(key)) return 'Cadastro existente'
    return 'Valor estruturado'
  }
  if (['unit', 'customer', 'sale_scheme', 'sale_layout'].includes(key)) return 'Cadastro existente'
  return String(value)
}

const rows = computed(() => (workspace.review.value?.items ?? workspace.outcome.value?.items ?? []).map((item, index) => ({
  id: `${item.key}-${index}`,
  label: itemLabels[item.key] ?? 'Outro parâmetro da empresa',
  statusLabel: item.status === 'conflict' ? 'Conflito' : item.status === 'create' ? 'Será criado' : item.status === 'reuse' ? 'Será mantido' : item.status === 'skip' ? 'Sem alteração' : 'Em análise',
  reasonLabel: formatReason(item.key, item.reason),
  beforeLabel: formatValue(item.key, item.status, item.before, 'before'),
  afterLabel: formatValue(item.key, item.status, item.after, 'after')
})))

const columns = [
  { accessorKey: 'label', header: 'Item' },
  { accessorKey: 'statusLabel', header: 'Situação' },
  { accessorKey: 'reasonLabel', header: 'Detalhe' },
  { accessorKey: 'beforeLabel', header: 'Atual' },
  { accessorKey: 'afterLabel', header: 'Após a preparação' }
]
</script>

<template>
  <UPageCard
    title="Preparação inicial da empresa"
    description="Confira os valores que serão preservados ou criados antes de aplicar a configuração inicial."
    variant="subtle"
  >
    <div class="space-y-4">
      <UAlert
        v-if="workspace.error.value"
        color="error"
        title="Não foi possível concluir esta etapa"
        :description="workspace.error.value"
      />
      <UAlert
        v-else-if="workspace.outcome.value?.completed === false"
        color="warning"
        title="Há itens pendentes de conferência"
        description="A operação foi registrada, mas alguns itens precisam de revisão. Gere uma nova prévia depois de resolver os conflitos."
      />
      <UAlert
        v-else-if="workspace.outcome.value?.completed === true"
        color="success"
        title="Preparação inicial concluída"
        description="A configuração inicial foi aplicada."
      />
      <UAlert
        v-else-if="workspace.needsReconciliation.value"
        color="warning"
        title="Confirme o resultado da operação"
        description="A resposta não confirmou o resultado. Consulte o registro antes de repetir a operação."
      />

      <p v-if="workspace.review.value" class="text-sm text-muted">
        Prévia {{ workspace.review.value.preset_version }} · revisão {{ workspace.review.value.revision }}
      </p>

      <UTable v-if="rows.length" :data="rows" :columns="columns" />

      <div class="flex flex-wrap gap-2">
        <UButton
          label="Gerar prévia"
          :loading="workspace.busy.value"
          :disabled="!workspace.canPreview.value"
          @click="workspace.preview"
        />
        <UButton
          v-if="workspace.review.value"
          label="Aplicar configuração"
          color="primary"
          :disabled="!workspace.canApply.value"
          :loading="workspace.busy.value"
          @click="workspace.apply()"
        />
        <UButton
          v-if="workspace.needsReconciliation.value"
          label="Consultar operação"
          variant="outline"
          :disabled="workspace.busy.value || !workspace.canPreview.value"
          @click="workspace.reconcile"
        />
        <UButton
          v-if="workspace.canRetryExact.value"
          label="Repetir mesma operação"
          variant="outline"
          :disabled="workspace.busy.value || !workspace.canPreview.value"
          @click="workspace.retryExact"
        />
      </div>
    </div>
  </UPageCard>
</template>
