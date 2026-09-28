<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelSelectUi, panelTableUi } from '~/components/data-table/panel'
import { adminListParams, createLatestRequestRunner, pageWithinLastPage } from '~/utils/adminListFilters'

definePageMeta({
  middleware: ['auth', 'super-admin']
})

interface AdminAccount {
  id: number
  name: string
  status: 'active' | 'suspended'
  members_count?: number
  subscription?: {
    id: number
    status: string
    plan?: { id: number, slug: string, name: string } | null
  } | null
  created_at: string
}

interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const { $api } = useNuxtApp()
const toast = useToast()
const { accounts, switchAccount, enterSupport } = useAuth()

const accountsList = ref<AdminAccount[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 15
const loading = ref(false)
const q = ref('')
const debouncedQ = refDebounced(q, 300)
const statusFilter = ref<'all' | 'active' | 'suspended'>('all')
const runLatestLoad = createLatestRequestRunner()

const columns: TableColumn<AdminAccount>[] = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'name', header: 'Conta' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'plan', header: 'Plano' },
  { accessorKey: 'members', header: 'Membros' },
  { id: 'actions' }
]

const listParams = computed(() => adminListParams(page.value, debouncedQ.value, 'status', statusFilter.value))

function load() {
  loading.value = true
  return runLatestLoad(
    () => $api<Paginated<AdminAccount>>('/admin/accounts', { params: listParams.value }),
    {
      onSuccess: (res) => {
        accountsList.value = res.data
        total.value = res.total
        page.value = pageWithinLastPage(page.value, res.last_page)
      },
      onError: () => toast.add({ title: 'Não foi possível carregar as contas', color: 'error' }),
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

function isOwnAccount(id: number) {
  return accounts.value.some(a => a.id === id)
}

async function toggleStatus(account: AdminAccount) {
  const status = account.status === 'active' ? 'suspended' : 'active'
  try {
    await $api(`/admin/accounts/${account.id}`, { method: 'PATCH', body: { status } })
    toast.add({
      title: status === 'suspended' ? 'Conta suspensa' : 'Conta reativada',
      description: account.name,
      color: status === 'suspended' ? 'warning' : 'success'
    })
    await load()
    return true
  } catch {
    toast.add({ title: 'Não foi possível atualizar a conta', color: 'error' })
    return false
  }
}

async function enterAccount(account: AdminAccount) {
  try {
    if (isOwnAccount(account.id)) {
      await switchAccount(account.id)
    } else {
      await enterSupport(account.id)
    }
    window.location.assign('/')
  } catch {
    toast.add({ title: 'Não foi possível entrar na conta', color: 'error' })
  }
}

const confirmOpen = ref(false)
const confirmTarget = ref<AdminAccount | null>(null)
const confirming = ref(false)

function openStatusConfirm(account: AdminAccount) {
  confirmTarget.value = account
  confirmOpen.value = true
}

async function confirmStatus() {
  if (!confirmTarget.value) return
  confirming.value = true
  try {
    const ok = await toggleStatus(confirmTarget.value)
    if (ok) confirmOpen.value = false
  } finally {
    confirming.value = false
  }
}

function accountActions(account: AdminAccount) {
  return [{
    type: 'label' as const,
    label: 'Ações'
  }, {
    label: 'Renomear',
    icon: 'i-lucide-pencil',
    onSelect: () => openRename(account)
  }, {
    label: 'Entrar em suporte',
    icon: 'i-lucide-life-buoy',
    onSelect: () => enterAccount(account)
  }, {
    type: 'separator' as const
  }, {
    label: account.status === 'active' ? 'Suspender' : 'Reativar',
    icon: account.status === 'active' ? 'i-lucide-ban' : 'i-lucide-circle-check',
    color: account.status === 'active' ? 'error' as const : undefined,
    onSelect: () => openStatusConfirm(account)
  }]
}

const createOpen = ref(false)
const createSchema = z.object({
  name: z.string().min(2, 'Nome muito curto')
})
type CreateSchema = z.output<typeof createSchema>
const createState = reactive<Partial<CreateSchema>>({ name: '' })
const creating = ref(false)

async function onCreate(event: FormSubmitEvent<CreateSchema>) {
  creating.value = true
  try {
    await $api('/admin/accounts', { method: 'POST', body: { name: event.data.name } })
    toast.add({ title: 'Conta criada', description: event.data.name, color: 'success' })
    createOpen.value = false
    createState.name = ''
    page.value = 1
    await load()
  } catch {
    toast.add({ title: 'Não foi possível criar a conta', color: 'error' })
  } finally {
    creating.value = false
  }
}

const renameOpen = ref(false)
const renameSchema = z.object({
  name: z.string().min(2, 'Nome muito curto')
})
type RenameSchema = z.output<typeof renameSchema>
const renameTarget = ref<AdminAccount | null>(null)
const renameState = reactive<Partial<RenameSchema>>({ name: '' })
const renaming = ref(false)

function openRename(account: AdminAccount) {
  renameTarget.value = account
  renameState.name = account.name
  renameOpen.value = true
}

async function onRename(event: FormSubmitEvent<RenameSchema>) {
  if (!renameTarget.value) return
  renaming.value = true
  try {
    await $api(`/admin/accounts/${renameTarget.value.id}`, { method: 'PATCH', body: { name: event.data.name } })
    toast.add({ title: 'Conta renomeada', description: event.data.name, color: 'success' })
    renameOpen.value = false
    await load()
  } catch {
    toast.add({ title: 'Não foi possível renomear a conta', color: 'error' })
  } finally {
    renaming.value = false
  }
}
</script>

<template>
  <DataTablePanelList
    title="Contas"
    description="Empresas que usam o opmoni."
  >
    <template #action>
      <UButton
        label="Nova conta"
        icon="i-lucide-plus"
        color="neutral"
        class="w-fit lg:ms-auto"
        @click="createOpen = true"
      />
    </template>

    <template #toolbar>
      <UInput
        v-model="q"
        class="max-w-sm"
        icon="i-lucide-search"
        placeholder="Filtrar por nome ou ID..."
      />

      <USelect
        v-model="statusFilter"
        :items="[
          { label: 'Todas', value: 'all' },
          { label: 'Ativas', value: 'active' },
          { label: 'Suspensas', value: 'suspended' }
        ]"
        :ui="panelSelectUi"
        placeholder="Status"
        class="min-w-28"
      />
    </template>

    <div :class="panelBodyClass">
      <UTable
        :data="accountsList"
        :columns="columns"
        :loading="loading"
        class="shrink-0"
        :ui="panelTableUi"
      >
        <template #name-cell="{ row }">
          <p class="font-medium text-highlighted">
            {{ row.original.name }}
          </p>
        </template>

        <template #status-cell="{ row }">
          <UBadge
            :color="row.original.status === 'active' ? 'success' : 'error'"
            variant="subtle"
          >
            {{ row.original.status === 'active' ? 'Ativa' : 'Suspensa' }}
          </UBadge>
        </template>

        <template #plan-cell="{ row }">
          {{ row.original.subscription?.plan?.name ?? '—' }}
        </template>

        <template #members-cell="{ row }">
          {{ row.original.members_count ?? '—' }}
        </template>

        <template #actions-cell="{ row }">
          <DataTableRowActionsMenu
            :items="accountActions(row.original)"
            :label="`Ações de ${row.original.name}`"
            flush
          />
        </template>

        <template #empty>
          <DataTablePanelTableEmpty
            icon="i-lucide-building-2"
            label="Nenhuma conta encontrada."
          />
        </template>
      </UTable>

      <div :class="panelFooterClass">
        <div :class="panelFooterCountClass">
          {{ accountsList.length }} de {{ total }} conta(s)
        </div>

        <div :class="panelPaginationClass">
          <UPagination v-model:page="page" :total="total" :items-per-page="perPage" />
        </div>
      </div>
    </div>
  </DataTablePanelList>

  <UModal
    v-model:open="createOpen"
    title="Nova conta"
    description="Criar uma conta manualmente."
  >
    <template #body>
      <UForm
        id="create-account-form"
        :schema="createSchema"
        :state="createState"
        class="space-y-4"
        @submit="onCreate"
      >
        <UFormField label="Nome" name="name">
          <UInput v-model="createState.name" placeholder="Nome da empresa" class="w-full" />
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
        label="Criar"
        type="submit"
        form="create-account-form"
        :loading="creating"
      />
    </template>
  </UModal>

  <USlideover
    v-model:open="renameOpen"
    title="Renomear conta"
    :description="renameTarget?.name"
    :ui="{ footer: 'justify-end' }"
  >
    <template #body>
      <UForm
        id="rename-account-form"
        :schema="renameSchema"
        :state="renameState"
        class="space-y-4"
        @submit="onRename"
      >
        <UFormField label="Nome" name="name">
          <UInput v-model="renameState.name" class="w-full" />
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
        form="rename-account-form"
        :loading="renaming"
      />
    </template>
  </USlideover>

  <UModal
    v-model:open="confirmOpen"
    :title="confirmTarget?.status === 'active' ? 'Suspender conta' : 'Reativar conta'"
    :description="confirmTarget?.status === 'active' ? `${confirmTarget?.name} deixa de acessar o app até ser reativada.` : `${confirmTarget?.name} volta a acessar o app.`"
  >
    <template #footer="{ close }">
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="close"
      />
      <UButton
        :label="confirmTarget?.status === 'active' ? 'Suspender' : 'Reativar'"
        :color="confirmTarget?.status === 'active' ? 'error' : 'primary'"
        :loading="confirming"
        @click="confirmStatus"
      />
    </template>
  </UModal>
</template>
