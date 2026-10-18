import type { NavigationMenuItem } from '@nuxt/ui'

/**
 * Permissões granulares de empresa ERP expostas em `/me` (`current_company.permissions`).
 * `erp.read` cobre leitura operacional até grants específicos existirem; `*` vem de owner/manager.
 */
export const erpPermissions = {
  read: 'erp.read',
  settingsManage: 'erp.settings.manage',
  catalogRead: 'erp.catalog.read',
  catalogWrite: 'erp.catalog.write',
  financeRead: 'erp.finance.read',
  financeWrite: 'erp.finance.write',
  cashRead: 'erp.cash.read',
  cashWrite: 'erp.cash.write',
  purchasesRead: 'erp.purchases.read',
  purchasesWrite: 'erp.purchases.write',
  stockRead: 'erp.stock.read',
  stockWrite: 'erp.stock.write',
  salesRead: 'erp.sales.read',
  salesWrite: 'erp.sales.write',
  fiscalRead: 'erp.fiscal.read',
  fiscalWrite: 'erp.fiscal.write',
  accountingRead: 'erp.accounting.read',
  accountingRetry: 'erp.accounting.retry',
  reportsRead: 'erp.reports.read',
  reportsExport: 'erp.reports.export'
} as const satisfies Record<string, string>

export type ErpPermission = typeof erpPermissions[keyof typeof erpPermissions]

export interface ErpNavModule {
  id: string
  label: string
  icon: string
  to: string
  readPermission: ErpPermission
  exact?: boolean
}

export interface ErpModuleTab {
  id: string
  label: string
  /** Caminho relativo `/api/v1` via `useAccessApi` (sem prefixo). */
  apiPath: string
  mode?: 'report' | 'xml-preview'
  emptyTitle: string
  emptyDescription: string
}

export interface ErpModulePageContract {
  id: string
  title: string
  description: string
  icon: string
  to: string
  readPermission: ErpPermission
  writePermission: ErpPermission
  tabs: readonly ErpModuleTab[]
  referenceNote: string
}

export const erpNavModules: readonly ErpNavModule[] = [
  { id: 'overview', label: 'Visão geral', icon: 'i-lucide-layout-dashboard', to: '/erp', readPermission: erpPermissions.read, exact: true },
  { id: 'cadastros', label: 'Cadastros', icon: 'i-lucide-library-big', to: '/erp/cadastros', readPermission: erpPermissions.catalogRead },
  { id: 'financeiro', label: 'Financeiro', icon: 'i-lucide-landmark', to: '/erp/financeiro', readPermission: erpPermissions.financeRead },
  { id: 'caixa', label: 'Caixa', icon: 'i-lucide-banknote', to: '/erp/caixa', readPermission: erpPermissions.cashRead },
  { id: 'compras', label: 'Compras', icon: 'i-lucide-shopping-bag', to: '/erp/compras', readPermission: erpPermissions.purchasesRead },
  { id: 'estoque', label: 'Estoque', icon: 'i-lucide-warehouse', to: '/erp/estoque', readPermission: erpPermissions.stockRead },
  { id: 'vendas', label: 'Vendas', icon: 'i-lucide-shopping-cart', to: '/erp/vendas', readPermission: erpPermissions.salesRead },
  { id: 'fiscal', label: 'Fiscal', icon: 'i-lucide-file-badge', to: '/erp/fiscal', readPermission: erpPermissions.fiscalRead },
  { id: 'contabilidade', label: 'Contabilidade', icon: 'i-lucide-send', to: '/erp/contabilidade', readPermission: erpPermissions.accountingRead },
  { id: 'relatorios', label: 'Relatórios', icon: 'i-lucide-chart-column', to: '/erp/relatorios', readPermission: erpPermissions.reportsRead },
  { id: 'configuracoes', label: 'Configurações', icon: 'i-lucide-settings', to: '/erp/configuracoes', readPermission: erpPermissions.read }
]

