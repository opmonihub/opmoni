import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { collapsedSidebarItems, foldedSidebarChildren, isOfficeErpAdmin, officeErpSidebarItem, sidebarOpenGroupFromPath } from '../app/utils/sidebarNav.ts'

describe('office ERP sidebar entry', () => {
  it('is available only to an active selected office administrator', () => {
    assert.equal(isOfficeErpAdmin({ status: 'active', role: 'admin' }), true)
    assert.equal(isOfficeErpAdmin({ status: 'suspended', role: 'admin' }), false)
    assert.equal(isOfficeErpAdmin({ status: 'active', role: 'operador' }), false)
    assert.equal(isOfficeErpAdmin({ status: 'active', role: 'super_admin' }), false)
    assert.equal(isOfficeErpAdmin(null), false)
  })

  it('exposes plans in primary navigation and marks only the matching destination active', () => {
    assert.deepEqual(officeErpSidebarItem('/gestao-erp/planos').children, [
      { label: 'Visão geral', to: '/gestao-erp', exact: true, active: false },
      { label: 'Planos', to: '/gestao-erp/planos', exact: true, active: true },
      { label: 'Clientes ERP', to: '/gestao-erp/clientes', exact: true, active: false },
      { label: 'Assinaturas', to: '/gestao-erp/assinaturas', exact: false, active: false },
      { label: 'Faturas', to: '/gestao-erp/faturas', exact: false, active: false },
      { label: 'Recebimentos', to: '/gestao-erp/recebimentos', exact: true, active: false },
      { label: 'Configurações', to: '/gestao-erp/configuracoes', exact: true, active: false }
    ])
    assert.equal(officeErpSidebarItem('/gestao-erp/planos-antigos').children[1]?.active, false)
    assert.equal(officeErpSidebarItem('/gestao-erp/clientes').children[2]?.active, true)
    assert.equal(officeErpSidebarItem('/gestao-erp/assinaturas/123').children[3]?.active, true)
    assert.equal(officeErpSidebarItem('/gestao-erp/faturas').children[4]?.active, true)
    assert.equal(officeErpSidebarItem('/gestao-erp/faturas/123/imprimir').children[4]?.active, true)
    assert.equal(officeErpSidebarItem('/gestao-erp/recebimentos').children[5]?.active, true)
    assert.equal(officeErpSidebarItem('/gestao-erp/configuracoes').children[6]?.active, true)
    assert.equal(officeErpSidebarItem('/gestao-erp/faturas-antigas').children[4]?.active, false)
    assert.equal(officeErpSidebarItem('/gestao-erp/faturas/123/imprimir').children[0]?.active, false)
    assert.equal(sidebarOpenGroupFromPath('/gestao-erp/planos'), 'gestao-erp')
  })

  it('links directly to the overview and marks the exact root route active', () => {
    const item = officeErpSidebarItem('/gestao-erp')
    assert.equal(item.children[0]?.to, '/gestao-erp')
    assert.equal(item.children[0]?.active, true)
    assert.equal(item.active, true)
    assert.equal(sidebarOpenGroupFromPath('/gestao-erp'), 'gestao-erp')
    assert.equal(officeErpSidebarItem('/gestao-erp/faturas/123/imprimir').children[0]?.active, false)
  })
})

describe('sidebarOpenGroupFromPath', () => {
  it('mapeia prefixos de módulo para o value do accordion', () => {
    assert.equal(sidebarOpenGroupFromPath('/customers/painel'), 'clientes')
    assert.equal(sidebarOpenGroupFromPath('/equipe/membros'), 'equipe')
    assert.equal(sidebarOpenGroupFromPath('/monitoring/obrigacoes'), 'monitoramento')
    assert.equal(sidebarOpenGroupFromPath('/fiscal/notas'), 'fiscal')
    assert.equal(sidebarOpenGroupFromPath('/work/calendario'), 'work')
    assert.equal(sidebarOpenGroupFromPath('/settings/security'), 'configuracoes')
    assert.equal(sidebarOpenGroupFromPath('/admin/serpro'), 'admin')
    assert.equal(sidebarOpenGroupFromPath('/gestao-erp'), 'gestao-erp')
    assert.equal(sidebarOpenGroupFromPath('/gestao-erp/planos'), 'gestao-erp')
  })

  it('exige igualdade ou prefixo delimitado por barra em todos os módulos', () => {
    for (const [path, value] of [
      ['/customers', 'clientes'], ['/equipe', 'equipe'], ['/monitoring', 'monitoramento'],
      ['/fiscal', 'fiscal'], ['/work', 'work'], ['/settings', 'configuracoes'], ['/admin', 'admin'], ['/gestao-erp', 'gestao-erp']
    ]) {
      assert.equal(sidebarOpenGroupFromPath(path!), value)
      assert.equal(sidebarOpenGroupFromPath(`${path}/detalhe`), value)
      assert.equal(sidebarOpenGroupFromPath(`${path}-other`), '')
      assert.equal(sidebarOpenGroupFromPath(`${path}flow`), '')
    }
    assert.equal(sidebarOpenGroupFromPath('/workflow'), '')
  })

  it('retorna vazio fora dos módulos com submenu', () => {
    assert.equal(sidebarOpenGroupFromPath('/'), '')
    assert.equal(sidebarOpenGroupFromPath('/inbox'), '')
  })
})

