import { apiFailure } from '#shared/utils/access'
import type { ErpProductTaxPreviewCandidate, ErpProductTaxProfileField, ErpProductTaxProfileResolution } from '#shared/types/erpCatalogs'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'

const fieldNames: ErpProductTaxProfileField[] = [
  'icms_cst', 'icms_csosn', 'icms_rate', 'simple_national_credit_rate',
  'pis_cst', 'pis_rate', 'cofins_cst', 'cofins_rate', 'ipi_cst', 'ipi_rate',
  'ibs_cbs_cst', 'ibs_cbs_classification_code'
]

export function erpProductTaxPreviewPayload(candidate: ErpProductTaxPreviewCandidate): ErpProductTaxPreviewCandidate {
  const payload: Record<string, unknown> = {
    ...candidate,
    ...(candidate.tax_field_intents ? { tax_field_intents: { ...candidate.tax_field_intents } } : {})
  }
  const inherited = new Set<ErpProductTaxProfileField>()
  for (const field of fieldNames) {
    const intent = candidate.tax_field_intents?.[field]
    if (intent === 'inherit')
      inherited.add(field)
    else if (intent === 'unset')
      payload[field] = null
  }
  return Object.fromEntries(Object.entries(payload).filter(([key]) => !inherited.has(key as ErpProductTaxProfileField))) as ErpProductTaxPreviewCandidate
}

export function useErpProductTaxProfilePreview() {
  const { api } = useAccessApi()
  const { user, currentCompany, currentAccount } = useAuth()
  const context = useErpAdministrationContext()
  const result = ref<ErpProductTaxProfileResolution | null>(null)
  const pending = ref(false)
  const error = ref('')
  const stale = ref(false)
  const blockedContextKey = ref<string | null>(null)
  const authorityBlocked = computed(() => blockedContextKey.value === context.key.value)
  const canPreview = computed(() => {
    const company = currentCompany.value
    if (!user.value?.id || !company || company.status !== 'active' || !Number.isSafeInteger(company.id) || company.id < 1 || authorityBlocked.value)
      return false
    if (company.access_kind === 'delegated') {
      const office = currentAccount.value
      if (office?.status !== 'active' || office.id !== company.delegated_office_id || !['admin', 'operador'].includes(office.role))
        return false
    } else if (company.access_kind !== 'direct' || !['owner', 'manager', 'operator'].includes(company.role)) {
      return false
    }
    return erpCompanyHasPermission(company.permissions, 'erp.products.update')
  })

  let sequence = 0
  let activeController: AbortController | null = null
  let disposed = false

  function invalidate(clearResult: boolean) {
    sequence++
    activeController?.abort()
    activeController = null
    pending.value = false
    if (clearResult) {
      result.value = null
      stale.value = false
    }
  }

  function clear() {
    invalidate(true)
    error.value = ''
  }

  function markStale() {
    if (result.value)
      stale.value = true
  }

  function markFresh() {
    if (result.value) {
      error.value = ''
      stale.value = false
    }
  }

  watch(context.key, () => {
    invalidate(true)
    blockedContextKey.value = null
    error.value = ''
  }, { flush: 'sync' })

  async function preview(candidate: ErpProductTaxPreviewCandidate): Promise<ErpProductTaxProfileResolution | null> {
    if (disposed || pending.value)
      return null
    if (!canPreview.value) {
      error.value = 'Você não tem permissão para pré-visualizar os tributos deste produto nesta empresa.'
      return null
    }
    invalidate(false)
    const attempt = sequence
    const token = context.capture()
    const controller = new AbortController()
    activeController = controller
    pending.value = true
    error.value = ''
    const current = () => !disposed && sequence === attempt && context.isCurrent(token)

    try {
      const response = await api<{ data: ErpProductTaxProfileResolution }>('/erp/products/tax-preview', {
        method: 'POST',
        body: erpProductTaxPreviewPayload(candidate),
        signal: controller.signal
      })
      if (!current())
        return null
      result.value = response.data
      stale.value = false
      return response.data
    } catch (failure) {
      if (!current())
        return null
      const details = apiFailure(failure)
      error.value = details.message
      stale.value = result.value !== null
      if (details.status === 401 || details.status === 403 || (details.status === 409 && details.code === 'company_context_changed')) {
        blockedContextKey.value = context.key.value
        invalidate(false)
      }
      return null
    } finally {
      if (current()) {
        pending.value = false
        if (activeController === controller)
          activeController = null
      }
    }
  }

  onScopeDispose(() => {
    disposed = true
    invalidate(true)
  })

  return { result, pending, error, stale, canPreview, authorityBlocked, preview, clear, markStale, markFresh }
}
