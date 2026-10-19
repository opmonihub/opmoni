<script setup lang="ts">
import { h, nextTick, resolveComponent, useId } from 'vue'
import type { Component } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import ErpCatalogPagination from '~/components/erp/ErpCatalogPagination.vue'
import { useErpCatalogEditor } from '~/composables/useErpCatalogEditor'
import { useErpCatalogList } from '~/composables/useErpCatalogList'

type CatalogRow = Record<string, unknown> & { id: number, status?: string }

const props = defineProps<{
  path: string
  title: string
  form: Component
  columns: TableColumn<CatalogRow>[]
  recordLabel: (record: CatalogRow) => string
  searchPlaceholder?: string
}>()

const list = useErpCatalogList<CatalogRow>(props.path)
const editor = useErpCatalogEditor(props.path, list.refresh)
const { search, page, pending, searchPending, state, error, hasLoaded, stale, authorityBlocked, retry } = list
const { record, mode, open, loadingDetail, archiving, error: editorError, fieldErrors } = editor
const archiveTarget = ref<CatalogRow | null>(null)
const editorRegion = ref<HTMLElement | null>(null)
const restoreFocusTarget = ref<HTMLElement | null>(null)
const rows = computed(() => state.value?.data ?? [])
const resourceBlocked = ref(false)
const resourceError = ref('')
const resourceRefreshing = ref(false)
const resourceAuthorityBlocked = computed(() => resourceBlocked.value || authorityBlocked.value || editor.authorityBlocked.value)
const editorTitleId = useId()
let propagatingAuthorityBlock = false
let resourceRecoverySequence = 0
const canStore = computed(() => editor.permissions.store.value && !resourceAuthorityBlocked.value && !editor.mutationPending.value)
const canShow = computed(() => editor.permissions.show.value && !resourceAuthorityBlocked.value && !editor.mutationPending.value)
const canEdit = computed(() => editor.permissions.edit.value && !resourceAuthorityBlocked.value && !editor.mutationPending.value)
const canArchive = computed(() => editor.permissions.archive.value && !resourceAuthorityBlocked.value && !editor.mutationPending.value)
const isSaving = computed(() => editor.saving.value || editor.archiving.value)
const archiveOpen = computed({
  get: () => archiveTarget.value !== null,
  set: (value: boolean) => {
    if (!value && !isSaving.value)
      archiveTarget.value = null
  }
})
const UButton = resolveComponent('UButton')
const tableColumns = computed<TableColumn<CatalogRow>[]>(() => [
  ...props.columns,
  {
    id: 'actions',
    header: 'Ações',
    cell: ({ row }: { row: { original: CatalogRow } }) => h('div', { class: 'flex flex-wrap gap-2' }, [
      canShow.value ? h(UButton, { label: 'Ver', color: 'neutral', variant: 'soft', onClick: () => void editor.view(row.original.id) }) : null,
      canEdit.value ? h(UButton, { label: 'Editar', color: 'neutral', variant: 'soft', onClick: () => void editor.edit(row.original.id) }) : null,
      canArchive.value ? h(UButton, { label: 'Arquivar', color: 'error', variant: 'soft', onClick: () => openArchive(row.original) }) : null
    ])
  }
])

function blockResourceAuthority(message = editor.error.value || error.value || 'Confirme seu acesso antes de continuar.') {
  resourceBlocked.value = true
  resourceError.value = message
  archiveTarget.value = null
  if (propagatingAuthorityBlock)
    return

  propagatingAuthorityBlock = true
  if (!authorityBlocked.value)
    list.blockAuthority(message)
  if (!editor.authorityBlocked.value)
    editor.blockAuthority(message)
  propagatingAuthorityBlock = false
}

watch([authorityBlocked, editor.authorityBlocked], ([listBlocked, editorBlocked]) => {
  if (!resourceRefreshing.value && (listBlocked || editorBlocked))
    blockResourceAuthority(editor.error.value || error.value)
}, { flush: 'sync' })

watch(editor.contextKey, () => {
  resourceRecoverySequence++
  resourceRefreshing.value = false
  archiveTarget.value = null
  restoreFocusTarget.value = null
  resourceBlocked.value = false
  resourceError.value = ''
}, { flush: 'sync' })

watch(open, async (isOpen) => {
  if (isOpen) {
    restoreFocusTarget.value = typeof document === 'undefined' || !(document.activeElement instanceof HTMLElement)
      ? null
      : document.activeElement
    await nextTick()
    editorRegion.value?.focus()
    return
  }
  const target = restoreFocusTarget.value
  restoreFocusTarget.value = null
  await nextTick()
  if (target?.isConnected)
    target.focus()
})

function openArchive(row: CatalogRow) {
  if (canArchive.value)
    archiveTarget.value = row
}

function handleEditorEscape(event: KeyboardEvent) {
  if (editor.mutationPending.value) {
    event.preventDefault()
    event.stopPropagation()
    return
  }
  editor.cancel()
}

