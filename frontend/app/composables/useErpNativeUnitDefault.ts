import { computed, onScopeDispose, ref, watch } from 'vue'
import type { Ref } from 'vue'
import type { ErpNativeDefaultStatus, ErpNativeReferenceDefault } from '#shared/types/erpNativeDefaults'
import { apiFailure } from '#shared/utils/access'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'

export function useErpNativeUnitDefault(options: { mode: Readonly<Ref<string>>, unitId: Ref<number | null> }) {
  const { api } = useAccessApi()
  const { user, currentCompany, currentAccount } = useAuth()
  const context = useErpAdministrationContext()
  const pending = ref(false)
  const error = ref('')
  const status = ref<ErpNativeDefaultStatus | 'idle'>('idle')
  const canRead = computed(() => {
    const company = currentCompany.value
    if (!user.value?.id || !company || company.status !== 'active' || !Number.isSafeInteger(company.id) || company.id < 1) return false
    if (company.access_kind === 'delegated') {
      const office = currentAccount.value
      if (office?.status !== 'active' || office.id !== company.delegated_office_id) return false
    } else if (company.access_kind !== 'direct') return false
    return erpCompanyHasPermission(company.permissions, 'erp.units.index') && erpCompanyHasPermission(company.permissions, 'erp.units.show')
  })
  let sequence = 0
  let controller: AbortController | null = null
  let explicit = false
  let disposed = false
  let adoptedId: number | null = null
  let blockedKey: string | null = null
  let ownerKey = context.key.value

  function invalidate() {
    sequence++
    controller?.abort()
    controller = null
    pending.value = false
  }

  function markExplicit() {
    explicit = true
    adoptedId = null
    invalidate()
  }

  async function retry() {
    if (disposed || explicit || options.mode.value !== 'create' || options.unitId.value !== null || !canRead.value || blockedKey === context.key.value) return
    invalidate()
    const attempt = sequence
    const token = context.capture()
    const active = new AbortController()
    controller = active
    const current = () => !disposed && sequence === attempt && context.isCurrent(token) && canRead.value && !explicit && options.mode.value === 'create'
    pending.value = true
    error.value = ''
    try {
      const result = await api<{ meta: { native_default_unit: ErpNativeReferenceDefault } }>('/erp/units?per_page=1', { signal: active.signal })
      if (!current()) return
      const selected = result.meta.native_default_unit
      if (!selected || !['available', 'unset', 'unresolved', 'unavailable'].includes(selected.status)) throw new Error('Resposta de unidade padrão inválida.')
      status.value = selected.status
      if (selected.status === 'available') {
        if (!Number.isSafeInteger(selected.id) || selected.id === null || selected.id < 1 || typeof selected.label !== 'string') throw new Error('Unidade padrão inválida.')
        if (options.unitId.value === null) {
          adoptedId = selected.id
          options.unitId.value = selected.id
        }
      } else {
        error.value = selected.status === 'unresolved'
          ? 'A unidade legada não tem vínculo nativo confirmado. Selecione uma unidade ativa.'
          : selected.status === 'unavailable'
            ? 'A unidade padrão está indisponível. Selecione outra unidade ou tente consultar novamente.'
            : 'Não há unidade padrão configurada. Selecione uma unidade ativa.'
      }
    } catch (failure) {
      if (!current()) return
      const details = apiFailure(failure)
      if ([401, 403].includes(details.status) || (details.status === 409 && details.code === 'company_context_changed')) blockedKey = context.key.value
      status.value = 'unavailable'
      error.value = 'Não foi possível consultar a unidade padrão. Selecione uma unidade ativa ou tente novamente.'
    } finally {
      if (sequence === attempt && controller === active) {
        controller = null
        pending.value = false
      }
    }
  }

  watch([context.key, options.mode, canRead], () => {
    invalidate()
    error.value = ''
    status.value = 'idle'
    if (ownerKey !== context.key.value) {
      if (adoptedId !== null && options.unitId.value === adoptedId) options.unitId.value = null
      adoptedId = null
      ownerKey = context.key.value
      blockedKey = null
    }
    explicit = false
    void retry()
  }, { immediate: true, flush: 'sync' })

  onScopeDispose(() => {
    disposed = true
    invalidate()
  })

  return { pending, error, status, retry, markExplicit }
}
