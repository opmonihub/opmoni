<script setup lang="ts">
import { nextTick, useId } from 'vue'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'

type AdministrationKind = 'establishments' | 'members'
type Establishment = { id: number, name: string, cnpj: string | null, status: 'active' | 'inactive' }
type CompanyMember = {
  id: number
  user: { id: number, name: string, email: string }
  role: string
  action_permissions: string[] | null
  effective_permissions: string[]
  establishment_scope: 'all' | 'selected'
  establishment_ids: number[]
}
type PermissionChoice = { permission: string, label: string, write: boolean }
type IdentityMatch = { id: number, name: string, email: string }

const props = defineProps<{ kind: AdministrationKind }>()
const kind = computed(() => props.kind)
const { currentCompany } = useAuth()
const { $api } = useNuxtApp()
const { key: contextKey, capture, isCurrent, isCompanyOwner } = useErpAdministrationContext()

const title = computed(() => props.kind === 'establishments' ? 'Estabelecimentos' : 'Membros da empresa')
const rows = ref<Array<Establishment | CompanyMember>>([])
const establishments = ref<Establishment[]>([])
const permissionChoices = ref<PermissionChoice[]>([])
const roleDefaults = ref<Record<string, string[]>>({})
const currentPage = ref(1)
const lastPage = ref(1)
const hasAllEstablishments = ref(false)
const establishmentPage = ref(1)
const establishmentLastPage = ref(1)
const searchText = ref('')
const searchState = computed(() => ({ search: searchText.value }))
const submittedSearch = ref('')
const establishmentSearchText = ref('')
const submittedEstablishmentSearch = ref('')
const loading = ref(false)
const busyAction = ref('')
const listError = ref('')
const listStale = ref(false)
const authorityBlocked = ref(false)
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string>>({})
const fieldErrorId = useId()
const editorFormId = `${fieldErrorId}-form`
const formOpen = ref(false)
const editorReturnFocus = ref<{ focus: () => void } | null>(null)
const confirmTarget = ref<Establishment | CompanyMember | null>(null)
const identityMatch = ref<IdentityMatch | null>(null)
const lookupEmail = ref('')
const form = reactive({
  id: null as number | null,
  name: '',
  cnpj: '',
  status: 'active' as 'active' | 'inactive',
  role: 'manager',
  permissionsMode: 'defaults' as 'defaults' | 'custom',
  actionPermissions: [] as string[],
  establishmentScope: 'all' as 'all' | 'selected',
  establishmentIds: [] as number[]
})

const typedRows = computed(() => rows.value)
const isMemberKind = computed(() => props.kind === 'members')
const isEstablishmentKind = computed(() => props.kind === 'establishments')
const pageHasRows = computed(() => rows.value.length > 0)
const canReadEstablishments = computed(() => !authorityBlocked.value && currentCompany.value?.status === 'active'
  && erpCompanyHasPermission(currentCompany.value.permissions, 'erp.establishments.index'))
const canCreateEstablishments = computed(() => !authorityBlocked.value && !listStale.value && currentCompany.value?.status === 'active'
  && (currentCompany.value.access_kind === 'delegated' || currentCompany.value.role !== 'viewer')
  && hasAllEstablishments.value
  && erpCompanyHasPermission(currentCompany.value.permissions, 'erp.establishments.store'))
const canUpdateEstablishments = computed(() => !authorityBlocked.value && !listStale.value && currentCompany.value?.status === 'active'
  && (currentCompany.value.access_kind === 'delegated' || currentCompany.value.role !== 'viewer')
  && erpCompanyHasPermission(currentCompany.value.permissions, 'erp.establishments.update'))
const canView = computed(() => !authorityBlocked.value && (isEstablishmentKind.value ? canReadEstablishments.value : isCompanyOwner.value))
const canCreate = computed(() => !authorityBlocked.value && !listStale.value && (isEstablishmentKind.value ? canCreateEstablishments.value : isCompanyOwner.value))
const canUpdate = computed(() => !authorityBlocked.value && !listStale.value && (isEstablishmentKind.value ? canUpdateEstablishments.value : isCompanyOwner.value))
const canWrite = computed(() => canCreate.value || canUpdate.value)

let listRequestGeneration = 0

