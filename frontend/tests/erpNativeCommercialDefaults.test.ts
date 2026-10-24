import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type Draft = Record<string, unknown> & { lines: Array<Record<string, unknown>> }
type ExposedForm = { submit(): void, draft: Vue.Ref<Draft> }

function form(initial: Record<string, unknown>, kind = 'sale', editing = false) {
  const component = compileVueComponent('components/erp/ErpCommercialDraftForm.vue') as {
    setup(props: Record<string, unknown>, context: { expose(value: ExposedForm): void, emit(event: string, payload: Draft): void }): unknown
  }
  const scope = Vue.effectScope()
  let exposed!: ExposedForm
  let saved: Draft | null = null
  const draft: Draft = { erp_establishment_id: 1, lines: [{ description: 'Serviço', quantity: '1.0000', unit_price: '10.0000' }], ...initial }
  scope.run(() => component.setup(Vue.reactive({
    kind, editing, draft, references: { establishments: [], contacts: [], units: [], variants: [] },
    errors: {}, saving: false, canOverridePrice: true, canOverrideDiscount: true
  }), {
    expose: (value) => { exposed = value },
    emit: (event, payload) => { if (event === 'save') saved = payload }
  }))
  return { exposed, saved: () => saved!, dispose: () => scope.stop() }
}

type HostNode = { tag: string, style: Record<string, string>, props: Record<string, unknown>, children: HostNode[], parent: HostNode | null, text: string }

function mountedForm(initial: Record<string, unknown> = {}, editing = false, errors: Record<string, string> = {}) {
  const make = (tag: string, text = ''): HostNode => ({ tag, text, style: {}, props: {}, children: [], parent: null })
  const renderer = Vue.createRenderer<HostNode>({
    patchProp: (node, key, _old, value) => { node.props[key] = value },
    insert(node, parent, anchor) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1) },
    createElement: tag => make(tag), createText: text => make('#text', text), createComment: text => make('#comment', text),
    setText: (node, text) => { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insertStaticContent(content, parent, anchor) {
      const node = make('#static', content)
      this.insert(node, parent, anchor)
      return [node, node]
    }
  })
  const component = compileVueComponent('components/erp/ErpCommercialDraftForm.vue')
  let instance!: { submit(): void }
  let saved: Draft | null = null
  const app = renderer.createApp({ setup: () => () => Vue.h(component, {
    ref: (value) => { instance = value as unknown as typeof instance },
    kind: 'sale', editing, draft: { erp_establishment_id: 1, lines: [{ description: 'Serviço', quantity: '1.0000', unit_price: '10.0000' }], ...initial },
    references: { establishments: [], contacts: [{ id: 31, name: 'Cliente escolhido' }], units: [], variants: [] },
    errors, saving: false, canOverridePrice: true, canOverrideDiscount: true,
    onSave: (value: Draft) => { saved = value }
  }) })
  app.component('UFormField', { props: ['name', 'label', 'error'], setup: (props, ctx) => () => Vue.h('fieldset', { name: props.name }, [props.label, ctx.slots.default?.(), props.error]) })
  // Product-variant lookup is outside these customer/currency controls.
  app.component('ErpCatalogReferenceSelect', {
    props: ['modelValue', 'name', 'label', 'path', 'query', 'disabled'],
    emits: ['update:modelValue', 'selection-record', 'selection-validity'],
    setup: props => () => Vue.h('div', { 'data-reference': props.name }, props.label)
  })
  app.component('USelect', { props: ['modelValue', 'items'], setup: (props, ctx) => () => Vue.h('select', { ...ctx.attrs, modelValue: props.modelValue, items: props.items }) })
  for (const name of ['UForm', 'UInput', 'UAlert', 'UButton']) app.component(name, { setup: (_props, ctx) => () => Vue.h('div', ctx.attrs, ctx.slots.default?.()) })
  const root = make('root')
  const walk = (node: HostNode): HostNode[] => [node, ...node.children.flatMap(walk)]
  app.mount(root)
  const select = (name: string) => {
    const field = walk(root).find(node => node.props.name === name)
    assert.ok(field, `actual form renders field ${name}`)
    const control = walk(field).find(node => node.tag === 'select')
    assert.ok(control, `actual field ${name} has a select`)
    return control
  }
  return { root, walk, select, submit: () => instance.submit(), saved: () => saved!, dispose: () => app.unmount() }
}

