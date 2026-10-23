<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
</script>

<template>
  <ErpSettingsSectionForm
    title="Prefixos"
    description="Defina o prefixo dos códigos SKU dos produtos. Os demais prefixos ainda não têm editor nesta tela."
    :fields="[
      { key: 'sku_prefix', label: 'Prefixo SKU (atalho)' }
    ]"
  />
</template>
