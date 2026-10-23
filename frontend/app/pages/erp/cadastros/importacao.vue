<script setup lang="ts">
import { erpOperationalTableUi } from '~/components/erp/erpPresentation'
import type { TableColumn } from '@nuxt/ui'
import type { ErpImportRow } from '#shared/types/erpCatalogImport'
import { erpImportHeaders } from '#shared/types/erpCatalogImport'
import { useErpCatalogImport } from '~/composables/useErpCatalogImport'
import ErpCatalogReferenceSelect from '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'

definePageMeta({ layout: 'erp', middleware: 'auth' })
const workspace = useErpCatalogImport()
const { kind, file, unitId, preview, busy, error, fieldErrors, success, unknownOutcome, hasSubmittedRecovery, canRetryExact, canImport, canPreview, canConfirmOrRecover, canConfirm, unitReady, recoveryToken, lockedChoices, authorityBlocked } = workspace
const modes = [{ label: 'Produtos e serviços', value: 'products' }, { label: 'Clientes e fornecedores', value: 'contacts' }]
const columns = computed<TableColumn<ErpImportRow>[]>(() => [
  { accessorKey: 'record', header: 'Registro' }, { accessorKey: 'line', header: 'Linha' }, { accessorKey: 'name', header: 'Nome' },
  kind.value === 'products' ? { accessorKey: 'sku', header: 'SKU' } : { accessorKey: 'tax_id', header: 'CPF/CNPJ' },
  kind.value === 'products' ? { accessorKey: 'sale_price', header: 'Preço' } : { accessorKey: 'email', header: 'E-mail' },
  { id: 'validation', header: 'Validação' }
])
function rowErrors(record: number) {
  return preview.value?.errors.filter(error => error.record === record).map(error => `${error.field}: ${error.message}`).join(' ') || 'Válido'
}
</script>

<template>
  <ErpCatalogPageShell title="Importação" description="Revise o CSV antes de confirmar. Nenhum cadastro é criado na pré-visualização.">
    <UAlert v-if="!canImport" color="warning" title="Você não tem permissão para importar nesta empresa." />
    <UAlert v-if="error" color="error" :title="error" />
    <UButton
      v-if="authorityBlocked || (hasSubmittedRecovery && !unknownOutcome)"
      label="Verificar acesso"
      color="neutral"
      :loading="busy"
      @click="workspace.refreshAuthority"
    />
    <UAlert
      v-if="hasSubmittedRecovery && !unknownOutcome"
      color="warning"
      title="Uma confirmação anterior precisa ser conciliada."
      description="A identificação original foi preservada. Revalide o acesso para consultar o resultado antes de iniciar outra importação."
    />
    <UAlert v-if="success !== null" color="success" :title="`${success} cadastros importados.`" />
    <UCard>
      <div class="space-y-4">
        <UFormField label="Tipo de cadastro" name="kind">
          <USelect
            v-model="kind"
            :items="modes"
            :disabled="authorityBlocked || lockedChoices"
            class="w-full sm:w-80"
          />
        </UFormField>
        <UFormField
          label="Arquivo CSV"
          name="file"
          :error="fieldErrors.file?.[0]"
          description="UTF-8, separado por vírgulas. Até 500 registros e 512 KiB; pré-visualização válida por uma hora."
        >
          <UFileUpload
            v-model="file"
            accept=".csv,text/csv"
            label="Selecione ou solte o CSV"
            description="O arquivo permanece selecionado em caso de falha."
            :disabled="!canPreview || lockedChoices"
            class="min-h-32"
          />
        </UFormField>
        <UAlert
          color="neutral"
          variant="subtle"
          title="Colunas do CSV"
          :description="`Obrigatória: name. Permitidas: ${erpImportHeaders[kind].filter(header => header !== 'name').join(', ')}.`"
        />
        <p class="text-muted text-sm">
          Códigos e valores devem ser texto; decimais usam ponto. Referências são IDs existentes da empresa. Planilhas antigas por posição, estoque inicial e CFOP não são importados.
        </p>
        <ErpCatalogReferenceSelect
          v-if="kind === 'products'"
          v-model="unitId"
          path="/erp/units"
          label="Unidade dos produtos"
          label-key="short_name"
          name="erp_unit_id"
          :error="fieldErrors.erp_unit_id?.[0]"
          :disabled="!canConfirmOrRecover || lockedChoices"
          @selection-validity="workspace.setUnitValidity"
        />
        <p v-if="kind === 'products' && !unitReady && !unknownOutcome" role="status" class="text-muted text-sm">
          Selecione uma unidade ativa e aguarde a validação para confirmar.
        </p>
        <div class="flex flex-wrap gap-2">
          <UButton
            label="Pré-visualizar"
            :disabled="!canPreview || !file || lockedChoices"
            :loading="busy && !unknownOutcome"
            @click="workspace.createPreview"
          />
          <UButton
            label="Cancelar"
            color="neutral"
            variant="outline"
            :disabled="lockedChoices"
            @click="workspace.cancel"
          />
          <UButton
            :label="canRetryExact ? 'Repetir mesma confirmação' : 'Confirmar importação'"
            color="success"
            :disabled="!canConfirm"
            :loading="busy && unknownOutcome"
            @click="workspace.confirm"
          />
          <UButton
            v-if="unknownOutcome"
            label="Consultar resultado"
            color="neutral"
            :disabled="busy || !canConfirmOrRecover"
            @click="workspace.reconcile"
          />
          <UButton
            v-if="recoveryToken && canConfirmOrRecover"
            label="Recarregar pré-visualização"
            color="neutral"
            :disabled="busy"
            @click="workspace.recover"
          />
        </div>
      </div>
    </UCard>
    <UAlert
      v-if="unknownOutcome && !preview"
      color="warning"
      title="A pré-visualização ainda não foi recuperada."
      description="Consulte o resultado ou recarregue a pré-visualização. Se necessário, repita a mesma confirmação: a identificação e as escolhas originais serão mantidas."
    />
    <UCard v-if="preview">
      <template #header>
        <p class="font-medium">
          {{ preview.totals.records }} registros · {{ preview.totals.valid }} válidos · {{ preview.totals.invalid }} inválidos
        </p>
        <p v-if="preview.errors.length" class="text-error text-sm">
          A confirmação está bloqueada. Corrija o arquivo e gere outra pré-visualização.
        </p>
        <p v-if="unknownOutcome" class="text-warning text-sm">
          Aguarde a confirmação do resultado ou consulte a operação antes de repetir.
        </p>
      </template>
      <UTable
        :ui="erpOperationalTableUi"
        :data="preview.rows"
        :columns="columns"
        class="max-h-96"
      >
        <template #validation-cell="{ row }">
          <span :class="preview.errors.some(error => error.record === row.original.record) ? 'text-error' : 'text-muted'">{{ rowErrors(row.original.record) }}</span>
        </template>
      </UTable>
    </UCard>
  </ErpCatalogPageShell>
</template>
