import { apiFailure } from '#shared/utils/access'
import type {
  OfficeErpFailure,
  TrialRegistrationAvailability,
  TrialRegistrationAvailabilityResponse,
  TrialRegistrationInput,
  TrialRegistrationOutcome,
  TrialRegistrationOutcomeResponse,
  TrialRegistrationResponse
} from '#shared/types/officeErpCommercial'

type TrialRegistrationPhase = 'availability' | 'form' | 'submitting' | 'confirmed' | 'recovery'
type TrialRegistrationFailure = OfficeErpFailure & { fieldErrors: Record<string, string[]> }
type PendingTrialRegistration = { key: string, email: string, expires_at: number }

const storageKey = 'office-erp.trial-registration.pending'
const recoveryRetention = 15 * 60 * 1000
const publicAvailabilityMessages: Record<string, string> = {
  trial_capacity_unavailable: 'O escritório atingiu o limite de avaliações disponíveis. Entre em contato com o escritório para saber como continuar.',
  subscription_inactive: 'O escritório responsável não pode iniciar avaliações neste momento. Entre em contato com o escritório.',
  modules_not_allowed: 'Os módulos deste teste não estão disponíveis para o escritório responsável neste momento. Entre em contato com ele.',
  commercial_module_not_allowed: 'Os módulos deste teste não estão disponíveis para o escritório responsável neste momento. Entre em contato com ele.',
  trial_registration_disabled: 'O escritório não está aceitando novos testes neste momento. Entre em contato com ele.'
}

