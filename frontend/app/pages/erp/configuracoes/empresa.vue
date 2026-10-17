<script setup lang="ts">
import { erpSettingsBusinessFields } from '~/utils/erpCompanySettingsFields'

definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Dados da empresa"
    description="Espelha business/partials/settings_business.blade.php (referência sistema)."
    :fields="erpSettingsBusinessFields"
  />
</template>
