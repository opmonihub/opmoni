<script setup lang="ts">
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { companyListBodyClass, companyListFooterClass, companyListTableUi } from '~/components/office-erp/companyList'
import type { OfficeErpCommandResult } from '#shared/types/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'

defineProps<{ embedded?: boolean }>()
interface MemberRow {
  kind: 'membership' | 'invitation'
  id: number
  user?: { id: number, name: string, email: string }
  name?: string
  email?: string
  role: 'owner' | 'manager' | 'operator' | 'viewer'
  status?: string
  action_permissions?: string[] | null
  effective_permissions?: string[]
  permissions_mode?: 'defaults' | 'explicit'
  permissions?: string[]
  establishment_scope: 'all' | 'selected'
  establishment_ids: number[]
  expires_at?: string
  access_auth_revision?: number
}
interface MemberOptions {
  permissions: Array<{ permission: string, label: string, write: boolean }>
  roles: Array<{ role: 'owner' | 'manager' | 'operator' | 'viewer', default_permissions: string[] }>
  establishments: Array<{ id: number, name: string, cnpj: string, status: string }>
}
interface AccessDelivery { id: number, state: 'prepared' | 'sending' | 'transport_accepted' | 'failed' | 'unknown' | 'expired', expires_at: string, authority_invalidated_at: string | null }
interface MemberCommandData { company_id?: number, delivery?: { id: number, state: AccessDelivery['state'] } }
interface MemberResponse { data: MemberRow[], meta: { current_page: number, last_page: number, per_page: 25 | 50 | 100, total: number } }
const route = useRoute()
const companyId = computed(() => typeof route.params.companyId === 'string' && /^[1-9]\d{0,14}$/.test(route.params.companyId) ? route.params.companyId : '')
const nestedMemberRoute = computed(() => !!companyId.value && route.path.startsWith(`/gestao-erp/assinaturas/empresas/${companyId.value}/membros/`))
const { api } = useAccessApi()
const command = useOfficeErpCommand()
const context = useOfficeErpContext()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const query = ref('')
const filter = ref<'all' | 'active' | 'pending'>('all')
const page = ref(1)
const perPage = ref<25 | 50 | 100>(25)
const rows = ref<MemberRow[]>([])
const options = ref<MemberOptions | null>(null)
const total = ref(0)
const loading = ref(false)
const optionsLoading = ref(false)
const optionsError = ref('')
const error = ref('')
const notice = ref('')
const delivery = ref<AccessDelivery | null>(null)
const deliveryLoading = ref(false)
const deliveryError = ref('')
const empty = ref(false)
const editorOpen = ref(false)
const destructiveOpen = ref(false)
const restoreEditorFocus = useModalFocusReturn(editorOpen)
const restoreDestructiveFocus = useModalFocusReturn(destructiveOpen)
const destructiveTarget = ref<MemberRow | null>(null)
const destructiveAction = ref<'remove' | 'revoke'>('remove')
const editorMode = ref<'invite' | 'edit'>('invite')
const target = ref<MemberRow | null>(null)
const submitting = ref(false)
const name = ref('')
const email = ref('')
const role = ref<'manager' | 'operator' | 'viewer'>('operator')
const permissionMode = ref<'defaults' | 'explicit'>('defaults')
const permissions = ref<string[]>([])
const scope = ref<'all' | 'selected'>('all')
const establishmentIds = ref<number[]>([])
let requestGeneration = 0
let mutationGeneration = 0
let mounted = false
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null, companyId.value, route.path], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const visibleRows = computed(() => rows.value)
const columns: TableColumn<MemberRow>[] = [
  { id: 'user', header: 'Usuário', meta: { class: { th: 'w-[60%] md:w-[40%]', td: 'min-w-0' } } },
  { id: 'role', header: 'Papel' },
  { id: 'status', header: 'Situação' },
  { id: 'actions', header: '', meta: { class: { th: 'w-[40%] md:w-[30%]', td: 'text-right' } } }
]
const roles = computed(() => (options.value?.roles ?? []).filter(option => ['manager', 'operator', 'viewer'].includes(option.role)))
const selectablePermissions = computed(() => (options.value?.permissions ?? []).filter(item => role.value !== 'viewer' || !item.write))

