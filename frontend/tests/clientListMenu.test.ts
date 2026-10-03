import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { clientListMenuItems } from '../app/utils/clientListMenu.ts'
import type { ClientSavedFilter } from '../app/types/client.ts'

const filter = { id: 7, name: 'Simples Nacional', q: 'acme', filters: [] } as unknown as ClientSavedFilter

function build(overrides: Partial<Parameters<typeof clientListMenuItems>[0]> = {}) {
  const calls: string[] = []
  const items = clientListMenuItems({
    saved: [filter],
    canSave: true,
    canManageTags: true,
    onApply: saved => calls.push(`apply:${saved.id}`),
    onSave: () => calls.push('save'),
    onTags: focusCreate => calls.push(`tags:${focusCreate}`),
    ...overrides
  })
  return { items, calls }
}

describe('clientListMenuItems', () => {
  it('junta filtros salvos e tags em seções rotuladas', () => {
    const { items } = build()

    assert.equal(items.length, 2)
    assert.deepEqual(items[0]?.map(item => item.label), ['Filtros salvos', 'Simples Nacional', 'Salvar filtros atuais'])
    assert.deepEqual(items[1]?.map(item => item.label), ['Tags', 'Criar tag', 'Gerenciar tags'])
    assert.equal(items[0]?.[0]?.type, 'label')
    assert.equal(items[1]?.[0]?.type, 'label')
  })

  it('liga cada item à sua ação', () => {
    const { items, calls } = build()

    items[0]?.[1]?.onSelect?.(new Event('select'))
    items[0]?.[2]?.onSelect?.(new Event('select'))
    items[1]?.[1]?.onSelect?.(new Event('select'))
    items[1]?.[2]?.onSelect?.(new Event('select'))

    assert.deepEqual(calls, ['apply:7', 'save', 'tags:true', 'tags:false'])
    assert.equal(items[0]?.[1]?.savedId, 7)
    assert.equal(items[0]?.[1]?.slot, 'saved')
  })

  it('mostra o vazio e bloqueia salvar sem busca nem filtro', () => {
    const { items } = build({ saved: [], canSave: false })

    assert.deepEqual(items[0]?.map(item => item.label), ['Filtros salvos', 'Nenhum filtro salvo', 'Salvar filtros atuais'])
    assert.equal(items[0]?.[1]?.disabled, true)
    assert.equal(items[0]?.[2]?.disabled, true)
  })

  it('omite a seção de filtros fora de Todos', () => {
    const { items } = build({ saved: null })

    assert.equal(items.length, 1)
    assert.equal(items[0]?.[0]?.label, 'Tags')
  })

  it('omite as tags para quem não gerencia clientes', () => {
    const { items } = build({ canManageTags: false })

    assert.equal(items.length, 1)
    assert.equal(items[0]?.[0]?.label, 'Filtros salvos')
  })

  it('fica vazio quando nenhuma seção se aplica', () => {
    assert.deepEqual(build({ saved: null, canManageTags: false }).items, [])
  })
})
