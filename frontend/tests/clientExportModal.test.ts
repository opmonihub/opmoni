import assert from 'node:assert/strict'
import { it } from 'node:test'
import { compileVueComponent, mountVueComponent } from './helpers/compileVueComponent.ts'

const modal = compileVueComponent('components/customers/ClientExportModal.vue')
const columns = [
  { id: 'name', label: 'Nome/Razão social' },
  { id: 'status', label: 'Situação' }
]

it('opens a small column picker and exports only the marked columns', (t) => {
  const exported: string[][] = []
  const view = mountVueComponent(modal, {}, {
    'open': true,
    columns,
    'selectedIds': ['name'],
    'onUpdate:open': () => {},
    'onExport': (columnIds: string[]) => exported.push(columnIds)
  })
  t.after(view.unmount)

  const dialog = view.nodes(node => node.props['data-component'] === 'UModal')[0]
  assert.equal(dialog?.props.title, 'Exportar')
  assert.equal(dialog?.props.description, undefined)
  const picker = view.nodes(node => node.props['aria-label'] === 'Colunas do arquivo')[0]
  assert.ok(picker)
  assert.equal(picker.props.variant, 'table')
  assert.deepEqual(JSON.parse(String(picker.props['data-items'])), [
    { label: 'Nome/Razão social', value: 'name' },
    { label: 'Situação', value: 'status' }
  ])
  const confirm = view.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Exportar colunas escolhidas')[0]
  assert.equal(confirm?.props.disabled, false)
  ;(confirm?.props.onClick as () => void)()
  assert.deepEqual(exported, [['name']])
})

it('names a selection export without a subtitle', (t) => {
  const view = mountVueComponent(modal, {}, {
    'open': true,
    columns,
    'selectedIds': ['name'],
    'selectionOnly': true,
    'onUpdate:open': () => {}
  })
  t.after(view.unmount)

  const dialog = view.nodes(node => node.props['data-component'] === 'UModal')[0]
  assert.equal(dialog?.props.title, 'Exportar seleção')
  assert.equal(dialog?.props.description, undefined)
})

it('keeps export unavailable until a column is marked', (t) => {
  const view = mountVueComponent(modal, {}, {
    'open': true,
    columns,
    'selectedIds': [],
    'onUpdate:open': () => {}
  })
  t.after(view.unmount)

  const confirm = view.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Exportar colunas escolhidas')[0]
  assert.equal(confirm?.props.disabled, true)
})
