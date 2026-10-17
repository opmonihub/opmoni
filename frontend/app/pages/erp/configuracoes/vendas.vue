<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Vendas"
    description="settings_sales.blade.php"
    :fields="[
      { key: 'default_sales_discount', label: 'Desconto padrão venda' },
      { key: 'default_sales_tax', label: 'Imposto padrão venda (ID)' },
      { key: 'sell_price_tax', label: 'Preço de venda (includes/excludes)' },
      { key: 'sales_cmsn_agnt', label: 'Comissionado padrão' },
      { key: 'item_addition_method', label: 'Método adição item', type: 'number' }
    ]"
  />
</template>