function validMeta(value: MemberResponse['meta']) {
  return !!value && Number.isSafeInteger(value.total) && value.total >= 0 && Number.isSafeInteger(value.current_page) && value.current_page > 0 && Number.isSafeInteger(value.last_page) && value.last_page > 0 && [25, 50, 100].includes(value.per_page)
}
function memberName(row: MemberRow) {
  return row.user?.name ?? row.name ?? 'Usuário'
}
function memberEmail(row: MemberRow) {
  return row.user?.email ?? row.email ?? ''
}
function roleLabel(value: string) {
  if (value === 'owner') return 'Proprietário'
  if (value === 'manager') return 'Gerente'
  if (value === 'operator') return 'Operador'
  if (value === 'viewer') return 'Leitor'
  return value
}
function isCurrentUser(row: MemberRow) {
  return row.kind === 'membership' && row.user?.id === user.value?.id
}
function memberActions(member: MemberRow): DropdownMenuItem[] {
  if (member.kind === 'invitation') return member.status === 'pending' ? [{ label: 'Revogar convite', icon: 'i-lucide-mail-x', color: 'error', disabled: submitting.value, onSelect: () => memberAction(member, 'revoke') }] : []
  return [
    { label: 'Recuperar acesso', icon: 'i-lucide-key-round', to: `/gestao-erp/assinaturas/empresas/${companyId.value}/membros/${member.id}/recuperar` },
    ...(member.role !== 'owner' ? [{ label: 'Remover acesso', icon: 'i-lucide-user-minus', color: 'error' as const, disabled: isCurrentUser(member) || submitting.value, onSelect: () => memberAction(member, 'remove') }] : [])
  ]
}
function date(value?: string) {
  if (!value) return '—'
  const parsed = new Date(value)
  return Number.isFinite(parsed.getTime()) ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(parsed) : '—'
}
async function loadOptions() {
  if (!companyId.value || nestedMemberRoute.value || options.value || optionsLoading.value) return
  const read = reads.begin('loadMemberOptions')
  const identity = identityStamp.value
  optionsLoading.value = true
  optionsError.value = ''
  try {
    await context.refresh()
    if (!read.current() || identity !== identityStamp.value) return
    const token = context.capture()
    const response = await api<{ data: MemberOptions }>(`/erp-commercial/companies/${companyId.value}/member-options`, { signal: read.signal })
    if (!read.current() || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data?.permissions) || !Array.isArray(response.data.roles) || !Array.isArray(response.data.establishments)) throw new Error('Os catálogos de permissões e estabelecimentos estão incompletos.')
    options.value = response.data
  } catch (exception) {
    if (read.current() && identity === identityStamp.value) optionsError.value = apiErrorMessage(exception) ?? 'Não foi possível carregar os catálogos da empresa.'
  } finally {
    if (read.current() && identity === identityStamp.value) optionsLoading.value = false
  }
}
async function loadMembers() {
  if (nestedMemberRoute.value) return
  if (!companyId.value) {
    error.value = 'Identificador de empresa inválido.'
    return
  }
  const read = reads.begin('loadMembers')
  const request = ++requestGeneration
  const identity = identityStamp.value
  loading.value = true
  error.value = ''
  empty.value = false
  try {
    await context.refresh()
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_maintain) {
      rows.value = []
      total.value = 0
      error.value = 'A conta selecionada não tem permissão para administrar acessos comerciais.'
      return
    }
    const token = context.capture()
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value }
    if (query.value.trim()) params.search = query.value.trim()
    if (filter.value !== 'all') params.status = filter.value
    const response = await api<MemberResponse>(`/erp-commercial/companies/${companyId.value}/members`, { signal: read.signal, params })
    if (!read.current() || request !== requestGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !validMeta(response.meta)) throw new Error('A resposta de usuários e convites está incompleta.')
    rows.value = response.data
    total.value = response.meta.total
    page.value = response.meta.current_page
    empty.value = response.meta.total === 0
  } catch (exception) {
    if (read.current() && request === requestGeneration && identity === identityStamp.value) error.value = apiErrorMessage(exception) ?? 'Não foi possível consultar usuários e convites.'
  } finally { if (read.current() && request === requestGeneration && identity === identityStamp.value) loading.value = false }
}
watch([query, filter, page, perPage, identityStamp], () => {
  void loadMembers()
}, { immediate: true })
watch([query, filter, perPage], () => {
  page.value = 1
}, { flush: 'sync' })
watch(role, (selectedRole) => {
  if (selectedRole !== 'viewer' || !options.value) return
  const readOnly = new Set(options.value.permissions.filter(item => !item.write).map(item => item.permission))
  permissions.value = permissions.value.filter(permission => readOnly.has(permission))
})
watch(identityStamp, () => {
  requestGeneration++
  mutationGeneration++
  submitting.value = false
  rows.value = []
  total.value = 0
  options.value = null
  optionsLoading.value = false
  target.value = null
  editorOpen.value = false
  name.value = ''
  email.value = ''
  role.value = 'operator'
  permissionMode.value = 'defaults'
  permissions.value = []
  scope.value = 'all'
  establishmentIds.value = []
  destructiveOpen.value = false
  destructiveTarget.value = null
  destructiveAction.value = 'remove'
  delivery.value = null
  deliveryLoading.value = false
  deliveryError.value = ''
  notice.value = ''
  empty.value = false
  error.value = ''
  void loadOptions()
}, { flush: 'sync' })
onMounted(() => {
  mounted = true
  void loadOptions()
})
onBeforeUnmount(() => {
  mounted = false
  mutationGeneration++
  submitting.value = false
  name.value = ''
  email.value = ''
  permissions.value = []
  establishmentIds.value = []
  target.value = null
  destructiveOpen.value = false
  destructiveTarget.value = null
  delivery.value = null
  deliveryLoading.value = false
  deliveryError.value = ''
  notice.value = ''
})

