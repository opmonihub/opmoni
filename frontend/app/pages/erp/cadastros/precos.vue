<script setup lang="ts">
import ErpCatalogResource from '~/components/erp/catalogs/ErpCatalogResource.vue'
import ErpDiscountRuleCatalogForm from '~/components/erp/catalogs/ErpDiscountRuleCatalogForm.vue'
import ErpPriceGroupCatalogForm from '~/components/erp/catalogs/ErpPriceGroupCatalogForm.vue'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'
import { erpCatalogRoutes, formatErpCatalogStatus } from '#shared/utils/erpCatalogs'
import { formatErpDecimalDisplay } from '#shared/utils/erpDecimalDisplay'
import type { ComponentPublicInstance } from 'vue'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Preços · Cadastros ERP' })

const groupColumns = [
  { accessorKey: 'name', header: 'Grupo' },
  { accessorKey: 'percentage_adjustment', header: 'Ajuste %', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpDecimalDisplay(row.original.percentage_adjustment as string | number | null) },
  { accessorKey: 'is_default', header: 'Padrão', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.is_default ? 'Sim' : 'Não' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const discountColumns = [
  { accessorKey: 'name', header: 'Regra' },
  { accessorKey: 'kind', header: 'Tipo', cell: ({ row }: { row: { original: Record<string, unknown> } }) => row.original.kind === 'percentage' ? 'Percentual' : 'Valor fixo' },
  { accessorKey: 'value', header: 'Valor', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpDecimalDisplay(row.original.value as string | number | null) },
  { accessorKey: 'coupon_code', header: 'Cupom' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }: { row: { original: Record<string, unknown> } }) => formatErpCatalogStatus(row.original.status) }
]
const groupLabel = (record: Record<string, unknown>) => String(record.name ?? '')
const discountLabel = (record: Record<string, unknown>) => String(record.name ?? '')
type CatalogResourceHandle = InstanceType<typeof ErpCatalogResource>
type CatalogCreateAction = CatalogResourceHandle['createAction']
const groupResource = ref<CatalogResourceHandle | null>(null)
const discountResource = ref<CatalogResourceHandle | null>(null)
const createMenuTrigger = ref<ComponentPublicInstance | null>(null)
let pendingCreateFocusAction: CatalogCreateAction | null = null
const createActions = computed(() => [groupResource.value?.createAction, discountResource.value?.createAction]
  .filter((action): action is CatalogCreateAction => !!action && action.allowed))
function handoffCreateMenuFocus(event: Event) {
  const action = pendingCreateFocusAction
  if (!action)
    return
  pendingCreateFocusAction = null
  event.preventDefault()
}
const createMenuItems = computed(() => [createActions.value.map(action => ({
  label: action.label,
  disabled: action.busy,
  onSelect: () => {
    if (action.trigger(createMenuTrigger.value?.$el as HTMLElement | undefined))
      pendingCreateFocusAction = action
  }
}))])
</script>

<template>
  <ErpCatalogPageShell embedded title="Preços e descontos" description="Grupos de preço e regras comerciais com valores decimais exatos.">
    <template #actions>
      <UDropdownMenu v-if="createActions.length" :items="createMenuItems" :content="{ onCloseAutoFocus: handoffCreateMenuFocus }">
        <UButton ref="createMenuTrigger" aria-label="Adicionar cadastro de preços" icon="i-lucide-plus">
          <span class="hidden sm:inline">Adicionar</span>
        </UButton>
      </UDropdownMenu>
    </template>
    <ErpCatalogResource
      ref="groupResource"
      :path="erpCatalogRoutes.priceGroups"
      title="Grupos de preço"
      :form="ErpPriceGroupCatalogForm"
      :columns="groupColumns"
      :record-label="groupLabel"
    />
    <ErpCatalogResource
      ref="discountResource"
      :path="erpCatalogRoutes.discountRules"
      title="Descontos e cupons"
      :form="ErpDiscountRuleCatalogForm"
      :columns="discountColumns"
      :record-label="discountLabel"
    />
  </ErpCatalogPageShell>
</template>
