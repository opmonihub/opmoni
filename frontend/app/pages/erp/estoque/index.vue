<script setup lang="ts">
import { erpEstoqueContract } from '~/utils/erpModulesNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpModuleHub :contract="erpEstoqueContract" />
</template>
