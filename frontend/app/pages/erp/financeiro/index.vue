<script setup lang="ts">
import { erpFinanceiroContract } from '~/utils/erpModulesNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpModuleHub :contract="erpFinanceiroContract" />
</template>
