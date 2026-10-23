<script setup lang="ts">
definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpCatalogPageShell
    title="Contabilidade"
    description="Acompanhe documentos fiscais autorizados que foram projetados no histórico do escritório delegado."
  >
    <ErpAccountingDeliveries />
  </ErpCatalogPageShell>
</template>