export const erpFinanceiroContract: ErpModulePageContract = {
  id: 'financeiro',
  title: 'Financeiro',
  description: 'Contas, obrigações e pagamentos com saldos derivados de lançamentos imutáveis.',
  icon: 'i-lucide-landmark',
  to: '/erp/financeiro',
  readPermission: erpPermissions.financeRead,
  writePermission: erpPermissions.financeWrite,
  referenceNote: 'AccountController, Expense/Revenue, TransactionPayment (referência sistema).',
  tabs: [
    {
      id: 'accounts',
      label: 'Contas',
      apiPath: '/erp/financial-accounts',
      emptyTitle: 'Nenhuma conta financeira',
      emptyDescription: 'Cadastre contas ERP separadas do escritório quando a API estiver ativa.'
    },
    {
      id: 'obligations',
      label: 'Obrigações',
      apiPath: '/erp/obligations',
      emptyTitle: 'Nenhuma obrigação',
      emptyDescription: 'Recebíveis e pagáveis aparecem aqui após lançamentos confirmados.'
    },
    {
      id: 'payments',
      label: 'Pagamentos',
      apiPath: '/erp/payments',
      emptyTitle: 'Nenhum pagamento',
      emptyDescription: 'Alocações e estornos ficam registrados sem apagar histórico.'
    }
  ]
}

export const erpCaixaContract: ErpModulePageContract = {
  id: 'caixa',
  title: 'Caixa',
  description: 'Caixas registradores e sessões com abertura, movimentos e fechamento reconciliado.',
  icon: 'i-lucide-banknote',
  to: '/erp/caixa',
  readPermission: erpPermissions.cashRead,
  writePermission: erpPermissions.cashWrite,
  referenceNote: 'CashRegisterController (referência sistema).',
  tabs: [
    {
      id: 'registers',
      label: 'Caixas',
      apiPath: '/erp/cash-registers',
      emptyTitle: 'Nenhum caixa',
      emptyDescription: 'Configure caixas por estabelecimento quando o módulo for liberado.'
    },
    {
      id: 'sessions',
      label: 'Sessões',
      apiPath: '/erp/cash-sessions',
      emptyTitle: 'Nenhuma sessão',
      emptyDescription: 'Sessões abertas e fechadas aparecem com saldo esperado e conferência.'
    }
  ]
}

export const erpComprasContract: ErpModulePageContract = {
  id: 'compras',
  title: 'Compras',
  description: 'Entradas de fornecedor, prévia de XML e devoluções com confirmação explícita.',
  icon: 'i-lucide-shopping-bag',
  to: '/erp/compras',
  readPermission: erpPermissions.purchasesRead,
  writePermission: erpPermissions.purchasesWrite,
  referenceNote: 'PurchaseController, PurchaseReturnController, PurchaseXmlController.',
  tabs: [
    {
      id: 'purchases',
      label: 'Compras',
      apiPath: '/erp/purchases',
      emptyTitle: 'Nenhuma compra',
      emptyDescription: 'Rascunhos e documentos lançados ficam separados por status.'
    },
    {
      id: 'xml-preview',
      label: 'Prévia XML',
      apiPath: '/erp/purchases/xml-preview',
      mode: 'xml-preview',
      emptyTitle: 'Nenhuma prévia',
      emptyDescription: 'Envie XML de entrada para validar duplicidade antes de confirmar.'
    },
    {
      id: 'returns',
      label: 'Devoluções',
      apiPath: '/erp/purchase-returns',
      emptyTitle: 'Nenhuma devolução',
      emptyDescription: 'Devoluções referenciam quantidades já recebidas.'
    }
  ]
}

