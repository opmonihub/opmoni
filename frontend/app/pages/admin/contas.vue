<script setup lang="ts">
import * as z from 'zod'
import type { IssuedAccount } from '#shared/types/access'
import type { ExtendedPlatformAccount, AccountListResponse as AccountPage, DetailResponse } from '#shared/types/admin'
import type { TableColumn } from '@nuxt/ui'
import DataTableColumnMenu from '~/components/data-table/ColumnMenu.vue'
import { panelBodyClass, panelFooterClass, panelFooterCountClass, panelPaginationClass, panelTableUi } from '~/components/data-table/panel'
import { createLatestRequestRunner, readPaginatedMeta, pageWithinLastPage } from '~/utils/adminListFilters'
import { brazilUfItems } from '~/utils/brazilUfs'
import { apiFailure } from '#shared/utils/access'

const { user, accounts, switchAccount } = useAuth()
const switching = ref(false)
const { api } = useAccessApi()
const readAuthority = useAdminReadAuthority(invalidateAuthority)
const { q, status, page, filter, clearFilters } = useAccountFilters(load, readAuthority)
const result = ref<AccountPage | null>(null)
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const detailLoaded = ref(true)
const commercialOpen = ref(false)
const commercialAccount = ref<ExtendedPlatformAccount | null>(null)
const open = ref(false)
const mode = ref<'create' | 'edit' | 'reissue'>('create')
const target = ref<ExtendedPlatformAccount | null>(null)
const form = reactive({ name: '', owner_name: '', login_email: '', status: 'active', phone: '', phone_whatsapp: false, state: '', city: '' })
const contactSchema = { phone: z.string().max(20, 'Informe até 20 caracteres.'), phone_whatsapp: z.boolean(), state: z.union([z.literal(''), z.string().length(2)]), city: z.string().max(255) }
const selectedState = computed({ get: () => form.state || 'none', set: (value) => {
  form.state = value === 'none' ? '' : value
} })
const schema = computed(() => mode.value === 'edit' ? z.object({ ...contactSchema, name: z.string().trim().min(2, 'Informe ao menos 2 caracteres.').max(255), status: z.enum(['active', 'suspended']) }) : z.object({ ...contactSchema, ...(mode.value === 'create' ? { name: z.string().trim().min(2, 'Informe ao menos 2 caracteres.').max(255) } : {}), owner_name: z.string().trim().min(2, 'Informe ao menos 2 caracteres.').max(255), login_email: z.email('Informe um email válido.').max(254) }))
const fieldErrors = ref<Record<string, string[]>>({})
const operationError = ref('')
const link = ref<{
  url: string
  expires_at: string
} | null>(null)
const copied = ref(false)
const confirmed = ref(false)
const { showError, retry } = useRetryableLoad({
  refresh: load, error, loading, loadErrorTitle: 'Não foi possível carregar as contas'
})
const columns: TableColumn<ExtendedPlatformAccount>[] = [
  { accessorKey: 'id', header: 'ID' }, { accessorKey: 'name', header: 'Conta' },
  { accessorKey: 'status', header: 'Situação' }, { id: 'plan', header: 'Plano' },
  { accessorKey: 'members_count', header: 'Membros' }, { id: 'contact', header: 'Contato' }, { id: 'actions' }
]
const columnVisibility = ref<Record<string, boolean>>({})
const hideableColumns = [{ id: 'id', label: 'ID' }, { id: 'name', label: 'Conta' }, { id: 'status', label: 'Situação' }, { id: 'plan', label: 'Plano' }, { id: 'members_count', label: 'Membros' }, { id: 'contact', label: 'Contato' }]
function canEnter(account: ExtendedPlatformAccount) {
  return account.status === 'active' && accounts.value.some(member => member.id === account.id && member.status === 'active')
}
async function enterAccount(account: ExtendedPlatformAccount) {
  if (switching.value || !canEnter(account)) return
  switching.value = true
  try {
    await switchAccount(account.id)
  } catch (failure) {
    error.value = apiFailure(failure).message
  } finally {
    switching.value = false
  }
}
function accountActions(account: ExtendedPlatformAccount) {
  return [
    { label: account.status !== 'active' ? 'Conta suspensa' : canEnter(account) ? 'Acessar como membro' : 'Acesso exige vínculo de membro', icon: 'i-lucide-log-in', disabled: switching.value || !canEnter(account), onSelect: () => enterAccount(account) },
    { label: 'Editar conta', icon: 'i-lucide-pencil', onSelect: () => begin('edit', account) },
    { label: 'Dados comerciais', icon: 'i-lucide-receipt', onSelect: () => {
      commercialAccount.value = account
      commercialOpen.value = true
    } },
    ...(account.members_count === 0 ? [{ label: 'Reemitir primeiro acesso', icon: 'i-lucide-link', disabled: account.status !== 'active', onSelect: () => begin('reissue', account) }] : [])
  ]
}
const runList = createLatestRequestRunner()
const runDetail = createLatestRequestRunner()
let operationId = 0
async function load() {
  if (!user.value?.is_super_admin)
    return
  loading.value = true
  error.value = ''
  return runList(signal => api<AccountPage>('/admin/accounts', { signal, query: { q: q.value, status: status.value === 'all' ? undefined : status.value, page: page.value } }), {
    onSuccess: (data) => {
      const nextPage = pageWithinLastPage(page.value, readPaginatedMeta(data).last_page)
      if (nextPage !== page.value) {
        page.value = nextPage
        return
      }
      result.value = data
    },
    onError: (failure) => {
      error.value = apiFailure(failure).message
    },
    onSettled: () => {
      loading.value = false
    }
  })
}

