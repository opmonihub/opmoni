<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { OfficeErpCommandResult, OfficeErpCompanyRegistrationOutcome, OfficeErpPortfolioClient, OfficeErpPortfolioClientListResponse } from '#shared/types/officeErpCommercial'
import { apiErrorMessage } from '~/composables/useApiError'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'
import { useModalFocusReturn } from '~/composables/useModalFocusReturn'
import { focusFormError } from '~/utils/formPresentation'

const open = defineModel<boolean>('open', { required: true })
const restoreModalFocus = useModalFocusReturn(open)
const emit = defineEmits<{ registered: [OfficeErpCompanyRegistrationOutcome] }>()
const { api } = useAccessApi()
const command = useOfficeErpCommand()
const context = useOfficeErpContext()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const query = ref('')
const search = ref('')
const page = ref(1)
const perPage = ref<25 | 50 | 100>(25)
const rows = ref<OfficeErpPortfolioClient[]>([])
const selected = ref<number[]>([])
const pendingRegistrationIds = ref<number[]>([])
const total = ref(0)
const loading = ref(false)
const submitting = ref(false)
const error = ref('')
const notice = ref('')
const noticeKind = ref<'status' | 'warning'>('status')
const empty = ref(false)
let generation = 0
let mutationGeneration = 0
let timer: ReturnType<typeof setTimeout> | undefined
const maxSelection = 100
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const eligibleRows = computed(() => rows.value.filter(row => !row.erp_registration))
const allSelected = computed(() => eligibleRows.value.length > 0 && eligibleRows.value.every(row => selected.value.includes(row.id)))
const someSelected = computed(() => eligibleRows.value.some(row => selected.value.includes(row.id)) && !allSelected.value)
const checkboxHitTargetUi = {
  root: 'size-11 items-center justify-center',
  container: 'size-11 justify-center',
  base: 'relative overflow-visible after:absolute after:-inset-3.5 after:content-[""]'
}
const columns: TableColumn<OfficeErpPortfolioClient>[] = [
  { id: 'select', header: 'Selecionar', meta: { class: { th: 'w-11 min-w-11 max-w-11 px-0 sm:w-24 sm:min-w-24 sm:max-w-24 sm:px-3', td: 'w-11 min-w-11 max-w-11 px-0 sm:w-24 sm:min-w-24 sm:max-w-24 sm:px-3' } } },
  { accessorKey: 'name', header: 'Cliente', meta: { class: { th: 'w-full min-w-0 md:w-auto', td: 'w-full min-w-0 md:w-auto' } } },
  { accessorKey: 'tax_id', header: 'CNPJ' },
  { id: 'actions', header: '', meta: { class: { th: 'w-11 min-w-11 max-w-11 px-0 sm:px-3', td: 'w-11 min-w-11 max-w-11 px-0 sm:px-3' } } }
]
watch(query, (value) => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => {
    search.value = value.trim()
    page.value = 1
  }, 250)
})
watch([open, search, page, perPage, identityStamp], ([isOpen]) => {
  if (isOpen) void load()
}, { immediate: true })
watch(open, (isOpen) => {
  mutationGeneration++
  if (!isOpen) submitting.value = false
}, { flush: 'sync' })
watch(identityStamp, () => {
  generation++
  mutationGeneration++
  submitting.value = false
  rows.value = []
  pendingRegistrationIds.value = []
  selected.value = []
  total.value = 0
  empty.value = false
  error.value = ''
  notice.value = ''
  noticeKind.value = 'status'
}, { flush: 'sync' })
onScopeDispose(() => {
  generation++
  mutationGeneration++
  reads.cancel('loadClients')
  submitting.value = false
  pendingRegistrationIds.value = []
  selected.value = []
  rows.value = []
  query.value = ''
  search.value = ''
  error.value = ''
  notice.value = ''
  if (timer) clearTimeout(timer)
})

