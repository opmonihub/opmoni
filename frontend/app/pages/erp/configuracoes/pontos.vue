<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Pontos de recompensa"
    description="Defina as regras de acúmulo, resgate e validade dos pontos de recompensa."
    :fields="[
      { key: 'enable_rp', label: 'Habilitar pontos', type: 'checkbox' },
      { key: 'rp_name', label: 'Nome do programa' },
      { key: 'amount_for_unit_rp', label: 'Valor por ponto' },
      { key: 'min_order_total_for_rp', label: 'Pedido mínimo para ganhar' },
      { key: 'max_rp_per_order', label: 'Máx. pontos por pedido', type: 'number' },
      { key: 'redeem_amount_per_unit_rp', label: 'Resgate por ponto' },
      { key: 'min_order_total_for_redeem', label: 'Pedido mínimo resgate' },
      { key: 'min_redeem_point', label: 'Mínimo resgate', type: 'number' },
      { key: 'max_redeem_point', label: 'Máximo resgate', type: 'number' },
      { key: 'rp_expiry_period', label: 'Validade (periodo)', type: 'number' },
      { key: 'rp_expiry_type', label: 'Tipo validade' }
    ]"
  />
</template>
