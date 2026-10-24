import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type Host = { tag: string, text: string, props: Record<string, unknown>, children: Host[], parent: Host | null }
type Request = { signal?: AbortSignal, resolve(value: unknown): void }

function mountedProduct(editing = false) {
  const make = (tag: string, text = ''): Host => ({ tag, text, props: {}, children: [], parent: null })
  const renderer = Vue.createRenderer<Host>({
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
  const requests: Request[] = []
  const currentCompany = Vue.ref({ id: 41, status: 'active', access_kind: 'direct', role: 'operator', permissions: ['erp.products.store', 'erp.units.index', 'erp.units.show'] })
  const bindings = {
    computed: Vue.computed, ref: Vue.ref, reactive: Vue.reactive, watch: Vue.watch, nextTick: Vue.nextTick, toRef: Vue.toRef, onScopeDispose: Vue.onScopeDispose,
    useAuth: () => ({ user: Vue.ref({ id: 5 }), currentCompany, currentAccount: Vue.ref(null) }),
    useState: (_key: string, init: () => unknown) => Vue.ref(init()),
    useAccessApi: () => ({ api: (_path: string, options: { signal?: AbortSignal }) => new Promise(resolve => requests.push({ ...options, resolve })) })
  }
  const reference = Vue.defineComponent({ props: ['name', 'modelValue'], setup: (props, ctx) => () => Vue.h('unit', { ...ctx.attrs, name: props.name, modelValue: props.modelValue }) })
  const product = compileVueComponent('components/erp/catalogs/ErpProductCatalogForm.vue', bindings, {
    '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': reference
  })
  const open = Vue.ref(true)
  let saved: Record<string, unknown> | null = null
  const permissions = Object.fromEntries(['store', 'show', 'edit', 'update', 'archive'].map(key => [key, Vue.ref(true)]))
  const editor = {
    open, record: Vue.ref(editing ? { id: 7, name: 'Produto existente', erp_unit_id: 77, variants: [] } : null), mode: Vue.ref(editing ? 'edit' : 'create'),
    permissions, canSave: Vue.ref(true), loadingDetail: Vue.ref(false), saving: Vue.ref(false), archiving: Vue.ref(false), mutationPending: Vue.ref(false),
    authorityBlocked: Vue.ref(false), error: Vue.ref(''), fieldErrors: Vue.ref({}), contextKey: Vue.ref('company-41'),
    cancel: () => { open.value = false },
    save: (payload: Record<string, unknown>) => { saved = payload }
  }
  const list = {
    search: Vue.ref(''), page: Vue.ref(1), pending: Vue.ref(false), searchPending: Vue.ref(false), error: Vue.ref(''),
    state: Vue.ref({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }), hasLoaded: Vue.ref(true), stale: Vue.ref(false), authorityBlocked: Vue.ref(false),
    refresh: async () => undefined
  }
  const resource = compileVueComponent('components/erp/catalogs/ErpCatalogResource.vue', bindings, {
    '~/composables/useErpCatalogEditor': { useErpCatalogEditor: () => editor },
    '~/composables/useErpCatalogList': { useErpCatalogList: () => list },
    '~/components/erp/ErpCatalogPagination.vue': Vue.defineComponent({ setup: () => () => Vue.h('div') })
  })
  const app = renderer.createApp(resource, { path: '/erp/products', title: 'Produtos', form: product, columns: [], recordLabel: () => 'Produto' })
  app.component('UForm', { setup: (_props, ctx) => () => Vue.h('form', ctx.attrs, ctx.slots.default?.()) })
  app.component('UInput', { props: ['modelValue'], setup: (props, ctx) => () => Vue.h('input', { ...ctx.attrs, modelValue: props.modelValue }) })
  app.component('UButton', { props: ['label'], setup: (props, ctx) => () => Vue.h('button', ctx.attrs, String(props.label ?? '')) })
  app.component('UAlert', { props: ['title', 'description'], setup: (props, ctx) => () => Vue.h('alert', {}, [props.title, props.description, ctx.slots.default?.()]) })
  app.component('USlideover', { props: ['open'], setup: (props, ctx) => () => props.open ? Vue.h('aside', [...(ctx.slots.body?.() ?? []), ...(ctx.slots.footer?.({}) ?? [])]) : null })
  app.component('UModal', { props: ['open', 'title'], setup: (props, ctx) => () => props.open ? Vue.h('confirmation', [props.title, ...(ctx.slots.body?.() ?? []), ...(ctx.slots.footer?.({}) ?? [])]) : null })
  for (const name of ['UFormField', 'USelect', 'UCheckbox', 'UBadge', 'UDropdownMenu', 'UTabs', 'UTable', 'UAccordion']) app.component(name, { setup: (_props, ctx) => () => Vue.h('div', ctx.attrs, ctx.slots.default?.()) })
  const root = make('root')
  const walk = (node: Host): Host[] => [node, ...node.children.flatMap(walk)]
  app.mount(root)
  const nodes = () => walk(root)
  const control = (name: string) => {
    const found = nodes().find(node => ['input', 'unit'].includes(node.tag) && node.props.name === name)
    assert.ok(found, `actual product form renders ${name}`)
    return found
  }
  const update = (name: string, value: unknown) => {
    const listener = control(name).props['onUpdate:modelValue']
    for (const handler of Array.isArray(listener) ? listener : [listener]) (handler as (value: unknown) => void)(value)
  }
  const click = (label: string) => {
    const button = nodes().find(node => node.tag === 'button' && node.text === label)
    assert.ok(button, `actual Resource renders action ${label}`)
    ;(button.props.onClick as () => void)()
  }
  const submit = () => {
    const actual = nodes().find(node => node.tag === 'form')
    assert.ok(actual)
    ;(actual.props.onSubmit as () => void)()
  }
  return { requests, control, update, click, submit, saved: () => saved!, open, nodes, dispose: () => app.unmount() }
}

const flush = async () => {
  await Vue.nextTick()
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
}
const available = (id = 81) => ({ data: [], meta: { native_default_unit: { status: 'available', id, label: 'UN · Unidade' } } })

describe('actual ProductForm native default through the shared Resource', () => {
  it('initializes a pristine unit baseline and submits the selected native unit with the first product', async () => {
    const h = mountedProduct()
    try {
      await flush()
      assert.equal(h.requests.length, 1)
      h.requests[0]!.resolve(available())
      await flush()
      assert.equal(h.control('erp_unit_id').props.modelValue, 81)
      h.update('name', 'Primeiro produto')
      await flush()
      h.submit()
      assert.equal(h.saved().erp_unit_id, 81)
      assert.equal(h.saved().name, 'Primeiro produto')
    } finally { h.dispose() }
  })

  it('does not create a phantom discard confirmation when only the native baseline was loaded', async () => {
    const h = mountedProduct()
    try {
      await flush()
      assert.equal(h.requests.length, 1)
      h.requests[0]!.resolve(available())
      await flush()
      h.click('Cancelar')
      await flush()
      assert.equal(h.open.value, false)
      assert.equal(h.nodes().some(node => node.tag === 'confirmation'), false)
    } finally { h.dispose() }
  })

  it('keeps real edits dirty in the actual Resource when a late default fills the untouched unit', async () => {
    const h = mountedProduct()
    try {
      await flush()
      assert.equal(h.requests.length, 1)
      h.update('name', 'Nome já editado')
      h.requests[0]!.resolve(available())
      await flush()
      assert.equal(h.control('erp_unit_id').props.modelValue, 81)
      h.click('Cancelar')
      await flush()
      assert.equal(h.open.value, true)
      assert.equal(h.nodes().some(node => node.tag === 'confirmation'), true)
      assert.equal(h.control('name').props.modelValue, 'Nome já editado')
    } finally { h.dispose() }
  })

  it('preserves a manually selected or explicitly cleared unit against a late native lookup', async () => {
    for (const value of [93, null]) {
      const h = mountedProduct()
      try {
        await flush()
        assert.equal(h.requests.length, 1)
        h.update('erp_unit_id', value)
        h.requests[0]!.resolve(available())
        await flush()
        assert.equal(h.control('erp_unit_id').props.modelValue, value)
      } finally { h.dispose() }
    }
  })

  it('reports unavailable defaults with retry while existing product edits preserve their own unit', async () => {
    const h = mountedProduct()
    try {
      await flush()
      assert.equal(h.requests.length, 1)
      h.requests[0]!.resolve({ data: [], meta: { native_default_unit: { status: 'unavailable', id: null, label: null } } })
      await flush()
      assert.equal(h.control('erp_unit_id').props.modelValue, null)
      assert.ok(h.nodes().some(node => node.text.includes('unidade padrão está indisponível')))
      h.click('Consultar unidade padrão novamente')
      assert.equal(h.requests.length, 2)
      h.requests[1]!.resolve(available(82))
      await flush()
      assert.equal(h.control('erp_unit_id').props.modelValue, 82)
    } finally { h.dispose() }
    const edit = mountedProduct(true)
    try {
      await flush()
      assert.equal(edit.requests.length, 0)
      assert.equal(edit.control('erp_unit_id').props.modelValue, 77)
      edit.submit()
      assert.equal(edit.saved().erp_unit_id, 77)
    } finally { edit.dispose() }
  })
})
