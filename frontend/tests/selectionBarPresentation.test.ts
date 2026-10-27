import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type Node = { tag?: string, text?: string, props: Record<string, unknown>, children: Node[], parent?: Node | null }

function mount(component: Vue.Component, components: Record<string, Vue.Component> = {}) {
  const root: Node = { props: {}, children: [] }
  const renderer = Vue.createRenderer<Node, Node>({
    createElement: tag => ({ tag, props: {}, children: [] }),
    createText: text => ({ text, props: {}, children: [] }),
    createComment: text => ({ text, props: {}, children: [] }),
    setText: (node, text) => { node.text = text },
    setElementText: (node, text) => {
      node.text = text
      node.children = []
    },
    patchProp: (node, key, _previous, next) => { node.props[key] = next },
    insert(node, parent, anchor) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
    },
    remove(node) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    parentNode: node => node.parent ?? null,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const app = renderer.createApp(component)
  for (const [name, implementation] of Object.entries(components)) app.component(name, implementation)
  app.mount(root)
  const walk = (node: Node): Node[] => [node, ...node.children.flatMap(walk)]
  return { nodes: () => walk(root), unmount: () => app.unmount() }
}

test('shared selection chrome announces caller-owned scope and clears without taking action ownership', async () => {
  const component = compileVueComponent('components/data-table/SelectionBar.vue')
  let clears = 0
  const wrapper = Vue.defineComponent({
    setup: () => () => Vue.h(component, { count: 1234, label: 'clientes da página selecionados', floating: true, onClear: () => { clears++ } }, {
      default: () => [Vue.h('button', { id: 'domain-action' }, 'Etiquetas')]
    })
  })
  const mounted = mount(wrapper, {
    UButton: Vue.defineComponent({
      setup: (_props, { attrs, slots }) => () => Vue.h('button', attrs, slots.default?.())
    })
  })

  try {
    assert.ok(mounted.nodes().some(node => node.props['aria-live'] === 'polite'))
    assert.ok(mounted.nodes().some(node => node.text === '1.234'))
    assert.ok(mounted.nodes().some(node => node.text === 'clientes da página selecionados'))
    assert.ok(mounted.nodes().some(node => node.props.id === 'domain-action'), 'domain action remains caller-owned')
    assert.match(String(mounted.nodes().find(node => node.props['aria-live'] === 'polite')?.parent?.parent?.props.class), /fixed/)

    const clear = mounted.nodes().find(node => node.props['aria-label'] === 'Cancelar seleção')
    assert.ok(clear)
    ;(clear.props.onClick as () => void)()
    await Vue.nextTick()
    assert.equal(clears, 1)
  } finally { mounted.unmount() }
})

test('the phone task menu exposes the same assignment and dismissal commands with their existing guards', async () => {
  const component = compileVueComponent('components/work/WorkTarefasBulkBar.vue')
  const menus: Array<{ label?: string, disabled?: boolean, children?: Array<{ label: string, disabled?: boolean, onSelect?: () => void }>, onSelect?: () => void }>[] = []
  const assigned: unknown[] = []
  let dismissed = 0
  const wrapper = Vue.defineComponent({ setup: () => () => Vue.h(component, {
    count: 2, assigneeItems: [{ label: 'Ana', value: 7 }],
    onAssign: (id: unknown) => assigned.push(id), onDismiss: () => { dismissed++ }
  }) })
  const mounted = mount(wrapper, {
    DataTableSelectionBar: Vue.defineComponent({ setup: (_props, { slots }) => () => Vue.h('div', slots.default?.()) }),
    UDropdownMenu: Vue.defineComponent({ props: ['items'], setup: (props, { slots }) => () => {
      menus.push(props.items.flat())
      return Vue.h('div', slots.default?.())
    } }),
    UButton: Vue.defineComponent({ setup: (_props, { attrs }) => () => Vue.h('button', attrs) })
  })
  try {
    assert.ok(mounted.nodes().some(node => node.props['aria-label'] === 'Mais ações da seleção'), 'phone trigger stays reachable')
    const assignment = menus.flat().find(item => item.label === 'Atribuir responsável')
    assert.ok(assignment?.children?.[0]?.onSelect)
    assignment.children[0].onSelect()
    menus.flat().find(item => item.label === 'Dispensar')?.onSelect?.()
    assert.deepEqual(assigned, [7])
    assert.equal(dismissed, 1)
  } finally { mounted.unmount() }
})
