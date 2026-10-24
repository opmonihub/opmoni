import { computed, onScopeDispose, ref, watch, type Ref } from 'vue'
import { apiFailure } from '#shared/utils/access'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'
import type {
  ErpOperationNatureCfopChoice,
  ErpOperationNatureCfopReference,
  ErpOperationNatureProductException
} from '#shared/types/erpOperationNatureRules'

type RuleOptions = {
  natureId: Readonly<Ref<number | null>>
  movement: Readonly<Ref<'in' | 'out'>>
  canRead: Readonly<Ref<boolean>>
  canWrite: Readonly<Ref<boolean>>
  rootDirty: Readonly<Ref<boolean>>
}
type Page<T> = { data: T[], meta?: { current_page?: number, last_page?: number, total?: number, configuration_revision?: number }, configuration_revision?: number }
type ContextToken = ReturnType<ReturnType<typeof useErpAdministrationContext>['capture']>

const pageSize = 15

function queryString(values: Record<string, string | number | undefined>): string {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(values)) {
    if (value !== undefined && value !== '')
      query.set(key, String(value))
  }
  return query.toString()
}

function items<T>(response: Page<T>): T[] {
  return Array.isArray(response.data) ? response.data : []
}

function revisionOf<T>(response: Page<T>): number | null {
  const revision = response.meta?.configuration_revision ?? response.configuration_revision
  return Number.isSafeInteger(revision) && Number(revision) >= 0 ? Number(revision) : null
}

