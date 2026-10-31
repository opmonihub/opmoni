<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { OfficeErpCommandResult, OfficeErpCompanyRegistrationOutcome, OfficeErpPortfolioClient, OfficeErpPortfolioClientListResponse } from '#shared/types/officeErpCommercial'
import { canRegisterTypedCnpj } from '~/utils/taxId'
import { focusFormError } from '~/utils/formPresentation'
import { apiErrorMessage } from '~/composables/useApiError'
import { useOfficeErpReadIdentity } from '~/composables/useOfficeErpReadIdentity'
import { useOfficeErpReads } from '~/composables/useOfficeErpReads'
import { officeErpAvailablePage } from '~/composables/useOfficeErpListNavigation'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const { api } = useAccessApi()
const command = useOfficeErpCommand()
const context = useOfficeErpContext()
const { user, currentAccount } = useAuth()
const sessionGeneration = useState('access-session-generation', () => 0)
const mode = ref(route.query.modo === 'novo' ? 'new' : 'portfolio')
const name = ref('')
const taxId = ref('')
const taxRegime = ref('simple_national')
const email = ref('')
const phone = ref('')
const query = ref(typeof route.query.q === 'string' ? route.query.q : '')
const appliedQuery = ref(query.value)
const selectedIds = ref<number[]>(typeof route.query.cliente === 'string' && /^[1-9]\d*$/.test(route.query.cliente) ? [Number(route.query.cliente)] : [])
const clients = ref<OfficeErpPortfolioClient[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref<25 | 50 | 100>(25)
const loading = ref(false)
const submitting = ref(false)
const confirmedEmpty = ref(false)
const loadError = ref('')
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
watch(fieldErrors, () => {
  void focusFormError(undefined, mode.value === 'portfolio' ? 'office-erp-register-portfolio' : 'office-erp-register-company')
})
const resultMessage = ref('')
let readGeneration = 0
let mutationGeneration = 0
let debounceTimer: ReturnType<typeof setTimeout> | undefined
const identityStamp = useOfficeErpReadIdentity(() => [user.value?.id ?? null, sessionGeneration.value, currentAccount.value?.id ?? null, currentAccount.value?.status ?? null, currentAccount.value?.role ?? null], () => context.capabilities.value?.capability_key)
const reads = useOfficeErpReads(identityStamp)
const selectionLimit = 100
const columns: TableColumn<OfficeErpPortfolioClient>[] = [
  { id: 'select', header: 'Selecionar' },
  { accessorKey: 'name', header: 'Cliente' },
  { accessorKey: 'tax_id', header: 'CNPJ' },
  { id: 'status', header: 'Carteira' }
]
const eligibleClients = computed(() => clients.value.filter(client => !client.erp_registration))
const allPageSelected = computed(() => eligibleClients.value.length > 0 && eligibleClients.value.every(client => selectedIds.value.includes(client.id)))
const somePageSelected = computed(() => eligibleClients.value.some(client => selectedIds.value.includes(client.id)) && !allPageSelected.value)
const overLimit = computed(() => selectedIds.value.length > selectionLimit)

watch(query, (value) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    appliedQuery.value = value.trim()
    page.value = 1
  }, 300)
})
watch([appliedQuery, page, perPage, identityStamp], () => {
  void loadClients()
}, { immediate: true })
watch(identityStamp, () => {
  readGeneration++
  mutationGeneration++
  submitting.value = false
  selectedIds.value = []
  name.value = ''
  taxId.value = ''
  taxRegime.value = 'simple_national'
  email.value = ''
  phone.value = ''
  formError.value = ''
  fieldErrors.value = {}
  resultMessage.value = ''
  if (typeof route.query.cliente === 'string') {
    const nextQuery = { ...route.query }
    delete nextQuery.cliente
    void router.replace({ query: nextQuery })
  }
  clients.value = []
  total.value = 0
  confirmedEmpty.value = false
  loadError.value = ''
}, { flush: 'sync' })
onScopeDispose(() => {
  mutationGeneration++
  submitting.value = false
  selectedIds.value = []
  name.value = ''
  taxId.value = ''
  taxRegime.value = 'simple_national'
  email.value = ''
  phone.value = ''
  if (debounceTimer) clearTimeout(debounceTimer)
})