export const erpEstoqueContract: ErpModulePageContract = {
  id: 'estoque',
  title: 'Estoque',
  description: 'Saldos, movimentações, ajustes e transferências com política FIFO/LIFO da empresa.',
  icon: 'i-lucide-warehouse',
  to: '/erp/estoque',
  readPermission: erpPermissions.stockRead,
  writePermission: erpPermissions.stockWrite,
  referenceNote: 'StockTransferController, StockAdjustmentController, OpeningStock.',
  tabs: [
    {
      id: 'balances',
      label: 'Saldos',
      apiPath: '/erp/stock/balances',
      emptyTitle: 'Nenhum saldo',
      emptyDescription: 'Saldos por estabelecimento e variante aparecem após movimentações.'
    },
    {
      id: 'movements',
      label: 'Movimentações',
      apiPath: '/erp/stock/movements',
      emptyTitle: 'Nenhuma movimentação',
      emptyDescription: 'Histórico imutável com origem e operação de estoque.'
    },
    {
      id: 'adjustments',
      label: 'Ajustes',
      apiPath: '/erp/stock/adjustments',
      emptyTitle: 'Nenhum ajuste',
      emptyDescription: 'Ajustes exigem permissão, motivo e actor registrados.'
    },
    {
      id: 'transfers',
      label: 'Transferências',
      apiPath: '/erp/stock/transfers',
      emptyTitle: 'Nenhuma transferência',
      emptyDescription: 'Transferências balanceiam saída e entrada no mesmo commit.'
    }
  ]
}

export const erpVendasContract: ErpModulePageContract = {
  id: 'vendas',
  title: 'Vendas',
  description: 'Orçamentos, pedidos, balcão/POS e devoluções com status fiscal distinto do comercial.',
  icon: 'i-lucide-shopping-cart',
  to: '/erp/vendas',
  readPermission: erpPermissions.salesRead,
  writePermission: erpPermissions.salesWrite,
  referenceNote: 'SellController, SellPosController, SellReturnController.',
  tabs: [
    {
      id: 'quotes',
      label: 'Orçamentos',
      apiPath: '/erp/quotes',
      emptyTitle: 'Nenhum orçamento',
      emptyDescription: 'Orçamentos não alteram saldos até confirmação.'
    },
    {
      id: 'orders',
      label: 'Pedidos',
      apiPath: '/erp/orders',
      emptyTitle: 'Nenhum pedido',
      emptyDescription: 'Pedidos confirmados preservam quantidades solicitadas.'
    },
    {
      id: 'sales',
      label: 'Vendas / balcão',
      apiPath: '/erp/sales',
      emptyTitle: 'Nenhuma venda',
      emptyDescription: 'Vendas lançadas consomem estoque e financeiro no mesmo commit.'
    },
    {
      id: 'returns',
      label: 'Devoluções',
      apiPath: '/erp/sale-returns',
      emptyTitle: 'Nenhuma devolução',
      emptyDescription: 'Devoluções limitam-se às linhas ainda retornáveis.'
    }
  ]
}

export const erpFiscalContract: ErpModulePageContract = {
  id: 'fiscal',
  title: 'Fiscal',
  description: 'Emissão NF-e, NFC-e, CT-e, MDF-e, certificados, eventos e inutilização com estados explícitos.',
  icon: 'i-lucide-file-badge',
  to: '/erp/fiscal',
  readPermission: erpPermissions.fiscalRead,
  writePermission: erpPermissions.fiscalWrite,
  referenceNote: 'NfeController, NfceController, CteController, MdfeController, InutilController.',
  tabs: [
    { id: 'nfe', label: 'NF-e', apiPath: '/erp/fiscal/documents?model=55', emptyTitle: 'Nenhuma NF-e', emptyDescription: 'Rascunhos e autorizadas ficam separadas por status de transmissão.' },
    { id: 'nfce', label: 'NFC-e', apiPath: '/erp/fiscal/documents?model=65', emptyTitle: 'Nenhuma NFC-e', emptyDescription: 'Contingência exige reconciliação posterior; nunca simula autorização.' },
    { id: 'cte', label: 'CT-e', apiPath: '/erp/fiscal/documents?model=57', emptyTitle: 'Nenhum CT-e', emptyDescription: 'Complemento e substituição permanecem ações explícitas.' },
    { id: 'mdfe', label: 'MDF-e', apiPath: '/erp/fiscal/documents?model=58', emptyTitle: 'Nenhum MDF-e', emptyDescription: 'Encerramento e eventos seguem o modelo de transporte.' },
    { id: 'certificates', label: 'Certificados', apiPath: '/erp/fiscal/certificates', emptyTitle: 'Nenhum certificado', emptyDescription: 'Metadados seguros; bytes e senhas nunca entram no navegador.' },
    { id: 'events', label: 'Eventos', apiPath: '/erp/fiscal/events', emptyTitle: 'Nenhum evento', emptyDescription: 'Histórico append-only de tentativas e retornos.' },
    { id: 'invalidations', label: 'Inutilização', apiPath: '/erp/fiscal/invalidations', emptyTitle: 'Nenhuma inutilização', emptyDescription: 'Faixas inutilizadas com justificativa e permissão.' }
  ]
}

