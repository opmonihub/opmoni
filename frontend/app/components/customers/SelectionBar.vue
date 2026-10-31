<script setup lang="ts">
const props = defineProps<{
  count: number
  disabled?: boolean
}>()

const emit = defineEmits<{
  clear: []
  tags: []
  export: []
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
    <UButton
      label="Exportar"
      icon="i-lucide-file-spreadsheet"
      color="neutral"
      variant="outline"
      size="sm"
      aria-label="Exportar seleção"
      :disabled="disabled"
      @click="emit('export')"
    />
  </DataTableSelectionBar>
</template>