function resetEditor() {
  name.value = ''
  email.value = ''
  role.value = 'operator'
  permissionMode.value = 'defaults'
  permissions.value = []
  scope.value = 'all'
  establishmentIds.value = []
  error.value = ''
}
async function openInvite() {
  const targetCompanyId = companyId.value
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  resetEditor()
  editorMode.value = 'invite'
  target.value = null
  await loadOptions()
  if (mounted && mutation === mutationGeneration && identity === identityStamp.value && companyId.value === targetCompanyId && options.value) editorOpen.value = true
}
async function openEdit(row: MemberRow) {
  const targetCompanyId = companyId.value
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  resetEditor()
  await loadOptions()
  if (!mounted || mutation !== mutationGeneration || identity !== identityStamp.value || companyId.value !== targetCompanyId || !options.value) return
  target.value = row
  editorMode.value = 'edit'
  name.value = memberName(row)
  email.value = memberEmail(row)
  role.value = ['manager', 'operator', 'viewer'].includes(row.role) ? row.role as typeof role.value : 'viewer'
  permissionMode.value = row.kind === 'invitation' ? row.permissions_mode ?? 'defaults' : !row.action_permissions?.length ? 'defaults' : 'explicit'
  permissions.value = row.kind === 'invitation' ? [...(row.permissions ?? [])] : [...(row.action_permissions ?? [])]
  scope.value = row.establishment_scope
  establishmentIds.value = [...row.establishment_ids]
  editorOpen.value = true
}
function bodyForEditor() {
  if (scope.value === 'selected' && establishmentIds.value.length === 0) return null
  if (permissionMode.value === 'explicit' && permissions.value.length === 0) return null
  return editorMode.value === 'invite'
    ? { name: name.value.trim(), email: email.value.trim(), role: role.value, permissions_mode: permissionMode.value, permissions: permissionMode.value === 'explicit' ? permissions.value : [], establishment_scope: scope.value, establishment_ids: scope.value === 'selected' ? establishmentIds.value : [] }
    : target.value?.kind === 'membership'
      ? { role: role.value, permissions_mode: permissionMode.value, action_permissions: permissionMode.value === 'defaults' ? null : permissions.value, establishment_scope: scope.value, establishment_ids: scope.value === 'selected' ? establishmentIds.value : [] }
      : null
}
async function saveEditor() {
  const payload = bodyForEditor()
  if (!payload) {
    error.value = 'Selecione ao menos uma permissão explícita e uma filial quando usar escopo selecionado.'
    return
  }
  if (editorMode.value === 'invite' && (name.value.trim().length < 2 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim()))) {
    error.value = 'Informe nome e e-mail válidos para o convite.'
    return
  }
  if (!companyId.value || submitting.value || command.pending.value) return
  const targetCompanyId = companyId.value
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  submitting.value = true
  error.value = ''
  try {
    const isMembership = editorMode.value === 'edit' && target.value?.kind === 'membership'
    const path = isMembership
      ? `/erp-commercial/companies/${companyId.value}/members/${target.value!.id}`
      : `/erp-commercial/companies/${companyId.value}/member-invitations`
    const action = isMembership ? 'member.update' : 'member.invitation.issue'
    const method = isMembership ? 'PATCH' : 'POST'
    const result = await command.execute(action, path, payload, method) as OfficeErpCommandResult<unknown>
    if (mutation !== mutationGeneration || identity !== identityStamp.value || companyId.value !== targetCompanyId) return
    if (result.status === 'confirmed') {
      const data = result.data as MemberCommandData | null
      const matchesCompany = data?.company_id === Number(targetCompanyId)
      const deliveryReceipt = matchesCompany ? data?.delivery : null
      if (deliveryReceipt) delivery.value = { ...deliveryReceipt, expires_at: '', authority_invalidated_at: null }
      editorOpen.value = false
      notice.value = !matchesCompany
        ? 'A operação foi confirmada. Atualize os dados desta empresa para conferir o estado atual.'
        : editorMode.value === 'edit' ? 'Acesso atualizado.' : 'Convite preparado para entrega. O link não fica visível nesta carteira.'
      await loadMembers()
    } else if (result.status === 'unknown') notice.value = `O resultado ainda não foi confirmado. Recupere a operação ${result.key}.`
    else if (result.status === 'failed') error.value = result.failure.message
    else error.value = 'O contexto da empresa mudou. A operação não foi repetida.'
  } finally {
    if (mutation === mutationGeneration) submitting.value = false
  }
}
async function reissueInvitation(row: MemberRow) {
  if (row.kind !== 'invitation' || row.status !== 'pending' || !companyId.value || submitting.value || command.pending.value) return
  const targetCompanyId = companyId.value
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  submitting.value = true
  error.value = ''
  try {
    const result = await command.execute<{ company_id: number, delivery?: { id: number, state: AccessDelivery['state'] } }>('member.invitation.reissue', `/erp-commercial/companies/${targetCompanyId}/member-invitations/${row.id}/reissue`, {}, 'POST') as OfficeErpCommandResult<{ company_id: number, delivery?: { id: number, state: AccessDelivery['state'] } }>
    if (mutation !== mutationGeneration || identity !== identityStamp.value || companyId.value !== targetCompanyId) return
    if (result.status === 'confirmed') {
      const matchesCompany = result.data.company_id === Number(targetCompanyId)
      const receipt = matchesCompany ? result.data.delivery : null
      if (receipt) delivery.value = { ...receipt, expires_at: '', authority_invalidated_at: null }
      notice.value = matchesCompany ? 'Novo pedido de entrega preparado com as permissões já associadas ao convite.' : 'A operação foi confirmada. Atualize os dados desta empresa para conferir o estado atual.'
      await loadMembers()
    } else if (result.status === 'unknown') notice.value = `O reenvio ainda não foi confirmado. Recupere a operação ${result.key}.`
    else if (result.status === 'failed') error.value = result.failure.message
    else error.value = 'O contexto da empresa mudou. O reenvio não foi repetido.'
  } finally {
    if (mutation === mutationGeneration) submitting.value = false
  }
}
function memberAction(row: MemberRow, action: 'remove' | 'revoke') {
  destructiveTarget.value = row
  destructiveAction.value = action
  destructiveOpen.value = true
}
async function confirmMemberAction() {
  const row = destructiveTarget.value
  const action = destructiveAction.value
  if (!row) return
  if (!companyId.value || submitting.value || command.pending.value) return
  const targetCompanyId = companyId.value
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  submitting.value = true
  error.value = ''
  try {
    const path = action === 'remove'
      ? `/erp-commercial/companies/${targetCompanyId}/members/${row.id}`
      : `/erp-commercial/companies/${targetCompanyId}/member-invitations/${row.id}/revoke`
    const result = await command.execute(action === 'remove' ? 'member.remove' : 'member.invitation.revoke', path, action === 'remove' ? { confirmed: true } : {}, action === 'remove' ? 'DELETE' : 'POST') as OfficeErpCommandResult<unknown>
    if (mutation !== mutationGeneration || identity !== identityStamp.value || companyId.value !== targetCompanyId) return
    if (result.status === 'confirmed') {
      const data = result.data as { company_id?: unknown } | null
      const matchesCompany = data?.company_id === Number(targetCompanyId)
      destructiveOpen.value = false
      destructiveTarget.value = null
      notice.value = matchesCompany
        ? action === 'remove' ? 'Acesso removido. A identidade global permanece intacta.' : 'Convite revogado.'
        : 'A operação foi confirmada. Atualize os dados desta empresa para conferir o estado atual.'
      await loadMembers()
    } else if (result.status === 'unknown') notice.value = `O resultado ainda não foi confirmado. Recupere a operação ${result.key}.`
    else if (result.status === 'failed') error.value = result.failure.message
    else error.value = 'O contexto da empresa mudou. A operação não foi repetida.'
  } finally {
    if (mutation === mutationGeneration) submitting.value = false
  }
}
async function recover() {
  const key = command.recoveryKey.value
  if (!key || submitting.value) return
  const targetCompanyId = companyId.value
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  submitting.value = true
  try {
    const result = await command.recover<unknown>(key)
    if (mutation !== mutationGeneration || identity !== identityStamp.value || companyId.value !== targetCompanyId) return
    if (result.status === 'confirmed') {
      const data = result.data as MemberCommandData | null
      const matchesCompany = data?.company_id === Number(targetCompanyId)
      const deliveryReceipt = matchesCompany ? data?.delivery : null
      if (deliveryReceipt) delivery.value = { ...deliveryReceipt, expires_at: '', authority_invalidated_at: null }
      notice.value = matchesCompany ? 'Operação da empresa confirmada.' : 'Uma operação foi confirmada. Consulte os dados atualizados desta empresa.'
      await loadMembers()
    } else if (result.status === 'unknown') notice.value = `A operação ${key} ainda não foi confirmada.`
    else error.value = result.status === 'failed' ? result.failure.message : 'A operação pertence a outro contexto.'
  } finally {
    if (mutation === mutationGeneration) submitting.value = false
  }
}
function deliveryLabel(state: AccessDelivery['state']) {
  if (state === 'prepared') return 'Preparada, aguardando envio'
  if (state === 'sending') return 'Em envio'
  if (state === 'transport_accepted') return 'Aceita pelo serviço de e-mail; entrega final não confirmada'
  if (state === 'failed') return 'Falha no envio'
  if (state === 'unknown') return 'Resultado do envio desconhecido'
  return 'Expirada'
}
async function loadDelivery() {
  if (!delivery.value || deliveryLoading.value || !companyId.value) return
  const read = reads.begin('accessDelivery')
  const identity = identityStamp.value
  deliveryLoading.value = true
  deliveryError.value = ''
  try {
    await context.refresh()
    if (!read.current() || identity !== identityStamp.value || !context.capabilities.value?.can_maintain) return
    const token = context.capture()
    const response = await api<{ data: AccessDelivery }>(`/erp-commercial/companies/${companyId.value}/access-deliveries/${delivery.value.id}`, { signal: read.signal })
    if (!read.current() || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!response.data || response.data.id !== delivery.value.id) throw new Error('A resposta da entrega não corresponde à operação consultada.')
    delivery.value = response.data
  } catch (caught) {
    if (read.current() && identity === identityStamp.value) deliveryError.value = apiErrorMessage(caught) ?? 'Não foi possível consultar o estado da entrega.'
  } finally {
    if (read.current() && identity === identityStamp.value) deliveryLoading.value = false
  }
}
</script>