export const erpContabilidadeContract: ErpModulePageContract = {
  id: 'contabilidade',
  title: 'Contabilidade',
  description: 'Entrega de XML autorizado ao escritório delegado, com retry limitado e motivos auditáveis.',
  icon: 'i-lucide-send',
  to: '/erp/contabilidade',
  readPermission: erpPermissions.accountingRead,
  writePermission: erpPermissions.accountingRetry,
  referenceNote: 'Entrega ERP → escritório (change 8); captura incoming permanece no Fiscal Opmoni.',
  tabs: [
    {
      id: 'deliveries',
      label: 'Entregas',
      apiPath: '/erp/accounting-deliveries',
      emptyTitle: 'Nenhuma entrega',
      emptyDescription: 'Projeções aparecem após commit fiscal autorizado e delegação válida.'
    }
  ]
}

export const erpRelatoriosContract: ErpModulePageContract = {
  id: 'relatorios',
  title: 'Relatórios',
  description: 'Relatórios reconciliados aos ledgers lançados; rascunhos e tentativas fiscais indeterminadas não inflam totais.',
  icon: 'i-lucide-chart-column',
  to: '/erp/relatorios',
  readPermission: erpPermissions.reportsRead,
  writePermission: erpPermissions.reportsExport,
  referenceNote: 'ReportController, AccountReportsController, HomeController (referência sistema).',
  tabs: [
    { id: 'sales', label: 'Vendas', apiPath: '/erp/reports/sales', mode: 'report', emptyTitle: 'Sem dados de vendas', emptyDescription: 'Agregados usam snapshots e lançamentos confirmados.' },
    { id: 'purchases', label: 'Compras', apiPath: '/erp/reports/purchases', mode: 'report', emptyTitle: 'Sem dados de compras', emptyDescription: 'Compras lançadas e devoluções compensatórias entram nos filtros.' },
    { id: 'stock', label: 'Estoque', apiPath: '/erp/reports/stock', mode: 'report', emptyTitle: 'Sem dados de estoque', emptyDescription: 'Quantidades e custos seguem movimentos registrados.' },
    { id: 'finance', label: 'Financeiro', apiPath: '/erp/reports/financial', mode: 'report', emptyTitle: 'Sem dados financeiros', emptyDescription: 'Recebíveis, pagáveis e aging derivam de obrigações postadas.' },
    { id: 'fiscal', label: 'Fiscal', apiPath: '/erp/reports/fiscal', mode: 'report', emptyTitle: 'Sem dados fiscais', emptyDescription: 'Status de autorização separado de rascunhos e indeterminados.' },
    { id: 'dashboard', label: 'Integrado', apiPath: '/erp/reports/overview', mode: 'report', emptyTitle: 'Painel indisponível', emptyDescription: 'Indicadores combinados respeitam os mesmos filtros de período.' }
  ]
}

export const erpModulePageContracts: readonly ErpModulePageContract[] = [
  erpFinanceiroContract,
  erpCaixaContract,
  erpComprasContract,
  erpEstoqueContract,
  erpVendasContract,
  erpFiscalContract,
  erpContabilidadeContract,
  erpRelatoriosContract
]

