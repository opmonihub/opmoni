import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { collapsedSidebarItems, foldedSidebarChildren, sidebarOpenGroupFromPath } from '../app/utils/sidebarNav.ts'

describe('sidebarOpenGroupFromPath', () => {
  it('mapeia prefixos de módulo para o value do accordion', () => {
    assert.equal(sidebarOpenGroupFromPath('/customers/painel'), 'clientes')
    assert.equal(sidebarOpenGroupFromPath('/equipe/membros'), 'equipe')
    assert.equal(sidebarOpenGroupFromPath('/monitoring/obrigacoes'), 'monitoramento')
    assert.equal(sidebarOpenGroupFromPath('/fiscal/notas'), 'fiscal')
    assert.equal(sidebarOpenGroupFromPath('/work/calendario'), 'work')
    assert.equal(sidebarOpenGroupFromPath('/settings/security'), 'configuracoes')
    assert.equal(sidebarOpenGroupFromPath('/admin/serpro'), 'admin')
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

  it('devolve a lista inteira com o grupo aberto', () => {
    assert.deepEqual(foldedSidebarChildren(children, false), children)
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
