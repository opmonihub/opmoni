<script setup lang="ts">
import { erpCaixaContract } from '~/utils/erpModulesNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpModuleHub :contract="erpCaixaContract" />
</template>
