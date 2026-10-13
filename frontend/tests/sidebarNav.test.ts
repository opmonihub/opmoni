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

  it('exige igualdade ou prefixo delimitado por barra em todos os módulos', () => {
    for (const [path, value] of [
      ['/customers', 'clientes'], ['/equipe', 'equipe'], ['/monitoring', 'monitoramento'],
      ['/fiscal', 'fiscal'], ['/work', 'work'], ['/settings', 'configuracoes'], ['/admin', 'admin']
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
