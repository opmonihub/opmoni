<script setup lang="ts">
import { erpSettingsBusinessFields } from '~/utils/erpCompanySettingsFields'

definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpCompanyInitializationReconciliation class="mb-6" />
  <ErpCompanyProductTaxProfileDefault />
  <ErpSettingsSectionForm
    title="Dados da empresa"
    :fields="erpSettingsBusinessFields"
  />
</template>