function toggleClient(clientId: number, checked: boolean | 'indeterminate') {
  if (clients.value.find(client => client.id === clientId)?.erp_registration) return
  if (checked) {
    if (!selectedIds.value.includes(clientId)) selectedIds.value = [...selectedIds.value, clientId]
  } else selectedIds.value = selectedIds.value.filter(id => id !== clientId)
  formError.value = ''
}
function clearFieldError(key: string) {
  fieldErrors.value = Object.fromEntries(Object.entries(fieldErrors.value).filter(([field]) => field !== key))
}
function toggleCurrentPage(checked: boolean | 'indeterminate') {
  if (checked) {
    const merged = new Set([...selectedIds.value, ...eligibleClients.value.map(client => client.id)])
    if (merged.size > selectionLimit) {
      formError.value = `Selecione até ${selectionLimit} clientes por operação.`
      return
    }
    selectedIds.value = [...merged]
  } else {
    const pageIds = new Set(eligibleClients.value.map(client => client.id))
    selectedIds.value = selectedIds.value.filter(id => !pageIds.has(id))
  }
  formError.value = ''
  fieldErrors.value = {}
}
function selectableIds() {
  return selectedIds.value.filter(Number.isSafeInteger).slice(0, selectionLimit)
}

async function loadClients() {
  const read = reads.begin('loadClients')
  const generation = ++readGeneration
  const identity = identityStamp.value
  loading.value = true
  loadError.value = ''
  confirmedEmpty.value = false
  try {
    await context.refresh()
    if (!read.current() || generation !== readGeneration || identity !== identityStamp.value) return
    const capabilities = context.capabilities.value
    if (!capabilities?.can_read) {
      loadError.value = 'A conta selecionada não tem permissão para consultar a carteira do escritório.'
      clients.value = []
      total.value = 0
      return
    }
    const token = context.capture()
    const params: Record<string, string | number> = { page: page.value, per_page: perPage.value }
    if (appliedQuery.value) params.search = appliedQuery.value
    const response = await api<OfficeErpPortfolioClientListResponse>('/erp-commercial/clients', { params, signal: read.signal })
    if (!read.current() || generation !== readGeneration || identity !== identityStamp.value || !context.isCurrent(token)) return
    if (!Array.isArray(response?.data) || !response.meta || !Number.isSafeInteger(response.meta.total)) throw new Error('A resposta da carteira está incompleta.')
    const available = officeErpAvailablePage(response.meta)
    if (page.value !== available) {
      page.value = available
      return
    }
    clients.value = response.data
    const registeredIds = new Set(clients.value.filter(client => client.erp_registration).map(client => client.id))
    selectedIds.value = selectedIds.value.filter(id => !registeredIds.has(id))
    page.value = response.meta.current_page
    total.value = response.meta.total
    confirmedEmpty.value = response.meta.total === 0
  } catch (error) {
    if (read.current() && generation === readGeneration && identity === identityStamp.value) loadError.value = apiErrorMessage(error) ?? 'Não foi possível consultar os clientes agora.'
  } finally {
    if (read.current() && generation === readGeneration && identity === identityStamp.value) loading.value = false
  }
}

function formatFailure(code: string, message: string) {
  if (code === 'company_association_required') return 'Este cliente já está associado a uma empresa que exige revisão da carteira. O cadastro não foi alterado.'
  if (code === 'profile_changed') return 'Os dados mudaram em outra sessão. Atualize a carteira e tente novamente.'
  return message || 'Não foi possível registrar a empresa. Revise os dados e tente novamente.'
}

