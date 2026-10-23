import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import type { ErpPaginated } from '../shared/types/erpCatalogs.ts'

type Meta = ErpPaginated<unknown>['meta']
type Node = { text?: string, children: Node[], parent?: Node, props: Record<string, unknown> }
const meta = (total: number, perPage = 15, currentPage = 1): Meta => ({ total, per_page: perPage, current_page: currentPage, last_page: Math.max(1, Math.ceil(total / perPage)) })

function mountFooter(initial: Meta, pending = false, summary?: string) {
  const props = Vue.reactive({ meta: initial, page: initial.current_page, pending, summary })
  const events: number[] = []
  let pagination: Record<string, unknown> | undefined
  const renderer = Vue.createRenderer<Node, Node>({
    patchProp(node, key, _old, value) { node.props[key] = value },
    insert(node, parent, anchor) {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
    },
    createElement: () => ({ children: [], props: {} }),
    createText: text => ({ text, children: [], props: {} }),
    createComment: () => ({ children: [], props: {} }),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent ?? null,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const Footer = compileVueComponent('components/erp/ErpListFooter.vue')
  const app = renderer.createApp({ setup: () => () => Vue.h(Footer, {
    ...props,
    'onUpdate:page': (page: number) => {
      events.push(page)
      props.page = page
    }
  }) })
  app.component('UPagination', Vue.defineComponent({
    props: ['page', 'total', 'itemsPerPage', 'disabled'],
    inheritAttrs: false,
    setup(props, { attrs }) {
      pagination = new Proxy({}, { get: (_, key) => Reflect.get(props, key) ?? Reflect.get(attrs, key) })
      Vue.onUnmounted(() => {
        pagination = undefined
      })
      return () => Vue.h('nav')
    }
  }))
  const root: Node = { children: [], props: {} }
  app.mount(root)
  const text = (node: Node): string => (node.text ?? '') + node.children.map(text).join('')
  return { props, events, text: () => text(root), pagination: () => pagination, change: (page: number) => (pagination?.['onUpdate:page'] as (value: number) => void)(page), stop: () => app.unmount() }
}

describe('ERP controlled list footer', () => {
  for (const total of [0, 1, 15]) {
    it(`keeps the confirmed ${total} count without pagination for a single page`, (t) => {
      const footer = mountFooter(meta(total))
      t.after(footer.stop)
      assert.equal(footer.text().trim(), `${total} ${total === 1 ? 'registro' : 'registros'}`)
      assert.equal(footer.pagination(), undefined)
      assert.deepEqual(footer.events, [])
    })
  }

  it('uses server total and page size and emits exactly one changed valid transition', async (t) => {
    const footer = mountFooter(meta(1234, 7))
    t.after(footer.stop)
    assert.match(footer.text(), /1\.234 registros/)
    assert.equal(footer.pagination()?.total, 1234)
    assert.equal(footer.pagination()?.itemsPerPage, 7)
    assert.equal(footer.pagination()?.page, 1)
    assert.equal(footer.pagination()?.disabled, false)
    assert.deepEqual(footer.events, [])
    footer.change(2)
    await Vue.nextTick()
    footer.change(2)
    assert.deepEqual(footer.events, [2])
  })

  it('does not emit on metadata replacement or synchronize the model automatically', async (t) => {
    const footer = mountFooter(meta(45, 15, 3))
    t.after(footer.stop)
    footer.props.meta = meta(5)
    await Vue.nextTick()
    assert.match(footer.text(), /5 registros/)
    assert.equal(footer.props.page, 3)
    assert.equal(footer.pagination(), undefined)
    assert.deepEqual(footer.events, [])
    footer.props.meta = meta(70, 10, 2)
    await Vue.nextTick()
    assert.equal(footer.pagination()?.page, 3)
    assert.equal(footer.pagination()?.total, 70)
    assert.equal(footer.pagination()?.itemsPerPage, 10)
    assert.deepEqual(footer.events, [])
  })

  it('rejects pending, unchanged, out-of-range, fractional and non-finite transitions', async (t) => {
    const footer = mountFooter(meta(40, 15), true)
    t.after(footer.stop)
    assert.equal(footer.pagination()?.disabled, true)
    footer.change(2)
    assert.deepEqual(footer.events, [])
    footer.props.pending = false
    await Vue.nextTick()
    for (const page of [1, 0, -1, 4, 1.5, NaN, Infinity]) footer.change(page)
    assert.deepEqual(footer.events, [])
    footer.change(3)
    assert.deepEqual(footer.events, [3])
  })

  it('allows a consumer summary without changing authoritative pagination', (t) => {
    const footer = mountFooter(meta(35, 10), false, '35 compras confirmadas')
    t.after(footer.stop)
    assert.equal(footer.text().trim(), '35 compras confirmadas')
    assert.equal(footer.pagination()?.total, 35)
    assert.equal(footer.pagination()?.itemsPerPage, 10)
  })
})
