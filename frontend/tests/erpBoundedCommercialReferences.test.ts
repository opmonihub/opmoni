import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { erpProductVariantQuery, formatErpProductVariantLabel, isErpProductVariantCompatible } from '../app/utils/erpProductVariantReferences.ts'
import { apiFailure } from '../shared/utils/access.ts'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type ProductVariantReference = {
  id: number
  name: string
  sku: string | null
  status: 'active' | 'archived'
  product: {
    id: number
    name: string
    sku: string | null
    kind: 'goods' | 'service'
    track_stock: boolean
    erp_unit_id: number
    status: 'active' | 'archived'
  }
}

type ApiRequest = {
  url: string
  options: { signal?: AbortSignal }
  resolve(value: unknown): void
  reject(reason: unknown): void
}

function productVariant(overrides: Partial<ProductVariantReference> = {}): ProductVariantReference {
  return {
    id: 41,
    name: 'Pacote 500 g',
    sku: 'CAFE-500',
    status: 'active',
    product: {
      id: 9,
      name: 'Café especial',
      sku: 'CAFE',
      kind: 'goods',
      track_stock: true,
      erp_unit_id: 3,
      status: 'active'
    },
    ...overrides
  }
}

function setupVariantList(initialUsage: 'sales' | 'purchase' = 'sales') {
  const user = Vue.ref<{ id: number } | null>({ id: 7 })
  const currentCompany = Vue.ref({
    id: 21,
    status: 'active',
    access_kind: 'direct',
    role: 'owner',
    permissions: ['erp.products.index'],
    delegated_office_id: null
  })
  const currentAccount = Vue.ref<{ id: number, status: string, role: string } | null>(null)
  const sessionGeneration = Vue.ref(1)
  const usage = Vue.ref<'sales' | 'purchase'>(initialUsage)
  const requests: ApiRequest[] = []
  const api = (url: string, options: ApiRequest['options'] = {}) => new Promise<unknown>((resolve, reject) => {
    requests.push({ url, options, resolve, reject })
  })
  const bindings = {
    useAuth: () => ({ user, currentCompany, currentAccount, refresh: async () => user.value }),
    useState: <T>(key: string, init: () => T) => key === 'access-session-generation'
      ? sessionGeneration as Vue.Ref<T>
      : Vue.ref(init()),
    useAccessApi: () => ({ api }),
    apiFailure,
    computed: Vue.computed,
    onScopeDispose: Vue.onScopeDispose,
    ref: Vue.ref,
    watch: Vue.watch
  }
  const source = loadAppComposable<{ useErpCatalogList(path: string, fixedQuery?: () => Record<string, string>): unknown }>(
    'composables/useErpCatalogList.ts',
    bindings
  )
  const scope = Vue.effectScope()
  const list = scope.run(() => source.useErpCatalogList(
    '/erp/product-variants',
    () => ({ ...erpProductVariantQuery(usage.value), 'page': '99', 'per_page': '1', 'search': 'injected', 'X-Company-Id': '999' })
  ))!
  return { list, requests, user, currentCompany, currentAccount, sessionGeneration, usage, dispose: () => scope.stop() }
}

function requestParams(request: ApiRequest): URLSearchParams {
  return new URLSearchParams(request.url.split('?')[1] ?? '')
}

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
  focus(): void
  addEventListener(): void
  removeEventListener(): void
}

function descendants(root: HostNode): HostNode[] {
  return [root, ...root.children.flatMap(descendants)]
}

function visibleText(root: HostNode): string {
  return descendants(root).map(node => node.text).join(' ')
}

