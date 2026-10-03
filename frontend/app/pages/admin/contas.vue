<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelSelectUi, panelTableUi } from '~/components/data-table/panel'
import { adminListParams, createLatestRequestRunner, pageWithinLastPage, readPaginatedMeta } from '~/utils/adminListFilters'
import { brazilUfItems } from '~/utils/brazilUfs'

definePageMeta({
  middleware: ['auth', 'super-admin']
})

interface PlatformBillingContact {
  phone?: string
  phone_whatsapp?: boolean
  state?: string
  city?: string
}

interface AdminAccount {
  id: number
  name: string
  status: 'active' | 'suspended'
  members_count?: number
  platform_billing_contact?: PlatformBillingContact | null
  platform_owner_invite?: { name: string, email: string } | null
  subscription?: {
    id: number
    status: string
    plan?: { id: number, slug: string, name: string } | null
  } | null
  created_at: string
}

interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
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

const columnVisibility = ref<Record<string, boolean>>({})

const hideableColumns = [
  { id: 'id', label: 'ID' },
  { id: 'name', label: 'Conta' },
  { id: 'status', label: 'Status' },
  { id: 'plan', label: 'Plano' },
  { id: 'members', label: 'Membros' }
]

const listParams = computed(() => adminListParams(page.value, debouncedQ.value, 'status', statusFilter.value))

/**
 * Falha fatal de carga inicial x falha de refresh: a primeira vez que a lista
 * não carrega o alerta com retry toma o corpo do painel (o contrato de
 * `useRetryableLoad`); depois de uma carga bem-sucedida, falha de paginação ou
 * filtro é só toast, e a última boa lista fica visível.
 */
const loadError = ref<unknown>(null)
const hasLoaded = ref(false)

const { isLoading, showError, retry } = useRetryableLoad({
  refresh: load,
  error: loadError,
  loading,
  loadErrorTitle: 'Não foi possível carregar as contas',
  refreshErrorTitle: 'Não foi possível atualizar as contas'
})