async function retryResource() {
  if (!resourceAuthorityBlocked.value) {
    await list.retry()
    return
  }
  if (resourceRefreshing.value)
    return

  const sequence = ++resourceRecoverySequence
  const contextKey = editor.contextKey.value
  const isCurrentRecovery = () => sequence === resourceRecoverySequence && contextKey === editor.contextKey.value
  resourceRefreshing.value = true
  try {
    const [, editorRestored] = await Promise.all([list.retry(), editor.refreshAuthority()])
    if (!isCurrentRecovery())
      return
    if (!editorRestored || list.authorityBlocked.value || editor.authorityBlocked.value || !list.canRead.value) {
      blockResourceAuthority(editor.error.value || error.value || 'Seu acesso ainda não permite consultar este cadastro.')
      return
    }
    resourceBlocked.value = false
    resourceError.value = ''
  } finally {
    if (isCurrentRecovery())
      resourceRefreshing.value = false
  }
}

async function confirmArchive() {
  const target = archiveTarget.value
  if (!target || !canArchive.value)
    return
  await editor.archive(target.id)
  if (!editor.error.value)
    archiveTarget.value = null
}

function labelFor(row: CatalogRow): string {
  return props.recordLabel(row)
}
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-highlighted text-lg font-semibold">
            {{ title }}
          </h2>
        </div>
        <UButton v-if="canStore" :label="`Adicionar ${title.toLocaleLowerCase()}`" @click="editor.create" />
      </div>
    </template>

    <div class="space-y-4">
      <UFormField label="Busca" class="max-w-md">
        <UInput v-model="search" :placeholder="searchPlaceholder ?? `Buscar ${title.toLocaleLowerCase()}`" />
      </UFormField>

      <UTable
        v-if="hasLoaded && rows.length"
        :data="rows"
        :columns="tableColumns"
        :loading="pending"
      />

      <ErpCatalogPagination
        v-model:page="page"
        :meta="state?.meta"
        :pending="pending"
        :error="error"
        :loaded="hasLoaded"
        :has-rows="rows.length > 0"
        :stale="stale"
        :search-pending="searchPending"
        :authority-blocked="authorityBlocked"
        :search="search"
        @retry="retry"
      />
    </div>
  </UCard>

  <div v-if="resourceAuthorityBlocked" role="alert" class="border-error/30 bg-error/5 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
    <p class="text-error text-sm">
      {{ resourceError || editorError || error || 'Seu acesso foi alterado. Confirme a autorização para continuar.' }}
    </p>
    <UButton
      label="Revalidar acesso"
      color="error"
      variant="soft"
      :loading="resourceRefreshing"
      :disabled="resourceRefreshing"
      @click="retryResource"
    />
  </div>

  <section
    v-if="open"
    ref="editorRegion"
    tabindex="-1"
    :aria-labelledby="editorTitleId"
    class="border-default bg-default mt-4 space-y-4 rounded-xl border p-4 shadow-sm sm:p-6"
    @keydown.esc="handleEditorEscape"
  >
    <header class="flex items-start justify-between gap-3">
      <div>
        <h2 :id="editorTitleId" class="text-highlighted text-xl font-semibold">
          {{ mode === 'detail' ? `Detalhes · ${record ? labelFor(record) : title}` : mode === 'edit' ? `Editar · ${title}` : `Novo cadastro · ${title}` }}
        </h2>
        <p v-if="mode === 'detail'" class="text-muted mt-1 text-sm">
          Consulta somente leitura.
        </p>
      </div>
      <UButton
        label="Fechar"
        color="neutral"
        variant="ghost"
        :disabled="isSaving"
        @click="editor.cancel"
      />
    </header>
    <p v-if="editorError" role="alert" class="text-error border-error/30 bg-error/5 rounded-md border p-3 text-sm">
      {{ editorError }}
    </p>
    <p v-if="loadingDetail" role="status" class="text-muted text-sm">
      Carregando registro…
    </p>
    <component
      :is="form"
      v-if="!loadingDetail && (mode === 'create' || record)"
      :record="record"
      :mode="mode"
      :can-save="editor.canSave.value"
      :can-archive="editor.permissions.archive.value"
      :busy="editor.mutationPending.value"
      :field-errors="fieldErrors"
      @save="editor.save"
    />
  </section>

  <UModal v-model:open="archiveOpen" title="Arquivar cadastro" :description="archiveTarget ? `Arquivar ${labelFor(archiveTarget)}? Os registros e referências históricas serão mantidos.` : 'Confirme o arquivamento do cadastro.'">
    <template #body>
      <p v-if="editorError" role="alert" class="text-error text-sm">
        {{ editorError }}
      </p>
      <p v-else class="text-sm">
        O cadastro deixará de aparecer nas opções ativas. O histórico existente será mantido.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          label="Cancelar"
          color="neutral"
          variant="ghost"
          :disabled="isSaving"
          @click="archiveOpen = false"
        />
        <UButton
          label="Confirmar arquivamento"
          color="error"
          :loading="archiving"
          :disabled="isSaving || !canArchive"
          @click="confirmArchive"
        />
      </div>
    </template>
  </UModal>
</template>