function setupActualVariantSelector() {
  const user = Vue.ref<{ id: number } | null>({ id: 7 })
  const currentCompany = Vue.ref({
    id: 21,
    status: 'active',
    access_kind: 'direct',
    role: 'owner',
    permissions: ['erp.products.index'],
    delegated_office_id: null
  })
  const currentAccount = Vue.ref<{ id: number, status: string, role: string } | null>(null)
  const sessionGeneration = Vue.ref(1)
  const usage = Vue.ref<'sales' | 'purchase'>('sales')
  const selectedId = Vue.ref<number | null>(77)
  const requests: ApiRequest[] = []
  const selectedRecords: Array<{ id: number | null, record: ProductVariantReference | null, source: 'selection' | 'hydration' }> = []
  let triggerSelection: (() => void) | null = null
  const api = (url: string, options: ApiRequest['options'] = {}) => new Promise<unknown>((resolve, reject) => {
    requests.push({ url, options, resolve, reject })
  })
  const bindings = {
    useAuth: () => ({ user, currentCompany, currentAccount, refresh: async () => user.value }),
    useState: <T>(key: string, init: () => T) => key === 'access-session-generation'
      ? sessionGeneration as Vue.Ref<T>
      : Vue.ref(init()),
    useAccessApi: () => ({ api }),
    apiFailure,
    computed: Vue.computed,
    onScopeDispose: Vue.onScopeDispose,
    ref: Vue.ref,
    watch: Vue.watch
  }
  const list = loadAppComposable<{ useErpCatalogList(path: string, fixedQuery?: () => Record<string, string>): unknown }>(
    'composables/useErpCatalogList.ts',
    bindings
  )
  const context = loadAppComposable<{ useErpAdministrationContext(extra?: () => string | number | null): unknown }>(
    'composables/useErpAdministrationContext.ts',
    bindings
  )
  const selector = compileVueComponent(
    'components/erp/catalogs/ErpCatalogReferenceSelect.vue',
    bindings,
    {
      '#shared/utils/access': { apiFailure },
      '~/composables/useErpCatalogList': list,
      '~/composables/useErpAdministrationContext': context
    }
  )
  const makeNode = (tag: string, text = ''): HostNode => ({
    tag, text, props: {}, children: [], parent: null,
    focus() {}, addEventListener() {}, removeEventListener() {}
  })
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    patchProp(node, key, _previous, next) { node.props[key] = next },
    insert(node, parent, anchor = null) {
      if (node.parent) {
        const oldIndex = node.parent.children.indexOf(node)
        if (oldIndex >= 0) node.parent.children.splice(oldIndex, 1)
      }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (!node.parent) return
      const index = node.parent.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
      node.parent = null
    },
    createElement: tag => makeNode(tag),
    createText: text => makeNode('#text', text),
    createComment: text => makeNode('#comment', text),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insertStaticContent(content, parent, anchor) {
      const node = makeNode('#static', content.replace(/<[^>]*>/g, ''))
      this.insert(node, parent, anchor)
      return [node, node]
    }
  })
  const harness = Vue.defineComponent({
    setup() {
      return () => Vue.h(selector, {
        'path': '/erp/product-variants',
        'query': erpProductVariantQuery(usage.value),
        'label': 'Produto ou variante',
        'labelKey': 'name',
        'name': 'line.erp_product_variant_id',
        'modelValue': selectedId.value,
        'onUpdate:modelValue': (value: number | null) => { selectedId.value = value },
        'onSelectionRecord': (value: { id: number | null, record: ProductVariantReference | null, source: 'selection' | 'hydration' }) => selectedRecords.push(value)
      })
    }
  })
  const app = renderer.createApp(harness)
  app.component('UFormField', Vue.defineComponent({
    props: ['name', 'label', 'error', 'description', 'required'],
    setup(_props, { slots }) { return () => Vue.h('div', slots.default?.()) }
  }))
  app.component('USelectMenu', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'items', 'valueKey', 'labelKey', 'searchInput', 'searchTerm', 'loading', 'disabled', 'ignoreFilter', 'clear', 'required', 'name'],
    emits: ['update:modelValue', 'update:searchTerm'],
    setup(_props, { attrs, slots, emit }) {
      triggerSelection = () => emit('update:modelValue', 41)
      return () => Vue.h('div', attrs, slots.default?.())
    }
  }))
  app.component('UButton', Vue.defineComponent({
    props: ['label'],
    setup(props, { slots }) {
      return () => Vue.h('button', props.label, slots.default?.())
    }
  }))
  app.component('UBadge', Vue.defineComponent({ props: ['label'], setup: props => () => Vue.h('span', props.label) }))
  app.component('UAlert', Vue.defineComponent({ props: ['title'], setup: props => () => Vue.h('section', props.title) }))
  const root = makeNode('root')
  app.mount(root)
  return {
    app, root, requests, usage, selectedId, selectedRecords,
    setUsage(value: 'sales' | 'purchase') { usage.value = value },
    select() { triggerSelection?.() },
    text: () => visibleText(root),
    settle: flush,
    dispose: () => app.unmount()
  }
}

