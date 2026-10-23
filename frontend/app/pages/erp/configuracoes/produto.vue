<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Produto"
    description="Configure a identificação, a classificação e as regras de validade dos produtos."
    :fields="[
      { key: 'sku_prefix', label: 'Prefixo SKU' },
      { key: 'default_unit', label: 'Unidade padrão (ID)' },
      { key: 'enable_brand', label: 'Habilitar marcas', type: 'checkbox' },
      { key: 'enable_category', label: 'Habilitar categorias', type: 'checkbox' },
      { key: 'enable_sub_category', label: 'Habilitar subcategorias', type: 'checkbox' },
      { key: 'enable_price_tax', label: 'Preço com imposto', type: 'checkbox' },
      { key: 'enable_product_expiry', label: 'Validade de produto', type: 'checkbox' },
      { key: 'on_product_expiry', label: 'Ao expirar' },
      { key: 'stop_selling_before', label: 'Parar venda X dias antes', type: 'number' },
      { key: 'stock_expiry_alert_days', label: 'Alerta estoque (dias)', type: 'number' }
    ]"
  />
</template>
