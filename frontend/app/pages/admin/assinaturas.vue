<script setup lang="ts">
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'
import { focusFormError } from '~/utils/formPresentation'
import { nextTick } from 'vue'
import { useDirtyModal } from '~/composables/useDirtyModal'
import type { Subscription, Plan, ResourceListResponse } from '#shared/types/admin'
import * as z from 'zod'
import { apiFailure } from '#shared/utils/access'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelSelectUi, panelTableUi } from '~/components/data-table/panel'
import { adminListNavigationOptions, adminListParams, createLatestRequestRunner, pageWithinLastPage, readPaginatedMeta } from '~/utils/adminListFilters'

definePageMeta({
  middleware: ['auth', 'super-admin']
})
const STATUS_META: Record<Subscription['status'], {
  label: string
  color: 'success' | 'warning' | 'neutral'
}> = {
  active: { label: 'Ativa', color: 'success' },
  past_due: { label: 'Inadimplente', color: 'warning' },
  canceled: { label: 'Cancelada', color: 'neutral' }
}
const adminReadAllowed = useAdminReadAllowed()
const { $api } = useNuxtApp()
const toast = useToast()
const subscriptions = ref<Subscription[]>([])
const plans = ref<Plan[]>([])
const catalogLoaded = ref(false)
const catalogLoading = ref(false)
const catalogError = ref<unknown>(null)
const runCatalog = createLatestRequestRunner()
function loadCatalog() {
  if (!adminReadAllowed.value)
    return Promise.resolve()
  if (catalogLoading.value)
    return
  catalogLoading.value = true
  catalogError.value = null
  return runCatalog(signal => $api<Plan[]>('/admin/plans', { signal }), {
    onSuccess: (data) => {
      plans.value = data
      catalogLoaded.value = true
    },
    onError: (error) => {
      catalogError.value = error
    },
    onSettled: () => {
      catalogLoading.value = false
    }
  })
}
const total = ref(0)
const route = useRoute()
const sortFields = ['id', 'account', 'status']
const navigation = useListNavigationState('admin:assinaturas', adminListNavigationOptions(['all', 'active', 'past_due', 'canceled'], sortFields, () => route.query ?? {}))
const page = listNavigationField(navigation, 'page')
const sort = listNavigationField(navigation, 'sort')
const direction = listNavigationField(navigation, 'direction')
const perPage = 15
const loading = ref(true)
const q = listNavigationField(navigation, 'q')
const debouncedQ = refDebounced(q, 300)
const statusFilter = listNavigationField(navigation, 'filter')
const runLatestLoad = createLatestRequestRunner()
const columns: TableColumn<Subscription>[] = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'account', header: 'Conta' },
  { accessorKey: 'plan', header: 'Plano' },
  { accessorKey: 'status', header: 'Status' },
  { id: 'actions' }
]
const columnVisibility = ref<Record<string, boolean>>({})
const hideableColumns = [
  { id: 'id', label: 'ID' },
  { id: 'account', label: 'Conta' },
  { id: 'plan', label: 'Plano' },
  { id: 'status', label: 'Status' }
]
const listParams = computed(() => adminListParams(page.value, debouncedQ.value, 'status', statusFilter.value, sort.value, direction.value, sortFields))
const loadError = ref<unknown>(null)
const hasLoaded = ref(false)
const { isLoading, showError, retry } = useRetryableLoad({
  refresh: load,
  error: loadError,
  loading,
  loadErrorTitle: 'Não foi possível carregar as assinaturas',
  refreshErrorTitle: 'Não foi possível atualizar as assinaturas'
})
function load() {
  if (!adminReadAllowed.value)
    return Promise.resolve()
  loading.value = true
  loadError.value = null
  return runLatestLoad(signal => $api<ResourceListResponse<Subscription>>('/admin/subscriptions', { params: listParams.value, signal }), {
    onSuccess: (subs) => {
      const meta = readPaginatedMeta(subs)
      const recovered = pageWithinLastPage(page.value, meta.last_page)
      if (recovered !== page.value) {
        page.value = recovered
        return
      }
      subscriptions.value = subs.data
      total.value = meta.total
      page.value = pageWithinLastPage(page.value, meta.last_page)
      hasLoaded.value = true
    },
    onError: (err) => {
      loadError.value = err
    },
    onSettled: () => {
      loading.value = false
    }
  })
}
onMounted(() => {
  void load()
})
onBeforeUnmount(() => {
  runLatestLoad.invalidate()
  runCatalog.invalidate()
})
watch([debouncedQ, statusFilter, sort, direction], () => {
  page.value = 1
}, { flush: 'sync' })
const editOpen = ref(false)
watch(editOpen, (open) => {
  if (open) return
  runCatalog.invalidate()
  catalogLoading.value = false
  catalogError.value = null
}, { flush: 'sync' })
const editSchema = z.object({
  plan_id: z.number({ error: 'Escolha um plano' }),
  status: z.enum(['active', 'past_due', 'canceled'])
})
type EditSchema = z.output<typeof editSchema>
const editTarget = ref<Subscription | null>(null)
const editState = reactive<Partial<EditSchema>>({ plan_id: undefined, status: undefined })
const saving = ref(false)
const { modelOpen: editorModelOpen, discardOpen, requestClose: requestEditorClose, confirmDiscard } = useDirtyModal(editOpen, () => editState, () => saving.value)
const restoreEditorFocus = useModalFocusReturn(editOpen)
let active = true
onBeforeUnmount(() => {
  active = false
})
const fieldErrors = ref<Record<string, string[]>>({})
watch(fieldErrors, (errors) => {
  if (editOpen.value && Object.keys(errors).length) void nextTick(() => focusFormError(undefined, 'edit-subscription-form'))
})
function openEdit(subscription: Subscription) {
  if (saving.value)
    return
  fieldErrors.value = {}
  editTarget.value = subscription
  editState.plan_id = subscription.plan?.id
  editState.status = subscription.status
  editOpen.value = true
  if (!catalogLoaded.value) void loadCatalog()
}
function subscriptionActions(subscription: Subscription) {
  return [{
    type: 'label' as const,
    label: 'Ações'
  }, {
    label: 'Editar',
    icon: 'i-lucide-pencil',
    onSelect: () => openEdit(subscription)
  }]
}
async function onSave(event: FormSubmitEvent<EditSchema>) {
  if (!editTarget.value || saving.value || !catalogLoaded.value || catalogLoading.value || catalogError.value || !plans.value.some(plan => plan.id === event.data.plan_id))
    return
  fieldErrors.value = {}
  saving.value = true
  const authority = readAuthority.value
  try {
    await $api(`/admin/subscriptions/${editTarget.value.id}`, {
      method: 'PUT',
      body: { plan_id: event.data.plan_id, status: event.data.status }
    })
    if (!active || authority !== readAuthority.value)
      return
    toast.add({ title: 'Assinatura atualizada', color: 'success' })
    editOpen.value = false
    await load()
  } catch (error) {
    if (!active || authority !== readAuthority.value)
      return
    fieldErrors.value = apiFailure(error).errors || {}
    toast.add({ title: 'Não foi possível atualizar a assinatura', color: 'error' })
  } finally {
    if (authority === readAuthority.value) saving.value = false
  }
}
const readAuthority = useAdminReadAuthority(() => {
  saving.value = false
  runLatestLoad.invalidate()
  runCatalog.invalidate()
  subscriptions.value = []
  plans.value = []
  total.value = 0
  hasLoaded.value = false
  catalogLoaded.value = false
  loadError.value = null
  catalogError.value = null
  loading.value = false
  catalogLoading.value = false
  editOpen.value = false
  editTarget.value = null
  Object.assign(editState, { plan_id: undefined, status: undefined })
  fieldErrors.value = {}
})
watch([listParams, readAuthority], load)
</script>

