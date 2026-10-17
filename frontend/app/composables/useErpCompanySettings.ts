import type { ErpCompanySettingsPayload, ErpCompanySettingsResponse } from '#shared/types/erp-company-settings'

export function useErpCompanySettings() {
  const { api } = useAccessApi()
  const state = useState<ErpCompanySettingsPayload | null>('erp-company-settings', () => null)
  const meta = useState<ErpCompanySettingsResponse['meta'] | null>('erp-company-settings-meta', () => null)
  const pending = useState('erp-company-settings-pending', () => false)

  async function refresh() {
    pending.value = true
    try {
      const response = await api<ErpCompanySettingsResponse>('/erp/company/settings')
      state.value = response.data
      meta.value = response.meta
      return response
    } finally {
      pending.value = false
    }
  }

  async function save(patch: Partial<ErpCompanySettingsPayload>) {
    pending.value = true
    try {
      const response = await api<ErpCompanySettingsResponse>('/erp/company/settings', {
        method: 'PATCH',
        body: patch
      })
      state.value = response.data
      meta.value = response.meta
      return response
    } finally {
      pending.value = false
    }
  }

  return { state, meta, pending, refresh, save }
}
