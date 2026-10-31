import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { defineComponent, h } from 'vue'
import { compileVueComponent, mountVueComponent } from './helpers/compileVueComponent.ts'
import type { DataTableFilterModel } from '../app/components/data-table/filter-model.ts'
import {
  isSavedClientOperator,
  toPanelClientFilters,
  toStoredClientFilters
} from '../app/utils/clientFilterPanel.ts'

const filtersComponent = compileVueComponent('components/customers/ClientListFilters.vue')
const SlotBoundary = defineComponent({
  setup: (_props, { slots }) => () => h('div', [slots.default?.(), slots.trailing?.()])
})

it('keeps search and export reachable in a named client action group', (t) => {
  const searches: string[] = []
  let exports = 0
  const view = mountVueComponent(filtersComponent, {
    DataTableFilterPanel: SlotBoundary,
    CustomersClientListMenu: SlotBoundary,
    UDropdownMenu: SlotBoundary,
    UKbd: SlotBoundary,
    DataTableColumnMenu: SlotBoundary
  }, {
    'documentStatus': 'all', 'search': '', 'filterColumns': [], 'filterModels': [],
    'canManageClients': true, 'isLoading': false,
    'desktopTable': false, 'rowsLength': 3, 'columnVisibility': {},
    'hideableColumns': [],
    'onUpdate:search': (value: string) => searches.push(value),
    'onExport': () => { exports++ }
  })
  t.after(view.unmount)

  const actions = view.nodes(node => node.props.role === 'group' && node.props['aria-label'] === 'Buscar e gerenciar clientes')
  assert.equal(actions.length, 1)
  const input = view.nodes(node => node.tag === 'input' && node.props['aria-label'] === 'Buscar clientes')[0]
  assert.ok(input, 'Search must have a persistent accessible name, not only a placeholder')
  ;(input.props.onInput as (event: unknown) => void)({ target: { value: 'Acme' } })
  assert.deepEqual(searches, ['Acme'])
  const exportButton = view.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Exportar para Excel')[0]
  assert.ok(exportButton)
  assert.equal(exportButton.props.disabled, false)
  ;(exportButton.props.onClick as () => void)()
  assert.equal(exports, 1)
  assert.equal(view.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Limpar busca').length, 0)
  assert.equal(view.nodes(node => node.props['aria-label'] === 'Ações da seleção').length, 0)
})

it('clears the client search from the field itself', (t) => {
  const searches: string[] = []
  const view = mountVueComponent(filtersComponent, {
    DataTableFilterPanel: SlotBoundary,
    CustomersClientListMenu: SlotBoundary,
    UDropdownMenu: SlotBoundary,
    UKbd: SlotBoundary,
    DataTableColumnMenu: SlotBoundary
  }, {
    'search': 'Acme', 'filterColumns': [], 'filterModels': [],
    'canManageClients': true, 'isLoading': false,
    'desktopTable': true, 'rowsLength': 1, 'columnVisibility': {},
    'hideableColumns': [],
    'onUpdate:search': (value: string) => searches.push(value)
  })
  t.after(view.unmount)

  const clear = view.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Limpar busca')[0]
  assert.ok(clear)
  ;(clear.props.onClick as () => void)()
  assert.deepEqual(searches, [''])
})

describe('filtro salvo da carteira no painel', () => {
  it('mostra no painel o que foi gravado e grava de volta o operador que a API aceita', () => {
    const saved: DataTableFilterModel[] = [
      { columnId: 'regime', operator: 'is', values: ['mei'] },
      { columnId: 'status', operator: 'is not', values: ['inactive'] },
      { columnId: 'tag', operator: 'is any of', values: ['1', '2'] },
      { columnId: 'certificate', operator: 'is none of', values: ['expired', 'missing'] }
    ]

    const panel = toPanelClientFilters(saved)
    assert.deepEqual(panel.map(filter => filter.operator), [
      'include',
      'exclude',
      'include any of',
      'exclude if any of'
    ])

    const stored = toStoredClientFilters(panel)
    assert.deepEqual(stored, saved)
    assert.ok(stored.every(filter => isSavedClientOperator(filter.operator)))
  })

  it('guarda inclui todos como é qualquer um de, que é o operador que o salvamento aceita', () => {
    const stored = toStoredClientFilters([{
      columnId: 'tag',
      type: 'multiOption',
      operator: 'include all of',
      values: ['1', '2']
    }])

    assert.deepEqual(stored, [{ columnId: 'tag', operator: 'is any of', values: ['1', '2'] }])
    assert.equal(isSavedClientOperator('include all of'), false)
  })
})