describe('foldedSidebarChildren', () => {
  const children = [
    { label: 'Painel', active: false },
    { label: 'Simples Nacional', active: true }
  ]

  it('esconde os irmãos e preserva o filho ativo', () => {
    const folded = foldedSidebarChildren(children, true)
    assert.equal(folded?.[0]?.ui?.childItem, 'hidden')
    assert.equal(folded?.[1]?.label, 'Simples Nacional')
    assert.equal(folded?.[1]?.ui, undefined)
  })

  it('não muta o catálogo nem substitui o nó ativo ao ocultar irmãos', () => {
    const catalogue = [
      { label: 'Painel', active: false, ui: { childItem: 'existing', childLink: 'text-default' } },
      { label: 'Obrigação', active: true, ui: { childLink: 'text-primary' } }
    ]
    const original = structuredClone(catalogue)
    const folded = foldedSidebarChildren(catalogue, true)!
    assert.notEqual(folded, catalogue)
    assert.equal(folded[1], catalogue[1])
    assert.equal(folded[0]?.ui?.childItem, 'hidden')
    assert.equal(folded[0]?.ui?.childLink, 'text-default')
    assert.deepEqual(catalogue, original)
    assert.equal(foldedSidebarChildren(catalogue, false), catalogue)
  })

  it('mantém a projeção idempotente e a identidade do ativo em recolhimentos repetidos', () => {
    const first = foldedSidebarChildren(children, true)!
    const second = foldedSidebarChildren(first, true)!
    assert.equal(first[1], children[1])
    assert.equal(second[1], children[1])
    assert.deepEqual(second, first)
    assert.deepEqual(children, [
      { label: 'Painel', active: false },
      { label: 'Simples Nacional', active: true }
    ])
  })

  it('devolve o próprio catálogo com o grupo aberto, inclusive vazio ou indefinido', () => {
    const empty: Array<{ active?: boolean }> = []
    assert.equal(foldedSidebarChildren(children, false), children)
    assert.equal(foldedSidebarChildren(empty, false), empty)
    assert.equal(foldedSidebarChildren(undefined, false), undefined)
    assert.equal(foldedSidebarChildren(undefined, true), undefined)
  })
})

describe('collapsedSidebarItems', () => {
  const clientes = {
    label: 'Clientes',
    type: 'trigger' as const,
    to: '/customers',
    exact: true,
    children: [
      { label: 'Painel', to: '/customers/painel', exact: true },
      { label: 'Meus clientes', to: '/customers/certificados' }
    ]
  }
  const inicio = { label: 'Início', to: '/', exact: true }

  it('acende o pai em rota filha sem mexer no exact dos filhos', () => {
    const collapsed = collapsedSidebarItems([clientes, inicio], '/customers/painel')
    assert.equal(collapsed[0]?.exact, false)
    assert.equal(collapsed[0]?.active, true)
    assert.equal(collapsed[0]?.children?.[0]?.exact, true)
    assert.equal(collapsed[1]?.exact, true)
    assert.equal(collapsed[1]?.active, undefined)
    assert.equal(clientes.exact, true)
    assert.equal(clientes.active, undefined)
  })

  it('não acende o módulo quando a rota é de outro grupo', () => {
    const collapsed = collapsedSidebarItems([clientes], '/settings/security')
    assert.equal(collapsed[0]?.active, false)
    assert.equal(collapsed[0]?.exact, false)
  })
})