export function useErpOperationNatureRules(options: RuleOptions) {
  const { api } = useAccessApi()
  const auth = useAuth()
  const context = useErpAdministrationContext()
  const choices = ref<ErpOperationNatureCfopChoice[]>([])
  const exceptions = ref<ErpOperationNatureProductException[]>([])
  const exceptionChoiceRefs = ref<Record<number, ErpOperationNatureCfopChoice>>({})
  const cfopResults = ref<ErpOperationNatureCfopReference[]>([])
  const productResults = ref<Array<{ id: number, name: string, sku?: string | null }>>([])
  const cfopResultsCurrent = ref(false)
  const productResultsCurrent = ref(false)
  const revision = ref<number | null>(null)
  const choicesPage = ref(1)
  const exceptionsPage = ref(1)
  const cfopsPage = ref(1)
  const productsPage = ref(1)
  const exceptionLastPage = ref(1)
  const choiceLastPage = ref(1)
  const cfopsLastPage = ref(1)
  const productsLastPage = ref(1)
  const choiceSearch = ref('')
  const exceptionSearch = ref('')
  const cfopSearch = ref('')
  const productSearch = ref('')
  const selectedProduct = ref<{ id: number, name: string, sku?: string | null } | null>(null)
  const selectedCfop = ref<ErpOperationNatureCfopReference | null>(null)
  const pending = ref(false)
  const error = ref('')
  const reviewRequired = ref(false)
  const blockedContextKey = ref<string | null>(null)
  const requiresReopen = ref(false)
  const authorityBlocked = computed(() => blockedContextKey.value === context.key.value)
  const readOnly = computed(() => authorityBlocked.value || !options.canRead.value || !Number.isSafeInteger(options.natureId.value) || options.natureId.value! < 1)
  const canMutate = computed(() => !readOnly.value && options.canWrite.value && !options.rootDirty.value && !pending.value && !reviewRequired.value)
  const canSearchProducts = computed(() => {
    const actor = auth.user.value
    const company = auth.currentCompany.value
    if (readOnly.value || !actor?.id || !company || company.status !== 'active')
      return false
    if (company.access_kind === 'delegated') {
      const office = auth.currentAccount.value
      if (office?.status !== 'active' || office.id !== company.delegated_office_id)
        return false
    } else if (company.access_kind !== 'direct') {
      return false
    }
    return erpCompanyHasPermission(company.permissions, 'erp.products.index')
  })
  const loaded = ref(false)
  let disposed = false
  let readCount = 0
  let writePending = false
  let loadSequence = 0
  const readSequences = new Map<string, number>()
  let cfopSearchTimer: ReturnType<typeof setTimeout> | null = null
  let productSearchTimer: ReturnType<typeof setTimeout> | null = null
  let tokenAtLoad: ContextToken | null = null

  const base = () => `/erp/tax-settings/operation-natures/${options.natureId.value}`
  function invalidate() {
    readSequences.clear()
    readCount = 0
    writePending = false
    pending.value = false
  }
  function updatePending() {
    pending.value = writePending || readCount > 0
  }
  function contextFailure(failure: unknown) {
    const details = apiFailure(failure)
    if (details.status === 401 || details.status === 403 || (details.status === 409 && details.code === 'company_context_changed')) {
      blockedContextKey.value = context.key.value
      requiresReopen.value = false
      invalidate()
      choices.value = []
      exceptions.value = []
      exceptionChoiceRefs.value = {}
      cfopResults.value = []
      productResults.value = []
      cfopResultsCurrent.value = false
      productResultsCurrent.value = false
      selectedProduct.value = null
      selectedCfop.value = null
      selectedExceptionChoiceCode.value = ''
      revision.value = null
      loaded.value = false
      error.value = 'A empresa ou sua autorização mudou. Atualize o contexto antes de continuar.'
      return true
    }
    error.value = details.message
    return false
  }
  function writeBlocked(): boolean {
    if (reviewRequired.value) {
      error.value = 'A configuração mudou. Revise os dados atuais antes de tentar novamente.'
      return true
    }
    if (options.rootDirty.value) {
      error.value = 'Salve ou descarte as alterações da natureza antes de alterar suas regras.'
      return true
    }
    if (!options.canWrite.value) {
      error.value = 'Você não tem permissão para alterar as regras desta natureza.'
      return true
    }
    if (revision.value === null) {
      error.value = 'Carregue a configuração atual antes de alterá-la.'
      return true
    }
    return false
  }
  async function readPage<T>(path: string): Promise<Page<T> | null> {
    if (readOnly.value)
      return null
    const token = context.capture()
    const natureId = options.natureId.value
    const requestKey = path.split('?')[0]!
    const requestSequence = (readSequences.get(requestKey) ?? 0) + 1
    readSequences.set(requestKey, requestSequence)
    readCount++
    pending.value = true
    try {
      const result = await api<Page<T>>(path)
      if (disposed || readSequences.get(requestKey) !== requestSequence || !context.isCurrent(token) || options.natureId.value !== natureId)
        return null
      return result
    } catch (failure) {
      if (!disposed && readSequences.get(requestKey) === requestSequence && context.isCurrent(token) && options.natureId.value === natureId)
        contextFailure(failure)
      return null
    } finally {
      readCount = Math.max(0, readCount - 1)
      updatePending()
    }
  }
  function acceptRevision(next: number | null) {
    if (next !== null)
      revision.value = next
  }
  async function load(): Promise<boolean> {
    if (readOnly.value)
      return false
    const currentLoad = ++loadSequence
    const loadToken = context.capture()
    error.value = ''
    const [choicePage, exceptionPage] = await Promise.all([
      readPage<ErpOperationNatureCfopChoice>(`${base()}/choices?${queryString({ search: choiceSearch.value, page: choicesPage.value, per_page: pageSize })}`),
      readPage<ErpOperationNatureProductException>(`${base()}/product-exceptions?${queryString({ search: exceptionSearch.value, page: exceptionsPage.value, per_page: pageSize })}`)
    ])
    if (!choicePage || !exceptionPage || disposed || currentLoad !== loadSequence || !context.isCurrent(loadToken))
      return false
    const choiceRevision = revisionOf(choicePage)
    const exceptionRevision = revisionOf(exceptionPage)
    if (choiceRevision === null || exceptionRevision === null || choiceRevision !== exceptionRevision) {
      reviewRequired.value = true
      error.value = 'A configuração mudou durante a consulta. Atualize e revise novamente.'
      return false
    }
    choices.value = items(choicePage)
    exceptions.value = items(exceptionPage)
    exceptionChoiceRefs.value = {}
    choiceLastPage.value = choicePage.meta?.last_page ?? 1
    exceptionLastPage.value = exceptionPage.meta?.last_page ?? 1
    revision.value = choiceRevision
    loaded.value = true
    tokenAtLoad = context.capture()
    const currentChoices = choices.value
    const missingChoiceIds = [...new Set(exceptions.value
      .map(exception => exception.erp_operation_nature_cfop_choice_id)
      .filter(id => !currentChoices.some(choice => choice.id === id)))]
    if (missingChoiceIds.length) {
      const token = context.capture()
      readCount++
      updatePending()
      const choiceRows = await Promise.all(missingChoiceIds.map(async (choiceId) => {
        try {
          const response = await api<{ data: ErpOperationNatureCfopChoice }>(`${base()}/choices/${choiceId}`)
          return context.isCurrent(token) ? [choiceId, response.data] as const : null
        } catch {
          return null
        }
      }))
      readCount = Math.max(0, readCount - 1)
      updatePending()
      if (currentLoad === loadSequence && context.isCurrent(token))
        exceptionChoiceRefs.value = Object.fromEntries(choiceRows.filter((row): row is readonly [number, ErpOperationNatureCfopChoice] => row !== null))
    }
    if (currentLoad === loadSequence && canSearchProducts.value && exceptions.value.length) {
      const token = context.capture()
      readCount++
      updatePending()
      const productRows = await Promise.all(exceptions.value.map(async (exception) => {
        try {
          const response = await api<{ data: { id: number, name: string, sku?: string | null } }>(`/erp/products/${exception.erp_product_id}`)
          return context.isCurrent(token) ? [exception.id, response.data] as const : null
        } catch {
          return null
        }
      }))
      readCount = Math.max(0, readCount - 1)
      updatePending()
      if (currentLoad === loadSequence && context.isCurrent(token)) {
        const byException = new Map(productRows.filter((row): row is readonly [number, { id: number, name: string, sku?: string | null }] => row !== null))
        exceptions.value = exceptions.value.map(exception => ({ ...exception, product: byException.get(exception.id) }))
      }
    }
    return currentLoad === loadSequence && context.isCurrent(loadToken) && !error.value
  }
  async function reviewCurrent(): Promise<boolean> {
    const reviewed = await load()
    if (!reviewed)
      return false
    reviewRequired.value = false
    return true
  }
  async function retry(): Promise<boolean> {
    if (!authorityBlocked.value) {
      await load()
      return loaded.value && !error.value
    }
    if (pending.value)
      return false
    if (requiresReopen.value) {
      error.value = 'O contexto mudou. Feche e abra novamente este registro na empresa atual.'
      return false
    }
    const rejectedContextKey = context.key.value
    pending.value = true
    error.value = ''
    try {
      const identity = await auth.refresh()
      if (disposed)
        return false
      if (context.key.value !== rejectedContextKey) {
        error.value = 'O contexto mudou. Abra novamente o registro nesta empresa para continuar.'
        return false
      }
      if (!identity) {
        error.value = 'Sua sessão precisa ser confirmada antes de consultar estas regras.'
        return false
      }
      blockedContextKey.value = null
      await load()
      return loaded.value && !error.value
    } catch (failure) {
      if (!disposed && context.key.value === rejectedContextKey)
        error.value = apiFailure(failure).message
      return false
    } finally {
      if (!disposed && context.key.value === rejectedContextKey)
        pending.value = false
    }
  }
  async function mutate(path: string, method: string, body: Record<string, unknown>, keepDraft = false): Promise<boolean> {
    if (writeBlocked())
      return false
    if (tokenAtLoad && !context.isCurrent(tokenAtLoad)) {
      contextFailure({ status: 409, data: { code: 'company_context_changed' } })
      return false
    }
    const reviewedRevision = revision.value!
    const token = context.capture()
    error.value = ''
    writePending = true
    pending.value = true
    try {
      const response = await api<Page<unknown> & { data?: unknown }>(path, {
        method,
        body: { ...body, expected_revision: reviewedRevision }
      })
      if (!context.isCurrent(token))
        return false
      const nextRevision = revisionOf(response)
      if (nextRevision !== null)
        revision.value = nextRevision
      if (!keepDraft)
        await load()
      return true
    } catch (failure) {
      if (!context.isCurrent(token))
        return false
      const details = apiFailure(failure)
      if (details.status === 409 && details.code === 'operation_nature_changed') {
        reviewRequired.value = true
        error.value = 'Outra pessoa alterou estas regras. Revise a configuração atual; seus campos continuam preservados.'
        await refreshRevisionOnly(token)
      } else {
        contextFailure(failure)
      }
      return false
    } finally {
      writePending = false
      if (context.isCurrent(token))
        updatePending()
    }
  }
  async function refreshRevisionOnly(token: ContextToken): Promise<void> {
    try {
      const result = await api<Page<ErpOperationNatureCfopChoice>>(`${base()}/choices?${queryString({ page: 1, per_page: pageSize })}`)
      if (context.isCurrent(token))
        acceptRevision(revisionOf(result))
    } catch {
      // Keep the local draft blocked until a fresh explicit review succeeds.
    }
  }
  async function addChoice(code = selectedCfop.value?.code ?? ''): Promise<boolean> {
    if (!cfopResultsCurrent.value) {
      if (!error.value)
        error.value = 'Atualize a consulta da referência antes de adicionar uma opção.'
      return false
    }
    if (!code || !selectedCfop.value || selectedCfop.value.code !== code || !cfopResults.value.some(item => item.code === code && item.reference_version === selectedCfop.value?.reference_version)) {
      error.value = 'Selecione um CFOP encontrado na referência para adicioná-lo.'
      return false
    }
    const success = await mutate(`${base()}/choices`, 'POST', { cfop: code })
    if (success)
      selectedCfop.value = null
    return success
  }
  async function setDefault(code: string): Promise<{ code: string, expected_revision: number } | null> {
    const choice = choices.value.find(item => item.cfop === code && item.status === 'active' && item.reference_version)
    if (!choice) {
      error.value = 'O padrão precisa ser uma opção ativa verificada.'
      return null
    }
    if (writeBlocked())
      return null
    return { code, expected_revision: revision.value! }
  }
  async function removeChoice(choice: ErpOperationNatureCfopChoice, replacement?: string): Promise<boolean> {
    if (choice.id === null) {
      error.value = 'A opção legada não pode ser removida antes de escolher outro padrão verificado.'
      return false
    }
    return mutate(`${base()}/choices/${choice.id}`, 'DELETE', replacement ? { cfop: replacement } : {})
  }
  async function addException(): Promise<boolean> {
    if (!productResultsCurrent.value) {
      if (!error.value)
        error.value = 'Atualize a consulta de produtos antes de criar a exceção.'
      return false
    }
    if (!selectedProduct.value) {
      error.value = 'Selecione um produto e um CFOP permitido para criar a exceção.'
      return false
    }
    const choice = [...choices.value, ...Object.values(exceptionChoiceRefs.value)].find(item => item.cfop === selectedExceptionChoiceCode.value && item.status === 'active')
    if (!choice?.id) {
      error.value = 'O CFOP selecionado ainda não está salvo como opção permitida nesta natureza.'
      return false
    }
    const success = await mutate(`${base()}/product-exceptions`, 'POST', {
      erp_product_id: selectedProduct.value.id,
      erp_operation_nature_cfop_choice_id: choice.id
    })
    if (success) {
      selectedProduct.value = null
      selectedCfop.value = null
      selectedExceptionChoiceCode.value = ''
    }
    return success
  }
  async function removeException(exception: ErpOperationNatureProductException): Promise<boolean> {
    return mutate(`${base()}/product-exceptions/${exception.id}`, 'DELETE', {})
  }
  async function updateException(exception: ErpOperationNatureProductException, choiceId: number): Promise<boolean> {
    if (![...choices.value, ...Object.values(exceptionChoiceRefs.value)].some(choice => choice.id === choiceId && choice.status === 'active')) {
      error.value = 'Selecione uma opção ativa desta natureza.'
      return false
    }
    return mutate(`${base()}/product-exceptions/${exception.id}`, 'PATCH', { erp_operation_nature_cfop_choice_id: choiceId })
  }
  async function searchCfops(): Promise<void> {
    if (readOnly.value)
      return
    cfopResultsCurrent.value = false
    const direction = options.movement.value === 'in' ? 'inbound' : 'outbound'
    const response = await readPage<ErpOperationNatureCfopReference>(`/erp/tax-settings/cfops?${queryString({ q: cfopSearch.value, direction, page: cfopsPage.value, per_page: pageSize })}`)
    if (response) {
      cfopResults.value = items(response)
      cfopsLastPage.value = response.meta?.last_page ?? 1
      cfopResultsCurrent.value = true
    }
  }
  async function searchProducts(): Promise<void> {
    if (!canSearchProducts.value) {
      productResults.value = []
      selectedProduct.value = null
      error.value = 'Você não tem permissão para consultar produtos nesta empresa.'
      return
    }
    productResultsCurrent.value = false
    const response = await readPage<{ id: number, name: string, sku?: string | null }>(`/erp/products?${queryString({ search: productSearch.value, page: productsPage.value, per_page: pageSize })}`)
    if (response) {
      productResults.value = items(response)
      productsLastPage.value = response.meta?.last_page ?? 1
      productResultsCurrent.value = true
    }
  }
  function selectCfop(value: ErpOperationNatureCfopReference | null) {
    if (value === null || (cfopResultsCurrent.value && cfopResults.value.some(item => item.code === value.code && item.reference_version === value.reference_version)))
      selectedCfop.value = value
  }
  const selectedExceptionChoiceCode = ref('')
  function selectExceptionChoice(code: string) {
    selectedExceptionChoiceCode.value = code
  }
  function selectProduct(value: { id: number, name: string, sku?: string | null } | null) {
    if (value === null || (productResultsCurrent.value && productResults.value.some(item => item.id === value.id)))
      selectedProduct.value = value
  }

  function resetLoadedState() {
    loadSequence++
    invalidate()
    readCount = 0
    choices.value = []
    exceptions.value = []
    exceptionChoiceRefs.value = {}
    cfopResults.value = []
    productResults.value = []
    cfopResultsCurrent.value = false
    productResultsCurrent.value = false
    selectedProduct.value = null
    selectedCfop.value = null
    selectedExceptionChoiceCode.value = ''
    revision.value = null
    loaded.value = false
    reviewRequired.value = false
    error.value = ''
  }
  watch(options.natureId, () => {
    resetLoadedState()
    blockedContextKey.value = null
    requiresReopen.value = false
  }, { flush: 'sync' })
  watch(context.key, () => {
    resetLoadedState()
    blockedContextKey.value = context.key.value
    requiresReopen.value = true
    error.value = 'O contexto mudou. Feche e abra novamente este registro na empresa atual.'
  }, { flush: 'sync' })
  watch([choiceSearch, choicesPage], () => {
    if (loaded.value)
      void load()
  })
  watch([exceptionSearch, exceptionsPage], () => {
    if (loaded.value)
      void load()
  })
  watch(choiceSearch, () => {
    choicesPage.value = 1
  })
  watch(exceptionSearch, () => {
    exceptionsPage.value = 1
  })
  watch(cfopSearch, () => {
    cfopsPage.value = 1
  })
  watch(productSearch, () => {
    productsPage.value = 1
  })
  watch([cfopSearch, cfopsPage], () => {
    cfopResultsCurrent.value = false
    if (cfopSearchTimer !== null)
      clearTimeout(cfopSearchTimer)
    cfopSearchTimer = setTimeout(() => {
      cfopSearchTimer = null
      void searchCfops()
    }, 250)
  })
  watch([productSearch, productsPage], () => {
    productResultsCurrent.value = false
    if (productSearchTimer !== null)
      clearTimeout(productSearchTimer)
    productSearchTimer = setTimeout(() => {
      productSearchTimer = null
      void searchProducts()
    }, 250)
  })
  onScopeDispose(() => {
    disposed = true
    loadSequence++
    invalidate()
    if (cfopSearchTimer !== null)
      clearTimeout(cfopSearchTimer)
    if (productSearchTimer !== null)
      clearTimeout(productSearchTimer)
  })

  return {
    choices, exceptions, exceptionChoiceRefs, cfopResults, productResults, revision, choicesPage, exceptionsPage, cfopsPage, productsPage,
    choiceSearch, exceptionSearch, cfopSearch, productSearch, selectedProduct, selectedCfop, selectedExceptionChoiceCode,
    choiceLastPage, exceptionLastPage, cfopsLastPage, productsLastPage, pending, error, reviewRequired, authorityBlocked, requiresReopen, canMutate, canSearchProducts,
    cfopResultsCurrent, productResultsCurrent, load, reviewCurrent, retry, addChoice, setDefault, removeChoice, addException, removeException, updateException,
    searchCfops, searchProducts, selectCfop, selectExceptionChoice, selectProduct
  }
}
