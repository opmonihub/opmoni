<script setup lang="ts">
import { h, nextTick, onScopeDispose, resolveComponent, useId, useSlots } from 'vue'
import type { Component, ComponentPublicInstance } from 'vue'
import type { TableColumn, DropdownMenuItem } from '@nuxt/ui'
import ErpCatalogPagination from '~/components/erp/ErpCatalogPagination.vue'
import { formatErpCatalogStatus } from '#shared/utils/erpCatalogs'
import { useErpCatalogEditor } from '~/composables/useErpCatalogEditor'
import { useErpCatalogList } from '~/composables/useErpCatalogList'

type CatalogRow = Record<string, unknown> & { id: number, status?: string }
type FocusTarget = { focus(): void, isConnected?: boolean }

const props = withDefaults(defineProps<{
  path: string
  title: string
  form: Component
  columns: TableColumn<CatalogRow>[]
  recordLabel: (record: CatalogRow) => string
  searchPlaceholder?: string
  showHeading?: boolean
  detailFormLabel?: string
  detailPanelLabel?: string
}>(), { showHeading: true, detailFormLabel: 'Cadastro', detailPanelLabel: 'Detalhes adicionais' })
const slots = useSlots()

const list = useErpCatalogList<CatalogRow>(props.path)
const editor = useErpCatalogEditor(props.path, list.refresh)
const { search, page, pending, searchPending, state, error, hasLoaded, stale, authorityBlocked } = list
const { record, mode, open, loadingDetail, archiving, error: editorError, fieldErrors } = editor
const archiveTarget = ref<CatalogRow | null>(null)
const editorRegion = ref<HTMLElement | null>(null)
const restoreFocusTarget = ref<FocusTarget | null>(null)
const rowActionTriggers = new Map<number, FocusTarget>()
const rows = computed(() => state.value?.data ?? [])
const createLabel = computed(() => ({
  '/erp/contacts': 'Adicionar contato',
  '/erp/products': 'Adicionar produto',
  '/erp/units': 'Adicionar unidade',
  '/erp/categories': 'Adicionar categoria',
  '/erp/brands': 'Adicionar marca',
  '/erp/price-groups': 'Adicionar grupo de preço',
  '/erp/customer-groups': 'Adicionar grupo de clientes',
  '/erp/discount-rules': 'Adicionar regra de desconto',
  '/erp/tax-settings/tax-rates': 'Adicionar alíquota',
  '/erp/tax-settings/operation-natures': 'Adicionar natureza',
  '/erp/carriers': 'Adicionar transportadora',
  '/erp/vehicles': 'Adicionar veículo',
  '/erp/drivers': 'Adicionar motorista'
}[props.path] ?? `Adicionar ${props.title.toLocaleLowerCase()}`))
const resourceBlocked = ref(false)
const resourceError = ref('')
const resourceRefreshing = ref(false)
const resourceAuthorityBlocked = computed(() => resourceBlocked.value || authorityBlocked.value || editor.authorityBlocked.value)
const editorTitleId = useId()
const detailTab = ref('record')
const detailTabs = computed(() => [
  { label: props.detailFormLabel, value: 'record', slot: 'record' },
  { label: props.detailPanelLabel, value: 'panel', slot: 'panel' }
])
const hasDetailPanel = computed(() => typeof slots['detail-panel'] === 'function')
let propagatingAuthorityBlock = false
let resourceRecoverySequence = 0
let resourceContextGeneration = 0
let resourceDisposed = false
const canStore = computed(() => editor.permissions.store.value && !resourceAuthorityBlocked.value)
const canShow = computed(() => editor.permissions.show.value && !resourceAuthorityBlocked.value)
const canEdit = computed(() => editor.permissions.edit.value && !resourceAuthorityBlocked.value)
const canArchive = computed(() => editor.permissions.archive.value && !resourceAuthorityBlocked.value)
const isSaving = computed(() => editor.saving.value || editor.archiving.value)
const archiveOpen = computed({
  get: () => archiveTarget.value !== null,
  set: (value: boolean) => {
    if (!value && !isSaving.value)
      archiveTarget.value = null
  }
})
const UButton = resolveComponent('UButton')
const UDropdownMenu = resolveComponent('UDropdownMenu')
const UBadge = resolveComponent('UBadge')