const effectiveReadFamilies: Partial<Record<ErpPermission, readonly string[]>> = {
  [erpPermissions.catalogRead]: ['contacts', 'products', 'units', 'categories', 'brands', 'price-groups', 'discount-rules', 'tax-rates', 'operation-natures', 'carriers', 'vehicles', 'drivers', 'catalog'],
  [erpPermissions.financeRead]: ['financial-accounts', 'obligations', 'payments'],
  [erpPermissions.cashRead]: ['cash-registers', 'cash-sessions'],
  [erpPermissions.purchasesRead]: ['purchases', 'purchase-returns'],
  [erpPermissions.stockRead]: ['stock'],
  [erpPermissions.salesRead]: ['quotes', 'orders', 'sales', 'sale-returns'],
  [erpPermissions.fiscalRead]: ['fiscal'],
  [erpPermissions.accountingRead]: ['accounting-deliveries'],
  [erpPermissions.reportsRead]: ['reports']
}

export function erpCompanyHasPermission(grants: readonly string[] | undefined, permission: ErpPermission | string): boolean {
  if (!grants?.length)
    return false
  if (grants.includes(permission))
    return true
  const family = permission.replace(/\.[^.]+$/, '')
  const isReadAction = /\.(index|show|read|xml|pdf)$/.test(permission)
  const actionScope = isReadAction ? 'read' : 'write'
  if ([
    '*',
    `${family}.*`,
    `${family}.${actionScope}`,
    isReadAction ? erpPermissions.read : 'erp.write'
  ].some(grant => grants.includes(grant)))
    return true
  const families = effectiveReadFamilies[permission as ErpPermission]
  return !!families && grants.some(grant => /\.(index|show|xml|pdf)$/.test(grant)
    && families.some(family => grant.startsWith(`erp.${family}.`)))
}

export function erpVisibleNavModules(grants: readonly string[] | undefined): readonly ErpNavModule[] {
  return erpNavModules.filter(module => erpCompanyHasPermission(grants, module.readPermission))
}

export function erpModuleActive(path: string, module: Pick<ErpNavModule, 'to' | 'exact'>): boolean {
  if (module.exact)
    return path === module.to
  return path === module.to || path.startsWith(`${module.to}/`)
}

/** Filhos da lateral ERP (operacional + cadastros + configurações). */
export function erpModuleSidebarChildren(path: string, grants: readonly string[] | undefined): NavigationMenuItem[] {
  return erpVisibleNavModules(grants).map(module => ({
    label: module.label,
    to: module.to,
    exact: module.exact ?? false,
    active: erpModuleActive(path, module)
  }))
}

export function erpModuleTabs(contract: ErpModulePageContract, path: string): NavigationMenuItem[][] {
  return [[
    ...contract.tabs.map(tab => ({
      label: tab.label,
      to: `${contract.to}#${tab.id}`,
      exact: false,
      active: path.startsWith(contract.to) && (path === contract.to || path === `${contract.to}/`)
    }))
  ]]
}

export function erpModuleNavbarTitle(path: string): string {
  const module = erpNavModules.find(item => erpModuleActive(path, item))
  return module?.label ?? 'ERP'
}

export function erpModuleContractById(id: string): ErpModulePageContract | undefined {
  return erpModulePageContracts.find(contract => contract.id === id)
}

export interface ErpPaginatedList {
  data: Record<string, unknown>[]
  meta?: {
    current_page?: number
    last_page?: number
    per_page?: number
    total?: number
  }
}

export interface ErpReportPayload {
  category: string
  filters: Record<string, unknown>
  totals?: Record<string, string | number>
  sections?: Record<string, Record<string, string | number>>
}

export function erpListTotal(payload: ErpPaginatedList | null | undefined): number | null {
  if (!payload?.meta || typeof payload.meta.total !== 'number')
    return null
  return payload.meta.total
}
