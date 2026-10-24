<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useErpOperationNatureRules } from '~/composables/useErpOperationNatureRules'
import type { ErpOperationNatureCfopReference } from '#shared/types/erpOperationNatureRules'

const props = defineProps<{
  natureId: number
  movement: 'in' | 'out'
  defaultCfop: string
  defaultCfopUnverified: boolean
  canRead: boolean
  canWrite: boolean
  rootDirty: boolean
}>()
const emit = defineEmits<{ 'stage-default': [value: { code: string, expected_revision: number }] }>()
const rules = useErpOperationNatureRules({
  natureId: computed(() => props.natureId),
  movement: computed(() => props.movement),
  canRead: computed(() => props.canRead),
  canWrite: computed(() => props.canWrite),
  rootDirty: computed(() => props.rootDirty)
})
const exceptionChoiceCode = ref('')
const exceptionChoiceDrafts = ref<Record<number, string>>({})
const archiveExceptionId = ref<number | null>(null)
const cfopItems = computed(() => rules.cfopResults.value.map(item => ({
  ...item,
  label: `${item.code} · ${item.description}`
})))
const productItems = computed(() => rules.productResults.value.map(item => ({
  ...item,
  label: item.sku ? `${item.name} · ${item.sku}` : item.name
})))
const allChoiceItems = computed(() => {
  const choices = [...rules.choices.value, ...Object.values(rules.exceptionChoiceRefs.value)]
  return [...new Map(choices.filter(choice => choice.id !== null).map(choice => [choice.id, choice])).values()]
})
const exceptionChoiceItems = computed(() => allChoiceItems.value.map(choice => ({ label: choice.cfop, value: choice.cfop })))
const selectedExceptionChoice = computed(() => allChoiceItems.value.find(choice => choice.cfop === exceptionChoiceCode.value && choice.id !== null))
const selectedCfopRecord = computed(() => rules.cfopResults.value.find((item: ErpOperationNatureCfopReference) => item.code === rules.selectedCfop.value?.code))

watch([rules.exceptions, rules.choices, rules.exceptionChoiceRefs], ([rows]) => {
  for (const exception of rows) {
    const choice = allChoiceItems.value.find(item => item.id === exception.erp_operation_nature_cfop_choice_id)
    if (choice && exceptionChoiceDrafts.value[exception.id] === undefined)
      exceptionChoiceDrafts.value[exception.id] = choice.cfop
  }
}, { immediate: true })

onMounted(() => {
  if (props.canRead) {
    rules.cfopSearch.value = props.defaultCfop
    void rules.load()
  }
})

async function chooseDefault(code: string) {
  const reviewed = await rules.setDefault(code)
  if (reviewed)
    emit('stage-default', reviewed)
}

function chooseCfop(code: unknown) {
  const selected = rules.cfopResults.value.find(item => item.code === String(code)) ?? null
  rules.selectCfop(selected)
}

function chooseProduct(id: unknown) {
  const selected = rules.productResults.value.find(item => item.id === Number(id)) ?? null
  rules.selectProduct(selected)
}

async function addException() {
  const choice = allChoiceItems.value.find(item => item.cfop === exceptionChoiceCode.value && item.id !== null)
  if (choice)
    rules.selectExceptionChoice(choice.cfop)
  await rules.addException()
}
</script>

