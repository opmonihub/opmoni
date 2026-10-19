<script setup lang="ts">
defineProps<{
  label: string
  error?: string
  required?: boolean
}>()
</script>

<template>
  <UFormField
    :label="label"
    :error="error"
    :required="required"
    class="block"
  >
    <slot />
  </UFormField>
</template>
