<script setup lang="ts">
import { erpSettingsTaxFields } from '~/utils/erpCompanySettingsFields'

definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Taxas"
    description="Espelha business/partials/settings_tax.blade.php (referência sistema)."
    :fields="erpSettingsTaxFields"
  />
</template>