export function useErpTrialRegistration() {
  const { api, refreshCsrf } = useAccessApi()
  const { user, resolved, refreshWithStatus } = useAuth()
  const sessionGeneration = useState('access-session-generation', () => 0)
  const phase = ref<TrialRegistrationPhase>('availability')
  const failure = ref<TrialRegistrationFailure | null>(null)
  const outcome = ref<TrialRegistrationOutcome | null>(null)
  const availability = ref<TrialRegistrationAvailability | null>(null)
  const operationKey = ref('')
  const pending = ref<PendingTrialRegistration | null>(null)
  let requestGeneration = 0
  let recoveryGeneration = 0

  function persistPending(value: PendingTrialRegistration | null) {
    pending.value = value
    try {
      if (typeof window === 'undefined') return
      if (!value) window.sessionStorage.removeItem(storageKey)
      else window.sessionStorage.setItem(storageKey, JSON.stringify(value))
    } catch { /* In-memory recovery remains available if storage is restricted. */ }
  }

  function restorePending(): PendingTrialRegistration | null {
    try {
      if (typeof window === 'undefined') return null
      const raw = window.sessionStorage.getItem(storageKey)
      if (!raw) return null
      const saved = JSON.parse(raw) as Partial<PendingTrialRegistration>
      if (typeof saved.key === 'string' && saved.key.length >= 16 && saved.key.length <= 128
        && typeof saved.email === 'string' && Number.isFinite(saved.expires_at)
        && saved.expires_at! > Date.now() && saved.expires_at! <= Date.now() + recoveryRetention) {
        return { key: saved.key, email: saved.email, expires_at: saved.expires_at! }
      }
    } catch { /* Invalid or unavailable storage is ignored. */ }
    try {
      window.sessionStorage.removeItem(storageKey)
    } catch { /* Ignore storage failures. */ }
    return null
  }

  pending.value = restorePending()
  if (pending.value) {
    operationKey.value = pending.value.key
    phase.value = 'recovery'
  }

  function newOperationKey() {
    operationKey.value = crypto.randomUUID()
    return operationKey.value
  }

  function setFailure(error: unknown) {
    const parsed = apiFailure(error)
    failure.value = {
      code: parsed.code,
      message: parsed.message,
      errors: parsed.errors,
      http_status: parsed.status,
      fieldErrors: parsed.errors ?? {}
    }
    return parsed
  }

  function clearRecovery() {
    persistPending(null)
    operationKey.value = ''
    recoveryGeneration++
  }

  async function load(code: string): Promise<void> {
    const request = ++requestGeneration
    failure.value = null
    phase.value = pending.value ? 'recovery' : 'availability'
    try {
      await refreshWithStatus()
      if (request !== requestGeneration) return
      if (user.value)
        await refreshCsrf()
      if (request !== requestGeneration) return
      const response = await api<TrialRegistrationAvailabilityResponse>(`/erp/trials/${encodeURIComponent(code)}`)
      if (request !== requestGeneration) return
      availability.value = response.data
      if (!pending.value) phase.value = response.data.available ? 'form' : 'availability'
      if (!response.data.available) {
        failure.value = {
          code: 'trial_unavailable',
          message: publicAvailabilityMessages[response.data.reason ?? ''] ?? 'Este link não pode iniciar um teste agora.',
          http_status: 403,
          fieldErrors: {}
        }
      }
    } catch (error) {
      if (request !== requestGeneration) return
      const parsed = setFailure(error)
      phase.value = parsed.status === 410 || parsed.status === 403 ? 'availability' : pending.value ? 'recovery' : 'availability'
    }
  }

  async function submit(input: TrialRegistrationInput): Promise<void> {
    if (phase.value === 'submitting' || phase.value === 'recovery' || phase.value === 'confirmed') return
    const code = (useRoute().params.code ?? '').toString()
    if (!code) {
      failure.value = { code: 'invalid_link', message: 'O link de teste não é válido.', http_status: 410, fieldErrors: {} }
      phase.value = 'availability'
      return
    }

    const request = ++requestGeneration
    const actorAtSubmit = user.value?.id ?? null
    const sessionAtSubmit = sessionGeneration.value
    let registrationCommitted = false
    failure.value = null
    phase.value = 'submitting'
    const key = newOperationKey()
    try {
      const requestBody = { ...input }
      if (actorAtSubmit !== null)
        delete requestBody.password
      const response = await api<TrialRegistrationResponse>(`/erp/trials/${encodeURIComponent(code)}/register`, {
        method: 'POST',
        headers: { 'Idempotency-Key': key },
        body: requestBody
      })
      if (request !== requestGeneration) return
      if ((user.value?.id ?? null) !== actorAtSubmit || sessionGeneration.value !== sessionAtSubmit) {
        input.password = undefined
        operationKey.value = ''
        phase.value = availability.value?.available ? 'form' : 'availability'
        return
      }
      if (!response?.identity || !response.data || response.data.status !== 'trialing')
        throw new Error('O cadastro retornou uma confirmação incompleta.')
      input.password = undefined
      outcome.value = response.data
      registrationCommitted = true
      if (actorAtSubmit === null)
        user.value = response.identity
      resolved.value = true
      sessionGeneration.value++
      persistPending({ key, email: input.email.trim().toLowerCase(), expires_at: Date.now() + recoveryRetention })
      phase.value = 'recovery'
      const refreshed = await refreshWithStatus()
      if (request !== requestGeneration) return
      if (!refreshed.committed || !refreshed.identity) {
        failure.value = {
          code: 'identity_refresh_failed',
          message: 'Seu cadastro foi recebido, mas não foi possível confirmar o acesso. Recupere o resultado sem reenviar sua senha.',
          http_status: 0,
          fieldErrors: {}
        }
        return
      }
      outcome.value = response.data
      phase.value = 'confirmed'
      clearRecovery()
    } catch (error) {
      if (request !== requestGeneration) return
      if (registrationCommitted) {
        input.password = undefined
        failure.value = {
          code: 'identity_refresh_failed',
          message: 'Seu cadastro foi recebido, mas não foi possível confirmar o acesso. Recupere o resultado sem reenviar sua senha.',
          http_status: 0,
          fieldErrors: {}
        }
        persistPending({ key, email: input.email.trim().toLowerCase(), expires_at: Date.now() + recoveryRetention })
        phase.value = 'recovery'
        return
      }
      if (!registrationCommitted && ((user.value?.id ?? null) !== actorAtSubmit || sessionGeneration.value !== sessionAtSubmit)) {
        input.password = undefined
        operationKey.value = ''
        failure.value = null
        phase.value = availability.value?.available ? 'form' : 'availability'
        return
      }
      const parsed = setFailure(error)
      if (parsed.status === 422 || parsed.status === 403 || parsed.status === 410
        || (parsed.status === 409 && ['login_required', 'company_association_required', 'client_association_requires_owner_verification', 'confirm_existing_association_required', 'trial_already_used', 'trial_capacity_unavailable'].includes(parsed.code))) {
        if (parsed.status !== 422 && !['client_association_requires_owner_verification', 'confirm_existing_association_required'].includes(parsed.code))
          input.password = undefined
        operationKey.value = ''
        phase.value = parsed.status === 410 || parsed.status === 403 ? 'availability' : 'form'
        return
      }

      input.password = undefined
      persistPending({ key, email: input.email.trim().toLowerCase(), expires_at: Date.now() + recoveryRetention })
      phase.value = 'recovery'
    }
  }

  async function recover(): Promise<void> {
    const saved = pending.value ?? restorePending()
    if (!saved || saved.expires_at <= Date.now()) {
      clearRecovery()
      phase.value = availability.value?.available ? 'form' : 'availability'
      return
    }
    const request = ++recoveryGeneration
    const key = saved.key
    const actorAtRecovery = user.value?.id ?? null
    const sessionAtRecovery = sessionGeneration.value
    pending.value = saved
    operationKey.value = key
    phase.value = 'recovery'
    failure.value = null
    try {
      const response = await api<TrialRegistrationOutcomeResponse>('/erp/trials/current', {
        headers: { 'Idempotency-Key': key }
      })
      if (request !== recoveryGeneration) return
      if ((user.value?.id ?? null) !== actorAtRecovery || sessionGeneration.value !== sessionAtRecovery) {
        outcome.value = null
        return
      }
      if (!response?.data || response.data.status !== 'trialing')
        throw new Error('O resultado consultado está incompleto.')
      outcome.value = response.data
      const refreshed = await refreshWithStatus()
      if (request !== recoveryGeneration) return
      if (!refreshed.committed || !refreshed.identity) {
        outcome.value = null
        failure.value = {
          code: 'identity_refresh_failed',
          message: 'O teste foi confirmado, mas sua sessão precisa ser renovada para continuar.',
          http_status: 0,
          fieldErrors: {}
        }
        return
      }
      phase.value = 'confirmed'
      clearRecovery()
    } catch (error) {
      if (request !== recoveryGeneration) return
      const parsed = setFailure(error)
      if (parsed.status === 401) {
        failure.value = { ...failure.value!, code: 'login_required', message: 'Entre com o email usado no cadastro para recuperar o teste.', fieldErrors: {} }
      } else if (parsed.status === 404) {
        failure.value = { ...failure.value!, code: 'registration_not_found', message: 'Ainda não encontramos o cadastro confirmado. Tente consultar novamente dentro do prazo.', fieldErrors: {} }
      }
      phase.value = 'recovery'
    }
  }

  return { phase, failure, outcome, availability, pending, operationKey, load, submit, recover, clearRecovery }
}
