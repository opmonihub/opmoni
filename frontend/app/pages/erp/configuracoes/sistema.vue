<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Sistema"
    description="settings_system.blade.php + settings_dashboard"
    :fields="[
      { key: 'theme_color', label: 'Cor do tema' },
      { key: 'enable_tooltip', label: 'Tooltips', type: 'checkbox' },
      { key: 'cst_csosn_padrao', label: 'CST/CSOSN padrão', type: 'number' },
      { key: 'cst_pis_padrao', label: 'CST PIS padrão', type: 'number' },
      { key: 'cst_cofins_padrao', label: 'CST COFINS padrão', type: 'number' },
      { key: 'cst_ipi_padrao', label: 'CST IPI padrão', type: 'number' },
      { key: 'ncm_padrao', label: 'NCM padrão' },
      { key: 'perc_icms_padrao', label: '% ICMS padrão' },
      { key: 'perc_pis_padrao', label: '% PIS padrão' },
      { key: 'perc_cofins_padrao', label: '% COFINS padrão' },
      { key: 'perc_ipi_padrao', label: '% IPI padrão' },
      { key: 'pCredSN', label: 'pCredSN' },
      { key: 'exclusao_icms_pis_cofins', label: 'Exclusão ICMS PIS/COFINS', type: 'checkbox' }
    ]"
  />
</template>
