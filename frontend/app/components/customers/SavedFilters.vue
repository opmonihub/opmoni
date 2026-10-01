<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import type { DataTableFilterModel } from '~/components/data-table/Filter.vue'
import type { ClientSavedFilter } from '~/types/client'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  search: string
  filters: DataTableFilterModel[]
  compact?: boolean
}>()

const emit = defineEmits<{
  apply: [value: { q: string, filters: DataTableFilterModel[] }]
}>()

const { listSavedFilters, createSavedFilter, deleteSavedFilter } = useClients()
const toast = useToast()

const saved = useState<ClientSavedFilter[]>('client-saved-filters', () => [])
const saveOpen = ref(false)
const saving = ref(false)
const name = ref('')

const canSave = computed(() => props.search.trim() !== '' || props.filters.length > 0)

interface SavedFilterItem extends DropdownMenuItem {
  savedId?: number
}

const items = computed<SavedFilterItem[][]>(() => {
  const rows: SavedFilterItem[] = saved.value.length
    ? saved.value.map(filter => ({
        label: filter.name,
        icon: 'i-lucide-bookmark',
        slot: 'saved' as const,
        savedId: filter.id,
        onSelect: () => emit('apply', {
          q: filter.q ?? '',
          filters: filter.filters as DataTableFilterModel[]
        })
      }))
    : [{ label: 'Nenhum filtro salvo', disabled: true }]

  return [
    rows,
    [{
      label: 'Salvar filtros atuais',
      icon: 'i-lucide-plus',
      disabled: !canSave.value,
      onSelect: () => {
        name.value = ''
        saveOpen.value = true
      }
    }]
  ]
})

async function load() {
  try {
    saved.value = (await listSavedFilters()).data
  } catch {
    toast.add({ title: 'Não foi possível carregar os filtros salvos', color: 'error' })
  }
}

async function save() {
  const label = name.value.trim()
  if (!label || saving.value) return
  saving.value = true
  try {
    const created = await createSavedFilter({
      name: label,
      q: props.search.trim() || null,
      filters: props.filters.map(filter => ({
        columnId: filter.columnId,
        operator: filter.operator,
        values: filter.values.map(String)
      }))
    })
    saved.value = [...saved.value, created].sort((left, right) => left.name.localeCompare(right.name, 'pt-BR'))
    saveOpen.value = false
    toast.add({ title: 'Filtro salvo', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível salvar o filtro', color: 'error' })
  } finally {
    saving.value = false
  }
}

async function remove(id: number) {
  const previous = saved.value
  saved.value = saved.value.filter(filter => filter.id !== id)
  try {
    await deleteSavedFilter(id)
  } catch {
    saved.value = previous
    toast.add({ title: 'Não foi possível apagar o filtro', color: 'error' })
  }
}

onMounted(load)
</script>

<template>
  <UDropdownMenu :items="items" :content="{ align: 'end' }">
    <UButton
      v-bind="$attrs"
      :label="compact ? undefined : 'Salvos'"
      icon="i-lucide-bookmark"
      trailing-icon="i-lucide-chevron-down"
      color="neutral"
      variant="ghost"
      aria-label="Filtros salvos"
    />

    <template #saved-trailing="{ item }: { item: SavedFilterItem }">
      <UButton
        icon="i-lucide-trash-2"
        color="neutral"
        variant="ghost"
        size="xs"
        aria-label="Apagar filtro salvo"
        @pointerdown.stop
        @click.stop.prevent="item.savedId !== undefined && remove(item.savedId)"
      />
    </template>
  </UDropdownMenu>

  <UModal v-model:open="saveOpen" title="Salvar filtros" description="Este atalho fica só com você e abre em Todos.">
    <template #body>
      <UFormField label="Nome" name="name">
        <UInput
          v-model="name"
          maxlength="40"
          placeholder="Ex.: Simples Nacional"
          class="w-full"
          autofocus
          @keydown.enter.prevent="save"
        />
      </UFormField>
    </template>
    <template #footer>
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="saveOpen = false"
      />
      <UButton
        label="Salvar"
        :loading="saving"
        :disabled="!name.trim()"
        @click="save"
      />
    </template>
  </UModal>
</template>
