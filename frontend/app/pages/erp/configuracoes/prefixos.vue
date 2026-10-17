<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Prefixos"
    description="settings_prefixes.blade.php — use JSON ref_no_prefixes via API avançada futuramente."
    :fields="[
      { key: 'sku_prefix', label: 'Prefixo SKU (atalho)' }
    ]"
  />
</template>
