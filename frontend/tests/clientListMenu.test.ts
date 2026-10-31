import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { clientListMenuItems } from '../app/utils/clientListMenu.ts'

function build(overrides: Partial<Parameters<typeof clientListMenuItems>[0]> = {}) {
  const calls: string[] = []
  const items = clientListMenuItems({
    canManageTags: true,
    onTags: focusCreate => calls.push(`tags:${focusCreate}`),
    ...overrides
  })
  return { items, calls }
}

describe('clientListMenuItems', () => {
  it('oferece criar e gerenciar tags', () => {
    const { items, calls } = build()

    assert.equal(items.length, 1)
    assert.deepEqual(items[0]?.map(item => item.label), ['Tags', 'Criar tag', 'Gerenciar tags'])
    assert.equal(items[0]?.[0]?.type, 'label')
    items[0]?.[1]?.onSelect?.(new Event('select'))
    items[0]?.[2]?.onSelect?.(new Event('select'))
    assert.deepEqual(calls, ['tags:true', 'tags:false'])
  })

  it('fica vazio para quem não gerencia clientes', () => {
    assert.deepEqual(build({ canManageTags: false }).items, [])
  })
})