<template>
  <DataTablePanelList
    title="Assinaturas"
    description="Plano e situação comercial de cada conta. Assinaturas inativas permitem leitura e bloqueiam alterações nos dados da conta."
  >
    <template #toolbar>
      <DataTableSortMenu
        :sort="sort"
        :direction="direction"
        :fields="[{ value: 'id', label: 'ID' }, { value: 'account', label: 'Conta' }, { value: 'status', label: 'Situação' }]"
        @change="ordering => navigation = { ...navigation, ...ordering, page: 1 }"
      />
      <UInput
        v-model="q"
        class="max-w-sm"
        icon="i-lucide-search"
        placeholder="Filtrar por conta ou plano..."
        aria-label="Buscar conta ou plano"
      />

      <div class="ms-auto flex shrink-0 items-center gap-1.5">
        <USelect
          v-model="statusFilter"
          :items="[
            { label: 'Todas', value: 'all' },
            { label: 'Ativas', value: 'active' },
            { label: 'Inadimplentes', value: 'past_due' },
            { label: 'Canceladas', value: 'canceled' }
          ]"
          :ui="panelSelectUi"
          placeholder="Status"
          aria-label="Situação da assinatura"
          class="min-w-36"
        />
        <DataTableColumnMenu
          v-model="columnVisibility"
          :columns="hideableColumns"
          class="shrink-0"
        />
      </div>
    </template>

    <div :class="panelBodyClass">
      <ErrorRetryAlert
        v-if="showError"
        :title="hasLoaded ? 'Não foi possível atualizar. Os resultados anteriores foram mantidos.' : 'Não foi possível carregar as assinaturas'"
        :loading="isLoading"
        @retry="retry"
      />

      <template v-if="!showError || hasLoaded">
        <UTable
          v-model:column-visibility="columnVisibility"
          :data="subscriptions"
          :columns="columns"
          :get-row-id="row => String(row.id)"
          :sorting-options="{ manualSorting: true }"
          :loading="loading"
          class="shrink-0"
          :ui="panelTableUi"
        >
          <template #id-header>
            <DataTableSortButton label="ID" :sorted="sort === 'id' ? direction === 'desc' ? 'desc' : 'asc' : false" @toggle="navigation = { ...navigation, sort: sort === 'id' && direction === 'desc' ? '' : 'id', direction: sort === 'id' && direction === 'asc' ? 'desc' : 'asc', page: 1 }" />
          </template>
          <template #account-header>
            <DataTableSortButton label="Conta" :sorted="sort === 'account' ? direction === 'desc' ? 'desc' : 'asc' : false" @toggle="navigation = { ...navigation, sort: sort === 'account' && direction === 'desc' ? '' : 'account', direction: sort === 'account' && direction === 'asc' ? 'desc' : 'asc', page: 1 }" />
          </template>
          <template #status-header>
            <DataTableSortButton label="Situação" :sorted="sort === 'status' ? direction === 'desc' ? 'desc' : 'asc' : false" @toggle="navigation = { ...navigation, sort: sort === 'status' && direction === 'desc' ? '' : 'status', direction: sort === 'status' && direction === 'asc' ? 'desc' : 'asc', page: 1 }" />
          </template>
          <template #account-cell="{ row }">
            <p class="font-medium text-highlighted">
              {{ row.original.account?.name ?? `#${row.original.id}` }}
            </p>
          </template>

          <template #plan-cell="{ row }">
            {{ row.original.plan?.name ?? '—' }}
          </template>

          <template #status-cell="{ row }">
            <UBadge :color="STATUS_META[row.original.status].color" variant="subtle">
              {{ STATUS_META[row.original.status].label }}
            </UBadge>
          </template>

          <template #actions-cell="{ row }">
            <DataTableRowActionsMenu
              :items="subscriptionActions(row.original)"
              :label="`Ações de ${row.original.account?.name ?? `#${row.original.id}`}`"
              flush
            />
          </template>

          <template #empty>
            <DataTablePanelTableEmpty
              icon="i-lucide-receipt"
              label="Nenhuma assinatura encontrada."
            />
          </template>
        </UTable>

        <div :class="panelFooterClass">
          <div :class="panelFooterCountClass">
            {{ subscriptions.length }} de {{ total }} assinatura(s)
          </div>

          <div :class="panelPaginationClass">
            <UPagination v-model:page="page" :total="total" :items-per-page="perPage" />
          </div>
        </div>
      </template>
    </div>
  </DataTablePanelList>

  <UModal
    v-model:open="editorModelOpen"
    :dismissible="!saving"
    :close="!saving"
    title="Editar assinatura"
    :description="editTarget?.account?.name"
    :ui="{ content: 'max-w-2xl', footer: 'flex-col-reverse sm:flex-row sm:justify-end' }"
    @after:leave="restoreEditorFocus"
  >
    <template #body>
      <ErrorRetryAlert
        v-if="catalogError"
        title="Não foi possível carregar os planos"
        :loading="catalogLoading"
        @retry="loadCatalog"
      />
      <p v-else-if="catalogLoading" role="status">
        Carregando planos…
      </p>
      <p v-else-if="catalogLoaded && !plans.length" role="status">
        Nenhum plano disponível.
      </p>
      <UForm
        id="edit-subscription-form"
        :schema="editSchema"
        :state="editState"
        class="space-y-4"
        @submit="onSave"
        @error="focusFormError"
      >
        <UFormField label="Plano" name="plan_id" :error="fieldErrors.plan_id?.[0]">
          <USelect
            v-model="editState.plan_id"
            :items="plans.map(p => ({ label: p.name, value: p.id }))"
            placeholder="Escolha um plano"
            :disabled="saving || catalogLoading || !catalogLoaded || !!catalogError"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Status" name="status" :error="fieldErrors.status?.[0]">
          <USelect
            v-model="editState.status"
            :items="[
              { label: 'Ativa', value: 'active' },
              { label: 'Inadimplente', value: 'past_due' },
              { label: 'Cancelada', value: 'canceled' }
            ]"
            class="w-full"
          />
        </UFormField>
      </UForm>
    </template>

    <template #footer>
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        :disabled="saving"
        @click="requestEditorClose"
      />
      <UButton
        label="Salvar"
        type="submit"
        form="edit-subscription-form"
        :loading="saving"
        :disabled="saving || !catalogLoaded || catalogLoading || !!catalogError || !plans.some(plan => plan.id === editState.plan_id)"
      />
    </template>
  </UModal>
  <UModal
    v-model:open="discardOpen"
    title="Descartar alterações?"
    description="As alterações não salvas serão perdidas."
    @after:leave="restoreEditorFocus"
  >
    <template #footer>
      <UButton
        label="Continuar editando"
        color="neutral"
        variant="outline"
        @click="discardOpen = false"
      />
      <UButton label="Descartar alterações" color="error" @click="confirmDiscard" />
    </template>
  </UModal>
</template>