function api<T>(path: string, options?: Record<string, unknown>): Promise<T> {
  return $api<T>(path, options as never)
}

function isEstablishment(value: Establishment | CompanyMember): value is Establishment {
  return 'cnpj' in value
}

function isProtectedOwner(value: Establishment | CompanyMember): boolean {
  return !isEstablishment(value) && value.role === 'owner'
}

function resetForm() {
  form.id = null
  form.name = ''
  form.cnpj = ''
  form.status = 'active'
  form.role = 'manager'
  form.permissionsMode = 'defaults'
  form.actionPermissions = []
  form.establishmentScope = 'all'
  form.establishmentIds = []
  identityMatch.value = null
  lookupEmail.value = ''
  fieldErrors.value = {}
  errorMessage.value = ''
  confirmTarget.value = null
}

function clearProtectedRows() {
  rows.value = []
  establishments.value = []
  permissionChoices.value = []
  roleDefaults.value = {}
  currentPage.value = 1
  lastPage.value = 1
  hasAllEstablishments.value = false
  establishmentPage.value = 1
  establishmentLastPage.value = 1
}

function handleAuthorityError(error: unknown) {
  const status = apiStatus(error)
  const contextChanged = status === 409 && (error as { data?: { code?: string } }).data?.code === 'company_context_changed'
  if ([401, 403].includes(status ?? 0) || contextChanged) {
    listRequestGeneration++
    loading.value = false
    clearProtectedRows()
    resetForm()
    formOpen.value = false
    editorReturnFocus.value = null
    confirmTarget.value = null
    authorityBlocked.value = true
    listStale.value = false
    return
  }
  if (status === 409) {
    clearProtectedRows()
    listStale.value = false
    return
  }
  listStale.value = rows.value.length > 0 || establishments.value.length > 0
}

async function load() {
  if (!canView.value) return
  const token = capture()
  const request = ++listRequestGeneration
  loading.value = true
  listError.value = ''
  try {
    if (props.kind === 'establishments') {
      const query = new URLSearchParams({ page: String(currentPage.value), per_page: '100', status: 'all' })
      if (submittedSearch.value) query.set('search', submittedSearch.value)
      const response = await api<{ data: Establishment[], meta: { current_page: number, last_page: number, all_establishments: boolean } }>(`/erp/establishments?${query}`)
      if (!isCurrent(token) || request !== listRequestGeneration) return
      rows.value = response.data
      lastPage.value = response.meta.last_page
      hasAllEstablishments.value = response.meta.all_establishments
      listStale.value = false
      return
    }

    const memberQuery = new URLSearchParams({ page: String(currentPage.value), per_page: '100' })
    if (submittedSearch.value) memberQuery.set('search', submittedSearch.value)
    const establishmentQuery = new URLSearchParams({ page: String(establishmentPage.value), per_page: '100', status: 'all' })
    if (submittedEstablishmentSearch.value) establishmentQuery.set('search', submittedEstablishmentSearch.value)
    const [members, establishmentResults, permissions] = await Promise.all([
      api<{ data: CompanyMember[], meta: { current_page: number, last_page: number } }>(`/erp/members?${memberQuery}`),
      api<{ data: Establishment[], meta: { current_page: number, last_page: number } }>(`/erp/establishments?${establishmentQuery}`),
      api<{ data: PermissionChoice[], meta?: { roles?: Array<{ role: string, default_permissions: string[] }> } }>('/erp/member-permissions')
    ])
    if (!isCurrent(token) || request !== listRequestGeneration) return
    rows.value = members.data
    lastPage.value = members.meta.last_page
    establishmentLastPage.value = establishmentResults.meta.last_page
    establishments.value = establishmentResults.data
    permissionChoices.value = permissions.data
    roleDefaults.value = Object.fromEntries((permissions.meta?.roles ?? []).map(item => [item.role, item.default_permissions]))
    listStale.value = false
  } catch (error) {
    if (isCurrent(token) && request === listRequestGeneration) {
      handleAuthorityError(error)
      listError.value = apiErrorMessage(error) ?? 'Não foi possível carregar a lista de administração.'
    }
  } finally {
    if (isCurrent(token) && request === listRequestGeneration) loading.value = false
  }
}