function load() {
  loading.value = true
  loadError.value = null
  return runLatestLoad(
    () => $api<Paginated<AdminAccount>>('/admin/accounts', { params: listParams.value }),
    {
      onSuccess: (res) => {
        const meta = readPaginatedMeta(res)
        accountsList.value = res.data
        total.value = meta.total
        page.value = pageWithinLastPage(page.value, meta.last_page)
        hasLoaded.value = true
      },
      onError: (err) => {
        if (hasLoaded.value) {
          toast.add({ title: 'Não foi possível atualizar as contas', color: 'error' })
        } else {
          loadError.value = err
        }
      },
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
    label: 'Editar',
    icon: 'i-lucide-pencil',
    onSelect: () => openEdit(account)
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
const accessItems = [
  { value: 'password_now', label: 'Definir senha agora' },
  { value: 'first_access', label: 'Usuário define no primeiro acesso' }
] as const

const accountFormSchema = z.object({
  name: z.string().min(2, 'Nome muito curto'),
  owner_name: z.string().max(255).optional(),
  login_email: z.union([z.literal(''), z.email('E-mail inválido')]).optional(),
  access: z.enum(['none', 'password_now', 'first_access']),
  password: z.string().optional(),
  phone: z.string().max(20, 'Telefone muito longo').optional(),
  phone_whatsapp: z.boolean().optional(),
  state: z.union([z.literal(''), z.string().length(2, 'UF inválida')]).optional(),
  city: z.string().max(255, 'Município muito longo').optional()
}).superRefine((data, ctx) => {
  const loginEmail = data.login_email?.trim()
  if (!loginEmail) {
    return
  }
  if (!data.owner_name?.trim()) {
    ctx.addIssue({ code: 'custom', path: ['owner_name'], message: 'Informe o nome' })
  }
  if (data.access === 'password_now' && (!data.password || data.password.length < 8)) {
    ctx.addIssue({ code: 'custom', path: ['password'], message: 'Mínimo de 8 caracteres' })
  }
})
const createSchema = accountFormSchema
type CreateSchema = z.output<typeof createSchema>
type AccountFormSchema = CreateSchema
const createState = reactive<Partial<CreateSchema>>({
  name: '',
  owner_name: '',
  login_email: '',
  access: 'password_now',
  password: '',
  phone: '',
  phone_whatsapp: false,
  state: '',
  city: ''
})
const creating = ref(false)
const lastFirstAccessUrl = ref<string | null>(null)

function resetCreateForm() {
  createState.name = ''
  createState.owner_name = ''
  createState.login_email = ''
  createState.access = 'password_now'
  createState.password = ''
  createState.phone = ''
  createState.phone_whatsapp = false
  createState.state = ''
  createState.city = ''
  lastFirstAccessUrl.value = null
}

function accountFormBody(data: AccountFormSchema, options: { includeAccess: boolean }) {
  const body: Record<string, string | boolean> = { name: data.name }
  const loginEmail = data.login_email?.trim()
  const phone = data.phone?.trim() ?? ''
  const city = data.city?.trim() ?? ''
  const state = data.state?.trim() ?? ''
  const ownerName = data.owner_name?.trim()

  body.phone = phone
  body.phone_whatsapp = Boolean(data.phone_whatsapp)
  body.state = state
  body.city = city

  if (options.includeAccess) {
    if (loginEmail) {
      body.login_email = loginEmail
      body.access = data.access ?? 'password_now'
      if (ownerName) body.owner_name = ownerName
      if (data.access === 'password_now' && data.password) {
        body.password = data.password
      }
    } else {
      body.access = 'none'
    }
  }

  return body
}

function createAccountBody(data: CreateSchema) {
  return accountFormBody(data, { includeAccess: true })
}

async function copyFirstAccessLink() {
  if (!lastFirstAccessUrl.value) return
  try {
    await navigator.clipboard.writeText(lastFirstAccessUrl.value)
    toast.add({ title: 'Link copiado', color: 'success' })
  } catch {
    toast.add({ title: 'Copie o link manualmente', color: 'warning' })
  }
}

async function onCreate(event: FormSubmitEvent<CreateSchema>) {
  creating.value = true
  try {
    const res = await $api<{ data: AdminAccount, first_access?: { url: string } | null }>('/admin/accounts', {
      method: 'POST',
      body: createAccountBody(event.data)
    })
    lastFirstAccessUrl.value = res.first_access?.url ?? null
    toast.add({ title: 'Conta criada', description: event.data.name, color: 'success' })
    if (lastFirstAccessUrl.value) {
      await copyFirstAccessLink()
    }
    if (!lastFirstAccessUrl.value) {
      createOpen.value = false
      resetCreateForm()
    }
    page.value = 1
    await load()
  } catch {
    toast.add({ title: 'Não foi possível criar a conta', color: 'error' })
  } finally {
    creating.value = false
  }
}

const editOpen = ref(false)
const editSchema = accountFormSchema
type EditSchema = AccountFormSchema
const editTarget = ref<AdminAccount | null>(null)
const editState = reactive<Partial<EditSchema>>({
  name: '',
  owner_name: '',
  login_email: '',
  access: 'password_now',
  password: '',
  phone: '',
  phone_whatsapp: false,
  state: '',
  city: ''
})
const editSaving = ref(false)
const editLoading = ref(false)
const editCanManageAccess = ref(false)
const editLastFirstAccessUrl = ref<string | null>(null)

function resetEditForm() {
  editState.name = ''
  editState.owner_name = ''
  editState.login_email = ''
  editState.access = 'password_now'
  editState.password = ''
  editState.phone = ''
  editState.phone_whatsapp = false
  editState.state = ''
  editState.city = ''
  editCanManageAccess.value = false
  editLastFirstAccessUrl.value = null
}

async function openEdit(account: AdminAccount) {
  editTarget.value = account
  editOpen.value = true
  editLoading.value = true
  editLastFirstAccessUrl.value = null
  try {
    const detail = await $api<{ data: AdminAccount }>(`/admin/accounts/${account.id}`)
    const row = detail.data
    const billing = row.platform_billing_contact ?? {}
    editState.name = row.name
    editState.phone = billing.phone ?? ''
    editState.phone_whatsapp = billing.phone_whatsapp ?? false
    editState.state = billing.state ?? ''
    editState.city = billing.city ?? ''
    editCanManageAccess.value = (row.members_count ?? 0) === 0
    if (editCanManageAccess.value) {
      const invite = row.platform_owner_invite
      editState.owner_name = invite?.name ?? ''
      editState.login_email = invite?.email ?? ''
      editState.access = invite ? 'first_access' : 'password_now'
      editState.password = ''
    }
  } catch {
    toast.add({ title: 'Não foi possível carregar a conta', color: 'error' })
    editOpen.value = false
  } finally {
    editLoading.value = false
  }
}

async function onEdit(event: FormSubmitEvent<EditSchema>) {
  if (!editTarget.value) return
  editSaving.value = true
  try {
    const res = await $api<{ data: AdminAccount, first_access?: { url: string } | null }>(
      `/admin/accounts/${editTarget.value.id}`,
      {
        method: 'PATCH',
        body: accountFormBody(event.data, { includeAccess: editCanManageAccess.value })
      }
    )
    editLastFirstAccessUrl.value = res.first_access?.url ?? null
    toast.add({ title: 'Conta atualizada', description: event.data.name, color: 'success' })
    if (editLastFirstAccessUrl.value) {
      try {
        await navigator.clipboard.writeText(editLastFirstAccessUrl.value)
        toast.add({ title: 'Link copiado', color: 'success' })
      } catch {
        // clipboard opcional
      }
    }
    if (!editLastFirstAccessUrl.value) {
      editOpen.value = false
      resetEditForm()
    }
    await load()
  } catch {
    toast.add({ title: 'Não foi possível atualizar a conta', color: 'error' })
  } finally {
    editSaving.value = false
  }
}

async function copyEditFirstAccessLink() {
  if (!editLastFirstAccessUrl.value) return
  try {
    await navigator.clipboard.writeText(editLastFirstAccessUrl.value)
    toast.add({ title: 'Link copiado', color: 'success' })
  } catch {
    toast.add({ title: 'Copie o link manualmente', color: 'warning' })
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

      <div class="ms-auto flex shrink-0 items-center gap-1.5">
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
        title="Não foi possível carregar as contas"
        :loading="isLoading"
        @retry="retry"
      />

      <template v-else>
        <UTable
          v-model:column-visibility="columnVisibility"
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
      </template>
    </div>
  </DataTablePanelList>

  <UModal
    v-model:open="createOpen"
    title="Nova conta"
  >
    <template #body>
      <UForm
        id="create-account-form"
        :schema="createSchema"
        :state="createState"
        class="space-y-4"
        @submit="onCreate"
      >
        <UFormField label="Nome" name="name" required>
          <UInput v-model="createState.name" placeholder="Nome da empresa" class="w-full" />
        </UFormField>

        <UFormField label="Administrador" name="owner_name">
          <UInput v-model="createState.owner_name" placeholder="Nome de quem vai acessar" class="w-full" />
        </UFormField>

        <UFormField label="E-mail de login" name="login_email">
          <UInput
            v-model="createState.login_email"
            type="email"
            placeholder="login@empresa.com.br"
            class="w-full"
          />
        </UFormField>

        <UFormField
          v-if="createState.login_email?.trim()"
          label="Acesso"
          name="access"
        >
          <URadioGroup
            v-model="createState.access"
            :items="[...accessItems]"
            class="w-full"
          />
        </UFormField>

        <UFormField
          v-if="createState.login_email?.trim() && createState.access === 'password_now'"
          label="Senha inicial"
          name="password"
          required
        >
          <UInput v-model="createState.password" type="password" class="w-full" />
        </UFormField>

        <UFormField label="Telefone" name="phone">
          <UInput v-model="createState.phone" placeholder="(00) 00000-0000" class="w-full" />
        </UFormField>

        <UFormField name="phone_whatsapp">
          <UCheckbox
            v-model="createState.phone_whatsapp"
            label="Este número é WhatsApp"
            :disabled="!createState.phone?.trim()"
          />
        </UFormField>

        <div class="grid gap-4 sm:grid-cols-2">
          <UFormField label="Estado" name="state">
            <USelect
              v-model="createState.state"
              :items="brazilUfItems"
              placeholder="UF"
              class="w-full"
            />
          </UFormField>

          <UFormField label="Município" name="city">
            <UInput v-model="createState.city" placeholder="Cidade" class="w-full" />
          </UFormField>
        </div>
      </UForm>
    </template>

    <template #footer="{ close }">
      <UButton
        v-if="lastFirstAccessUrl"
        label="Copiar link"
        color="neutral"
        variant="outline"
        @click="copyFirstAccessLink"
      />
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="() => { resetCreateForm(); close() }"
      />
      <UButton
        v-if="!lastFirstAccessUrl"
        label="Criar"
        type="submit"
        form="create-account-form"
        :loading="creating"
      />
      <UButton
        v-else
        label="Fechar"
        @click="() => { createOpen = false; resetCreateForm() }"
      />
    </template>
  </UModal>

  <UModal
    v-model:open="editOpen"
    title="Editar conta"
    :description="editTarget?.name"
  >
    <template #body>
      <div v-if="editLoading" class="flex justify-center py-8">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>
      <UForm
        v-else
        id="edit-account-form"
        :schema="editSchema"
        :state="editState"
        class="space-y-4"
        @submit="onEdit"
      >
        <UFormField label="Nome" name="name" required>
          <UInput v-model="editState.name" placeholder="Nome da empresa" class="w-full" />
        </UFormField>

        <template v-if="editCanManageAccess">
          <UFormField label="Administrador" name="owner_name">
            <UInput v-model="editState.owner_name" placeholder="Nome de quem vai acessar" class="w-full" />
          </UFormField>

          <UFormField label="E-mail de login" name="login_email">
            <UInput
              v-model="editState.login_email"
              type="email"
              placeholder="login@empresa.com.br"
              class="w-full"
            />
          </UFormField>

          <UFormField
            v-if="editState.login_email?.trim()"
            label="Acesso"
            name="access"
          >
            <URadioGroup
              v-model="editState.access"
              :items="[...accessItems]"
              class="w-full"
            />
          </UFormField>

          <UFormField
            v-if="editState.login_email?.trim() && editState.access === 'password_now'"
            label="Senha inicial"
            name="password"
            required
          >
            <UInput v-model="editState.password" type="password" class="w-full" />
          </UFormField>
        </template>

        <UFormField label="Telefone" name="phone">
          <UInput v-model="editState.phone" placeholder="(00) 00000-0000" class="w-full" />
        </UFormField>

        <UFormField name="phone_whatsapp">
          <UCheckbox
            v-model="editState.phone_whatsapp"
            label="Este número é WhatsApp"
            :disabled="!editState.phone?.trim()"
          />
        </UFormField>

        <div class="grid gap-4 sm:grid-cols-2">
          <UFormField label="Estado" name="state">
            <USelect
              v-model="editState.state"
              :items="brazilUfItems"
              placeholder="UF"
              class="w-full"
            />
          </UFormField>

          <UFormField label="Município" name="city">
            <UInput v-model="editState.city" placeholder="Cidade" class="w-full" />
          </UFormField>
        </div>
      </UForm>
    </template>

    <template #footer="{ close }">
      <UButton
        v-if="editLastFirstAccessUrl"
        label="Copiar link"
        color="neutral"
        variant="outline"
        @click="copyEditFirstAccessLink"
      />
      <UButton
        label="Cancelar"
        color="neutral"
        variant="outline"
        @click="() => { resetEditForm(); close() }"
      />
      <UButton
        v-if="!editLastFirstAccessUrl"
        label="Salvar"
        type="submit"
        form="edit-account-form"
        :loading="editSaving"
        :disabled="editLoading"
      />
      <UButton
        v-else
        label="Fechar"
        @click="() => { editOpen = false; resetEditForm() }"
      />
    </template>
  </UModal>

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