function renderRowActions({ row }: { row: { original: CatalogRow } }) {
  const items = rowActionItems(row.original)
  if (!items.length)
    return null
  return h(UDropdownMenu, {
    items,
    'content': { align: 'end', onCloseAutoFocus: focusEditorAfterMenuClose },
    'aria-label': `Ações de ${labelFor(row.original)}`
  }, () => h(UButton, {
    'ref': (instance: Element | ComponentPublicInstance | null) => rememberRowActionTrigger(row.original.id, instance),
    'icon': 'i-lucide-ellipsis',
    'color': 'neutral',
    'variant': 'ghost',
    'disabled': isSaving.value,
    'aria-label': `Ações de ${labelFor(row.original)}`
  }))
}

const tableColumns = computed<TableColumn<CatalogRow>[]>(() => [
  ...props.columns.map((column) => {
    if (!('accessorKey' in column) || column.accessorKey !== 'status')
      return column
    return {
      ...column,
      cell: ({ row }: { row: { original: CatalogRow } }) => {
        const status = row.original.status
        const color = status === 'active' ? 'success' : status === 'inactive' ? 'warning' : 'neutral'
        return h(UBadge, { label: formatErpCatalogStatus(status), color, variant: 'subtle' })
      }
    }
  }),
  {
    id: 'actions',
    header: '',
    meta: { class: { th: 'w-14', td: 'text-right' } },
    cell: renderRowActions
  }
])

function focusTarget(instance: Element | ComponentPublicInstance | null): FocusTarget | null {
  const candidate = instance && typeof instance === 'object' && '$el' in instance
    ? instance.$el
    : instance
  if (candidate && typeof candidate === 'object' && 'focus' in candidate && typeof candidate.focus === 'function')
    return candidate as FocusTarget
  return null
}

function rememberRowActionTrigger(id: number, instance: Element | ComponentPublicInstance | null) {
  const target = focusTarget(instance)
  if (target)
    rowActionTriggers.set(id, target)
  else
    rowActionTriggers.delete(id)
}

function prepareRowAction(row: CatalogRow) {
  const target = rowActionTriggers.get(row.id)
  restoreFocusTarget.value = target && target.isConnected !== false ? target : null
}

async function focusEditorAfterMenuClose(event: Event) {
  if (!open.value || resourceDisposed)
    return

  event.preventDefault()
  const contextGeneration = resourceContextGeneration
  await nextTick()
  if (!resourceDisposed && contextGeneration === resourceContextGeneration && open.value)
    editorRegion.value?.focus()
}

function rowActionItems(row: CatalogRow): DropdownMenuItem[][] {
  const actions: DropdownMenuItem[] = []
  if (canShow.value) {
    actions.push({
      label: 'Ver',
      icon: 'i-lucide-eye',
      onSelect: () => {
        if (!canShow.value || editor.mutationPending.value)
          return
        prepareRowAction(row)
        void editor.view(row.id)
      }
    })
  }
  if (canEdit.value) {
    actions.push({
      label: 'Editar',
      icon: 'i-lucide-pencil',
      onSelect: () => {
        if (!canEdit.value || editor.mutationPending.value)
          return
        prepareRowAction(row)
        void editor.edit(row.id)
      }
    })
  }
  const groups = actions.length ? [actions] : []
  if (canArchive.value) {
    groups.push([{
      label: 'Arquivar',
      icon: 'i-lucide-archive',
      color: 'error',
      onSelect: () => {
        openArchive(row)
      }
    }])
  }
  return groups
}

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
  resourceContextGeneration++
  resourceRecoverySequence++
  resourceRefreshing.value = false
  archiveTarget.value = null
  restoreFocusTarget.value = null
  resourceBlocked.value = false
  resourceError.value = ''
}, { flush: 'sync' })

watch(() => record.value?.id, () => {
  detailTab.value = 'record'
})

async function restoreRowActionFocus() {
  if (resourceDisposed || open.value || editor.mutationPending.value)
    return

  const target = restoreFocusTarget.value
  if (!target)
    return

  const contextGeneration = resourceContextGeneration
  await nextTick()
  if (
    resourceDisposed
    || contextGeneration !== resourceContextGeneration
    || open.value
    || editor.mutationPending.value
    || restoreFocusTarget.value !== target
  ) {
    return
  }

  restoreFocusTarget.value = null
  if (target.isConnected !== false)
    target.focus()
}

onScopeDispose(() => {
  resourceDisposed = true
  resourceContextGeneration++
  resourceRecoverySequence++
})

