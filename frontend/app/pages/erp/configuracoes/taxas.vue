<script setup lang="ts">
import { erpSettingsTaxFields } from '~/utils/erpCompanySettingsFields'

definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Taxas"
    description="Defina os nomes e números de identificação dos impostos usados pela empresa."
    :fields="erpSettingsTaxFields"
  />
</template>
