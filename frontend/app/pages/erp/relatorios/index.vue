<script setup lang="ts">
import { erpRelatoriosContract } from '~/utils/erpModulesNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpModuleHub :contract="erpRelatoriosContract" />
</template>