watch(open, async (isOpen) => {
  if (isOpen) {
    if (!restoreFocusTarget.value) {
      const activeElement = typeof document === 'undefined' ? null : document.activeElement
      restoreFocusTarget.value = activeElement && typeof activeElement === 'object' && 'focus' in activeElement
        ? activeElement as FocusTarget
        : null
    }
    const contextGeneration = resourceContextGeneration
    await nextTick()
    if (!resourceDisposed && contextGeneration === resourceContextGeneration && open.value)
      editorRegion.value?.focus()
    return
  }
  await restoreRowActionFocus()
})

watch(editor.mutationPending, (pending) => {
  if (!pending && !open.value)
    void restoreRowActionFocus()
})

function openArchive(row: CatalogRow) {
  if (canArchive.value && !editor.mutationPending.value)
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
  if (!target || !canArchive.value || editor.mutationPending.value)
    return
  const contextGeneration = resourceContextGeneration
  await editor.archive(target.id)
  if (resourceDisposed || contextGeneration !== resourceContextGeneration || archiveTarget.value !== target)
    return
  if (!editor.error.value)
    archiveTarget.value = null
}

function labelFor(row: CatalogRow): string {
  return props.recordLabel(row)
}
</script>

<template>
  <div :class="['space-y-4', { hidden: open }]">
    <h2 v-if="showHeading" class="text-highlighted text-lg font-semibold">
      {{ title }}
    </h2>
    <UDashboardToolbar :ui="{ root: 'w-full min-w-0 flex-col items-stretch justify-start gap-3 overflow-visible sm:flex-row sm:items-end sm:justify-between', left: 'w-full min-w-0 flex-1', right: 'w-full min-w-0 sm:w-auto sm:shrink-0' }">
      <template #left>
        <div class="flex w-full min-w-0 flex-col gap-3 sm:flex-row sm:items-end">
          <UFormField :label="`Buscar ${title.toLocaleLowerCase()}`" class="min-w-0 w-full flex-1 sm:max-w-md">
            <UInput
              v-model="search"
              icon="i-lucide-search"
              :placeholder="searchPlaceholder ?? `Buscar ${title.toLocaleLowerCase()}`"
              class="w-full"
            />
          </UFormField>
          <p v-if="hasLoaded && state?.meta" class="text-muted shrink-0 pb-2 text-sm" aria-live="polite">
            {{ state.meta.total }} {{ state.meta.total === 1 ? 'registro' : 'registros' }}
          </p>
        </div>
      </template>
      <template #right>
        <UButton
          v-if="canStore"
          :label="createLabel"
          icon="i-lucide-plus"
          class="w-full sm:w-auto"
          :disabled="isSaving"
          @click="editor.create"
        />
      </template>
    </UDashboardToolbar>
    <div class="space-y-4">
      <UTable
        v-if="hasLoaded && rows.length"
        :data="rows"
        :columns="tableColumns"
        :loading="pending"
        class="flex-1"
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
        @retry="retryResource"
      />
    </div>
  </div>

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
      <div class="min-w-0 flex-1">
        <h2 :id="editorTitleId" class="text-highlighted break-words text-xl font-semibold">
          {{ mode === 'detail' ? `Detalhes · ${record ? labelFor(record) : title}` : mode === 'edit' ? `Editar · ${record ? labelFor(record) : title}` : `Novo cadastro · ${title}` }}
        </h2>
        <div v-if="mode === 'detail'" class="mt-2 flex items-center gap-2">
          <UBadge label="Somente leitura" color="neutral" variant="subtle" />
          <p class="text-muted text-sm">
            Consulta do cadastro.
          </p>
        </div>
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
    <UTabs
      v-if="!loadingDetail && mode === 'detail' && record && hasDetailPanel"
      v-model="detailTab"
      :items="detailTabs"
      :unmount-on-hide="true"
      class="min-w-0"
    >
      <template #record>
        <component
          :is="form"
          :record="record"
          :mode="mode"
          :can-save="editor.canSave.value"
          :can-archive="editor.permissions.archive.value"
          :busy="editor.mutationPending.value"
          :field-errors="fieldErrors"
          @save="editor.save"
        />
      </template>
      <template #panel>
        <slot name="detail-panel" :record="record" />
      </template>
    </UTabs>
    <component
      :is="form"
      v-else-if="!loadingDetail && (mode === 'create' || record)"
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
