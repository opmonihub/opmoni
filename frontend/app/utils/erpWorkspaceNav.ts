import type { CommandPaletteGroup, CommandPaletteItem, NavigationMenuItem } from '@nuxt/ui'
import type { CompanyMembership } from '../../shared/types/access.ts'
import { erpCadastrosPages } from '#shared/utils/erpCatalogs'
import {
  erpCompanyHasPermission,
  erpModulePageContracts,
  erpNavModules,
  type ErpModulePageContract
} from './erpModulesNav.ts'

export interface ErpWorkspaceNavDestination {
  id: string
  label: string
  icon?: string
  to: string
  readPermissions?: readonly string[]
  ownerOnly?: boolean
  directOwnerOnly?: boolean
}

export interface ErpWorkspaceNavGroup {
  id: string
  label: string
  icon: string
  destinations: readonly ErpWorkspaceNavDestination[]
}

const erpSidebarModuleGroups = [
  ['overview', 'vendas', 'compras', 'estoque', 'financeiro', 'caixa', 'fiscal', 'contabilidade', 'relatorios'],
  ['cadastros', 'configuracoes']
] as const

export const erpCatalogNavigationGroups: readonly ErpWorkspaceNavGroup[] = [
  {
    id: 'cadastros-relacionamentos',
    label: 'Relacionamentos',
    icon: 'i-lucide-users-round',
    destinations: [
      { id: 'contacts', label: 'Contatos', to: erpCadastrosPages.contacts, readPermissions: ['erp.contacts.index'] },
      { id: 'customer-groups', label: 'Grupos de clientes', to: erpCadastrosPages.customerGroups, readPermissions: ['erp.customer-groups.index'] }
    ]
  },
  {
    id: 'cadastros-produtos',
    label: 'Produtos',
    icon: 'i-lucide-package',
    destinations: [
      { id: 'products', label: 'Produtos', to: erpCadastrosPages.products, readPermissions: ['erp.products.index'] },
      { id: 'units', label: 'Unidades', to: erpCadastrosPages.units, readPermissions: ['erp.units.index'] },
      { id: 'brands', label: 'Marcas', to: erpCadastrosPages.brands, readPermissions: ['erp.brands.index'] },
      { id: 'categories', label: 'Categorias', to: erpCadastrosPages.categories, readPermissions: ['erp.categories.index'] },
      { id: 'pricing', label: 'Preços e descontos', to: erpCadastrosPages.pricing, readPermissions: ['erp.price-groups.index', 'erp.discount-rules.index'] }
    ]
  },
  {
    id: 'cadastros-fiscal',
    label: 'Fiscal',
    icon: 'i-lucide-receipt',
    destinations: [
      { id: 'fiscal', label: 'Referências fiscais', to: erpCadastrosPages.fiscal, readPermissions: ['erp.tax-rates.index', 'erp.operation-natures.index'] }
    ]
  },
  {
    id: 'cadastros-logistica',
    label: 'Logística',
    icon: 'i-lucide-truck',
    destinations: [
      { id: 'logistics', label: 'Logística', to: erpCadastrosPages.logistics, readPermissions: ['erp.carriers.index', 'erp.vehicles.index', 'erp.drivers.index'] },
      { id: 'import', label: 'Importação', to: erpCadastrosPages.import, readPermissions: ['erp.catalog.import.products.preview', 'erp.catalog.import.products.confirm', 'erp.catalog.import.contacts.preview', 'erp.catalog.import.contacts.confirm'] },
      { id: 'labels', label: 'Etiquetas', to: erpCadastrosPages.labels, readPermissions: ['erp.products.index', 'erp.catalog.print-settings.show', 'erp.catalog.label-layouts.index', 'erp.catalog.printer-profiles.index'] }
    ]
  }
]