function matchesSelection(outcome: Partial<OfficeErpCompanyRegistrationOutcome> | null | undefined, expected: number[]): outcome is OfficeErpCompanyRegistrationOutcome {
  if (!expected.length || !Array.isArray(outcome?.created) || !Array.isArray(outcome?.unchanged)) return false
  const registrations = [...outcome.created, ...outcome.unchanged]
  if (registrations.some(item => !item || !Number.isSafeInteger(item.registration_id) || !Number.isSafeInteger(item.client_id) || !Number.isSafeInteger(item.company_id))) return false
  const received = registrations.map(item => item.client_id)
  if (received.length !== expected.length) return false
  const expectedSorted = [...expected].sort((a, b) => a - b)
  const receivedSorted = received.sort((a, b) => a - b)
  return receivedSorted.every((id, index) => id === expectedSorted[index])
}

function toggle(id: number, checked: boolean | 'indeterminate') {
  if (rows.value.find(row => row.id === id)?.erp_registration) return
  if (checked) {
    if (selected.value.includes(id)) return
    if (selected.value.length >= maxSelection) {
      error.value = `Selecione até ${maxSelection} clientes por operação.`
      return
    }
    selected.value = [...selected.value, id]
  } else selected.value = selected.value.filter(value => value !== id)
  error.value = ''
}
function togglePage(checked: boolean | 'indeterminate') {
  if (!checked) {
    const ids = new Set(eligibleRows.value.map(row => row.id))
    selected.value = selected.value.filter(id => !ids.has(id))
    return
  }
  const merged = new Set([...selected.value, ...eligibleRows.value.map(row => row.id)])
  if (merged.size > maxSelection) {
    error.value = `Esta seleção ultrapassaria o limite de ${maxSelection} clientes.`
    return
  }
  selected.value = [...merged]
}
async function load() {
  if (!open.value) return
  const read = reads.begin('loadClients')
  const request = ++generation
  const identity = identityStamp.value
  loading.value = true
  error.value = ''
  empty.value = false
  try {
    await context.refresh()
    if (!read.current() || request !== generation || identity !== identityStamp.value) return
    if (!context.capabilities.value?.can_read) throw new Error('A conta selecionada não tem permissão para consultar a carteira do escritório.')
    const token = context.capture()
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value }
    if (search.value) params.search = search.value
    const response = await api<OfficeErpPortfolioClientListResponse>('/erp-commercial/clients', { params, signal: read.signal })
    if (!read.current() || request !== generation || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)) throw new Error('A resposta da carteira está incompleta.')
    const available = officeErpAvailablePage(response.meta)
    if (page.value !== available) {
      page.value = available
      return
    }
    rows.value = response.data
    const registeredIds = new Set(rows.value.filter(row => row.erp_registration).map(row => row.id))
    selected.value = selected.value.filter(id => !registeredIds.has(id))
    page.value = response.meta.current_page
    total.value = response.meta.total
    empty.value = response.meta.total === 0
  } catch (exception) {
    if (read.current() && request === generation && identity === identityStamp.value) error.value = apiErrorMessage(exception) ?? (exception instanceof Error ? exception.message : 'Não foi possível consultar a carteira.')
  } finally {
    if (read.current() && request === generation && identity === identityStamp.value) loading.value = false
  }
}
async function performRegistration(ids: number[]) {
  const expectedIds = [...new Set(ids)].sort((a, b) => a - b)
  if (submitting.value || !expectedIds.length || expectedIds.length > maxSelection || command.pending.value) return
  pendingRegistrationIds.value = expectedIds
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  submitting.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = await command.execute<OfficeErpCompanyRegistrationOutcome>('company.register', '/erp-commercial/companies', { client_ids: expectedIds }) as OfficeErpCommandResult<OfficeErpCompanyRegistrationOutcome>
    if (mutation !== mutationGeneration || !open.value || identity !== identityStamp.value) return
    if (result.status === 'stale') {
      pendingRegistrationIds.value = []
      error.value = 'O contexto do escritório mudou. Confirme a conta selecionada e tente novamente.'
    } else if (result.status === 'unknown') {
      noticeKind.value = 'warning'
      notice.value = `O resultado ainda é incerto. Recupere a operação ${result.key} antes de reenviar.`
    } else if (result.status === 'failed') {
      pendingRegistrationIds.value = []
      error.value = result.failure.message || 'Não foi possível registrar as empresas selecionadas.'
    } else {
      pendingRegistrationIds.value = []
      if (!matchesSelection(result.data, expectedIds)) {
        noticeKind.value = 'status'
        notice.value = 'A operação foi confirmada. Atualize a carteira para conferir os dados atuais.'
        await load()
        return
      }
      emit('registered', result.data)
      selected.value = selected.value.filter(id => !expectedIds.includes(id))
      noticeKind.value = 'status'
      notice.value = `${result.data.created.length} empresa(s) registrada(s); ${result.data.unchanged.length} já estava(m) na carteira.`
      await load()
    }
  } finally { if (mutation === mutationGeneration) submitting.value = false }
}
async function submit() {
  await performRegistration(selected.value)
}
async function registerOne(row: OfficeErpPortfolioClient) {
  if (row.erp_registration || !rows.value.some(candidate => candidate.id === row.id && !candidate.erp_registration)) return
  await performRegistration([row.id])
}
async function recover() {
  const key = command.recoveryKey.value
  if (!key || submitting.value) return
  const expectedIds = [...pendingRegistrationIds.value]
  const identity = identityStamp.value
  const mutation = ++mutationGeneration
  submitting.value = true
  try {
    const result = await command.recover<unknown>(key)
    if (mutation !== mutationGeneration || !open.value || identity !== identityStamp.value) return
    if (result.status === 'confirmed') {
      const outcome = result.data as Partial<OfficeErpCompanyRegistrationOutcome> | null
      pendingRegistrationIds.value = []
      if (matchesSelection(outcome, expectedIds)) {
        emit('registered', outcome)
        selected.value = selected.value.filter(id => !expectedIds.includes(id))
        noticeKind.value = 'status'
        notice.value = `${outcome.created.length} empresa(s) registrada(s); ${outcome.unchanged.length} já estava(m) na carteira.`
      } else {
        noticeKind.value = 'status'
        notice.value = 'A operação foi confirmada. Atualize a carteira para conferir os dados atuais.'
      }
      await load()
    } else if (result.status === 'unknown') {
      noticeKind.value = 'warning'
      notice.value = `A operação ${key} ainda não foi confirmada.`
    } else {
      pendingRegistrationIds.value = []
      error.value = result.status === 'failed' ? result.failure.message : 'A operação pertence a outro contexto comercial.'
    }
  } finally { if (mutation === mutationGeneration) submitting.value = false }
}
</script>

