<script setup lang="ts">
import type { FormSubmitEvent, TabsItem } from '@nuxt/ui'
import * as z from 'zod'
import type { ClientTag, ClientTagAssignment, ClientTagColor } from '~/types/client'

const props = withDefaults(defineProps<{
  count: number
  assignment: Omit<ClientTagAssignment, 'tag_ids' | 'action'>
  intent?: 'selection' | 'catalog'
  focusCreate?: boolean
}>(), {
  intent: 'selection',
  focusCreate: false
})

const emit = defineEmits<{
  applied: []
  changed: []
}>()

const open = defineModel<boolean>('open', { default: false })
const tab = ref('apply')
const query = ref('')
const { listTags, createTag, updateTag, deleteTag, assignTags } = useClients()
const toast = useToast()

const tags = ref<ClientTag[]>([])
const loading = ref(false)
const color = ref<ClientTagColor>('primary')
const picked = ref<number[]>([])
const creating = ref(false)
const saving = ref(false)
const deleting = ref(false)
const applying = ref<'attach' | 'detach' | null>(null)
const editing = ref<ClientTag | null>(null)
const editName = ref('')
const editColor = ref<ClientTagColor>('primary')
const pendingDelete = ref<ClientTag | null>(null)

const createSchema = z.object({
  names: z.array(z.string().trim().min(1).max(40, 'Use no máximo 40 caracteres')).min(1, 'Adicione ao menos uma tag')
})

const createState = reactive<{ names: string[] }>({ names: [] })

const colorOptions: { label: string, value: ClientTagColor }[] = [
  { label: 'Primária', value: 'primary' },
  { label: 'Neutro', value: 'neutral' },
  { label: 'Sucesso', value: 'success' },
  { label: 'Info', value: 'info' },
  { label: 'Aviso', value: 'warning' },
  { label: 'Erro', value: 'error' }
]

const description = computed(() => {
  if (props.intent === 'catalog') return 'Crie, edite e exclua as tags da conta.'
  const noun = props.count === 1 ? 'empresa selecionada' : 'empresas selecionadas'
  return `Aplique tags nas ${props.count} ${noun} ou gerencie o catálogo da conta.`
})

const visibleTags = computed(() => {
  const term = query.value.trim().toLocaleLowerCase('pt-BR')
  if (!term) return tags.value
  return tags.value.filter(tag => tag.name.toLocaleLowerCase('pt-BR').includes(term))
})

const tabs = computed<TabsItem[]>(() => [
  {
    label: 'Aplicar',
    value: 'apply',
    icon: 'i-lucide-tags',
    slot: 'apply',
    badge: picked.value.length || undefined
  },
  {
    label: 'Catálogo',
    value: 'catalog',
    icon: 'i-lucide-library',
    slot: 'catalog',
    badge: tags.value.length || undefined
  }
])

const editOpen = computed({
  get: () => editing.value !== null,
  set: (value) => {
    if (!value) editing.value = null
  }
})

const deleteOpen = computed({
  get: () => pendingDelete.value !== null,
  set: (value) => {
    if (!value) pendingDelete.value = null
  }
})

watch(open, (value) => {
  if (!value) return
  tab.value = props.intent === 'catalog' ? 'catalog' : 'apply'
  query.value = ''
  picked.value = []
  createState.names = []
  color.value = 'primary'
  editing.value = null
  pendingDelete.value = null
  load()
})

watch(editing, (tag) => {
  if (!tag) return
  editName.value = tag.name
  editColor.value = tag.color
})

async function load() {
  loading.value = true
  try {
    tags.value = (await listTags()).data
  } catch {
    toast.add({ title: 'Não foi possível carregar as tags', color: 'error' })
  } finally {
    loading.value = false
  }
}

function toggle(id: number, selected: boolean | 'indeterminate') {
  const on = !!selected
  picked.value = on ? [...new Set([...picked.value, id])] : picked.value.filter(item => item !== id)
}

function catalogActions(tag: ClientTag) {
  return [[{
    label: 'Editar',
    icon: 'i-lucide-pencil',
    onSelect: () => {
      editing.value = tag
    }
  }, {
    label: 'Excluir',
    icon: 'i-lucide-trash',
    color: 'error' as const,
    onSelect: () => {
      pendingDelete.value = tag
    }
  }]]
}