export const erpSettingsNavigationGroups: readonly ErpWorkspaceNavGroup[] = [
  {
    id: 'configuracoes-empresa',
    label: 'Empresa',
    icon: 'i-lucide-building-2',
    destinations: [
      { id: 'company', label: 'Empresa', to: '/erp/configuracoes/empresa', readPermissions: ['erp.company.settings.show'] },
      { id: 'establishments', label: 'Estabelecimentos', icon: 'i-lucide-map-pin-house', to: '/erp/configuracoes/estabelecimentos', readPermissions: ['erp.establishments.index'] }
    ]
  },
  {
    id: 'configuracoes-operacao',
    label: 'Operação',
    icon: 'i-lucide-sliders-horizontal',
    destinations: [
      { id: 'taxes', label: 'Taxas', to: '/erp/configuracoes/taxas', readPermissions: ['erp.company.settings.show'] },
      { id: 'product', label: 'Produto', to: '/erp/configuracoes/produto', readPermissions: ['erp.company.settings.show'] },
      { id: 'sales', label: 'Vendas', to: '/erp/configuracoes/vendas', readPermissions: ['erp.company.settings.show'] },
      { id: 'purchases', label: 'Compras', to: '/erp/configuracoes/compras', readPermissions: ['erp.company.settings.show'] }
    ]
  },
  {
    id: 'configuracoes-sistema',
    label: 'Sistema',
    icon: 'i-lucide-cog',
    destinations: [
      { id: 'system', label: 'Sistema', to: '/erp/configuracoes/sistema', readPermissions: ['erp.company.settings.show'] },
      { id: 'prefixes', label: 'Prefixos', to: '/erp/configuracoes/prefixos', readPermissions: ['erp.company.settings.show'] },
      { id: 'email', label: 'E-mail', to: '/erp/configuracoes/email', readPermissions: ['erp.company.settings.show'] },
      { id: 'sms', label: 'SMS', to: '/erp/configuracoes/sms', readPermissions: ['erp.company.settings.show'] },
      { id: 'points', label: 'Pontos', to: '/erp/configuracoes/pontos', readPermissions: ['erp.company.settings.show'] },
      { id: 'modules', label: 'Módulos', to: '/erp/configuracoes/modulos', readPermissions: ['erp.subscription.show', 'erp.read'] },
      { id: 'labels', label: 'Rótulos', to: '/erp/configuracoes/rotulos', readPermissions: ['erp.company.settings.show'] }
    ]
  },
  {
    id: 'configuracoes-administracao',
    label: 'Administração',
    icon: 'i-lucide-users',
    destinations: [
      { id: 'members', label: 'Membros', to: '/erp/configuracoes/membros', ownerOnly: true, directOwnerOnly: true },
      { id: 'office', label: 'Escritório', to: '/erp/configuracoes/escritorio', ownerOnly: true, directOwnerOnly: true }
    ]
  }
]

function companyCanSeeDestination(company: CompanyMembership | null, destination: ErpWorkspaceNavDestination): boolean {
  if (!company || company.status !== 'active')
    return false

  if (destination.directOwnerOnly
    && (company.access_kind !== 'direct' || company.role !== 'owner'))
    return false

  if (destination.ownerOnly && company.role !== 'owner')
    return false

  if (destination.readPermissions?.length
    && !destination.readPermissions.some(permission => erpCompanyHasPermission(company.permissions, permission)))
    return false

  return true
}
function destinationItems(
  groups: readonly ErpWorkspaceNavGroup[],
  path: string,
  company: CompanyMembership | null,
  onDestinationSelect?: () => void
): NavigationMenuItem[][] {
  return groups.flatMap((group) => {
    const children = group.destinations
      .filter(destination => companyCanSeeDestination(company, destination))
      .map(destination => ({
        label: destination.label,
        to: destination.to,
        active: path === destination.to || path.startsWith(`${destination.to}/`),
        onSelect: onDestinationSelect
      }))
    if (!children.length)
      return []

    return [[{
      label: group.label,
      icon: group.icon,
      type: 'trigger',
      value: group.id,
      active: children.some(child => child.active),
      defaultOpen: children.some(child => child.active),
      children
    } satisfies NavigationMenuItem]]
  })
}

export function erpWorkspaceCatalogGroups(
  path: string,
  company: CompanyMembership | null,
  onDestinationSelect?: () => void
): NavigationMenuItem[][] {
  return destinationItems(erpCatalogNavigationGroups, path, company, onDestinationSelect)
}

export function erpWorkspaceSettingsGroups(
  path: string,
  company: CompanyMembership | null,
  onDestinationSelect?: () => void
): NavigationMenuItem[][] {
  if (!company || company.status !== 'active')
    return []
  return destinationItems(erpSettingsNavigationGroups, path, company, onDestinationSelect)
}

const moduleTabReadPermissions: Record<string, string> = {
  'financeiro:accounts': 'erp.financial-accounts.index',
  'financeiro:obligations': 'erp.obligations.index',
  'financeiro:payments': 'erp.payments.index',
  'caixa:registers': 'erp.cash-registers.index',
  'caixa:sessions': 'erp.cash-sessions.index',
  'compras:purchases': 'erp.purchases.index',
  'compras:xml-preview': 'erp.purchases.xml-preview',
  'compras:returns': 'erp.purchase-returns.index',
  'estoque:balances': 'erp.stock.balances.index',
  'estoque:movements': 'erp.stock.movements.index',
  'estoque:adjustments': 'erp.stock.adjustments.index',
  'estoque:transfers': 'erp.stock.transfers.index',
  'vendas:quotes': 'erp.quotes.index',
  'vendas:orders': 'erp.orders.index',
  'vendas:sales': 'erp.sales.index',
  'vendas:returns': 'erp.sale-returns.index',
  'fiscal:nfe': 'erp.fiscal.documents.index',
  'fiscal:nfce': 'erp.fiscal.documents.index',
  'fiscal:cte': 'erp.fiscal.documents.index',
  'fiscal:mdfe': 'erp.fiscal.documents.index',
  'fiscal:certificates': 'erp.fiscal.certificates.index',
  'fiscal:events': 'erp.fiscal.events.index',
  'fiscal:invalidations': 'erp.fiscal.invalidations.index',
  'contabilidade:deliveries': 'erp.accounting-deliveries.index',
  'relatorios:sales': 'erp.reports.show',
  'relatorios:purchases': 'erp.reports.show',
  'relatorios:stock': 'erp.reports.show',
  'relatorios:finance': 'erp.reports.show',
  'relatorios:fiscal': 'erp.reports.show',
  'relatorios:dashboard': 'erp.reports.show'
}