<template>
  <UModal
    v-model:open="open"
    title="Escolher clientes da carteira"
    description="Selecione clientes para registrar no ERP. Limite: 100 por operação."
    :ui="{ content: 'max-w-4xl', body: 'min-h-0 overflow-y-auto', footer: 'shrink-0 flex-wrap justify-end' }"
    @after:leave="restoreModalFocus"
  >
    <template #body>
      <div class="space-y-3 sm:space-y-4">
        <UFormField label="Buscar cliente">
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Nome ou CNPJ"
            aria-label="Buscar cliente"
            class="w-full"
          />
        </UFormField>
        <UAlert
          v-if="error"
          color="error"
          title="Não foi possível concluir a ação"
          :description="error"
        />
        <UAlert
          v-if="(notice && noticeKind === 'warning') || command.recoveryKey.value"
          color="warning"
          title="Resultado da operação"
          :description="notice || 'Há uma operação comercial sem confirmação. Consulte o resultado antes de registrar outra empresa.'"
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
        <p v-if="notice && noticeKind === 'status'" class="text-sm text-muted" aria-live="polite">
          {{ notice }}
        </p>
        <UAlert
          v-if="empty && !error && !(notice && noticeKind === 'warning') && !command.recoveryKey.value"
          color="neutral"
          title="Nenhum cliente encontrado"
          description="Ajuste a busca ou cadastre um novo cliente."
        />
        <UForm
          id="office-erp-register-selection"
          :state="{ client_ids: selected }"
          @submit="submit"
          @error="event => focusFormError(event, 'office-erp-register-selection')"
        >
          <UFormField name="client_ids">
            <OfficeErpDataTable
              :columns="columns"
              :data="rows"
              :loading="loading"
              :get-row-id="row => String(row.id)"
              :mobile-columns="['select', 'name', 'actions']"
              compact
              label="Clientes da carteira"
            >
              <template #select-header>
                <div class="flex items-center gap-2">
                  <UCheckbox
                    :model-value="allSelected ? true : someSelected ? 'indeterminate' : false"
                    aria-label="Selecionar a página atual"
                    :ui="checkboxHitTargetUi"
                    @update:model-value="togglePage"
                  />
                  <span class="hidden sm:inline">Página</span>
                </div>
              </template>
              <template #select-cell="{ row }">
                <UCheckbox
                  :model-value="selected.includes(row.original.id)"
                  :disabled="!!row.original.erp_registration"
                  class="mx-auto size-11"
                  :aria-label="row.original.erp_registration ? `${row.original.name} já está registrado` : `Selecionar ${row.original.name}`"
                  :ui="checkboxHitTargetUi"
                  @update:model-value="value => toggle(row.original.id, value)"
                />
              </template>
              <template #name-cell="{ row }">
                <div class="min-w-0 space-y-1">
                  <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="min-w-0 break-words font-medium text-default">{{ row.original.name }}</span>
                    <UBadge
                      v-if="row.original.erp_registration"
                      color="neutral"
                      variant="subtle"
                      class="shrink-0"
                    >
                      Já registrada
                    </UBadge>
                  </div>
                  <p v-if="row.original.tax_id" class="text-xs text-muted">
                    {{ row.original.tax_id }}
                  </p>
                </div>
              </template>
              <template #tax_id-cell="{ row }">
                {{ row.original.tax_id ?? '—' }}
              </template>
              <template #actions-cell="{ row }">
                <UButton
                  v-if="!row.original.erp_registration"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  icon="i-lucide-plus"
                  class="size-11 min-w-11 p-0"
                  :aria-label="`Registrar ${row.original.name}`"
                  :disabled="submitting || command.pending.value"
                  @click="registerOne(row.original)"
                />
              </template>
            </OfficeErpDataTable>
          </UFormField>
        </UForm>
        <footer class="flex flex-col gap-3 border-t border-default pt-3 sm:flex-row sm:items-center sm:justify-between sm:pt-4">
          <div class="flex min-w-0 flex-wrap items-center justify-between gap-2 text-sm text-muted sm:justify-start sm:gap-3">
            <span aria-live="polite">{{ selected.length }} selecionados · limite {{ maxSelection }}</span><USelect
              v-if="total > 25 || perPage !== 25"
              v-model="perPage"
              :items="[{ label: '25 por página', value: 25 }, { label: '50 por página', value: 50 }, { label: '100 por página', value: 100 }]"
              aria-label="Clientes por página"
              class="w-32 sm:w-36"
            /><UPagination
              v-if="total > perPage || page > 1"
              v-model:page="page"
              :total="total"
              :items-per-page="perPage"
              :disabled="loading"
              :sibling-count="0"
              :show-edges="false"
              size="sm"
            />
          </div>
        </footer>
      </div>
    </template>
    <template #footer>
      <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:justify-end">
        <UButton
          color="neutral"
          variant="outline"
          class="min-h-11 w-full justify-center sm:w-auto"
          :disabled="submitting"
          @click="open = false"
        >
          <span class="sm:hidden">Fechar</span>
          <span class="hidden sm:inline">Cancelar</span>
        </UButton>
        <UButton
          class="min-h-11 w-full justify-center sm:w-auto"
          :aria-label="`Registrar ${selected.length} empresas selecionadas no ERP`"
          :disabled="selected.length === 0 || submitting || command.pending.value"
          :loading="submitting"
          type="submit"
          form="office-erp-register-selection"
        >
          <span class="sm:hidden">Adicionar ({{ selected.length }})</span>
          <span class="hidden sm:inline">Registrar selecionados</span>
        </UButton>
      </div>
    </template>
  </UModal>
</template>
