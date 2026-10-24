<script setup lang="ts">
definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpCatalogPageShell
    title="Contabilidade"
    description="Acompanhe os documentos fiscais autorizados disponíveis no histórico do escritório com acesso delegado."
  >
    <ErpAccountingDeliveries />
  </ErpCatalogPageShell>
</template>
