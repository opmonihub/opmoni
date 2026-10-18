<script setup lang="ts">
definePageMeta({ middleware: ['auth', 'account-erp-admin'] })
</script>

<template>
  <ErpAccountingMappingApproval mode="office" />
</template>