async function register() {
  if (submitting.value || command.pending.value) return
  formError.value = ''
  resultMessage.value = ''
  if (mode.value === 'portfolio') {
    if (!selectedIds.value.length) {
      formError.value = 'Selecione ao menos um cliente da carteira.'
      return
    }
    if (overLimit.value) {
      formError.value = `Selecione até ${selectionLimit} clientes por operação.`
      return
    }
  } else if (!canRegisterTypedCnpj(taxId.value)) {
    fieldErrors.value = { tax_id: ['Informe um CNPJ válido com 14 caracteres.'] }
    formError.value = fieldErrors.value.tax_id![0]!
    return
  }
  submitting.value = true
  const mutation = ++mutationGeneration
  const identity = identityStamp.value
  try {
    const body = mode.value === 'portfolio'
      ? { client_ids: selectableIds() }
      : { new_client: { tax_id: taxId.value.replace(/[./-]/g, '').toUpperCase(), tax_regime: taxRegime.value, ...(name.value.trim() ? { name: name.value.trim() } : {}), ...(email.value.trim() ? { email: email.value.trim() } : {}), ...(phone.value.trim() ? { phone: phone.value.trim() } : {}) } }
    const result = await command.execute<OfficeErpCompanyRegistrationOutcome>('company.register', '/erp-commercial/companies', body) as OfficeErpCommandResult<OfficeErpCompanyRegistrationOutcome>
    if (mutation !== mutationGeneration || identity !== identityStamp.value) return
    if (result.status === 'stale') {
      formError.value = 'O contexto do escritório mudou. Confira a conta selecionada e tente novamente.'
      return
    }
    if (result.status === 'unknown') {
      resultMessage.value = `O resultado ainda não foi confirmado. Recupere a operação ${result.key} antes de enviar outra vez.`
      return
    }
    if (result.status === 'failed') {
      fieldErrors.value = Object.fromEntries(Object.entries(result.failure.errors ?? {}).map(([field, messages]) => [field.replace(/^new_client\./, ''), messages]))
      formError.value = formatFailure(result.failure.code, result.failure.message)
      return
    }
    const count = result.data.created.length
    const unchanged = result.data.unchanged.length
    if (count === 1 && unchanged === 0 && result.data.created[0]) {
      await navigateTo(`/gestao-erp/assinaturas/empresas/${result.data.created[0].company_id}`)
      return
    }
    resultMessage.value = `${count} ${count === 1 ? 'empresa registrada' : 'empresas registradas'}; ${unchanged} ${unchanged === 1 ? 'já estava na carteira' : 'já estavam na carteira'}.`
    selectedIds.value = []
    await loadClients()
  } catch (error) {
    formError.value = apiErrorMessage(error) ?? 'Não foi possível registrar as empresas. Tente novamente.'
  } finally { if (mutation === mutationGeneration) submitting.value = false }
}

async function recover() {
  const key = command.recoveryKey.value
  if (!key || submitting.value) return
  submitting.value = true
  const mutation = ++mutationGeneration
  const identity = identityStamp.value
  const targetIds = [...selectedIds.value]
  formError.value = ''
  try {
    const result = await command.recover<unknown>(key)
    if (mutation !== mutationGeneration || identity !== identityStamp.value || targetIds.some(id => !selectedIds.value.includes(id))) return
    if (result.status === 'unknown') {
      resultMessage.value = `A operação ${key} ainda não foi confirmada.`
      return
    }
    if (result.status === 'failed') {
      formError.value = formatFailure(result.failure.code, result.failure.message)
      return
    }
    if (result.status === 'stale') {
      formError.value = 'A operação pertence a outro contexto comercial.'
      return
    }
    const outcome = result.data as Partial<OfficeErpCompanyRegistrationOutcome> | null
    if (Array.isArray(outcome?.created) && Array.isArray(outcome.unchanged)) {
      resultMessage.value = `${outcome.created.length} empresa(s) registrada(s); ${outcome.unchanged.length} já estava(m) na carteira.`
      selectedIds.value = []
    } else resultMessage.value = 'A operação foi confirmada. Atualize a carteira para conferir os dados atuais.'
    await loadClients()
  } finally { if (mutation === mutationGeneration) submitting.value = false }
}

function goBack() {
  const queryState = typeof route.query.q === 'string' ? { q: route.query.q } : {}
  void router.push({ path: '/gestao-erp/empresas', query: queryState })
}
</script>

