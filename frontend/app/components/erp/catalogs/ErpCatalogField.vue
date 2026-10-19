<script setup lang="ts">
defineProps<{
  label: string
  name?: string
  description?: string
  error?: string
  required?: boolean
}>()
</script>

<template>
  <UFormField
    :name="name"
    :label="label"
    :description="description"
    :error="error"
    :required="required"
    class="block"
  >
    <slot />
  </UFormField>
</template>
