<script setup lang="ts">
import type { DataTableFilterModel } from '~/components/data-table/filter-model'
import type { ClientSavedFilter } from '~/types/client'
import { clientListMenuItems, type ClientListMenuItem } from '~/utils/clientListMenu'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  search: string
  filters: DataTableFilterModel[]
  showSaved: boolean
  canManageTags: boolean
  compact?: boolean
}>()

const emit = defineEmits<{
  'apply': [value: { q: string, filters: DataTableFilterModel[] }]
  'open-tags': [intent: 'catalog', focusCreate: boolean]
}>()

const { listSavedFilters, createSavedFilter, deleteSavedFilter } = useClients()
const toast = useToast()

const saved = useState<ClientSavedFilter[]>('client-saved-filters', () => [])
const saveOpen = ref(false)
const saving = ref(false)
const name = ref('')

const canSave = computed(() => props.search.trim() !== '' || props.filters.length > 0)

const items = computed(() => clientListMenuItems({
  saved: props.showSaved ? saved.value : null,
  canSave: canSave.value,
  canManageTags: props.canManageTags,
  onApply: filter => emit('apply', {
    q: filter.q ?? '',
    filters: filter.filters as DataTableFilterModel[]
  }),
  onSave: () => {
    name.value = ''
    saveOpen.value = true
  },
  onTags: focusCreate => emit('open-tags', 'catalog', focusCreate)
}))

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

onMounted(() => {
  if (props.showSaved) void load()
})

watch(() => props.showSaved, (show) => {
  if (show) void load()
})
</script>

<template>
  <UDropdownMenu
    v-if="items.length"
    :items="items"
    :content="{ align: 'end' }"
    :ui="{ content: 'min-w-56' }"
  >
    <UButton
      v-bind="$attrs"
      :label="compact ? undefined : 'Mais'"
      :icon="compact ? 'i-lucide-ellipsis' : undefined"
      :trailing-icon="compact ? undefined : 'i-lucide-chevron-down'"
      color="neutral"
      :variant="compact ? 'outline' : 'ghost'"
      aria-label="Filtros salvos e tags"
    />

    <template #saved-trailing="{ item }: { item: ClientListMenuItem }">
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