function applySearch() {
  submittedSearch.value = searchText.value.trim()
  const pageChanged = currentPage.value !== 1 || establishmentPage.value !== 1
  currentPage.value = 1
  establishmentPage.value = 1
  if (!pageChanged) void load()
}

function applyEstablishmentSearch() {
  submittedEstablishmentSearch.value = establishmentSearchText.value.trim()
  if (establishmentPage.value === 1) void load()
  else establishmentPage.value = 1
}

function rememberEditorTrigger(event?: MouseEvent) {
  const target = event?.currentTarget as { focus?: () => void } | null
  editorReturnFocus.value = target && typeof target.focus === 'function' ? target as { focus: () => void } : null
}

async function closeEditor() {
  formOpen.value = false
  resetForm()
  const trigger = editorReturnFocus.value
  editorReturnFocus.value = null
  await nextTick()
  trigger?.focus()
}

function beginCreate(event?: MouseEvent) {
  if (!canCreate.value || busyAction.value) return
  rememberEditorTrigger(event)
  resetForm()
  formOpen.value = true
}

function beginEdit(row: Establishment | CompanyMember, event?: MouseEvent) {
  if (!canUpdate.value || busyAction.value || isProtectedOwner(row)) return
  rememberEditorTrigger(event)
  resetForm()
  form.id = row.id
  if (isEstablishment(row)) {
    form.name = row.name
    form.cnpj = row.cnpj ?? ''
    form.status = row.status
  } else {
    form.role = row.role
    form.permissionsMode = row.action_permissions?.length ? 'custom' : 'defaults'
    form.actionPermissions = [...(row.action_permissions ?? [])]
    form.establishmentScope = row.establishment_scope
    form.establishmentIds = [...row.establishment_ids]
    identityMatch.value = row.user
  }
  formOpen.value = true
}

function cancelForm() {
  if (busyAction.value) return
  void closeEditor()
}

function setFieldError(field: string, message: string) {
  fieldErrors.value = { ...fieldErrors.value, [field]: message }
}

async function lookupIdentity() {
  if (!canView.value || !canCreate.value || busyAction.value || !lookupEmail.value.trim()) return
  const token = capture()
  busyAction.value = 'lookup'
  errorMessage.value = ''
  fieldErrors.value = {}
  identityMatch.value = null
  try {
    const response = await api<{ data: IdentityMatch }>('/erp/members/identity-lookup', {
      method: 'POST',
      body: { email: lookupEmail.value.trim() }
    })
    if (!isCurrent(token)) return
    identityMatch.value = response.data
  } catch (error) {
    if (!isCurrent(token)) return
    handleAuthorityError(error)
    fieldErrors.value = apiFieldErrors(error)
    errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível localizar essa identidade.'
  } finally {
    if (isCurrent(token)) busyAction.value = ''
  }
}

function memberPayload() {
  const grants = form.permissionsMode === 'defaults' ? null : [...form.actionPermissions]
  return {
    role: form.role,
    action_permissions: grants,
    establishment_scope: form.establishmentScope,
    establishment_ids: form.establishmentScope === 'all' ? [] : [...form.establishmentIds]
  }
}

async function submitForm() {
  if ((form.id ? !canUpdate.value : !canCreate.value) || busyAction.value) return
  if (props.kind === 'members' && form.permissionsMode === 'custom' && form.actionPermissions.length === 0) {
    setFieldError('action_permissions', 'Selecione ao menos uma permissão ou use os padrões do papel.')
    return
  }
  if (props.kind === 'members' && form.establishmentScope === 'selected' && !form.id && !identityMatch.value) {
    setFieldError('email', 'Localize uma identidade existente antes de adicionar o membro.')
    return
  }
  const token = capture()
  busyAction.value = 'save'
  errorMessage.value = ''
  fieldErrors.value = {}
  const path = props.kind === 'establishments'
    ? form.id ? `/erp/establishments/${form.id}` : '/erp/establishments'
    : form.id ? `/erp/members/${form.id}` : '/erp/members'
  const method = props.kind === 'establishments' ? form.id ? 'PATCH' : 'POST' : form.id ? 'PATCH' : 'POST'
  const body = props.kind === 'establishments'
    ? { name: form.name.trim(), cnpj: form.cnpj.trim() || null, status: form.status }
    : { ...(form.id ? {} : { user_id: identityMatch.value?.id }), ...memberPayload() }
  try {
    await api(path, { method, body })
    if (!isCurrent(token)) return
    await closeEditor()
    await load()
  } catch (error) {
    if (!isCurrent(token)) return
    handleAuthorityError(error)
    fieldErrors.value = apiFieldErrors(error)
    errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível salvar os dados enviados.'
  } finally {
    if (isCurrent(token)) busyAction.value = ''
  }
}

