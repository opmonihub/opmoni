type Establishment = { id: number, status?: string }
type FinancialAccount = { id: number, currency: string, erp_establishment_id: number | null, status?: string }
type CashSession = { id: number, currency: string | null, erp_establishment_id: number | null, status: string, can_operate?: boolean, needs_reconciliation?: boolean }

type FinancialChoices = {
  currency: string
  establishmentId: number | null
  establishmentSelected: boolean
  accountId: number | null
  sessionId: number | null
  method: string
  establishments: Establishment[]
  accounts: FinancialAccount[]
  sessions: CashSession[]
  referencesCurrent: boolean
}

export function erpFinancialChoiceError(choices: FinancialChoices): string | null {
  if (!choices.referencesCurrent) {
    return 'As referências financeiras não estão atualizadas. Atualize as listas antes de registrar.'
  }

  if (choices.establishmentSelected && choices.establishmentId !== null
    && !choices.establishments.some(establishment => establishment.id === choices.establishmentId && establishment.status !== 'inactive')) {
    return 'O estabelecimento selecionado não está mais disponível. Atualize as referências e selecione outro.'
  }

  if (choices.accountId !== null) {
    const account = choices.accounts.find(item => item.id === choices.accountId)
    if (!account || (account.status !== undefined && account.status !== 'active') || account.currency !== choices.currency
      || (account.erp_establishment_id !== null && account.erp_establishment_id !== choices.establishmentId)) {
      return 'A conta financeira selecionada não está disponível para esta moeda e estabelecimento.'
    }
  }

  if (choices.sessionId !== null) {
    const session = choices.sessions.find(item => item.id === choices.sessionId)
    if (choices.method !== 'cash') {
      return 'A sessão de caixa selecionada exige o método dinheiro. Revise a seleção antes de continuar.'
    }
    if (!session || session.status !== 'open' || session.can_operate === false || session.needs_reconciliation
      || session.currency !== choices.currency
      || (choices.establishmentId !== null && session.erp_establishment_id !== choices.establishmentId)) {
      return 'A sessão selecionada não está aberta ou é incompatível com a moeda e o estabelecimento.'
    }
  } else if (choices.method === 'cash') {
    return 'Selecione explicitamente uma sessão de caixa aberta para pagamento em dinheiro.'
  }

  return null
}

export function retainedChoiceItem<T extends { id: number }>(items: T[], selectedId: string, label: (item: T) => string, unavailableLabel: (id: string) => string) {
  if (selectedId === 'none' || items.some(item => String(item.id) === selectedId)) {
    return items.map(item => ({ label: label(item), value: String(item.id) }))
  }

  return [...items.map(item => ({ label: label(item), value: String(item.id) })), {
    label: unavailableLabel(selectedId),
    value: selectedId,
    disabled: true
  }]
}