async function begin(action: typeof mode.value, account?: ExtendedPlatformAccount) {
  if (busy.value)
    return
  const version = ++operationId
  detailLoaded.value = action !== 'edit'
  mode.value = action
  target.value = account || null
  form.name = account?.name || ''
  form.status = account?.status || 'active'
  Object.assign(form, { phone: account?.phone ?? '', phone_whatsapp: account?.phone_whatsapp ?? false, state: account?.state ?? '', city: account?.city ?? '' })
  form.owner_name = account?.pending_owner?.name || ''
  form.login_email = account?.pending_owner?.email || ''
  fieldErrors.value = {}
  operationError.value = ''
  link.value = null
  copied.value = false
  confirmed.value = false
  open.value = true
  if (action === 'edit' && account) {
    busy.value = true
    await runDetail(signal => api<DetailResponse<ExtendedPlatformAccount>>(`/admin/accounts/${account.id}`, { signal }), {
      onSuccess: ({ data }) => {
        if (version !== operationId || !open.value)
          return
        detailLoaded.value = true
        target.value = data
        Object.assign(form, { name: data.name, status: data.status, phone: data.phone ?? '', phone_whatsapp: data.phone_whatsapp, state: data.state ?? '', city: data.city ?? '' })
      },
      onError: (error) => {
        if (version === operationId)
          operationError.value = apiFailure(error).message
      },
      onSettled: () => {
        if (version === operationId)
          busy.value = false
      }
    })
  }
}
async function save() {
  if (busy.value || !detailLoaded.value)
    return
  const version = operationId
  const authority = readAuthority.value
  if (mode.value === 'edit' && form.status !== target.value?.status && !confirmed.value) {
    operationError.value = 'Confirme a alteração de situação da conta.'
    return
  }
  busy.value = true
  operationError.value = ''
  fieldErrors.value = {}
  try {
    if (mode.value === 'edit')
      await api(`/admin/accounts/${target.value!.id}`, { method: 'PATCH', body: { name: form.name, status: form.status, phone: form.phone, phone_whatsapp: !!form.phone && form.phone_whatsapp, state: form.state, city: form.city } })
    else {
      const issued = await api<IssuedAccount | Pick<IssuedAccount, 'first_access'>>(mode.value === 'create' ? '/admin/accounts' : `/admin/accounts/${target.value!.id}/first-access`, { method: 'POST', body: { ...(mode.value === 'create' ? { name: form.name, phone: form.phone, phone_whatsapp: !!form.phone && form.phone_whatsapp, state: form.state, city: form.city } : {}), owner_name: form.owner_name, login_email: form.login_email } })
      if (version !== operationId)
        return
      link.value = issued.first_access
    }
    if (version !== operationId)
      return
    await load()
    if (version !== operationId)
      return
    if (!link.value)
      open.value = false
  } catch (e) {
    if (version !== operationId)
      return
    const failure = apiFailure(e)
    operationError.value = failure.message
    fieldErrors.value = failure.errors || {}
  } finally {
    if (authority === readAuthority.value) busy.value = false
  }
}
async function copy() {
  if (!link.value)
    return
  const current = link.value
  try {
    await navigator.clipboard.writeText(current.url)
    if (link.value === current)
      copied.value = true
  } catch {
    if (link.value !== current)
      return
    operationError.value = 'Não foi possível copiar. Selecione e copie o link abaixo.'
  }
}
watch(open, (value) => {
  if (!value) {
    operationId++
    runDetail.invalidate()
    if (!detailLoaded.value) busy.value = false
    link.value = null
    copied.value = false
  }
})
onMounted(load)
onBeforeUnmount(() => {
  link.value = null
  runList.invalidate()
  runDetail.invalidate()
  operationId++
})
function invalidateAuthority() {
  busy.value = false
  runList.invalidate()
  runDetail.invalidate()
  operationId++
  result.value = null
  error.value = ''
  loading.value = false
  open.value = false
  commercialOpen.value = false
  commercialAccount.value = null
  target.value = null
  link.value = null
  fieldErrors.value = {}
  operationError.value = ''
  Object.assign(form, { name: '', owner_name: '', login_email: '', status: 'active', phone: '', phone_whatsapp: false, state: '', city: '' })
}
</script>