const flush = async () => {
  await Vue.nextTick()
  await Promise.resolve()
  await Promise.resolve()
  await Vue.nextTick()
}

describe('bounded commercial product variant references', () => {
  it('formats a stable product, variant and SKU label and suppresses duplicate names', () => {
    const row = productVariant()
    assert.equal(formatErpProductVariantLabel(row), 'Café especial · Pacote 500 g · CAFE-500')
    assert.equal(formatErpProductVariantLabel({ ...row, name: row.product.name, sku: null }), 'Café especial · CAFE')
    assert.equal(formatErpProductVariantLabel({ ...row, sku: null }), 'Café especial · Pacote 500 g · CAFE')
  })

  it('allows active goods and services for sales and only stock-tracked goods for purchases', () => {
    assert.equal(isErpProductVariantCompatible(productVariant(), 'sales'), true)
    assert.equal(isErpProductVariantCompatible(productVariant({ product: { ...productVariant().product, kind: 'service', track_stock: false } }), 'sales'), true)
    assert.equal(isErpProductVariantCompatible(productVariant({ product: { ...productVariant().product, kind: 'service', track_stock: false } }), 'purchase'), false)
    assert.equal(isErpProductVariantCompatible(productVariant({ product: { ...productVariant().product, track_stock: false } }), 'purchase'), false)
    assert.equal(isErpProductVariantCompatible(productVariant({ status: 'archived' }), 'sales'), false)
    assert.equal(isErpProductVariantCompatible(productVariant({ product: { ...productVariant().product, status: 'archived' } }), 'sales'), false)
    assert.deepEqual(erpProductVariantQuery('purchase'), { usage: 'purchase' })
  })

  it('uses the bounded variant endpoint in the real sales, purchase and XML consumers', () => {
    const consumers = [
      ['components/erp/ErpCommercialDraftForm.vue', 'sales'],
      ['components/erp/ErpPurchaseForm.vue', 'purchase'],
      ['components/erp/ErpPurchaseXmlPreview.vue', 'purchase']
    ] as const
    for (const [path, usage] of consumers) {
      const source = readFileSync(new URL(`../app/${path}`, import.meta.url), 'utf8')
      const selector = source.match(/<ErpCatalogReferenceSelect\b[\s\S]*?\/>/)?.[0] ?? ''
      assert.ok(selector, `${path} should use the real bounded selector`)
      assert.ok(selector.includes('path="/erp/product-variants"'), `${path} should keep the stable variant base path`)
      assert.ok(selector.includes(':query="variantQuery"'), `${path} should pass usage as the bounded list query`)
      assert.ok(new RegExp(`erpProductVariantQuery\\(['"]${usage}['"]\\)`).test(source), `${path} should request ${usage}-compatible rows`)
      assert.ok(selector.includes('@selection-record='), `${path} should consume selection metadata from the actual selector`)
      assert.ok(source.includes('selection.source === \'hydration\'') || source.includes('selection.source !== \'selection\''), `${path} should keep async hydration separate from user edits`)
    }
  })

  it('keeps an off-page selected id and rehydrates it after usage changes while discarding late details', async (t) => {
    const app = setupActualVariantSelector()
    t.after(app.dispose)
    const initialList = app.requests.find(request => request.url.startsWith('/erp/product-variants?'))
    const oldDetail = app.requests.find(request => request.url === '/erp/product-variants/77')
    assert.ok(initialList)
    assert.equal(requestParams(initialList).get('usage'), 'sales')
    assert.ok(oldDetail)
    initialList.resolve({ data: [productVariant({ id: 41 })], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
    await app.settle()

    app.setUsage('purchase')
    await app.settle()
    assert.equal(app.selectedId.value, 77)
    assert.equal(oldDetail.options.signal?.aborted, true)
    const purchaseList = app.requests.find(request => request !== initialList && request.url.startsWith('/erp/product-variants?'))
    const refreshedDetail = app.requests.filter(request => request.url === '/erp/product-variants/77').at(-1)
    assert.ok(purchaseList)
    assert.equal(requestParams(purchaseList).get('usage'), 'purchase')
    assert.ok(refreshedDetail)
    oldDetail.resolve({ data: productVariant({ id: 77, name: 'Resposta da consulta antiga', status: 'archived' }) })
    await app.settle()
    assert.ok(!app.text().includes('Resposta da consulta antiga'))

    purchaseList.resolve({ data: [productVariant({ id: 42 })], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
    refreshedDetail.resolve({ data: productVariant({ id: 77, name: 'Variante histórica', status: 'archived' }) })
    await app.settle()
    assert.equal(app.selectedId.value, 77)
    assert.ok(app.text().includes('Variante histórica · Arquivado'))
    assert.ok(app.selectedRecords.some(selection => selection.id === 77 && selection.record?.name === 'Variante histórica' && selection.source === 'hydration'))
  })

  it('preserves an unavailable off-page selection and its stable detail path', async (t) => {
    const app = setupActualVariantSelector()
    t.after(app.dispose)
    const initialList = app.requests.find(request => request.url.startsWith('/erp/product-variants?'))
    const detail = app.requests.find(request => request.url === '/erp/product-variants/77')
    assert.ok(initialList)
    assert.ok(detail)
    assert.equal(requestParams(detail).size, 0)
    initialList.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } })
    detail.reject({ statusCode: 404, data: { message: 'Referência indisponível' } })
    await app.settle()
    assert.equal(app.selectedId.value, 77)
    assert.ok(app.text().includes('Referência atual #77'))
    assert.ok(app.text().includes('Não foi possível carregar o rótulo da referência atual'))
  })

  it('emits a user selection distinctly from asynchronous hydration', async (t) => {
    const app = setupActualVariantSelector()
    t.after(app.dispose)
    const list = app.requests.find(request => request.url.startsWith('/erp/product-variants?'))
    assert.ok(list)
    list.resolve({ data: [productVariant({ id: 41 })], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
    await app.settle()
    app.select()
    await app.settle()
    assert.equal(app.selectedId.value, 41)
    assert.ok(app.selectedRecords.some(selection => selection.id === 41 && selection.source === 'selection' && selection.record?.id === 41))
  })

  it('searches variant and parent identity over bounded pages and preserves retryable list errors', async (t) => {
    const app = setupVariantList()
    t.after(app.dispose)
    const list = app.list as {
      search: Vue.Ref<string>
      page: Vue.Ref<number>
      state: Vue.Ref<{ data: ProductVariantReference[], meta: { current_page: number, last_page: number, total: number } } | null>
      error: Vue.Ref<string>
      refresh: () => Promise<void>
      retry: () => Promise<void>
    }
    assert.equal(app.requests[0]?.url.split('?')[0], '/erp/product-variants')
    assert.equal(requestParams(app.requests[0]!).get('usage'), 'sales')
    assert.equal(requestParams(app.requests[0]!).get('page'), '1')
    assert.equal(requestParams(app.requests[0]!).get('search'), null)
    assert.equal(requestParams(app.requests[0]!).get('per_page'), null)
    assert.equal(requestParams(app.requests[0]!).get('X-Company-Id'), null)
    app.requests[0]!.resolve({ data: [productVariant()], meta: { current_page: 1, last_page: 2, per_page: 15, total: 16 } })
    await flush()
    assert.equal(Vue.unref(list.state)?.meta.last_page, 2)

    list.search.value = ' CAFE-500 '
    await new Promise(resolve => setTimeout(resolve, 300))
    await flush()
    assert.equal(requestParams(app.requests.at(-1)!).get('usage'), 'sales')
    assert.equal(requestParams(app.requests.at(-1)!).get('search'), 'CAFE-500')
    app.requests.at(-1)!.resolve({ data: [productVariant()], meta: { current_page: 1, last_page: 2, per_page: 15, total: 16 } })
    await flush()

    list.page.value = 2
    await flush()
    assert.equal(requestParams(app.requests.at(-1)!).get('page'), '2')
    app.requests.at(-1)!.reject({ statusCode: 503, data: { message: 'Busca temporariamente indisponível.' } })
    await flush()
    assert.equal(Vue.unref(list.error), 'Serviço temporariamente indisponível. Tente novamente.')

    const retry = list.retry()
    await flush()
    assert.equal(requestParams(app.requests.at(-1)!).get('usage'), 'sales')
    assert.equal(requestParams(app.requests.at(-1)!).get('search'), 'CAFE-500')
    app.requests.at(-1)!.resolve({ data: [productVariant({ id: 42, sku: 'CAFE-500-B' })], meta: { current_page: 2, last_page: 2, per_page: 15, total: 16 } })
    await retry
    assert.equal(Vue.unref(list.state)?.data[0]?.id, 42)
  })

  it('discards results when usage, company, session or product-read grant changes during a lookup', async (t) => {
    const app = setupVariantList()
    t.after(app.dispose)
    const list = app.list as { state: Vue.Ref<{ data: ProductVariantReference[] } | null>, error: Vue.Ref<string> }
    const original = app.requests[0]!
    app.usage.value = 'purchase'
    const switchedUsage = app.requests.at(-1)!
    assert.equal(requestParams(switchedUsage).get('usage'), 'purchase')
    assert.equal(requestParams(switchedUsage).get('page'), '1')
    assert.equal(requestParams(switchedUsage).get('search'), null)
    assert.equal(requestParams(switchedUsage).get('per_page'), null)
    assert.equal(original.options.signal?.aborted, true)
    original.resolve({ data: [productVariant({ name: 'Resposta da consulta de vendas' })], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
    await flush()
    assert.equal(Vue.unref(list.state), null)

    app.currentCompany.value = { ...app.currentCompany.value, id: 22 }
    const switchedCompany = app.requests.at(-1)!
    assert.equal(switchedUsage.options.signal?.aborted, true)
    switchedUsage.resolve({ data: [productVariant({ name: 'Resposta da empresa anterior' })], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
    await flush()
    assert.equal(Vue.unref(list.state), null)

    app.currentCompany.value = { ...app.currentCompany.value, permissions: [] }
    assert.equal(switchedCompany.options.signal?.aborted, true)
    switchedCompany.resolve({ data: [productVariant({ name: 'Resposta após revogação' })], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
    await flush()
    assert.equal(Vue.unref(list.state), null)
    assert.match(Vue.unref(list.error), /permissão/i)

    app.currentCompany.value = { ...app.currentCompany.value, permissions: ['erp.products.index'] }
    const beforeSessionChange = app.requests.at(-1)!
    app.sessionGeneration.value++
    assert.equal(beforeSessionChange.options.signal?.aborted, true)
    const current = app.requests.at(-1)!
    current.resolve({ data: [productVariant({ name: 'Resultado da empresa atual' })], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
    await flush()
    assert.equal(Vue.unref(list.state)?.data[0]?.name, 'Resultado da empresa atual')
  })
})
