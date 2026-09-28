<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelSelectUi, panelTableUi } from '~/components/data-table/panel'
import { adminListParams, createLatestRequestRunner, pageWithinLastPage } from '~/utils/adminListFilters'

definePageMeta({
  middleware: ['auth', 'super-admin']
})

interface AdminSubscription {
  id: number
  status: 'active' | 'past_due' | 'canceled'
  account?: { id: number, name: string } | null
  plan?: { id: number, slug: string, name: string } | null
}

interface AdminPlan {
  id: number
  slug: string
  name: string
}

interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const STATUS_META: Record<AdminSubscription['status'], { label: string, color: 'success' | 'warning' | 'neutral' }> = {
  active: { label: 'Ativa', color: 'success' },
  past_due: { label: 'Inadimplente', color: 'warning' },
  canceled: { label: 'Cancelada', color: 'neutral' }
}

const { $api } = useNuxtApp()
const toast = useToast()

const subscriptions = ref<AdminSubscription[]>([])
const plans = ref<AdminPlan[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 15
const loading = ref(false)
const q = ref('')
const debouncedQ = refDebounced(q, 300)
const statusFilter = ref<'all' | AdminSubscription['status']>('all')
const runLatestLoad = createLatestRequestRunner()

const columns: TableColumn<AdminSubscription>[] = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'account', header: 'Conta' },
  { accessorKey: 'plan', header: 'Plano' },
  { accessorKey: 'status', header: 'Status' },
  { id: 'actions' }
]

const listParams = computed(() => adminListParams(page.value, debouncedQ.value, 'status', statusFilter.value))

function load() {
  loading.value = true
  return runLatestLoad(
    () => Promise.all([
      $api<Paginated<AdminSubscription>>('/admin/subscriptions', { params: listParams.value }),
      plans.value.length ? Promise.resolve(null) : $api<AdminPlan[]>('/admin/plans')
    ]),
    {
      onSuccess: ([subs, planList]) => {
        subscriptions.value = subs.data
        total.value = subs.total
        page.value = pageWithinLastPage(page.value, subs.last_page)
        if (planList) plans.value = planList
      },
      onError: () => toast.add({ title: 'Não foi possível carregar as assinaturas', color: 'error' }),
      onSettled: () => {
        loading.value = false
      }
    }
  )
}

onMounted(load)
watch(page, load)
watch([debouncedQ, statusFilter], () => {
  if (page.value !== 1) {
    page.value = 1
    return
  }

  void load()
})

const editOpen = ref(false)
const editSchema = z.object({
  plan_id: z.number({ error: 'Escolha um plano' }),
  status: z.enum(['active', 'past_due', 'canceled'])
})
type EditSchema = z.output<typeof editSchema>
const editTarget = ref<AdminSubscription | null>(null)
const editState = reactive<Partial<EditSchema>>({ plan_id: undefined, status: undefined })
const saving = ref(false)

function openEdit(subscription: AdminSubscription) {
  editTarget.value = subscription
  editState.plan_id = subscription.plan?.id
  editState.status = subscription.status
  editOpen.value = true
}

function subscriptionActions(subscription: AdminSubscription) {
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
  if (!editTarget.value) return
  saving.value = true
  try {
    await $api(`/admin/subscriptions/${editTarget.value.id}`, {
      method: 'PUT',
      body: { plan_id: event.data.plan_id, status: event.data.status }
    })
    toast.add({ title: 'Assinatura atualizada', color: 'success' })
    editOpen.value = false
    await load()
  } catch {
    toast.add({ title: 'Não foi possível atualizar a assinatura', color: 'error' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <DataTablePanelList
    title="Assinaturas"
    description="Plano e status de cada conta."
  >
    <template #toolbar>
      <UInput
        v-model="q"
        class="max-w-sm"
        icon="i-lucide-search"
        placeholder="Filtrar por conta ou plano..."
      />

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
        class="min-w-36"
      />
    </template>

    <div :class="panelBodyClass">
      <UTable
        :data="subscriptions"
        :columns="columns"
        :loading="loading"
        class="shrink-0"
        :ui="panelTableUi"
      >
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
    </div>
  </DataTablePanelList>

  <USlideover
    v-model:open="editOpen"
    title="Editar assinatura"
    :description="editTarget?.account?.name"
    :ui="{ footer: 'justify-end' }"
  >
    <template #body>
      <UForm
        id="edit-subscription-form"
        :schema="editSchema"
        :state="editState"
        class="space-y-4"
        @submit="onSave"
      >
        <UFormField label="Plano" name="plan_id">
          <USelect
            v-model="editState.plan_id"
            :items="plans.map(p => ({ label: p.name, value: p.id }))"
            placeholder="Escolha um plano"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Status" name="status">
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

    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <UButton
        label="Salvar"
        type="submit"
        form="edit-subscription-form"
        :loading="saving"
      />
    </template>
  </USlideover>
</template>
