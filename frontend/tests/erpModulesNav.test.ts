import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  erpCaixaContract,
  erpCompanyHasPermission,
  erpFinanceiroContract,
  erpFiscalContract,
  erpEstoqueContract,
  erpModuleActive,
  erpModulePageContracts,
  erpModuleSidebarChildren,
  erpNavModules,
  erpPermissions,
  erpRelatoriosContract,
  erpVisibleNavModules,
  erpListTotal
} from '../app/utils/erpModulesNav.ts'

describe('erpModulesNav', () => {
  it('keeps route permissions and fiscal area/model on the existing contracts', () => {
    assert.ok(erpModulePageContracts.every(contract => contract.tabs.every(tab => tab.readPermission)))
    assert.equal(erpEstoqueContract.tabs.find(tab => tab.id === 'lots')?.readPermission, 'erp.stock.lots.index')
    assert.equal(erpFiscalContract.tabs.find(tab => tab.id === 'configuration')?.readPermission, 'erp.fiscal.configuration.show')
    assert.deepEqual(erpFiscalContract.tabs.filter(tab => tab.fiscalArea === 'documents').map(tab => tab.fiscalModel), ['55', '65', '57', '58'])
  })

  it('shows modules for exact effective reads without broadening narrow delegated grants', () => {
    const visible = erpVisibleNavModules(['erp.payments.index', 'erp.contacts.show'])
    assert.ok(visible.some(module => module.to === '/erp/financeiro'))
    assert.ok(visible.some(module => module.to === '/erp/cadastros'))
    assert.equal(visible.some(module => module.to === '/erp/fiscal'), false)
    assert.equal(erpCompanyHasPermission(['erp.payments.store'], erpPermissions.financeRead), false)
    assert.equal(erpCompanyHasPermission(['erp.payments.index'], erpPermissions.financeWrite), false)
    assert.ok(erpVisibleNavModules(['erp.customer-groups.index']).some(module => module.to === '/erp/cadastros'))
    assert.equal(erpCompanyHasPermission(['erp.customer-groups.store'], erpPermissions.catalogRead), false)
  })
  it('resolve permissões com wildcard, grant específico e fallback erp.read', () => {
    assert.equal(erpCompanyHasPermission(['*'], erpPermissions.financeWrite), true)
    assert.equal(erpCompanyHasPermission(['erp.finance.read'], erpPermissions.financeRead), true)
    assert.equal(erpCompanyHasPermission(['erp.read'], erpPermissions.financeRead), true)
    assert.equal(erpCompanyHasPermission(['erp.read'], erpPermissions.financeWrite), false)
    assert.equal(erpCompanyHasPermission(undefined, erpPermissions.read), false)
  })

  it('lista módulos operacionais com rotas e ícones estáveis', () => {
    const paths = erpNavModules.map(module => module.to)
    assert.ok(paths.includes('/erp/financeiro'))
    assert.ok(paths.includes('/erp/vendas'))
    assert.ok(paths.includes('/erp/relatorios'))
    assert.ok(paths.includes('/erp/cadastros'))
    assert.equal(erpNavModules.find(module => module.to === '/erp')?.exact, true)
  })

  it('filtra sidebar por permissões visíveis', () => {
    const viewer = erpVisibleNavModules(['erp.read'])
    assert.ok(viewer.some(module => module.to === '/erp/financeiro'))
    assert.ok(viewer.some(module => module.to === '/erp/fiscal'))

    const fiscalOnly = erpVisibleNavModules(['erp.fiscal.read'])
    assert.equal(fiscalOnly.some(module => module.to === '/erp/financeiro'), false)
    assert.ok(fiscalOnly.some(module => module.to === '/erp/fiscal'))
  })

  it('marca filho ativo por prefixo, com índice exato em /erp', () => {
    const children = erpModuleSidebarChildren('/erp/financeiro', ['erp.read', 'erp.finance.read'])
    const financeiro = children.find(child => child.to === '/erp/financeiro')
    assert.equal(financeiro?.active, true)

    const overview = erpModuleSidebarChildren('/erp', ['erp.read']).find(child => child.to === '/erp')
    assert.equal(overview?.active, true)
    assert.equal(erpModuleActive('/erp/configuracoes/empresa', { to: '/erp', exact: true }), false)
  })

  it('congela contratos de API alinhados ao design (changes 4–9)', () => {
    assert.equal(erpFinanceiroContract.tabs.map(tab => tab.apiPath).join(','), '/erp/financial-accounts,/erp/obligations,/erp/payments')
    assert.equal(erpCaixaContract.tabs[0]?.apiPath, '/erp/cash-registers')
    assert.equal(erpFiscalContract.tabs.some(tab => tab.apiPath.startsWith('/erp/fiscal/documents')), true)
    assert.equal(erpRelatoriosContract.tabs.some(tab => tab.apiPath === '/erp/reports/overview'), true)
    assert.equal(erpModulePageContracts.length, 8)
  })

  it('extrai total paginado apenas quando meta.total é numérico', () => {
    assert.equal(erpListTotal({ data: [], meta: { total: 12 } }), 12)
    assert.equal(erpListTotal({ data: [] }), null)
    assert.equal(erpListTotal(null), null)
  })
})
