<script setup lang="ts">
defineOptions({ name: 'OfficeErpPrintLayout' })
</script>

<template>
  <div class="min-h-screen bg-white text-default print:min-h-0">
    <slot />
  </div>
</template>
