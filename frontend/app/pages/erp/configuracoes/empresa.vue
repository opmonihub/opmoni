<script setup lang="ts">
import { erpSettingsBusinessFields } from '~/utils/erpCompanySettingsFields'

definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpCompanyInitializationReconciliation class="mb-6" />
  <ErpSettingsSectionForm
    title="Dados da empresa"
    description="Mantenha os dados cadastrais, o endereço e os parâmetros fiscais da empresa."
    :fields="erpSettingsBusinessFields"
  />
</template>
