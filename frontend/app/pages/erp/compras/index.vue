<script setup lang="ts">
import { erpComprasContract } from '~/utils/erpModulesNav'

definePageMeta({ layout: 'erp', middleware: 'auth' })
</script>

<template>
  <ErpModuleHub :contract="erpComprasContract" />
</template>
