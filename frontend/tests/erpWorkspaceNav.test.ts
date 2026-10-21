import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  erpWorkspaceCatalogGroups,
  erpWorkspaceSettingsGroups,
  erpWorkspaceSidebarItems
} from '../app/utils/erpWorkspaceNav.ts'

function company(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    name: 'Empresa A',
    status: 'active' as const,
    access_kind: 'direct' as const,
    role: 'owner',
    permissions: ['*'],
    delegated_office_id: null,
    ...overrides
  }
}

function item(items: ReturnType<typeof erpWorkspaceSidebarItems>, label: string) {
  return items.flat().find(link => link.label === label)
}

function children(group: { children?: Array<{ label?: string, to?: string, active?: boolean, onSelect?: () => void, children?: unknown }> } | undefined) {
  return group?.children ?? []
}

describe('ERP workspace navigation registry', () => {
  it('filters catalog destinations by exact read grants and omits empty groups', () => {
    const links = erpWorkspaceCatalogGroups('/erp/cadastros/produtos', company({ permissions: ['erp.products.index'] }))
    assert.deepEqual(links.flatMap(group => children(group[0]).map(child => child.to)), ['/erp/cadastros/produtos', '/erp/cadastros/etiquetas'])
    assert.equal(links.flat().some(group => group.label === 'Fiscal'), false)
    assert.equal(links.flat().some(group => group.label === 'Relacionamentos'), false)
    assert.equal(links.flat().find(group => group.label === 'Produtos')?.defaultOpen, true)
  })

  it('keeps all prior settings destinations while restricting owner and establishment links', () => {
    const paths = (access: Record<string, unknown>) => erpWorkspaceSettingsGroups('/erp/configuracoes/empresa', company(access))
      .flatMap(group => children(group[0]).map(child => child.to))
    const manager = paths({ role: 'manager', permissions: ['erp.read', 'erp.company.settings.show', 'erp.establishments.index'] })
    assert.ok(manager.includes('/erp/configuracoes/empresa'))
    assert.ok(manager.includes('/erp/configuracoes/sistema'))
    assert.ok(manager.includes('/erp/configuracoes/estabelecimentos'), 'authorized non-owner readers can open establishments')
    assert.equal(manager.includes('/erp/configuracoes/membros'), false)
    assert.equal(manager.includes('/erp/configuracoes/escritorio'), false)

    const owner = paths({ role: 'owner', permissions: ['erp.read', 'erp.company.settings.show', 'erp.establishments.index'] })
    assert.ok(owner.includes('/erp/configuracoes/empresa'))
    assert.ok(owner.includes('/erp/configuracoes/estabelecimentos'))
    assert.ok(owner.includes('/erp/configuracoes/membros'))
    assert.ok(owner.includes('/erp/configuracoes/escritorio'))
  })

  it('keeps Empresa reachable from its exact read grant without requiring erp.read', () => {
    const links = erpWorkspaceSidebarItems('/erp/configuracoes/empresa', company({
      role: 'viewer',
      permissions: ['erp.company.settings.show']
    }))
    const settings = item(links, 'Configurações')
    assert.ok(children(settings).some(child => child.to === '/erp/configuracoes/empresa' && child.active))
    assert.ok(children(settings).some(child => child.to === '/erp/configuracoes/sistema'), 'the shared settings read action grants sibling settings pages')
  })

  it('keeps owner-only settings hidden for delegated access even when the role says owner', () => {
    const links = erpWorkspaceSettingsGroups('/erp/configuracoes/membros', company({
      access_kind: 'delegated',
      role: 'owner',
      delegated_office_id: 8,
      permissions: ['erp.read', 'erp.establishments.index']
    }))
    const paths = links.flatMap(group => children(group[0]).map(child => child.to))
    assert.equal(paths.includes('/erp/configuracoes/estabelecimentos'), true)
    assert.equal(paths.includes('/erp/configuracoes/membros'), false)
    assert.equal(paths.includes('/erp/configuracoes/escritorio'), false)
  })

  it('shows establishments to delegated readers with the exact index grant', () => {
    const links = erpWorkspaceSettingsGroups('/erp/configuracoes/estabelecimentos', company({
      access_kind: 'delegated',
      role: 'admin',
      delegated_office_id: 8,
      permissions: ['erp.establishments.index']
    }))
    const paths = links.flatMap(group => children(group[0]).map(child => child.to))
    assert.ok(paths.includes('/erp/configuracoes/estabelecimentos'))
  })

  it('keeps an establishments-only direct manager reachable through Configurações', () => {
    const links = erpWorkspaceSidebarItems('/erp/configuracoes/estabelecimentos', company({
      role: 'manager',
      permissions: ['erp.establishments.index']
    }))
    const settings = item(links, 'Configurações')
    assert.ok(children(settings).some(child => child.to === '/erp/configuracoes/estabelecimentos' && child.active))
  })

  it('uses the subscription read action for the Modules settings destination', () => {
    const settings = item(erpWorkspaceSidebarItems('/erp/configuracoes/modulos', company({
      role: 'viewer',
      permissions: ['erp.subscription.show']
    })), 'Configurações')
    assert.ok(children(settings).some(child => child.to === '/erp/configuracoes/modulos' && child.active))
    assert.equal(children(settings).length, 1)
  })

  it('uses hash child targets and expands the active module for deep links and history', () => {
    const finance = erpWorkspaceSidebarItems('/erp/financeiro', company(), undefined, '#payments')
    const group = item(finance, 'Financeiro')
    assert.equal(group?.type, 'trigger')
    assert.equal(group?.defaultOpen, true)
    assert.ok(children(group).some(child => child.to === '/erp/financeiro#payments' && child.active))
    assert.ok(children(group).some(child => child.to === '/erp/financeiro#obligations'))

    const reports = erpWorkspaceSidebarItems('/erp/relatorios', company(), undefined, '#stock')
    assert.equal(item(reports, 'Relatórios')?.defaultOpen, true)
    assert.ok(children(item(reports, 'Relatórios')).some(child => child.to === '/erp/relatorios#stock' && child.active))

    const defaultFinance = erpWorkspaceSidebarItems('/erp/financeiro', company())
    assert.ok(children(item(defaultFinance, 'Financeiro')).some(child => child.to === '/erp/financeiro#obligations' && child.active))
    const invalidFinanceHash = erpWorkspaceSidebarItems('/erp/financeiro', company(), undefined, '#unknown')
    assert.ok(children(item(invalidFinanceHash, 'Financeiro')).some(child => child.to === '/erp/financeiro#obligations' && child.active))
    const defaultReports = erpWorkspaceSidebarItems('/erp/relatorios', company())
    assert.ok(children(item(defaultReports, 'Relatórios')).some(child => child.to === '/erp/relatorios#sales' && child.active))
  })

  it('omits unusable multi-area modules, retains single-area leaves, and closes only on destination selection', () => {
    const onSelect = () => {}
    const links = erpWorkspaceSidebarItems('/erp/financeiro', company({ permissions: ['erp.finance.read'] }), onSelect)
    assert.equal(item(links, 'Financeiro'), undefined, 'multi-area modules need at least one readable child')
    const accounting = item(erpWorkspaceSidebarItems('/erp/contabilidade', company({ permissions: ['erp.accounting.read'] }), onSelect), 'Contabilidade')
    assert.equal(accounting?.to, '/erp/contabilidade')
    assert.equal(accounting?.onSelect, onSelect)

    const settings = item(erpWorkspaceSidebarItems('/erp/configuracoes/empresa', company(), onSelect), 'Configurações')
    assert.equal(settings?.onSelect, undefined, 'expansion trigger must not close the mobile sidebar')
    assert.ok(children(settings).some(child => child.to === '/erp/configuracoes/empresa' && child.onSelect === onSelect), 'selecting a leaf closes it')
  })

  it('keeps narrow import and XML preview grants reachable without broad aggregate module grants', () => {
    const importItem = item(erpWorkspaceSidebarItems('/erp/cadastros/importacao', company({
      role: 'operator',
      permissions: ['erp.catalog.import.products.preview']
    })), 'Cadastros')
    assert.ok(children(importItem).some(child => child.to === '/erp/cadastros/importacao'))
    assert.ok(children(importItem).every(child => !child.children), 'collapsed sidebar popovers receive direct destination links')

    const purchases = item(erpWorkspaceSidebarItems('/erp/compras', company({
      role: 'viewer',
      permissions: ['erp.purchases.xml-preview']
    })), 'Compras')
    assert.ok(children(purchases).some(child => child.to === '/erp/compras#xml-preview'))
    assert.equal(children(purchases).some(child => child.to === '/erp/compras#purchases'), false)
  })

  it('gives settings group navigation an active descendant and handles inactive companies safely', () => {
    const groups = erpWorkspaceSettingsGroups('/erp/configuracoes/email', company({ permissions: ['erp.read'] }))
    assert.equal(groups.find(group => group[0]?.label === 'Sistema')?.[0]?.defaultOpen, true)
    assert.deepEqual(erpWorkspaceSidebarItems('/erp', company({ status: 'suspended' })), [])
  })
})
