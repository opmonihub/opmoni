export type OfficeErpContractAmendmentAction = 'modules.expand' | 'contract.amend.modules.reduce' | 'contract.amend.price'

/** Keep operation identity aligned with the actual set difference accepted by the contract API. */
export function officeErpContractAmendmentAction(currentModules: string[], nextModules: string[]): OfficeErpContractAmendmentAction {
  if (nextModules.some(module => !currentModules.includes(module))) return 'modules.expand'
  if (currentModules.some(module => !nextModules.includes(module))) return 'contract.amend.modules.reduce'
  return 'contract.amend.price'
}

export function formatOfficeErpCompanyDate(value: string): string {
  const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(value)
  const parsed = dateOnly ? new Date(`${value}T12:00:00Z`) : new Date(value)
  return Number.isFinite(parsed.getTime())
    ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeZone: 'America/Sao_Paulo' }).format(parsed)
    : 'Data indisponível'
}

export function officeErpContractStatusLabel(value: string): string {
  return ({
    accepted: 'Aceita · aguardando ativação',
    effective: 'Vigente',
    canceled: 'Cancelada'
  } as Record<string, string>)[value] ?? 'Situação indisponível'
}

export function officeErpTrialStatusLabel(value: string): string {
  return ({
    trialing: 'Em período de teste',
    expired: 'Período de teste encerrado',
    converted: 'Convertido em assinatura'
  } as Record<string, string>)[value] ?? 'Situação indisponível'
}
