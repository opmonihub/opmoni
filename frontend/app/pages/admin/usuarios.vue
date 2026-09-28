<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelSelectUi, panelTableUi } from '~/components/data-table/panel'
import { adminListParams, createLatestRequestRunner } from '~/utils/adminListFilters'

definePageMeta({
  middleware: ['auth', 'super-admin']
})

interface AdminUser {
  id: number
  name: string
  email: string
  is_super_admin: boolean
  account_links?: { role: string, account?: { id: number, name: string } | null }[]
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

const users = ref<AdminUser[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 15
const loading = ref(false)
const q = ref('')
const debouncedQ = refDebounced(q, 300)
const typeFilter = ref<'all' | 'super' | 'user'>('all')
const runLatestLoad = createLatestRequestRunner()

const columns: TableColumn<AdminUser>[] = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'name', header: 'Nome' },
  { accessorKey: 'type', header: 'Tipo' },
  { accessorKey: 'accounts', header: 'Contas' }
]

const roleLabel: Record<string, string> = {
  admin: 'Admin',
  operador: 'Operador',
  user: 'Usuário'
}

function accountRoleLabel(role: string) {
  return roleLabel[role] ?? role
}

const listParams = computed(() => adminListParams(page.value, debouncedQ.value, 'type', typeFilter.value))

function load() {
  loading.value = true
  return runLatestLoad(
    () => $api<Paginated<AdminUser>>('/admin/users', { params: listParams.value }),
    {
      onSuccess: (res) => {
        users.value = res.data
        total.value = res.total
      },
      onError: () => toast.add({ title: 'Não foi possível carregar os usuários', color: 'error' }),
      onSettled: () => {
        loading.value = false
      }
    }
  )
}

onMounted(load)
watch(page, load)
watch([debouncedQ, typeFilter], () => {
  if (page.value !== 1) {
    page.value = 1
    return
  }

  void load()
})
</script>

<template>
  <DataTablePanelList
    title="Usuários"
    description="Pessoas com acesso ao sistema."
  >
    <template #toolbar>
      <UInput
        v-model="q"
        class="max-w-sm"
        icon="i-lucide-search"
        placeholder="Filtrar por nome ou email..."
      />

      <USelect
        v-model="typeFilter"
        :items="[
          { label: 'Todos', value: 'all' },
          { label: 'Super admin', value: 'super' },
          { label: 'Usuário', value: 'user' }
        ]"
        :ui="panelSelectUi"
        placeholder="Tipo"
        class="min-w-36"
      />
    </template>

    <div :class="panelBodyClass">
      <UTable
        :data="users"
        :columns="columns"
        :loading="loading"
        class="shrink-0"
        :ui="panelTableUi"
      >
        <template #name-cell="{ row }">
          <p class="font-medium text-highlighted">
            {{ row.original.name }}
          </p>
          <p class="text-muted">
            {{ row.original.email }}
          </p>
        </template>

        <template #type-cell="{ row }">
          <UBadge v-if="row.original.is_super_admin" color="primary" variant="subtle">
            Super admin
          </UBadge>
          <UBadge v-else color="neutral" variant="subtle">
            Usuário
          </UBadge>
        </template>

        <template #accounts-cell="{ row }">
          <div v-if="row.original.account_links?.length" class="flex flex-wrap gap-1.5">
            <UBadge
              v-for="link in row.original.account_links"
              :key="`${link.account?.id}-${link.role}`"
              color="neutral"
              variant="subtle"
            >
              {{ link.account?.name ?? '—' }} · {{ accountRoleLabel(link.role) }}
            </UBadge>
          </div>
          <span v-else class="text-muted">—</span>
        </template>

        <template #empty>
          <DataTablePanelTableEmpty
            icon="i-lucide-users"
            label="Nenhum usuário encontrado."
          />
        </template>
      </UTable>

      <div :class="panelFooterClass">
        <div :class="panelFooterCountClass">
          {{ users.length }} de {{ total }} usuário(s)
        </div>

        <div :class="panelPaginationClass">
          <UPagination v-model:page="page" :total="total" :items-per-page="perPage" />
        </div>
      </div>
    </div>
  </DataTablePanelList>
</template>
