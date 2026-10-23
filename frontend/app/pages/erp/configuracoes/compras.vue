<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Compras"
    description="Configure moeda, edição de produtos, status e controle de lotes nas compras."
    :fields="[
      { key: 'purchase_in_diff_currency', label: 'Compra em outra moeda', type: 'checkbox' },
      { key: 'purchase_currency_id', label: 'Moeda compra (ID)', type: 'number' },
      { key: 'p_exchange_rate', label: 'Taxa de câmbio' },
      { key: 'enable_editing_product_from_purchase', label: 'Editar produto na compra', type: 'checkbox' },
      { key: 'enable_purchase_status', label: 'Status de compra', type: 'checkbox' },
      { key: 'enable_lot_number', label: 'Número de lote', type: 'checkbox' }
    ]"
  />
</template>