function beginRemove(row: Establishment | CompanyMember) {
  if (!canUpdate.value || busyAction.value || isProtectedOwner(row)) return
  confirmTarget.value = row
}

function cancelRemove() {
  if (!busyAction.value) confirmTarget.value = null
}

async function confirmRemove() {
  const target = confirmTarget.value
  if (!target || !canUpdate.value || busyAction.value || isProtectedOwner(target)) return
  const token = capture()
  busyAction.value = 'remove'
  errorMessage.value = ''
  try {
    if (isEstablishment(target)) {
      await api(`/erp/establishments/${target.id}`, { method: 'PATCH', body: { status: 'inactive' } })
    } else {
      await api(`/erp/members/${target.id}`, { method: 'DELETE' })
    }
    if (!isCurrent(token)) return
    confirmTarget.value = null
    await load()
  } catch (error) {
    if (!isCurrent(token)) return
    handleAuthorityError(error)
    errorMessage.value = apiErrorMessage(error) ?? 'Não foi possível concluir esta ação.'
    fieldErrors.value = apiFieldErrors(error)
  } finally {
    if (isCurrent(token)) busyAction.value = ''
  }
}

function setPermission(permission: string, checked: boolean) {
  const next = new Set(form.actionPermissions)
  if (checked) next.add(permission)
  else next.delete(permission)
  form.actionPermissions = [...next]
}

function setEstablishment(id: number, checked: boolean) {
  const next = new Set(form.establishmentIds)
  if (checked) next.add(id)
  else next.delete(id)
  form.establishmentIds = [...next].sort((left, right) => left - right)
}

watch(contextKey, () => {
  listRequestGeneration++
  clearProtectedRows()
  searchText.value = ''
  submittedSearch.value = ''
  establishmentSearchText.value = ''
  submittedEstablishmentSearch.value = ''
  loading.value = false
  busyAction.value = ''
  listError.value = ''
  listStale.value = false
  authorityBlocked.value = false
  formOpen.value = false
  editorReturnFocus.value = null
  resetForm()
  if (canView.value) void load()
}, { immediate: true, flush: 'sync' })

watch([currentPage, establishmentPage], () => {
  void load()
})

defineExpose({ rows, establishments, permissionChoices, roleDefaults, form, formOpen, busyAction, fieldErrors, errorMessage,
  canReadEstablishments, canCreateEstablishments, canUpdateEstablishments, canView, canCreate, canUpdate, canWrite, listStale, authorityBlocked, currentPage, establishmentPage, lastPage, establishmentLastPage,
  applySearch, applyEstablishmentSearch, beginCreate, beginEdit, submitForm, lookupIdentity, beginRemove, confirmRemove, load, setPermission, setEstablishment })
</script>