describe('commercial native default intent at the real form boundary', () => {
  it('preserves omitted currency and customer for server native selection in a new counter sale', () => {
    const h = form({})
    try {
      h.exposed.submit()
      assert.equal(Object.hasOwn(h.saved(), 'currency'), false)
      assert.equal(Object.hasOwn(h.saved(), 'erp_contact_id'), false)
    } finally { h.dispose() }
  })

  it('preserves an explicit unassigned customer and an explicit ISO currency', () => {
    const h = form({ erp_contact_id: null, currency: 'BRL' })
    try {
      h.exposed.submit()
      assert.equal(h.saved().erp_contact_id, null)
      assert.equal(h.saved().currency, 'BRL')
      assert.equal(Object.hasOwn(h.saved(), 'erp_contact_id'), true)
    } finally { h.dispose() }
  })

  it('keeps an explicit selected customer when other fields are edited', () => {
    const h = form({ erp_contact_id: 31, currency: 'BRL' })
    try {
      h.exposed.draft.value.lines[0]!.quantity = '2.0000'
      h.exposed.submit()
      assert.equal(h.saved().erp_contact_id, 31)
      assert.equal(h.saved().lines[0]!.quantity, '2.0000')
    } finally { h.dispose() }
  })

  it('retains an existing document currency and null customer instead of creating new default intent', () => {
    const h = form({ erp_contact_id: null, currency: 'BRL', draft_revision: 3 }, 'sale', true)
    try {
      h.exposed.submit()
      assert.equal(h.saved().erp_contact_id, null)
      assert.equal(h.saved().currency, 'BRL')
      assert.equal(h.saved().draft_revision, 3)
    } finally { h.dispose() }
  })

  it('renders and submits distinct native-default, no-customer and explicit-customer choices', async () => {
    const h = mountedForm()
    try {
      const customer = h.select('erp_contact_id')
      assert.equal(customer.props.modelValue, -1)
      assert.ok((customer.props.items as Array<{ label: string, value: number }>).some(row => row.value === -1 && row.label === 'Usar cliente padrão da empresa'))
      const choose = customer.props['onUpdate:modelValue'] as (value: number) => void
      choose(0)
      h.submit()
      assert.equal(h.saved().erp_contact_id, null)
      choose(31)
      h.submit()
      assert.equal(h.saved().erp_contact_id, 31)
      choose(-1)
      h.submit()
      assert.equal(Object.hasOwn(h.saved(), 'erp_contact_id'), false)
      await Vue.nextTick()
    } finally { h.dispose() }
  })

  it('makes unresolved currency feedback actionable through an explicit ISO choice without rewriting edit intent', async () => {
    const h = mountedForm({}, false, { currency: 'A moeda padrão não está resolvida.' })
    try {
      const currency = h.select('currency')
      assert.ok(h.walk(h.root).some(node => node.text.includes('A moeda padrão não está resolvida.')))
      assert.equal(currency.props.modelValue, 'default')
      const choose = currency.props['onUpdate:modelValue'] as (value: string) => void
      choose('BRL')
      h.submit()
      assert.equal(h.saved().currency, 'BRL')
      choose('default')
      h.submit()
      assert.equal(Object.hasOwn(h.saved(), 'currency'), false)
      await Vue.nextTick()
    } finally { h.dispose() }
    const edit = mountedForm({ currency: 'BRL', erp_contact_id: null }, true)
    try {
      assert.equal(edit.select('currency').props.modelValue, 'BRL')
      assert.equal((edit.select('currency').props.items as Array<{ value: string }>).some(row => row.value === 'default'), false)
      edit.submit()
      assert.equal(edit.saved().currency, 'BRL')
      assert.equal(edit.saved().erp_contact_id, null)
    } finally { edit.dispose() }
  })
})
