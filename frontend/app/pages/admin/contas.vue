<script setup lang="ts">
import * as z from 'zod'
import type { AccountPage, PlatformAccount, IssuedAccount } from '#shared/types/access'
import { apiFailure } from '#shared/utils/access'

const { user } = useAuth()
const { api } = useAccessApi()
const { q, status, page, filter, clearFilters } = useAccountFilters(load)
const result = ref<AccountPage | null>(null)
const loading = ref(false)
const error = ref('')
const busy = ref(false)
const commercialOpen = ref(false)
const commercialAccount = ref<PlatformAccount | null>(null)
const open = ref(false)
const mode = ref<'create' | 'edit' | 'reissue'>('create')
const target = ref<PlatformAccount | null>(null)
const form = reactive({ name: '', owner_name: '', login_email: '', status: 'active' })
const schema = computed(() => mode.value === 'edit' ? z.object({ name: z.string().trim().min(2, 'Informe ao menos 2 caracteres.').max(255), status: z.enum(['active', 'suspended']) }) : z.object({ ...(mode.value === 'create' ? { name: z.string().trim().min(2, 'Informe ao menos 2 caracteres.').max(255) } : {}), owner_name: z.string().trim().min(2, 'Informe ao menos 2 caracteres.').max(255), login_email: z.email('Informe um email válido.').max(254) }))
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
let requestId = 0
async function load() {
  if (!user.value?.is_super_admin)
    return
  const version = ++requestId
  loading.value = true
  error.value = ''
  try {
    const data = await api<AccountPage>('/admin/accounts', { query: { q: q.value, status: status.value === 'all' ? undefined : status.value, page: page.value } })
    if (version === requestId)
      result.value = data
  } catch (e) {
    if (version === requestId) {
      result.value = null
      error.value = apiFailure(e).message
    }
  } finally {
    if (version === requestId)
      loading.value = false
  }
}
function begin(action: typeof mode.value, account?: PlatformAccount) {
  mode.value = action
  target.value = account || null
  form.name = account?.name || ''
  form.status = account?.status || 'active'
  form.owner_name = account?.pending_owner?.name || ''
  form.login_email = account?.pending_owner?.email || ''
  fieldErrors.value = {}
  operationError.value = ''
  link.value = null
  copied.value = false
  confirmed.value = false
  open.value = true
}
async function save() {
  if (busy.value)
    return
  if (mode.value === 'edit' && form.status !== target.value?.status && !confirmed.value) {
    operationError.value = 'Confirme a alteração de situação da conta.'
    return
  }
  busy.value = true
  operationError.value = ''
  fieldErrors.value = {}
  try {
    if (mode.value === 'edit')
      await api(`/admin/accounts/${target.value!.id}`, { method: 'PATCH', body: { name: form.name, status: form.status } })
    else {
      const issued = await api<IssuedAccount>(mode.value === 'create' ? '/admin/accounts' : `/admin/accounts/${target.value!.id}/first-access`, { method: 'POST', body: { ...(mode.value === 'create' ? { name: form.name } : {}), owner_name: form.owner_name, login_email: form.login_email } })
      link.value = issued.first_access
    }
    await load()
    if (!link.value)
      open.value = false
  } catch (e) {
    const failure = apiFailure(e)
    operationError.value = failure.message
    fieldErrors.value = failure.errors || {}
  } finally {
    busy.value = false
  }
}
async function copy() {
  if (!link.value)
    return
  try {
    await navigator.clipboard.writeText(link.value.url)
    copied.value = true
  } catch {
    operationError.value = 'Não foi possível copiar. Selecione e copie o link abaixo.'
  }
}
watch(open, (value) => {
  if (!value) {
    link.value = null
    copied.value = false
  }
})
onMounted(load)
onBeforeUnmount(() => {
  link.value = null
  requestId++
})
</script>

<template>
  <UCard v-if="!user?.is_super_admin">
    <h1 class="text-2xl font-semibold">
      Acesso negado
    </h1><p class="mt-3 text-muted">
      Somente o administrador da plataforma pode administrar contas.
    </p><UButton to="/" label="Voltar" class="mt-4" />
  </UCard>
  <div v-else class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-3xl font-semibold">
          Contas da plataforma
        </h1><p class="text-muted mt-2">
          Escritórios e acesso de seus responsáveis.
        </p>
      </div><UButton label="Criar escritório" @click="begin('create')" />
    </div>
    <form class="flex flex-wrap gap-3" @submit.prevent="filter">
      <UInput
        v-model="q"
        placeholder="Buscar nome ou ID"
        aria-label="Buscar nome ou ID"
        class="min-w-64"
      /><USelect
        v-model="status"
        :items="[{ label: 'Todas as situações', value: 'all' }, { label: 'Ativas', value: 'active' }, { label: 'Suspensas', value: 'suspended' }]"
        aria-label="Situação"
        name="status"
      /><UButton
        type="submit"
        label="Buscar"
        color="neutral"
        variant="outline"
      />
    </form>
    <p v-if="loading" role="status">
      Carregando contas…
    </p>
    <ErrorRetryAlert
      v-else-if="showError"
      :title="error"
      :loading="loading"
      @retry="retry"
    />
    <UCard v-else-if="result && !result.data.length">
      <p>{{ q || status !== 'all' ? 'Nenhum escritório corresponde aos filtros.' : 'Nenhum escritório cadastrado.' }}</p><UButton
        v-if="q || status !== 'all'"
        label="Limpar filtros"
        variant="outline"
        class="mt-4"
        @click="clearFilters"
      />
    </UCard>
    <div v-else-if="result" class="space-y-3">
      <UCard v-for="account in result.data" :key="account.id">
        <div class="flex flex-wrap items-start gap-4">
          <div class="flex-1">
            <h2 class="font-semibold">
              {{ account.name }} <span class="text-muted text-sm">#{{ account.id }}</span>
            </h2><p class="text-sm text-muted mt-1">
              {{ account.status === 'active' ? 'Ativa' : 'Suspensa' }} · {{ account.members_count }} membros
            </p><p v-if="account.pending_owner" class="text-sm mt-2">
              Responsável: {{ account.pending_owner.name }} · {{ account.pending_owner.email }}<br>Link emitido até {{ new Date(account.pending_owner.expires_at).toLocaleString('pt-BR') }}
            </p>
          </div><UButton
            label="Editar conta"
            color="neutral"
            variant="outline"
            @click="begin('edit', account)"
          /><UButton
            label="Dados comerciais"
            color="neutral"
            variant="outline"
            @click="commercialAccount = account; commercialOpen = true"
          /><UButton
            v-if="account.members_count === 0"
            label="Reemitir primeiro acesso"
            variant="outline"
            :disabled="account.status !== 'active'"
            @click="begin('reissue', account)"
          />
        </div>
      </UCard><UPagination
        v-if="result.meta"
        v-model:page="page"
        :items-per-page="15"
        :total="result.meta.total"
      />
    </div>
    <PlatformAccountCommercialModal v-model:open="commercialOpen" :account="commercialAccount" />
    <UModal
      v-model:open="open"
      :dismissible="!busy"
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
          </template><UAlert v-if="operationError" color="error" :title="operationError" /><UButton
            type="submit"
            label="Salvar"
            :loading="busy"
            :disabled="busy"
          />
        </UForm>
      </template>
    </UModal>
  </div>
</template>