<template>
  <div v-if="!nestedMemberRoute" class="flex min-w-0 flex-col gap-5">
    <OfficeErpPanel title="Usuários e acessos" description="Pessoas autorizadas a entrar e operar nesta empresa." company-list>
      <template #action>
        <div class="flex gap-2">
          <UButton
            v-if="!embedded"
            color="neutral"
            variant="outline"
            :to="`/gestao-erp/assinaturas/empresas/${companyId}?secao=members`"
          >
            Voltar à empresa
          </UButton><UButton icon="i-lucide-user-plus" :disabled="!context.capabilities.value?.can_maintain || optionsLoading" @click="openInvite">
            Convidar usuário
          </UButton>
        </div>
      </template>
      <template #toolbar>
        <UFormField label="Buscar pessoa" :ui="{ label: 'text-xs' }" class="w-full sm:max-w-sm">
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Nome ou e-mail"
            aria-label="Buscar pessoa"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Situação" :ui="{ label: 'text-xs' }" class="w-full sm:w-48">
          <USelect v-model="filter" :items="[{ label: 'Todos', value: 'all' }, { label: 'Ativos', value: 'active' }, { label: 'Convites pendentes', value: 'pending' }]" class="w-full" />
        </UFormField>
      </template>
      <div :class="[companyListBodyClass, 'space-y-4']">
        <UAlert
          v-if="delivery"
          color="neutral"
          title="Estado da entrega do convite"
          :description="`${deliveryLabel(delivery.state)}${delivery.authority_invalidated_at ? ' · acesso revogado antes do envio' : ''}`"
        >
          <template #actions>
            <UButton
              color="neutral"
              variant="outline"
              :loading="deliveryLoading"
              @click="loadDelivery"
            >
              Consultar estado
            </UButton>
          </template>
        </UAlert>
        <UAlert
          v-if="deliveryError"
          color="error"
          title="Falha ao consultar entrega"
          :description="deliveryError"
        />
        <UAlert
          v-if="error || command.recoveryKey.value"
          color="error"
          title="Não foi possível concluir a ação"
          :description="error || 'Há uma operação comercial sem confirmação. Consulte o resultado antes de iniciar outra ação.'"
        >
          <template #actions>
            <UButton
              v-if="command.recoveryKey.value"
              color="neutral"
              variant="outline"
              :loading="submitting"
              @click="recover"
            >
              Recuperar operação
            </UButton><UButton
              v-else
              color="neutral"
              variant="outline"
              :loading="loading"
              @click="loadMembers"
            >
              Tentar novamente
            </UButton>
          </template>
        </UAlert>
        <UAlert
          v-if="notice || command.recoveryKey.value"
          color="warning"
          title="Estado da operação"
          :description="notice || 'Há uma operação comercial sem confirmação. Consulte o resultado antes de iniciar outra ação.'"
        >
          <template #actions>
            <UButton
              v-if="command.recoveryKey.value"
              color="neutral"
              variant="outline"
              :loading="submitting"
              @click="recover"
            >
              Recuperar operação
            </UButton>
          </template>
        </UAlert>
        <UAlert
          v-if="optionsError"
          color="error"
          title="Catálogos indisponíveis"
          :description="optionsError"
        >
          <template #actions>
            <UButton
              color="neutral"
              variant="outline"
              :loading="optionsLoading"
              @click="loadOptions"
            >
              Tentar novamente
            </UButton>
          </template>
        </UAlert>
        <USkeleton v-if="loading && !rows.length" class="h-32 w-full" />
        <UAlert
          v-else-if="empty"
          color="neutral"
          title="Nenhum usuário ou convite encontrado"
          description="Convide uma pessoa para conceder acesso a esta empresa."
        />
        <OfficeErpDataTable
          v-else
          :data="visibleRows"
          :columns="columns"
          :loading="loading"
          :get-row-id="row => `${row.kind}-${row.id}`"
          :mobile-columns="['user', 'actions']"
          :ui="companyListTableUi"
          compact
          label="Usuários e convites da empresa"
        >
          <template #user-cell="{ row }">
            <div class="flex min-w-0 items-center gap-3">
              <UAvatar :alt="memberName(row.original)" size="sm" class="hidden shrink-0 sm:inline-flex" />
              <div class="min-w-0 space-y-1">
                <p class="font-medium wrap-anywhere text-highlighted">
                  {{ memberName(row.original) }}
                </p>
                <p class="text-xs wrap-anywhere text-muted">
                  {{ memberEmail(row.original) }}
                </p>
                <p v-if="row.original.kind === 'invitation'" class="text-xs text-muted">
                  Expira {{ date(row.original.expires_at) }}
                </p>
                <p class="text-xs text-muted md:hidden">
                  {{ roleLabel(row.original.role) }} · {{ row.original.kind === 'membership' ? 'Ativo' : 'Convite pendente' }}
                </p>
              </div>
            </div>
          </template>
          <template #role-cell="{ row }">
            <span class="text-sm">{{ roleLabel(row.original.role) }}</span>
          </template>
          <template #status-cell="{ row }">
            <UBadge :color="row.original.kind === 'membership' ? 'success' : row.original.status === 'pending' ? 'warning' : 'neutral'" variant="subtle" size="sm">
              {{ row.original.kind === 'membership' ? 'Ativo' : row.original.status === 'pending' ? 'Convite pendente' : row.original.status }}
            </UBadge>
          </template>
          <template #actions-cell="{ row }">
            <div class="flex flex-wrap items-center justify-end gap-1.5">
              <UButton
                v-if="row.original.kind === 'membership'"
                color="neutral"
                variant="outline"
                size="xs"
                class="min-h-11 justify-center whitespace-normal md:min-h-7"
                :disabled="row.original.role === 'owner' || isCurrentUser(row.original) || submitting"
                @click="openEdit(row.original)"
              >
                Administrar usuário
              </UButton>
              <UButton
                v-else-if="row.original.status === 'pending'"
                color="neutral"
                variant="outline"
                size="xs"
                class="min-h-11 justify-center whitespace-normal md:min-h-7"
                :disabled="submitting"
                @click="reissueInvitation(row.original)"
              >
                Reenviar convite
              </UButton>
              <UDropdownMenu v-if="memberActions(row.original).length" :items="memberActions(row.original)" :content="{ align: 'end' }">
                <UButton
                  color="neutral"
                  variant="ghost"
                  icon="i-lucide-ellipsis-vertical"
                  :aria-label="`Mais opções de ${memberName(row.original)}`"
                  class="min-h-11 min-w-11 md:min-h-7 md:min-w-7"
                />
              </UDropdownMenu>
            </div>
          </template>
        </OfficeErpDataTable>
        <OfficeErpPagination
          v-model:page="page"
          v-model:per-page="perPage"
          :total="total"
          label="usuários e convites"
          :loading="loading"
          :class="companyListFooterClass"
        />
      </div>
    </OfficeErpPanel>
    <UModal
      v-model:open="editorOpen"
      :title="editorMode === 'invite' ? 'Convidar usuário' : 'Administrar usuário'"
      :description="editorMode === 'invite' ? 'Defina o papel e as permissões para esta empresa.' : target ? `${memberName(target)} · ${memberEmail(target)}` : undefined"
      :ui="{ content: 'max-w-3xl max-h-[calc(100dvh-2rem)] flex flex-col overflow-hidden', body: 'min-h-0 flex-1 overflow-y-auto', footer: 'justify-end' }"
      @after:leave="restoreEditorFocus"
    >
      <template #body>
        <div class="space-y-4">
          <UAlert
            v-if="error"
            color="error"
            title="Revise as permissões"
            :description="error"
          />
          <div v-if="editorMode === 'invite'" class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Nome" required>
              <UInput v-model="name" autocomplete="name" class="w-full" />
            </UFormField><UFormField label="E-mail" required>
              <UInput
                v-model="email"
                type="email"
                autocomplete="email"
                class="w-full"
              />
            </UFormField>
          </div>
          <UFormField label="Papel" required>
            <USelect
              v-model="role"
              :items="roles.map(item => ({ label: roleLabel(item.role), value: item.role }))"
              class="w-full"
              :disabled="target?.kind === 'membership' && target.role === 'owner'"
            />
          </UFormField>
          <UFormField label="Permissões">
            <USelect v-model="permissionMode" :items="[{ label: 'Padrões do papel', value: 'defaults' }, { label: 'Selecionar permissões', value: 'explicit' }]" class="w-full" />
          </UFormField>
          <fieldset v-if="permissionMode === 'explicit'" class="space-y-2 rounded-lg border border-default p-3">
            <legend class="px-1 text-sm font-medium">
              Permissões da empresa
            </legend><UCheckboxGroup v-model="permissions" :items="selectablePermissions.map(item => ({ label: item.label, value: item.permission }))" orientation="vertical" />
          </fieldset>
          <UFormField label="Estabelecimentos">
            <USelect v-model="scope" :items="[{ label: 'Todos os estabelecimentos', value: 'all' }, { label: 'Selecionar estabelecimentos', value: 'selected' }]" class="w-full" />
          </UFormField>
          <UFormField v-if="scope === 'selected'" label="Estabelecimentos permitidos" required>
            <USelectMenu
              v-model="establishmentIds"
              :items="(options?.establishments ?? []).filter(item => item.status === 'active').map(item => ({ label: `${item.name} · ${item.cnpj}`, value: item.id }))"
              value-key="value"
              multiple
              class="w-full"
            />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <UButton
            color="neutral"
            variant="outline"
            :disabled="submitting"
            @click="editorOpen = false"
          >
            Cancelar
          </UButton><UButton :loading="submitting || command.pending.value" :disabled="submitting || command.pending.value" @click="saveEditor">
            {{ editorMode === 'invite' ? 'Preparar convite' : 'Salvar configuração' }}
          </UButton>
        </div>
      </template>
    </UModal>
    <UModal
      v-model:open="destructiveOpen"
      :title="destructiveAction === 'remove' ? 'Remover acesso' : 'Revogar convite'"
      :description="destructiveAction === 'remove' ? `Remover o acesso de ${destructiveTarget?.name}? A conta global e outros acessos permanecem intactos.` : `Revogar o convite de ${destructiveTarget?.name}?`"
      @after:leave="restoreDestructiveFocus"
    >
      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton
            color="neutral"
            variant="outline"
            :disabled="submitting"
            @click="destructiveOpen = false"
          >
            Cancelar
          </UButton><UButton
            color="error"
            :loading="submitting"
            :disabled="submitting || command.pending.value"
            @click="confirmMemberAction"
          >
            Confirmar
          </UButton>
        </div>
      </template>
    </UModal>
  </div>
  <NuxtPage v-else />
</template>