<template>
  <section class="space-y-4 border-t border-default pt-4" aria-labelledby="cfop-rules-heading">
    <div class="space-y-1">
      <h3 id="cfop-rules-heading" class="text-sm font-semibold text-highlighted">
        CFOP permitidos e exceções por produto
      </h3>
      <p class="text-sm text-muted">
        O CFOP padrão continua sendo definido separadamente. A referência não determina tratamento tributário.
      </p>
      <p v-if="props.defaultCfopUnverified" class="text-xs text-warning" role="status">
        O valor histórico {{ props.defaultCfop }} permanece visível, sem verificação ou adoção automática.
      </p>
    </div>

    <UAlert
      v-if="rules.error.value"
      color="error"
      title="Não foi possível concluir a alteração"
      :description="rules.error.value"
    >
      <template #actions>
        <UButton
          v-if="rules.authorityBlocked.value && !rules.requiresReopen.value"
          size="xs"
          color="neutral"
          variant="outline"
          label="Atualizar autorização"
          :loading="rules.pending.value"
          @click="rules.retry()"
        />
        <UButton
          v-else-if="rules.reviewRequired.value"
          size="xs"
          color="neutral"
          variant="outline"
          label="Revisar configuração atual"
          :loading="rules.pending.value"
          @click="rules.reviewCurrent()"
        />
        <UButton
          v-else
          size="xs"
          color="neutral"
          variant="outline"
          label="Tentar carregar novamente"
          :loading="rules.pending.value"
          @click="rules.load()"
        />
      </template>
    </UAlert>
    <UAlert
      v-if="props.rootDirty"
      color="warning"
      title="Salve ou descarte os dados da natureza antes de alterar as regras"
      description="As regras usam a revisão da natureza já salva. Seu rascunho permanece no formulário."
    />
    <UAlert
      v-else-if="rules.reviewRequired.value"
      color="warning"
      title="A revisão precisa ser atualizada"
      description="Os dados selecionados foram preservados. Revise a configuração atual antes de tentar novamente."
    />

    <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
      <UFormField label="Buscar CFOP na referência" name="cfop_reference_search">
        <UInputMenu
          v-model:search-term="rules.cfopSearch.value"
          :model-value="rules.selectedCfop.value?.code ?? undefined"
          :items="rules.cfopResultsCurrent.value ? cfopItems : []"
          value-key="code"
          placeholder="Digite código ou descrição"
          :disabled="!rules.canMutate.value || rules.pending.value"
          :loading="rules.pending.value"
          class="w-full"
          aria-label="Buscar CFOP na referência"
          @update:model-value="chooseCfop"
        />
      </UFormField>
      <UButton
        label="Adicionar opção permitida"
        icon="i-lucide-plus"
        :disabled="!rules.canMutate.value || !rules.selectedCfop.value || rules.pending.value"
        @click="rules.addChoice()"
      />
    </div>
    <p v-if="selectedCfopRecord" class="text-xs text-muted">
      {{ selectedCfopRecord.description }} · referência {{ selectedCfopRecord.reference_version }}
    </p>
    <div class="flex items-center justify-between gap-2 text-xs text-muted">
      <span>Exibindo até 15 CFOPs por busca.</span>
      <div class="flex gap-1">
        <UButton
          label="Anterior"
          size="xs"
          color="neutral"
          variant="ghost"
          :disabled="rules.cfopsPage.value <= 1 || rules.pending.value"
          @click="rules.cfopsPage.value--; rules.searchCfops()"
        />
        <span class="self-center">{{ rules.cfopsPage.value }} / {{ rules.cfopsLastPage.value }}</span>
        <UButton
          label="Próxima"
          size="xs"
          color="neutral"
          variant="ghost"
          :disabled="rules.cfopsPage.value >= rules.cfopsLastPage.value || rules.pending.value"
          @click="rules.cfopsPage.value++; rules.searchCfops()"
        />
      </div>
    </div>

    <div class="space-y-2" aria-label="Opções permitidas">
      <UFormField label="Filtrar opções permitidas" name="cfop_choice_filter">
        <UInput v-model="rules.choiceSearch.value" placeholder="Código CFOP" aria-label="Filtrar opções permitidas" />
      </UFormField>
      <div v-if="!rules.choices.value.length && !rules.pending.value" class="text-sm text-muted">
        Nenhuma opção disponível para consulta.
      </div>
      <div v-for="choice in rules.choices.value" :key="choice.id ?? `legacy-${choice.cfop}`" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-default px-3 py-2">
        <div>
          <p class="font-medium text-highlighted">
            {{ choice.cfop }} <span v-if="choice.cfop === props.defaultCfop" class="text-xs text-muted">· padrão atual</span>
          </p>
          <p class="text-xs text-muted">
            {{ choice.implicit ? 'Valor histórico, sem verificação da referência' : `Referência ${choice.reference_version ?? 'não identificada'}` }}
          </p>
        </div>
        <div class="flex gap-2">
          <UButton
            v-if="choice.cfop !== props.defaultCfop || (props.defaultCfopUnverified && !choice.implicit && choice.reference_version)"
            :label="choice.cfop === props.defaultCfop ? 'Verificar CFOP padrão' : 'Usar como padrão'"
            size="xs"
            color="neutral"
            variant="outline"
            :disabled="!rules.canMutate.value || !choice.reference_version"
            @click="chooseDefault(choice.cfop)"
          />
          <UButton
            v-if="choice.id !== null && choice.cfop !== props.defaultCfop"
            label="Arquivar opção"
            size="xs"
            color="error"
            variant="ghost"
            :disabled="!rules.canMutate.value || rules.pending.value"
            @click="rules.removeChoice(choice)"
          />
        </div>
      </div>
      <div class="flex items-center justify-end gap-1 text-xs text-muted">
        <UButton
          label="Anterior"
          size="xs"
          color="neutral"
          variant="ghost"
          :disabled="rules.choicesPage.value <= 1 || rules.pending.value"
          @click="rules.choicesPage.value--; rules.load()"
        />
        <span>{{ rules.choicesPage.value }} / {{ rules.choiceLastPage.value }}</span>
        <UButton
          label="Próxima"
          size="xs"
          color="neutral"
          variant="ghost"
          :disabled="rules.choicesPage.value >= rules.choiceLastPage.value || rules.pending.value"
          @click="rules.choicesPage.value++; rules.load()"
        />
      </div>
    </div>

    <div class="space-y-3 border-t border-default pt-4">
      <div>
        <h4 class="text-sm font-semibold text-highlighted">
          Exceções de produto
        </h4>
        <p class="text-xs text-muted">
          Uma exceção define outro CFOP permitido para um produto nesta natureza.
        </p>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <UFormField label="Produto" name="cfop_exception_product">
          <UInputMenu
            v-model:search-term="rules.productSearch.value"
            :model-value="rules.selectedProduct.value?.id ?? undefined"
            :items="rules.productResultsCurrent.value ? productItems : []"
            value-key="id"
            placeholder="Buscar produto por nome ou SKU"
            :disabled="!rules.canMutate.value || !rules.canSearchProducts.value || rules.pending.value"
            class="w-full"
            aria-label="Buscar produto para exceção"
            @update:model-value="chooseProduct"
          />
        </UFormField>
        <UFormField label="CFOP permitido" name="cfop_exception_choice">
          <USelect
            v-model="exceptionChoiceCode"
            :items="exceptionChoiceItems"
            value-key="value"
            placeholder="Selecione uma opção salva"
            :disabled="!rules.canMutate.value || !allChoiceItems.length || rules.pending.value"
            class="w-full"
          />
        </UFormField>
      </div>
      <UButton
        label="Salvar exceção"
        icon="i-lucide-plus"
        :disabled="!rules.canMutate.value || !rules.selectedProduct.value || !selectedExceptionChoice || rules.pending.value"
        @click="addException()"
      />
      <p v-if="!rules.canSearchProducts.value" class="text-xs text-muted">
        A consulta de produtos exige acesso de leitura ao catálogo de produtos.
      </p>
      <div v-if="rules.canSearchProducts.value" class="flex items-center justify-end gap-1 text-xs text-muted">
        <UButton
          label="Produtos anteriores"
          size="xs"
          color="neutral"
          variant="ghost"
          :disabled="rules.productsPage.value <= 1 || rules.pending.value"
          @click="rules.productsPage.value--; rules.searchProducts()"
        />
        <span>{{ rules.productsPage.value }} / {{ rules.productsLastPage.value }}</span>
        <UButton
          label="Próximos produtos"
          size="xs"
          color="neutral"
          variant="ghost"
          :disabled="rules.productsPage.value >= rules.productsLastPage.value || rules.pending.value"
          @click="rules.productsPage.value++; rules.searchProducts()"
        />
      </div>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <UFormField label="Filtrar exceções" name="cfop_exception_filter" class="min-w-0 flex-1">
          <UInput v-model="rules.exceptionSearch.value" placeholder="Produto ou SKU" aria-label="Filtrar exceções por produto" />
        </UFormField>
        <div class="flex items-center gap-1 text-xs text-muted">
          <UButton
            label="Anterior"
            size="xs"
            color="neutral"
            variant="ghost"
            :disabled="rules.exceptionsPage.value <= 1 || rules.pending.value"
            @click="rules.exceptionsPage.value--; rules.load()"
          />
          <span>{{ rules.exceptionsPage.value }} / {{ rules.exceptionLastPage.value }}</span>
          <UButton
            label="Próxima"
            size="xs"
            color="neutral"
            variant="ghost"
            :disabled="rules.exceptionsPage.value >= rules.exceptionLastPage.value || rules.pending.value"
            @click="rules.exceptionsPage.value++; rules.load()"
          />
        </div>
      </div>
      <div v-if="!rules.exceptions.value.length && !rules.pending.value" class="text-sm text-muted">
        Nenhuma exceção cadastrada.
      </div>
      <div v-for="exception in rules.exceptions.value" :key="exception.id" class="grid gap-2 rounded-md border border-default px-3 py-2 sm:grid-cols-[minmax(0,1fr)_minmax(10rem,auto)_auto] sm:items-center">
        <div class="min-w-0">
          <p class="truncate font-medium text-highlighted">
            {{ exception.product?.name ?? `Produto #${exception.erp_product_id}` }}
          </p>
          <p v-if="exception.product?.sku" class="text-xs text-muted">
            {{ exception.product.sku }}
          </p>
        </div>
        <USelect
          v-model="exceptionChoiceDrafts[exception.id]"
          :items="exceptionChoiceItems"
          value-key="value"
          :placeholder="`CFOP ${rules.choices.value.find(choice => choice.id === exception.erp_operation_nature_cfop_choice_id)?.cfop ?? 'indisponível'}`"
          :disabled="!rules.canMutate.value || !allChoiceItems.length || rules.pending.value"
          aria-label="CFOP permitido para exceção"
          class="w-full"
        />
        <div class="flex justify-end gap-1">
          <UButton
            v-if="exceptionChoiceDrafts[exception.id] && rules.choices.value.some(choice => choice.cfop === exceptionChoiceDrafts[exception.id] && choice.id !== exception.erp_operation_nature_cfop_choice_id)"
            label="Atualizar CFOP"
            size="xs"
            color="neutral"
            variant="outline"
            :disabled="!rules.canMutate.value || rules.pending.value"
            @click="rules.updateException(exception, rules.choices.value.find(choice => choice.cfop === exceptionChoiceDrafts[exception.id])!.id!)"
          />
          <UButton
            :label="archiveExceptionId === exception.id ? 'Cancelar' : 'Arquivar'"
            size="xs"
            color="error"
            variant="ghost"
            :disabled="!rules.canMutate.value || rules.pending.value"
            @click="archiveExceptionId = archiveExceptionId === exception.id ? null : exception.id"
          />
          <UButton
            v-if="archiveExceptionId === exception.id"
            label="Confirmar arquivamento"
            size="xs"
            color="error"
            :disabled="!rules.canMutate.value || rules.pending.value"
            @click="rules.removeException(exception).then((success) => { if (success) archiveExceptionId = null })"
          />
        </div>
      </div>
      <p class="text-xs text-muted">
        A lista e as buscas carregam no máximo 15 registros por página.
      </p>
    </div>
  </section>
</template>
