<script setup lang="ts">
const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  columns: Array<{ id: string, label: string }>
  selectedIds: string[]
  selectionOnly?: boolean
}>()

const emit = defineEmits<{
  export: [columnIds: string[]]
}>()

const selected = ref<string[]>([])

watch(() => open.value, (isOpen) => {
  if (isOpen) selected.value = [...props.selectedIds]
}, { immediate: true })

const items = computed(() => props.columns.map(column => ({
  label: column.label,
  value: column.id
})))

function confirm() {
  if (!selected.value.length) return
  emit('export', [...selected.value])
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="selectionOnly ? 'Exportar seleção' : 'Exportar'"
    :ui="{ content: 'sm:max-w-sm', body: 'p-4 sm:p-4' }"
  >
    <template #body>
      <UCheckboxGroup
        v-model="selected"
        :items="items"
        variant="table"
        color="neutral"
        size="sm"
        aria-label="Colunas do arquivo"
      />
    </template>
    <template #footer>
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="open = false"
      />
      <UButton
        label="Exportar"
        icon="i-lucide-file-spreadsheet"
        aria-label="Exportar colunas escolhidas"
        :disabled="!selected.length"
        @click="confirm"
      />
    </template>
  </UModal>
</template>
