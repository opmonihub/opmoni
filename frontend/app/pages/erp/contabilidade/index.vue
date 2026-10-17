<script setup lang="ts">
import { erpContabilidadeContract } from '~/utils/erpModulesNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpModuleHub :contract="erpContabilidadeContract" />
</template>
