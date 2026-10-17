<script setup lang="ts">
import { erpFiscalContract } from '~/utils/erpModulesNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpModuleHub :contract="erpFiscalContract" />
</template>
