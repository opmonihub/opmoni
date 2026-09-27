<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
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

const tableUi = {
  base: 'table-fixed border-separate border-spacing-0',
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
  td: 'border-b border-default',
  separator: 'h-0'
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
  <div>
    <UPageCard
      title="Usuários"
      description="Pessoas com acesso ao sistema."
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    />

    <UPageCard
      variant="subtle"
      :ui="{ container: 'p-0 sm:p-0 gap-y-0', wrapper: 'items-stretch', header: 'p-4 mb-0 border-b border-default' }"
    >
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-1.5">
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
            :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            placeholder="Tipo"
            class="min-w-36"
          />
        </div>
      </template>

      <div class="flex flex-col gap-4 p-4 sm:p-6">
        <UTable
          :data="users"
          :columns="columns"
          :loading="loading"
          class="shrink-0"
          :ui="tableUi"
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
            <div class="flex flex-col items-center justify-center gap-2 py-8 text-sm text-muted">
              <UIcon name="i-lucide-users" class="size-6" />
              <span>Nenhum usuário encontrado.</span>
            </div>
          </template>
        </UTable>

        <div class="flex items-center justify-between gap-3 border-t border-default pt-4">
          <div class="text-sm text-muted">
            {{ users.length }} de {{ total }} usuário(s)
          </div>

          <div class="flex items-center gap-1.5">
            <UPagination v-model:page="page" :total="total" :items-per-page="perPage" />
          </div>
        </div>
      </div>
    </UPageCard>
  </div>
</template>