async function onCreate(event: FormSubmitEvent<z.output<typeof createSchema>>) {
  const names = [...new Set(event.data.names.map(name => name.trim()).filter(Boolean))]
  if (!names.length) return
  creating.value = true
  try {
    for (const name of names) {
      const existing = tags.value.find(tag => tag.name.localeCompare(name, 'pt-BR', { sensitivity: 'accent' }) === 0)
      if (existing) {
        if (!picked.value.includes(existing.id)) picked.value = [...picked.value, existing.id]
        continue
      }
      const created = await createTag({ name, color: color.value })
      tags.value = [...tags.value, created].sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'))
      picked.value = [...picked.value, created.id]
    }
    createState.names = []
    if (props.intent !== 'catalog') tab.value = 'apply'
    emit('changed')
    toast.add({ title: names.length === 1 ? 'Tag criada' : 'Tags criadas', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível criar a tag', color: 'error' })
    await load()
  } finally {
    creating.value = false
  }
}

async function saveEdit() {
  const tag = editing.value
  const name = editName.value.trim()
  if (!tag || !name) return
  saving.value = true
  try {
    const updated = await updateTag(tag.id, { name, color: editColor.value })
    tags.value = tags.value
      .map(item => item.id === tag.id ? updated : item)
      .sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'))
    editing.value = null
    emit('changed')
    toast.add({ title: 'Tag atualizada', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível atualizar a tag', color: 'error' })
  } finally {
    saving.value = false
  }
}

async function confirmDelete() {
  const tag = pendingDelete.value
  if (!tag) return
  deleting.value = true
  try {
    await deleteTag(tag.id)
    tags.value = tags.value.filter(item => item.id !== tag.id)
    picked.value = picked.value.filter(id => id !== tag.id)
    pendingDelete.value = null
    emit('changed')
    toast.add({ title: 'Tag excluída do catálogo', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível excluir a tag', color: 'error' })
  } finally {
    deleting.value = false
  }
}

async function apply(action: 'attach' | 'detach') {
  if (!picked.value.length) return
  applying.value = action
  try {
    await assignTags({ ...props.assignment, tag_ids: [...picked.value], action })
    toast.add({
      title: action === 'attach' ? 'Tags aplicadas na seleção' : 'Tags removidas da seleção',
      color: 'success'
    })
    emit('applied')
    open.value = false
  } catch {
    toast.add({ title: 'Não foi possível atualizar as tags da seleção', color: 'error' })
  } finally {
    applying.value = null
  }
}
</script>

<template>
  <UModal
    v-model:open="open"
    title="Tags"
    :description="description"
    scrollable
    :ui="{ content: 'sm:max-w-xl' }"
  >
    <slot />

    <template #body>
      <UTabs
        v-if="intent !== 'catalog'"
        v-model="tab"
        :items="tabs"
        variant="link"
        :content="false"
        class="w-full"
      />

      <UInput
        v-if="tags.length"
        v-model="query"
        icon="i-lucide-search"
        placeholder="Buscar tag"
        class="mt-4 w-full"
      />

      <div v-if="loading" class="mt-4 space-y-2">
        <USkeleton class="h-10 w-full" />
        <USkeleton class="h-10 w-full" />
        <USkeleton class="h-10 w-full" />
      </div>

      <div v-else-if="tab === 'apply'" class="mt-4">
        <UEmpty
          v-if="!tags.length"
          icon="i-lucide-tags"
          title="Nenhuma tag"
          description="Crie a primeira no catálogo para categorizar a seleção."
          variant="naked"
          size="sm"
          :actions="[{ label: 'Ir para o catálogo', icon: 'i-lucide-library', color: 'neutral', variant: 'subtle', onClick: () => { tab = 'catalog' } }]"
        />
        <UEmpty
          v-else-if="!visibleTags.length"
          icon="i-lucide-search-x"
          title="Nenhuma tag encontrada"
          description="Tente outro nome ou crie uma nova no catálogo."
          variant="naked"
          size="sm"
        />
        <ul v-else class="divide-y divide-default overflow-hidden rounded-lg ring ring-default">
          <li v-for="tag in visibleTags" :key="tag.id">
            <label
              class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-elevated"
              :class="picked.includes(tag.id) ? 'bg-primary/10' : ''"
            >
              <UCheckbox
                :model-value="picked.includes(tag.id)"
                :aria-label="`Selecionar tag ${tag.name}`"
                @update:model-value="toggle(tag.id, $event)"
              />
              <UBadge :label="tag.name" :color="tag.color" variant="subtle" />
            </label>
          </li>
        </ul>
      </div>

      <div v-else class="mt-4 space-y-4">
        <UForm
          :schema="createSchema"
          :state="createState"
          class="space-y-3 rounded-lg bg-elevated/50 p-3 ring ring-default"
          @submit="onCreate"
        >
          <UFormField
            name="names"
            label="Novas tags"
            description="Enter adiciona. A cor vale para todas."
            :error-pattern="/^names\..+/"
          >
            <UInputTags
              v-model="createState.names"
              placeholder="Ex.: Prioridade"
              icon="i-lucide-tag"
              class="w-full"
              :max-length="40"
              :duplicate="false"
              :disabled="creating"
              :autofocus="focusCreate"
            />
          </UFormField>
          <div class="flex flex-wrap gap-1.5">
            <UButton
              v-for="option in colorOptions"
              :key="option.value"
              :label="option.label"
              :color="option.value"
              :variant="color === option.value ? 'solid' : 'outline'"
              size="xs"
              type="button"
              :aria-pressed="color === option.value"
              @click="color = option.value"
            />
          </div>
          <div class="flex justify-end">
            <UButton
              type="submit"
              label="Criar"
              icon="i-lucide-plus"
              :loading="creating"
              :disabled="!createState.names.length"
            />
          </div>
        </UForm>

        <UEmpty
          v-if="!tags.length"
          icon="i-lucide-tags"
          title="Catálogo vazio"
          description="As tags criadas aqui ficam disponíveis para toda a conta."
          variant="naked"
          size="sm"
        />
        <UEmpty
          v-else-if="!visibleTags.length"
          icon="i-lucide-search-x"
          title="Nenhuma tag encontrada"
          variant="naked"
          size="sm"
        />
        <ul v-else class="divide-y divide-default overflow-hidden rounded-lg ring ring-default">
          <li
            v-for="tag in visibleTags"
            :key="tag.id"
            class="flex items-center gap-2 px-3 py-2"
          >
            <UBadge :label="tag.name" :color="tag.color" variant="subtle" />
            <span class="flex-1" />
            <UDropdownMenu :items="catalogActions(tag)" :content="{ align: 'end' }">
              <UButton
                icon="i-lucide-ellipsis"
                color="neutral"
                variant="ghost"
                size="xs"
                :aria-label="`Ações da tag ${tag.name}`"
              />
            </UDropdownMenu>
          </li>
        </ul>
      </div>
    </template>

    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <template v-if="intent !== 'catalog'">
        <UButton
          label="Remover da seleção"
          color="neutral"
          variant="subtle"
          icon="i-lucide-tag"
          :loading="applying === 'detach'"
          :disabled="!picked.length || applying !== null"
          @click="apply('detach')"
        />
        <UButton
          label="Aplicar na seleção"
          color="primary"
          icon="i-lucide-tags"
          :loading="applying === 'attach'"
          :disabled="!picked.length || applying !== null"
          @click="apply('attach')"
        />
      </template>
    </template>
  </UModal>

  <UModal
    v-model:open="editOpen"
    title="Editar tag"
    description="O nome e a cor valem para todos os clientes que usam esta tag."
  >
    <template #body>
      <form id="edit-tag" class="space-y-4" @submit.prevent="saveEdit">
        <UFormField label="Nome" name="name" required>
          <UInput
            v-model="editName"
            class="w-full"
            maxlength="40"
            autofocus
          />
        </UFormField>
        <UFormField label="Cor" name="color">
          <div class="flex flex-wrap gap-1.5">
            <UButton
              v-for="option in colorOptions"
              :key="option.value"
              :label="option.label"
              :color="option.value"
              :variant="editColor === option.value ? 'solid' : 'outline'"
              size="xs"
              type="button"
              :aria-pressed="editColor === option.value"
              @click="editColor = option.value"
            />
          </div>
        </UFormField>
      </form>
    </template>
    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <UButton
        type="submit"
        form="edit-tag"
        label="Salvar"
        :loading="saving"
        :disabled="!editName.trim()"
      />
    </template>
  </UModal>

  <UModal
    v-model:open="deleteOpen"
    title="Excluir tag"
    :description="pendingDelete ? `Excluir ${pendingDelete.name} do catálogo e de todos os clientes. Essa ação não pode ser desfeita.` : 'Excluir tag do catálogo.'"
  >
    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <UButton
        label="Excluir"
        color="error"
        :loading="deleting"
        @click="confirmDelete"
      />
    </template>
  </UModal>
</template>