function moduleContract(contractId: string): ErpModulePageContract | undefined {
  return erpModulePageContracts.find(contract => contract.id === contractId)
}

function moduleChildren(
  contract: ErpModulePageContract,
  path: string,
  hash: string,
  company: CompanyMembership | null,
  onDestinationSelect?: () => void
): NavigationMenuItem[] {
  const requestedTab = hash.replace(/^#/, '')
  const requestedTabExists = contract.tabs.some(tab => tab.id === requestedTab)
  const defaultTab = contract.id === 'financeiro' ? 'obligations' : contract.tabs[0]?.id
  const activeTab = requestedTabExists ? requestedTab : defaultTab

  return contract.tabs
    .filter((tab) => {
      const permission = moduleTabReadPermissions[`${contract.id}:${tab.id}`]
      return !!permission && !!company && erpCompanyHasPermission(company.permissions, permission)
    })
    .map(tab => ({
      label: tab.label,
      to: `${contract.to}#${tab.id}`,
      active: path === contract.to && activeTab === tab.id,
      onSelect: onDestinationSelect
    }))
}

export function erpWorkspaceSidebarItems(
  path: string,
  company: CompanyMembership | null,
  onDestinationSelect?: () => void,
  hash = ''
): NavigationMenuItem[][] {
  if (!company || company.status !== 'active')
    return []

  const items = new Map<string, NavigationMenuItem>()
  for (const module of erpNavModules) {
    const contract = moduleContract(module.id)
    let children: NavigationMenuItem[] = []
    if (module.id === 'cadastros')
      children = erpWorkspaceCatalogGroups(path, company, onDestinationSelect).flatMap(group => group[0]?.children ?? [])
    else if (module.id === 'configuracoes')
      children = erpWorkspaceSettingsGroups(path, company, onDestinationSelect).flatMap(group => group[0]?.children ?? [])
    else {
      if (contract && contract.tabs.length > 1)
        children = moduleChildren(contract, path, hash, company, onDestinationSelect)
    }

    if (module.id === 'configuracoes') {
      if (!children.length)
        continue
    } else if (module.id === 'cadastros') {
      if (!children.length && !erpCompanyHasPermission(company.permissions, module.readPermission))
        continue
    } else if (contract && contract.tabs.length > 1) {
      if (!children.length)
        continue
    } else if (!erpCompanyHasPermission(company.permissions, module.readPermission)) {
      continue
    }

    if (!children.length) {
      items.set(module.id, {
        label: module.label,
        icon: module.icon,
        to: module.to,
        exact: module.exact ?? false,
        active: path === module.to || (!module.exact && path.startsWith(`${module.to}/`)),
        onSelect: onDestinationSelect
      })
      continue
    }

    const active = path === module.to || path.startsWith(`${module.to}/`)
    items.set(module.id, {
      label: module.label,
      icon: module.icon,
      type: 'trigger',
      value: `erp-${module.id}`,
      active,
      defaultOpen: active,
      children
    })
  }

  const groups = erpSidebarModuleGroups.map(moduleIds => moduleIds.flatMap((id) => {
    const item = items.get(id)
    return item ? [item] : []
  }))
  return groups.some(group => group.length) ? groups : []
}

export function erpWorkspaceSearchGroups(navigation: NavigationMenuItem[][]): CommandPaletteGroup<CommandPaletteItem>[] {
  const items = navigation.flat().flatMap(module => (module.children?.length ? module.children : [module])
    .flatMap((destination) => {
      if (typeof destination.to !== 'string' || destination.disabled)
        return []
      return [{
        id: destination.to,
        label: module.children?.length ? `${module.label} · ${destination.label}` : destination.label ?? '',
        icon: destination.icon ?? module.icon,
        to: destination.to,
        onSelect: destination.onSelect
      }]
    }))
  return items.length ? [{ id: 'erp-links', label: 'Ir para', items }] : []
}