<template>
  <section class="space-y-5" :data-kind="kind">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold">
          {{ title }}
        </h2>
        <p class="text-muted mt-1 text-sm">
          {{ isEstablishmentKind ? 'Cadastre as unidades da empresa e mantenha o estado de cada uma.' : 'Gerencie o acesso de usuários já cadastrados à empresa e aos estabelecimentos.' }}
        </p>
      </div>
      <UButton v-if="canCreate" :disabled="!!busyAction" @click="beginCreate($event)">
        {{ isEstablishmentKind ? 'Adicionar estabelecimento' : 'Adicionar membro' }}
      </UButton>
    </header>

    <UAlert v-if="!canView" color="warning" title="Acesso restrito">
      {{ isEstablishmentKind ? 'Sua função não tem permissão para consultar estabelecimentos desta empresa.' : 'Somente o proprietário direto da empresa pode administrar os membros.' }}
    </UAlert>

    <UAlert v-if="errorMessage" color="error" title="Não foi possível concluir a ação">
      {{ errorMessage }}
    </UAlert>
    <UAlert v-if="listError" color="error" title="Não foi possível atualizar a lista">
      <template #description>
        <span>{{ listError }}</span>
        <UButton
          class="ml-2"
          variant="link"
          :disabled="loading || authorityBlocked"
          @click="load"
        >
          Tentar novamente
        </UButton>
      </template>
    </UAlert>
    <UAlert v-if="listStale" color="warning" title="Lista desatualizada">
      A última leitura permanece visível. Atualize antes de alterar o cadastro.
    </UAlert>

    <UModal
      v-if="formOpen && canView"
      :open="formOpen"
      :dismissible="false"
      :close="false"
      :ui="{ content: 'w-full max-w-2xl' }"
    >
      <template #content>
        <div class="space-y-4 p-5">
          <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold">
              {{ form.id ? 'Editar' : 'Novo' }} {{ isEstablishmentKind ? 'estabelecimento' : 'membro' }}
            </h3>
            <UButton
              type="button"
              color="neutral"
              variant="ghost"
              :disabled="!!busyAction"
              @click="cancelForm"
            >
              Fechar
            </UButton>
          </div>

          <UForm
            :id="editorFormId"
            :state="form"
            class="space-y-4"
            @submit="submitForm"
          >
            <template v-if="isEstablishmentKind">
              <UFormField
                label="Nome"
                name="name"
                required
                :error="fieldErrors.name"
              >
                <UInput v-model="form.name" maxlength="255" />
              </UFormField>
              <UFormField label="CNPJ (opcional)" name="cnpj" :error="fieldErrors.cnpj">
                <UInput v-model="form.cnpj" inputmode="numeric" maxlength="20" />
              </UFormField>
              <UFormField v-if="form.id" label="Situação" name="status">
                <USelect v-model="form.status" :items="[{ label: 'Ativo', value: 'active' }, { label: 'Inativo', value: 'inactive' }]" />
              </UFormField>
            </template>

            <template v-else>
              <div v-if="!form.id" class="space-y-2">
                <UFormField label="E-mail de um usuário já cadastrado" name="email" :error="[fieldErrors.email, fieldErrors.user_id].filter(Boolean).join(' ')">
                  <UInput v-model="lookupEmail" type="email" autocomplete="off" />
                </UFormField>
                <UButton
                  type="button"
                  color="neutral"
                  variant="outline"
                  :loading="busyAction === 'lookup'"
                  :disabled="!!busyAction"
                  @click="lookupIdentity"
                >
                  Localizar identidade
                </UButton>
                <p v-if="identityMatch" class="text-sm" role="status">
                  {{ identityMatch.name }} · {{ identityMatch.email }}
                </p>
              </div>
              <p v-else-if="identityMatch" class="text-muted text-sm">
                {{ identityMatch.name }} · {{ identityMatch.email }}
              </p>

              <UFormField label="Papel" name="role">
                <USelect v-model="form.role" :items="[{ label: 'Gerente', value: 'manager' }, { label: 'Operador', value: 'operator' }, { label: 'Leitor', value: 'viewer' }]" />
              </UFormField>

              <UFormField label="Permissões de ação" name="action_permissions" :error="fieldErrors.action_permissions">
                <URadioGroup
                  v-model="form.permissionsMode"
                  :items="[{ label: 'Usar permissões padrão do papel', value: 'defaults' }, { label: 'Escolher uma lista personalizada', value: 'custom' }]"
                />
                <UAccordion
                  v-if="form.permissionsMode === 'defaults'"
                  :items="[{ label: `Ver permissões padrão (${(roleDefaults[form.role] ?? []).length})`, value: 'defaults', slot: 'defaultPermissions' }]"
                  type="single"
                  class="mt-3"
                >
                  <template #defaultPermissions>
                    <p class="text-muted text-xs">
                      {{ (roleDefaults[form.role] ?? []).join(', ') || 'Sem permissões padrão disponíveis para este papel.' }}
                    </p>
                  </template>
                </UAccordion>
                <div v-if="form.permissionsMode === 'custom'" class="grid gap-2 pt-3 sm:grid-cols-2">
                  <UCheckbox
                    v-for="choice in permissionChoices"
                    :key="choice.permission"
                    :model-value="form.actionPermissions.includes(choice.permission)"
                    :label="`${choice.label} · ${choice.write ? 'escrita' : 'leitura'}`"
                    @update:model-value="setPermission(choice.permission, $event === true)"
                  />
                </div>
              </UFormField>

              <UFormField label="Estabelecimentos permitidos" name="establishment_ids" :error="fieldErrors.establishment_ids">
                <URadioGroup
                  v-model="form.establishmentScope"
                  :items="[{ label: 'Todos os estabelecimentos', value: 'all' }, { label: 'Somente os selecionados', value: 'selected' }]"
                />
                <div v-if="form.establishmentScope === 'selected'" class="grid gap-2 pt-3 sm:grid-cols-2">
                  <div class="col-span-full flex flex-wrap gap-2" @keydown.enter.prevent.stop="applyEstablishmentSearch">
                    <UInput
                      id="member-establishment-search"
                      v-model="establishmentSearchText"
                      class="min-w-0 flex-1"
                      placeholder="Pesquisar estabelecimentos"
                      aria-label="Pesquisar estabelecimentos permitidos"
                    />
                    <UButton
                      type="button"
                      color="neutral"
                      variant="outline"
                      :disabled="loading"
                      @click="applyEstablishmentSearch"
                    >
                      Pesquisar
                    </UButton>
                  </div>
                  <UCheckbox
                    v-for="establishment in establishments"
                    :key="establishment.id"
                    :model-value="form.establishmentIds.includes(establishment.id)"
                    :label="`${establishment.name}${establishment.status === 'inactive' ? ' (inativo)' : ''}`"
                    @update:model-value="setEstablishment(establishment.id, $event === true)"
                  />
                  <p v-if="establishments.length === 0" class="text-muted text-sm">
                    Nenhum estabelecimento cadastrado; a lista vazia não concede acesso.
                  </p>
                  <p v-if="form.establishmentIds.length" class="col-span-full text-muted text-xs">
                    Seleção preservada: {{ form.establishmentIds.map(id => establishments.find(item => item.id === id)?.name ?? `#${id}`).join(', ') }}
                  </p>
                </div>
              </UFormField>
            </template>

            <div class="flex flex-wrap justify-end gap-2 border-t border-default pt-4">
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                :disabled="!!busyAction"
                @click="cancelForm"
              >
                Cancelar
              </UButton>
              <UButton type="submit" :loading="busyAction === 'save'" :disabled="!!busyAction">
                Salvar
              </UButton>
            </div>
          </UForm>
        </div>
      </template>
    </UModal>

    <div
      v-if="confirmTarget"
      class="rounded-xl border border-warning/50 bg-warning/5 p-4"
      role="alertdialog"
      aria-live="assertive"
    >
      <p class="font-medium">
        {{ isEstablishmentKind ? 'Inativar este estabelecimento?' : 'Remover este membro da empresa?' }}
      </p>
      <p class="text-muted mt-1 text-sm">
        {{ isEstablishment(confirmTarget) ? confirmTarget.name : confirmTarget.user.name }}. Esta ação exige confirmação.
      </p>
      <div class="mt-3 flex gap-2">
        <UButton
          color="error"
          :loading="busyAction === 'remove'"
          :disabled="!!busyAction"
          @click="confirmRemove"
        >
          Confirmar
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          :disabled="!!busyAction"
          @click="cancelRemove"
        >
          Cancelar
        </UButton>
      </div>
    </div>

    <UForm
      v-if="canView"
      :state="searchState"
      class="flex flex-wrap gap-2"
      @submit="applySearch"
    >
      <UInput
        v-model="searchText"
        class="min-w-0 flex-1"
        :aria-label="`Pesquisar ${title.toLocaleLowerCase()}`"
        :placeholder="isEstablishmentKind ? 'Pesquisar estabelecimentos' : 'Pesquisar por nome ou e-mail'"
      />
      <UButton
        type="submit"
        color="neutral"
        variant="outline"
        :disabled="loading"
      >
        Pesquisar
      </UButton>
    </UForm>

    <div v-if="canView" class="space-y-3">
      <div v-if="loading && !pageHasRows" class="text-muted py-8 text-center">
        Carregando {{ title.toLocaleLowerCase() }}…
      </div>
      <div v-else-if="!pageHasRows && !listError" class="rounded-xl border border-dashed border-default p-6 text-center">
        <h3 class="font-medium">
          {{ isEstablishmentKind ? 'Nenhum estabelecimento cadastrado' : 'Nenhum membro adicional' }}
        </h3>
        <p v-if="canCreate" class="text-muted mt-1 text-sm">
          Use “{{ isEstablishmentKind ? 'Adicionar estabelecimento' : 'Adicionar membro' }}” para começar.
        </p>
      </div>
      <article v-for="row in typedRows" :key="row.id" class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-default p-4">
        <div class="min-w-0 space-y-1">
          <template v-if="isEstablishment(row)">
            <h3 class="font-medium">
              {{ row.name }}
            </h3>
            <p class="text-muted text-sm">
              {{ row.cnpj || 'Sem CNPJ informado' }} · {{ row.status === 'active' ? 'Ativo' : 'Inativo' }}
            </p>
          </template>
          <template v-else>
            <h3 class="font-medium">
              {{ row.user.name }} <UBadge v-if="row.role === 'owner'" color="neutral" variant="subtle">
                Proprietário
              </UBadge>
            </h3>
            <p class="text-muted text-sm">
              {{ row.user.email }} · {{ row.role }}
            </p>
            <p class="text-muted text-xs">
              Escopo: {{ row.establishment_scope === 'all' ? 'todos os estabelecimentos' : row.establishment_ids.length ? row.establishment_ids.map(id => establishments.find(item => item.id === id)?.name ?? `#${id}`).join(', ') : 'nenhum estabelecimento' }}
            </p>
            <UAccordion
              v-if="row.effective_permissions.length"
              :items="[{ label: `${row.effective_permissions.length} permissões efetivas · ver detalhes`, value: 'effective', slot: 'effectivePermissions' }]"
              type="single"
              class="pt-1"
            >
              <template #effectivePermissions>
                <div class="flex flex-wrap gap-1">
                  <UBadge
                    v-for="permission in row.effective_permissions"
                    :key="permission"
                    color="neutral"
                    variant="subtle"
                  >
                    {{ permission }}
                  </UBadge>
                </div>
              </template>
            </UAccordion>
            <p v-else class="text-muted text-xs">
              Nenhuma permissão efetiva.
            </p>
          </template>
        </div>
        <div v-if="canUpdate && !isProtectedOwner(row)" class="flex shrink-0 gap-2">
          <UButton
            size="sm"
            color="neutral"
            variant="outline"
            :disabled="!!busyAction"
            @click="beginEdit(row, $event)"
          >
            Editar
          </UButton>
          <UButton
            size="sm"
            color="error"
            variant="soft"
            :disabled="!!busyAction"
            @click="beginRemove(row)"
          >
            {{ isEstablishment(row) ? 'Inativar' : 'Remover' }}
          </UButton>
        </div>
        <p v-else-if="isProtectedOwner(row)" class="text-muted text-sm">
          O proprietário não pode ser rebaixado nem removido por esta tela.
        </p>
      </article>
      <div v-if="isMemberKind && formOpen && form.establishmentScope === 'selected' && establishmentLastPage > 1" class="flex items-center justify-between gap-3">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          :disabled="establishmentPage <= 1 || loading"
          @click="establishmentPage--"
        >
          Estabelecimentos anteriores
        </UButton>
        <span class="text-muted text-sm">Página {{ establishmentPage }} de {{ establishmentLastPage }}</span>
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          :disabled="establishmentPage >= establishmentLastPage || loading"
          @click="establishmentPage++"
        >
          Mais estabelecimentos
        </UButton>
      </div>
      <div v-if="lastPage > 1" class="flex items-center justify-between gap-3">
        <UButton
          color="neutral"
          variant="outline"
          :disabled="currentPage <= 1 || loading"
          @click="currentPage--"
        >
          Anterior
        </UButton>
        <span class="text-muted text-sm">Página {{ currentPage }} de {{ lastPage }}</span>
        <UButton
          color="neutral"
          variant="outline"
          :disabled="currentPage >= lastPage || loading"
          @click="currentPage++"
        >
          Próxima
        </UButton>
      </div>
    </div>
  </section>
</template>
