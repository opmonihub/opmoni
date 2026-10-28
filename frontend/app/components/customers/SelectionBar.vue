<script setup lang="ts">
const props = defineProps<{
  count: number
  disabled?: boolean
}>()

const emit = defineEmits<{
  clear: []
  tags: []
}>()

const noun = computed(() => props.count === 1 ? 'cliente selecionado' : 'clientes selecionados')
</script>

<template>
  <DataTableSelectionBar
    :count="count"
    :label="noun"
    :disabled="disabled"
    @clear="emit('clear')"
  >
    <UButton
      label="Tags"
      icon="i-lucide-tags"
      color="neutral"
      variant="outline"
      size="sm"
      :disabled="disabled"
      @click="emit('tags')"
    />
  </DataTableSelectionBar>
</template>
