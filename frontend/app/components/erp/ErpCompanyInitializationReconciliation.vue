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
  'operating_settings.decimal_places': 'Casas decimais',
  'operating_settings.currency_symbol_placement': 'Posição do símbolo da moeda',
  'operating_settings.time_zone': 'Fuso horário',
  'operating_settings.accounting_method': 'Método contábil'
}

const reasonLabels: Record<string, string> = {
  missing_preference: 'Preferência ainda não definida',
  explicit_value_preserved: 'Valor atual será preservado',
  compatible_native_identity: 'Cadastro compatível já existe',
  unresolved_legacy_namespace: 'Cadastro existente precisa de conferência',
  selected_record_missing: 'Cadastro selecionado não foi encontrado',
  selected_record_incompatible: 'Cadastro selecionado precisa de conferência',
  conflicting_native_identity: 'Há cadastros que precisam de conferência'
}

function formatValue(value: unknown) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não'
  if (typeof value === 'object') return 'Valor configurado'
  return String(value)
}

const rows = computed(() => (workspace.review.value?.items ?? workspace.outcome.value?.items ?? []).map((item, index) => ({
  id: `${item.key}-${index}`,
  label: itemLabels[item.key] ?? 'Outro parâmetro da empresa',
  statusLabel: item.status === 'conflict' ? 'Conflito' : item.status === 'create' ? 'Será criado' : item.status === 'reuse' ? 'Será mantido' : item.status === 'skip' ? 'Sem alteração' : 'Em análise',
  reasonLabel: reasonLabels[item.reason] ?? 'Revise este item antes de continuar',
  beforeLabel: formatValue(item.before),
  afterLabel: formatValue(item.after)
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
          @click="workspace.apply"
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