<template>
  <DataTablePanelList title="Contas da plataforma" description="Escritórios e acesso de seus responsáveis.">
    <template #toolbar>
      <form class="flex flex-wrap items-center gap-3" @submit.prevent="filter">
        <UInput v-model="q" placeholder="Buscar nome ou ID" aria-label="Buscar nome ou ID" />
        <USelect
          v-model="status"
          :items="[{ label: 'Todas as situações', value: 'all' }, { label: 'Ativas', value: 'active' }, { label: 'Suspensas', value: 'suspended' }]"
          aria-label="Situação"
          name="status"
        />
        <UButton
          type="submit"
          label="Buscar"
          color="neutral"
          variant="outline"
        />
        <UButton
          v-if="q || status !== 'all'"
          label="Limpar filtros"
          color="neutral"
          variant="ghost"
          @click="clearFilters"
        />
      </form>
      <DataTableColumnMenu v-model="columnVisibility" :columns="hideableColumns" />
    </template>
    <template #action>
      <UButton label="Criar escritório" @click="begin('create')" />
    </template>
    <div :class="panelBodyClass">
      <ErrorRetryAlert
        v-if="showError"
        :title="result ? 'Não foi possível atualizar as contas. Os resultados anteriores foram mantidos.' : error"
        :loading="loading"
        @retry="retry"
      />
      <template v-if="result || !showError">
        <UTable
          v-model:column-visibility="columnVisibility"
          :data="result?.data ?? []"
          :columns="columns"
          :loading="loading"
          :ui="panelTableUi"
        >
          <template #name-cell="{ row }">
            <p class="font-medium text-highlighted">
              {{ row.original.name }}
            </p>
            <p v-if="row.original.pending_owner" class="text-sm text-muted">
              Responsável: {{ row.original.pending_owner.name }} · {{ row.original.pending_owner.email }}
            </p>
          </template>
          <template #status-cell="{ row }">
            <UBadge :color="row.original.status === 'active' ? 'success' : 'error'" variant="subtle">
              {{ row.original.status === 'active' ? 'Ativa' : 'Suspensa' }}
            </UBadge>
          </template>
          <template #plan-cell="{ row }">
            {{ row.original.subscription?.plan?.name ?? 'Sem assinatura' }}
          </template>
          <template #contact-cell="{ row }">
            <p>{{ row.original.phone ?? '—' }}{{ row.original.phone_whatsapp ? ' · WhatsApp' : '' }}</p><p class="text-sm text-muted">
              {{ [row.original.city, row.original.state].filter(Boolean).join(' / ') }}
            </p>
          </template>
          <template #actions-cell="{ row }">
            <DataTableRowActionsMenu :items="accountActions(row.original)" :label="`Ações de ${row.original.name}`" flush />
          </template>
          <template #empty>
            <DataTablePanelTableEmpty icon="i-lucide-building-2" :label="q || status !== 'all' ? 'Nenhum escritório corresponde aos filtros.' : 'Nenhum escritório cadastrado.'" />
          </template>
        </UTable>
        <div v-if="result" :class="panelFooterClass">
          <div :class="panelFooterCountClass">
            {{ result.data.length }} de {{ result.meta.total }} conta(s)
          </div><div :class="panelPaginationClass">
            <UPagination v-model:page="page" :items-per-page="result.meta.per_page" :total="result.meta.total" />
          </div>
        </div>
      </template>
    </div>
  </DataTablePanelList>
  <div>
    <PlatformAccountCommercialModal v-model:open="commercialOpen" :account="commercialAccount" @saved="load" />
    <UModal
      v-model:open="open"
      :dismissible="!busy"
      :close="!busy"
      :title="mode === 'create' ? 'Criar escritório' : mode === 'edit' ? 'Editar conta' : 'Reemitir primeiro acesso'"
      description="Administre os dados e o acesso do escritório."
    >
      <template #body>
        <div v-if="link" class="space-y-4">
          <UAlert title="Link de primeiro acesso emitido" description="Copie agora e envie ao responsável. Ao fechar, o link não será exibido novamente." /><p class="text-sm text-muted">
            Válido até {{ new Date(link.expires_at).toLocaleString('pt-BR') }}
          </p><input
            :value="link.url"
            readonly
            aria-label="Link de primeiro acesso"
            class="w-full border border-default rounded p-2"
            @focus="($event.target as HTMLInputElement).select()"
          ><UButton :label="copied ? 'Copiado' : 'Copiar link'" @click="copy" /><UButton
            label="Fechar"
            color="neutral"
            variant="outline"
            @click="open = false"
          /><UAlert v-if="operationError" color="error" :title="operationError" />
        </div><UForm
          v-else
          :schema="schema"
          :state="form"
          class="space-y-4"
          @submit="save"
        >
          <UFormField
            v-if="mode !== 'reissue'"
            label="Nome do escritório"
            name="name"
            :error="fieldErrors.name?.[0]"
          >
            <UInput
              v-model="form.name"
              required
              maxlength="255"
              class="w-full"
            />
          </UFormField><template v-if="mode !== 'edit'">
            <UFormField label="Nome do responsável" name="owner_name" :error="fieldErrors.owner_name?.[0]">
              <UInput
                v-model="form.owner_name"
                required
                maxlength="255"
                class="w-full"
              />
            </UFormField><UFormField label="Email do responsável" name="login_email" :error="fieldErrors.login_email?.[0]">
              <UInput
                v-model="form.login_email"
                type="email"
                required
                maxlength="254"
                class="w-full"
              />
            </UFormField>
          </template><template v-else>
            <UFormField label="Situação" name="status">
              <USelect v-model="form.status" :items="[{ label: 'Ativa', value: 'active' }, { label: 'Suspensa', value: 'suspended' }]" class="w-full" />
            </UFormField><UCheckbox v-if="form.status !== target?.status" v-model="confirmed" :label="form.status === 'suspended' ? 'Confirmo suspender esta conta e bloquear seus acessos.' : 'Confirmo reativar os acessos desta conta.'" />
          </template>
          <template v-if="mode !== 'reissue'">
            <UFormField label="Telefone" name="phone" :error="fieldErrors.phone?.[0]">
              <UInput
                v-model="form.phone"
                type="tel"
                maxlength="20"
                class="w-full"
              />
            </UFormField>
            <UFormField name="phone_whatsapp" :error="fieldErrors.phone_whatsapp?.[0]">
              <UCheckbox v-model="form.phone_whatsapp" label="Este telefone recebe WhatsApp" :disabled="!form.phone" />
            </UFormField>
            <UFormField label="UF" name="state" :error="fieldErrors.state?.[0]">
              <USelect v-model="selectedState" :items="[{ label: 'Não informado', value: 'none' }, ...brazilUfItems]" class="w-full" />
            </UFormField>
            <UFormField label="Município" name="city" :error="fieldErrors.city?.[0]">
              <UInput v-model="form.city" maxlength="255" class="w-full" />
            </UFormField>
          </template><UAlert v-if="operationError" color="error" :title="operationError" /><UButton
            type="submit"
            label="Salvar"
            :loading="busy"
            :disabled="busy || !detailLoaded"
          />
        </UForm>
      </template>
    </UModal>
  </div>
</template>
