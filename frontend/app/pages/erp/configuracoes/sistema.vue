<script setup lang="ts">
import { erpSettingsSystemFields } from '~/utils/erpCompanySettingsFields'

definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Sistema"
    description="Defina as preferências do sistema e os valores fiscais padrão da empresa."
    :fields="erpSettingsSystemFields"
  />
</template>
