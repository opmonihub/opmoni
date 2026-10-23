import type { ErpCompanySettingsPayload, ErpCompanySettingsResponse } from '#shared/types/erp-company-settings'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'

export function useErpCompanySettings() {
  const { api } = useAccessApi()
  const { user, currentCompany, currentAccount } = useAuth()
  const context = useErpAdministrationContext()
  const state = useState<ErpCompanySettingsPayload | null>('erp-company-settings', () => null)
  const meta = useState<ErpCompanySettingsResponse['meta'] | null>('erp-company-settings-meta', () => null)
  const pending = useState('erp-company-settings-pending', () => false)
  const ownerKey = useState('erp-company-settings-context', () => context.key.value)
  const sequence = useState('erp-company-settings-sequence', () => 0)
  const blockedKey = useState<string | null>('erp-company-settings-blocked', () => null)

  function hasPermission(permission: string, write: boolean) {
    const company = currentCompany.value
    if (!user.value?.id || company?.status !== 'active' || blockedKey.value === context.key.value) return false
    if (company.access_kind === 'delegated') {
      const office = currentAccount.value
      if (office?.status !== 'active' || office.id !== company.delegated_office_id || (write && !['admin', 'operador'].includes(office.role))) return false
    } else if (company.access_kind !== 'direct' || (write && !['owner', 'manager', 'operator'].includes(company.role))) return false
    return erpCompanyHasPermission(company.permissions, permission)
  }
  const canRead = computed(() => hasPermission('erp.company.settings.show', false))
  const canWrite = computed(() => hasPermission('erp.company.settings.update', true))

  function clear() {
    sequence.value++
    state.value = null
    meta.value = null
    pending.value = false
  }
  watch(context.key, (key) => {
    if (ownerKey.value === key) return
    ownerKey.value = key
    blockedKey.value = null
    clear()
  }, { flush: 'sync', immediate: true })

  let ownedAttempt = 0
  onScopeDispose(() => {
    if (pending.value && sequence.value === ownedAttempt) {
      sequence.value++
      pending.value = false
    }
  })

  async function request(patch?: Partial<ErpCompanySettingsPayload>) {
    if (pending.value || (patch ? !canWrite.value : !canRead.value)) return null
    const token = context.capture()
    const attempt = ++sequence.value
    ownedAttempt = attempt
    const current = () => context.isCurrent(token) && attempt === sequence.value
    pending.value = true
    try {
      const response = await api<ErpCompanySettingsResponse>('/erp/company/settings', patch ? { method: 'PATCH', body: patch } : undefined)
      if (!current()) return null
      state.value = response.data
      meta.value = response.meta
      return response
    } catch (error) {
      if (!current()) return null
      const failure = error as { status?: number, statusCode?: number, data?: { code?: string }, response?: { status?: number, _data?: { code?: string } } }
      const status = failure.status ?? failure.statusCode ?? failure.response?.status ?? 0
      if ([401, 403].includes(status) || (status === 409 && (failure.data ?? failure.response?._data)?.code === 'company_context_changed')) {
        blockedKey.value = context.key.value
        clear()
      }
      throw error
    } finally {
      if (current()) pending.value = false
    }
  }
  const refresh = () => request()
  const save = (patch: Partial<ErpCompanySettingsPayload>) => request(patch)
  return { state, meta, pending, canRead, canWrite, authorityKey: context.key, refresh, save }
}