<template>
  <div class="flex min-w-0 flex-col gap-5">
    <OfficeErpPanel title="Nova empresa" description="Registre uma empresa na carteira do escritório. O cadastro não cria assinatura nem consome licença.">
      <template #action>
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-arrow-left"
          @click="goBack"
        >
          Voltar para empresas
        </UButton>
      </template>
      <div class="space-y-5">
        <UFieldGroup>
          <UButton :color="mode === 'portfolio' ? 'primary' : 'neutral'" :variant="mode === 'portfolio' ? 'solid' : 'outline'" @click="mode = 'portfolio'; router.replace({ query: { ...route.query, modo: 'carteira' } })">
            Selecionar da carteira
          </UButton>
          <UButton :color="mode === 'new' ? 'primary' : 'neutral'" :variant="mode === 'new' ? 'solid' : 'outline'" @click="mode = 'new'; router.replace({ query: { ...route.query, modo: 'novo' } })">
            Cadastrar novo cliente
          </UButton>
        </UFieldGroup>

        <UAlert
          v-if="formError"
          color="error"
          title="Não foi possível concluir o cadastro"
          :description="formError"
        />
        <UAlert
          v-if="resultMessage || command.recoveryKey.value"
          color="warning"
          title="Confirme o resultado da operação"
          :description="resultMessage || 'Há uma operação comercial sem confirmação. Consulte o resultado antes de registrar outras empresas.'"
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
          v-if="context.capabilities.value && context.capabilities.value.can_register_company === false"
          color="warning"
          title="Cadastro temporariamente indisponível"
          description="A assinatura do escritório precisa estar ativa para registrar empresas. Atualize as condições da conta antes de tentar novamente."
        />

        <UForm
          v-if="mode === 'portfolio'"
          id="office-erp-register-portfolio"
          :state="{ client_ids: selectedIds }"
          aria-labelledby="portfolio-title"
          class="space-y-4"
          @submit="register"
          @error="event => focusFormError(event, 'office-erp-register-portfolio')"
        >
          <div>
            <h2 id="portfolio-title" class="text-base font-semibold text-highlighted">
              Selecione clientes existentes
            </h2><p class="mt-1 text-sm text-muted">
              Você pode selecionar até 100 clientes. A seleção em massa considera apenas a página atual.
            </p>
          </div>
          <UFormField label="Buscar cliente" class="w-full sm:max-w-sm">
            <UInput
              v-model="query"
              icon="i-lucide-search"
              placeholder="Nome ou CNPJ"
              aria-label="Buscar cliente"
              class="w-full"
            />
          </UFormField>
          <UAlert
            v-if="loadError"
            color="error"
            title="Não foi possível carregar a carteira"
            :description="loadError"
          >
            <template #actions>
              <UButton color="neutral" variant="outline" @click="loadClients">
                Tentar novamente
              </UButton>
            </template>
          </UAlert>
          <UAlert
            v-else-if="confirmedEmpty"
            color="neutral"
            title="Nenhum cliente encontrado"
            description="Cadastre um novo cliente ou ajuste a busca."
          />
          <UFormField
            v-else
            name="client_ids"
            :error="fieldErrors.client_ids?.[0]"
            :error-pattern="/^client_ids\..*/"
          >
            <OfficeErpDataTable
              :columns="columns"
              :data="clients"
              :loading="loading"
              :get-row-id="row => String(row.id)"
              :mobile-columns="['name', 'status']"
              compact
              label="Clientes da carteira"
            >
              <template #select-header>
                <div class="flex items-center gap-2">
                  <UCheckbox :model-value="allPageSelected ? true : somePageSelected ? 'indeterminate' : false" aria-label="Selecionar clientes da página atual" @update:model-value="toggleCurrentPage" /><span>Página</span>
                </div>
              </template>
              <template #select-cell="{ row }">
                <UCheckbox
                  :model-value="selectedIds.includes(row.original.id)"
                  :disabled="!!row.original.erp_registration"
                  :aria-label="row.original.erp_registration ? `${row.original.name} já está registrado` : `Selecionar ${row.original.name}`"
                  @update:model-value="value => toggleClient(row.original.id, value)"
                />
              </template>
              <template #tax_id-cell="{ row }">
                <span>{{ row.original.tax_id ?? '—' }}</span><UBadge
                  v-if="row.original.erp_registration"
                  color="neutral"
                  variant="subtle"
                  class="ml-2"
                >
                  Já registrado
                </UBadge>
              </template>
              <template #status-cell="{ row }">
                {{ row.original.subscription ? 'Com assinatura ou histórico' : 'Disponível para cadastro' }}
              </template>
            </OfficeErpDataTable>
          </UFormField>
          <div class="flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-3 text-sm text-muted">
              <span>{{ selectedIds.length }} de {{ selectionLimit }} selecionados</span><USelect
                v-model="perPage"
                :items="[{ label: '25 por página', value: 25 }, { label: '50 por página', value: 50 }, { label: '100 por página', value: 100 }]"
                aria-label="Clientes por página"
                class="w-36"
              /><UPagination
                v-model:page="page"
                :total="total"
                :items-per-page="perPage"
                :disabled="loading"
                :sibling-count="0"
                :show-edges="false"
                size="sm"
              />
            </div>
            <UButton
              :disabled="!selectedIds.length || overLimit || submitting || command.pending.value"
              :loading="submitting"
              icon="i-lucide-check"
              type="submit"
            >
              Registrar selecionados
            </UButton>
          </div>
        </UForm>

        <UForm
          v-else
          id="office-erp-register-company"
          :state="{ name, tax_id: taxId, email, phone, tax_regime: taxRegime }"
          :disabled="submitting || command.pending.value"
          class="grid gap-4 sm:grid-cols-2"
          @submit="register"
          @error="event => focusFormError(event, 'office-erp-register-company')"
        >
          <UFormField
            label="CNPJ"
            name="tax_id"
            :error="fieldErrors.tax_id?.[0]"
            required
          >
            <UInput
              v-model="taxId"
              inputmode="text"
              maxlength="18"
              placeholder="00.000.000/0000-00"
              autocomplete="off"
              class="w-full"
              @update:model-value="clearFieldError('tax_id')"
            />
          </UFormField>
          <UFormField
            label="Regime tributário"
            name="tax_regime"
            :error="fieldErrors.tax_regime?.[0]"
            required
          >
            <USelect
              v-model="taxRegime"
              :items="[{ label: 'Simples Nacional', value: 'simple_national' }, { label: 'MEI', value: 'mei' }, { label: 'Lucro presumido', value: 'presumed_profit' }, { label: 'Lucro real', value: 'actual_profit' }, { label: 'Outro', value: 'other' }]"
              class="w-full"
              @update:model-value="clearFieldError('tax_regime')"
            />
          </UFormField>
          <UFormField
            label="Razão social"
            name="name"
            :error="fieldErrors.name?.[0]"
            hint="Opcional; poderá ser complementada depois."
          >
            <UInput
              v-model="name"
              maxlength="255"
              autocomplete="organization"
              class="w-full"
              @update:model-value="clearFieldError('name')"
            />
          </UFormField>
          <UFormField label="E-mail de contato" name="email" :error="fieldErrors.email?.[0]">
            <UInput
              v-model="email"
              type="email"
              autocomplete="email"
              class="w-full"
              @update:model-value="clearFieldError('email')"
            />
          </UFormField>
          <UFormField label="Telefone" name="phone" :error="fieldErrors.phone?.[0]">
            <UInput
              v-model="phone"
              type="tel"
              autocomplete="tel"
              class="w-full"
              @update:model-value="clearFieldError('phone')"
            />
          </UFormField>
          <div class="flex flex-wrap gap-2 sm:col-span-2">
            <UButton color="neutral" variant="outline" @click="goBack">
              Cancelar
            </UButton><UButton type="submit" :loading="submitting" :disabled="submitting || command.pending.value">
              Registrar empresa
            </UButton>
          </div>
        </UForm>
      </div>
    </OfficeErpPanel>
  </div>
</template>
