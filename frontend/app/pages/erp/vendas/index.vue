<script setup lang="ts">
import { erpVendasContract } from '~/utils/erpModulesNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpModuleHub :contract="erpVendasContract" />
</template>
