/* eslint-disable @stylistic/max-statements-per-line -- Compact renderer callbacks keep the local Vue test harness readable beside its workflow assertions. */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import * as VueCompiler from 'vue/compiler-sfc'
import ts from 'typescript'
import { erpCommercialDocumentToDraft, erpSalesActionAllowed } from '../shared/utils/erpSales.ts'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as operationRecovery from '../shared/utils/erpOperationRecovery.ts'

const salesPage = readFileSync(new URL('../app/pages/erp/vendas/index.vue', import.meta.url), 'utf8')

describe('sales workspace page contract', () => {
  it('mounts the sales workflow workspace inside the existing ERP route', () => {
    assert.match(salesPage, /ErpSalesWorkspace/)
    assert.doesNotMatch(salesPage, /ErpModuleHub/)
    assert.match(salesPage, /layout: 'erp'/)
  })
})

function compileModule(source: string, imports: Record<string, unknown>, bindings: Record<string, unknown>) {
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const exports: Record<string, unknown> = {}
  const runtime = new Function('require', 'exports', ...Object.keys(bindings), output)
  runtime((path: string) => {
    if (!(path in imports)) throw new Error(`Unexpected import ${path}`)
    return imports[path]
  }, exports, ...Object.values(bindings))
  return exports
}

function createSalesWorkspace(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, permissions = ['*']) {
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, permissions, role: 'owner' })
  const contextKey = Vue.computed(() => `${user.value.id}:${currentCompany.value.id}:${currentCompany.value.role}:${currentCompany.value.permissions.join(',')}`)
  const context = { key: contextKey, capture: () => ({ key: contextKey.value }), isCurrent: (token: { key: string }) => token.key === contextKey.value }
  const scope = Vue.effectScope()
  const exports = compileModule(readFileSync(new URL('../app/composables/useErpSalesWorkspace.ts', import.meta.url), 'utf8'), {
    '#shared/types/erpBusiness': {},
    '~/composables/useApiError': apiErrors,
    '#shared/utils/erpOperationRecovery': operationRecovery,
    '#shared/utils/erpSales': { erpSalesActionAllowed }
  }, {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted: (_callback: () => void) => {}, onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany, refreshWithStatus: async () => ({ committed: true }) }),
    useErpAdministrationContext: () => context
  })
  const state = scope.run(() => (exports.useErpSalesWorkspace as (initialArea?: string) => Record<string, unknown>)('quotes'))!
  return { state, user, currentCompany, stop: () => scope.stop() }
}

function installSessionStorage() {
  const values = new Map<string, string>()
  const previous = Object.getOwnPropertyDescriptor(globalThis, 'sessionStorage')
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: {
    getItem(key: string) { return values.get(key) ?? null },
    setItem(key: string, value: string) { values.set(key, value) },
    removeItem(key: string) { values.delete(key) }
  } })
  return { values, restore() { if (previous) Object.defineProperty(globalThis, 'sessionStorage', previous); else Reflect.deleteProperty(globalThis, 'sessionStorage') } }
}

describe('sales authority and mounted draft form', () => {
  it('requires the exact sales action and blocks viewer writes', () => {
    assert.equal(erpSalesActionAllowed(['erp.quotes.index'], 'manager', 'erp.quotes.index'), true)
    assert.equal(erpSalesActionAllowed(['erp.sales.*'], 'manager', 'erp.sales.post'), true)
    assert.equal(erpSalesActionAllowed(['erp.sales.*'], 'manager', 'erp.sales.price.override'), false)
    assert.equal(erpSalesActionAllowed(['*'], 'viewer', 'erp.sales.post'), false)
    assert.equal(erpSalesActionAllowed(['erp.read'], 'manager', 'erp.payments.store'), false)
    assert.equal(erpSalesActionAllowed(['*'], 'viewer', 'erp.sales.discount.override'), false)
    assert.equal(erpSalesActionAllowed(['erp.sales.post'], 'manager', 'erp.payments.store'), false)
    assert.equal(erpSalesActionAllowed(['erp.write'], 'manager', 'erp.quotes.store'), true)
    assert.equal(erpSalesActionAllowed(['erp.write'], 'manager', 'erp.sales.price.override'), false)
    assert.equal(erpSalesActionAllowed(['erp.sales.price.override'], 'manager', 'erp.sales.price.override'), true)
    assert.equal(erpSalesActionAllowed(['erp.sales.*'], 'manager', 'erp.sales.future'), false)
  })

  it('releases an obsolete list spinner while allowing unrelated catalog reads to finish after new intent', async () => {
    let resolveList: ((value: unknown) => void) | undefined
    const list = new Promise((resolve) => { resolveList = resolve })
    const api = async (path: string) => {
      if (path.startsWith('/erp/quotes?')) return await list
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    try {
      const loadingList = (state.load as (area: string) => Promise<void>)('quotes')
      const loadingReferences = (state.loadReferences as () => Promise<void>)()
      ;(state.claimIntent as () => number)()
      assert.equal((state.loading as Vue.Reactive<Record<string, boolean>>).quotes, false)
      resolveList?.({ data: [{ id: 1 }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
      await Promise.all([loadingList, loadingReferences])
      assert.deepEqual((state.rows as Vue.Reactive<Record<string, unknown[]>>).quotes, [])
      assert.equal((state.referenceLoading as Vue.Ref<boolean>).value, false)
      assert.equal((state.references as Vue.Reactive<{ establishments: Array<{ id: number }> }>).establishments[0]?.id, 3)
    } finally {
      stop()
    }
  })

  it('rebuilds edits from actual raw line snapshots, retaining explicit price, unit, rule, header amounts, and exact IDs', () => {
    const draft = erpCommercialDocumentToDraft({
      id: 41, erp_establishment_id: 3, erp_contact_id: 6, erp_price_group_id: null, status: 'draft', currency: 'BRL', draft_revision: 7,
      subtotal_amount: '20.0000', discount_amount: '2.0000', tax_amount: '1.8000', total_amount: '19.8000', stock_reserved: false, needs_reconciliation: false,
      lines: [{ id: 88, erp_product_variant_id: 18, erp_unit_id: 4, quantity: '2.0000', unit_price: '10.0000', gross_amount: '20.0000', discount_amount: '2.0000', tax_amount: '1.8000', line_total: '19.8000', unit_conversion_factor: '1.25000000', product_snapshot: {
        description: 'Produto congelado / Variante A', pricing: { manual_override: true }, tax_rate: { id: 9 }, discount_rule: { id: 11 },
        header_discount: { mode: 'manual', submitted: { discount_type: 'fixed', discount_amount: '2.0000' } },
        header_tax: { rate_percent: '10.0000' }
      } }]
    })
    assert.equal(draft.draft_revision, 7)
    assert.deepEqual(draft.discount, { discount_type: 'fixed', discount_amount: '2.0000' })
    assert.equal(draft.tax_rate_percent, '10.0000')
    assert.deepEqual(draft.lines[0], {
      id: 88, erp_product_variant_id: 18, erp_unit_id: 4, description: 'Produto congelado / Variante A', quantity: '2.0000', unit_price: '10.0000',
      discount_amount: '0.0000', tax_amount: '0.0000', unit_conversion_factor: '1.25000000', erp_tax_rate_id: 9, erp_discount_rule_id: null
    })
  })

  it('submits exact decimal strings and omits a catalog-derived unit price', () => {
    const source = readFileSync(new URL('../app/components/erp/ErpCommercialDraftForm.vue', import.meta.url), 'utf8')
    const { descriptor } = VueCompiler.parse(source)
    const script = VueCompiler.compileScript(descriptor, { id: 'sales-draft', inlineTemplate: true })
    const bindings = { computed: Vue.computed, ref: Vue.ref }
    const exports = compileModule(script.content, { vue: Vue }, bindings)
    const component = exports.default as { setup: (props: unknown, context: { emit: (event: string, value: unknown) => void, expose: (value: unknown) => void }) => unknown }
    const draft = Vue.reactive({
      erp_establishment_id: 7,
      currency: 'BRL' as const,
      lines: [{ erp_product_variant_id: 18, erp_unit_id: null, description: '', quantity: '0.2500', discount_amount: '0.0002', tax_amount: '0.0001', unit_price: '' }]
    })
    let submitted: unknown
    type Node = { tag?: string, text?: string, children: Node[], parent: Node | null, props: Record<string, unknown> }
    const root: Node = { tag: 'root', children: [], parent: null, props: {} }
    const renderer = Vue.createRenderer<Node>({
      patchProp(node, key, _previous, next) { node.props[key] = next },
      insert(node, parent, anchor) {
        if (node.parent) { const index = node.parent.children.indexOf(node); if (index >= 0) node.parent.children.splice(index, 1) }
        node.parent = parent
        const index = anchor ? parent.children.indexOf(anchor) : -1
        if (index < 0) parent.children.push(node)
        else parent.children.splice(index, 0, node)
      },
      remove(node) { if (!node.parent) return; const index = node.parent.children.indexOf(node); if (index >= 0) node.parent.children.splice(index, 1); node.parent = null },
      createElement(tag) { return { tag, children: [], parent: null, props: {} } },
      createText(text) { return { text, children: [], parent: null, props: {} } },
      createComment(text) { return { text, children: [], parent: null, props: {} } },
      setText(node, text) { node.text = text },
      setElementText(node, text) { node.children = []; node.text = text },
      parentNode(node) { return node.parent },
      nextSibling(node) { if (!node.parent) return null; return node.parent.children[node.parent.children.indexOf(node) + 1] ?? null }
    })
    const instance = Vue.ref<{ submit: () => void } | null>(null)
    const host = Vue.defineComponent({ setup: () => () => Vue.h(component, {
      ref: instance,
      kind: 'sale', draft, references: { establishments: [], contacts: [], units: [], variants: [] }, errors: {}, saving: false, canOverridePrice: false, canOverrideDiscount: false,
      onSave: (value: unknown) => { submitted = value }
    }) })
    const app = renderer.createApp(host)
    const passthrough = Vue.defineComponent({ setup: (_props, context) => () => Vue.h('div', context.slots.default?.() ?? []) })
    for (const name of ['UForm', 'UFormField', 'UInput', 'USelect', 'UAlert', 'UButton']) app.component(name, passthrough)
    app.mount(root)
    assert.ok(instance.value)
    instance.value.submit()
    assert.deepEqual(submitted, {
      erp_establishment_id: 7,
      currency: 'BRL',
      lines: [{ erp_product_variant_id: 18, erp_unit_id: null, quantity: '0.2500', discount_amount: '0.0002', tax_amount: '0.0001' }]
    })
    app.unmount()
    assert.match(source, /inputmode="decimal"/)
    assert.doesNotMatch(source, /UInputNumber/)
  })

  it('submits only selected source lines and preserves exact return quantities', () => {
    const source = readFileSync(new URL('../app/components/erp/ErpSaleReturnForm.vue', import.meta.url), 'utf8')
    const { descriptor } = VueCompiler.parse(source)
    const script = VueCompiler.compileScript(descriptor, { id: 'sales-return', inlineTemplate: true })
    const exports = compileModule(script.content, { vue: Vue }, { computed: Vue.computed, reactive: Vue.reactive, ref: Vue.ref })
    const component = exports.default as Vue.Component
    const sale = { id: 23, erp_establishment_id: 3, currency: 'BRL', returnability: { needs_reconciliation: false }, finance: { needs_reconciliation: false }, returnable_lines: [
      { source_line_id: 81, quantity: '1.0000', returned_quantity: '0.0000', remaining_quantity: '1.0000', unit_conversion_factor: '1.00000000', line_total: '10.0000', physical_eligibility: 'validated_at_post' }
    ] }
    type Node = { tag?: string, text?: string, children: Node[], parent: Node | null, props: Record<string, unknown> }
    const root: Node = { tag: 'root', children: [], parent: null, props: {} }
    const renderer = Vue.createRenderer<Node>({
      patchProp(node, key, _previous, next) { node.props[key] = next },
      insert(node, parent, anchor) {
        if (node.parent) { const index = node.parent.children.indexOf(node); if (index >= 0) node.parent.children.splice(index, 1) }
        node.parent = parent
        const index = anchor ? parent.children.indexOf(anchor) : -1
        if (index < 0) parent.children.push(node)
        else parent.children.splice(index, 0, node)
      },
      remove(node) { if (!node.parent) return; const index = node.parent.children.indexOf(node); if (index >= 0) node.parent.children.splice(index, 1); node.parent = null },
      createElement(tag) { return { tag, children: [], parent: null, props: {} } }, createText(text) { return { text, children: [], parent: null, props: {} } }, createComment(text) { return { text, children: [], parent: null, props: {} } },
      setText(node, text) { node.text = text }, setElementText(node, text) { node.children = []; node.text = text }, parentNode(node) { return node.parent }, nextSibling(node) { return node.parent ? node.parent.children[node.parent.children.indexOf(node) + 1] ?? null : null }
    })
    const instance = Vue.ref<{ reason: string, quantities: Record<number, string>, submit: () => void } | null>(null)
    let submitted: unknown[] = []
    const host = Vue.defineComponent({ setup: () => () => Vue.h(component, { ref: instance, sale, saving: false, errors: {}, onSubmit: (...value: unknown[]) => { submitted = value } }) })
    const app = renderer.createApp(host)
    const passthrough = Vue.defineComponent({ setup: (_props, context) => () => Vue.h('div', context.slots.default?.() ?? []) })
    for (const name of ['UAlert', 'UButton', 'UForm', 'UFormField', 'UInput', 'UTextarea']) app.component(name, passthrough)
    app.mount(root)
    assert.ok(instance.value)
    instance.value.reason = 'Parcial'
    instance.value.quantities[81] = '0.2500'
    instance.value.submit()
    assert.deepEqual(submitted, ['Parcial', [{ source_line_id: 81, quantity: '0.2500' }]])
    app.unmount()
  })
})

describe('commercial API lifecycle', () => {
  it('patches an owned draft and preserves the exact method through uncertain retry', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let writes = 0
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'PATCH') {
        writes++
        if (writes === 1) throw { statusCode: 503, message: 'timeout' }
        return { data: { id: 66 } }
      }
      if (path.startsWith('/erp/operations/lookup?')) throw { statusCode: 404 }
      if (path.startsWith('/erp/quotes?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/quotes/66') return { data: {
        id: 66, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, status: 'draft', currency: 'BRL', draft_revision: 3,
        subtotal_amount: '4.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '4.0000', stock_reserved: false,
        needs_reconciliation: false, lines: []
      } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    const payload = { erp_establishment_id: 3, currency: 'BRL' as const, draft_revision: 2, lines: [] }
    try {
      await (state.saveDraft as (kind: string, value: typeof payload, id: number) => Promise<unknown>)('quote', payload, 66)
      const original = calls.find(call => call.options?.method === 'PATCH')
      assert.equal(original?.path, '/erp/quotes/66')
      assert.deepEqual(original?.options?.body, payload)
      await (state.reconcile as () => Promise<void>)()
      const lookup = calls.find(call => call.path.startsWith('/erp/operations/lookup?'))
      assert.equal((original?.options?.headers as Record<string, unknown>)['Idempotency-Key'], (lookup?.options?.headers as Record<string, unknown>)['Idempotency-Key'])
      assert.equal((state.canRetryExact as Vue.Ref<boolean>).value, true)
      await (state.retryExact as () => Promise<unknown>)()
      const retries = calls.filter(call => call.options?.method === 'PATCH')
      assert.equal(retries.length, 2)
      assert.deepEqual(retries[1]?.options?.body, retries[0]?.options?.body)
      assert.equal((retries[1]?.options?.headers as Record<string, unknown>)['Idempotency-Key'], (retries[0]?.options?.headers as Record<string, unknown>)['Idempotency-Key'])
    } finally {
      stop()
      storage.restore()
    }
  })

  it('resolves a proven stale-revision update with a fresh detail while retaining a rebase choice', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'PATCH') throw { statusCode: 409, message: 'Rascunho alterado.' }
      if (path === '/erp/quotes/66') return { data: {
        id: 66, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, status: 'draft', currency: 'BRL', draft_revision: 4,
        subtotal_amount: '5.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '5.0000', stock_reserved: false,
        needs_reconciliation: false, lines: []
      } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    const payload = { erp_establishment_id: 3, currency: 'BRL' as const, draft_revision: 2, lines: [] }
    try {
      await (state.saveDraft as (kind: string, value: typeof payload, id: number) => Promise<unknown>)('quote', payload, 66)
      assert.equal(calls.some(call => call.path === '/erp/quotes/66' && call.options?.method === undefined), true)
      assert.equal((state.selected as Vue.Ref<{ draft_revision: number } | null>).value?.draft_revision, 4)
      assert.deepEqual((state.revisionConflict as Vue.Ref<unknown>).value, { area: 'quotes', id: 66, latestRevision: 4 })
      assert.equal((state.selectedOperation as Vue.Ref<unknown>).value, null)
      assert.equal((state.needsReconciliation as Vue.Ref<boolean>).value, false)
      assert.equal((state.mutating as Vue.Ref<boolean>).value, false)
    } finally {
      stop()
      storage.restore()
    }
  })

  it('converts quote to order and order to sale using HTTP 200 acknowledgements followed by authorized details', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path === '/erp/quotes/5/convert') return { data: { quote: { id: 5 }, order: { id: 8 } } }
      if (path === '/erp/orders/8/convert') return { data: { order: { id: 8 }, sale: { id: 13 } } }
      if (path.startsWith('/erp/quotes?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path.startsWith('/erp/orders?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path.startsWith('/erp/sales?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/orders/8') return { data: { id: 8, status: 'confirmed', draft_revision: 4, currency: 'BRL', total_amount: '10.0000', lines: [] } }
      if (path === '/erp/sales/13') return { data: { id: 13, status: 'draft', fiscal_status: 'none', draft_revision: 1, currency: 'BRL', total_amount: '10.0000', lines: [] } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    const quote = { id: 5, status: 'draft', draft_revision: 3, currency: 'BRL', total_amount: '10.0000' }
    try {
      await (state.convertQuote as (value: typeof quote, orderNo?: string) => Promise<unknown>)(quote)
      assert.equal((state.selected as Vue.Ref<{ id: number } | null>).value?.id, 8)
      await (state.convertOrder as (value: { id: number, draft_revision: number }, invoiceNo?: string) => Promise<unknown>)({ id: 8, draft_revision: 4 })
      assert.equal((state.selected as Vue.Ref<{ id: number, status: string, fiscal_status: string } | null>).value?.id, 13)
      assert.equal((state.selected as Vue.Ref<{ status: string, fiscal_status: string } | null>).value?.status, 'draft')
      assert.equal((state.selected as Vue.Ref<{ status: string, fiscal_status: string } | null>).value?.fiscal_status, 'none')
      assert.deepEqual(calls.find(call => call.path === '/erp/quotes/5/convert')?.options?.body, { draft_revision: 3 })
      assert.deepEqual(calls.find(call => call.path === '/erp/orders/8/convert')?.options?.body, { draft_revision: 4 })
      assert.equal(calls.some(call => call.path === '/erp/orders/8'), true)
      assert.equal(calls.some(call => call.path === '/erp/sales/13'), true)
    } finally {
      stop()
      storage.restore()
    }
  })

  it('uses the accepted save acknowledgement then installs only the authorized detail snapshot', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST') return { data: { id: 55, total_amount: '999.0000' } }
      if (path.startsWith('/erp/quotes?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/quotes/55') return { data: {
        id: 55, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, status: 'draft', currency: 'BRL', draft_revision: 1,
        subtotal_amount: '12.3456', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '12.3456', stock_reserved: false,
        needs_reconciliation: false, lines: [{ id: 91, erp_product_variant_id: 18, erp_unit_id: null, description: 'Serviço', quantity: '0.2500', unit_price: '49.3824', discount_amount: '0.0000', tax_amount: '0.0000', line_total: '12.3456' }]
      } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    const payload = { erp_establishment_id: 3, currency: 'BRL' as const, lines: [{ erp_product_variant_id: 18, erp_unit_id: null, description: 'Serviço', quantity: '0.2500', discount_amount: '0.0000', tax_amount: '0.0000' }] }
    try {
      await (state.saveDraft as (kind: string, value: typeof payload) => Promise<unknown>)('quote', payload)
      const post = calls.find(call => call.options?.method === 'POST')
      assert.equal(post?.path, '/erp/quotes')
      assert.deepEqual(post?.options?.body, payload)
      assert.equal(typeof (post?.options?.headers as Record<string, unknown>)['Idempotency-Key'], 'string')
      assert.equal(calls.some(call => call.path === '/erp/quotes/55'), true)
      assert.equal((state.selected as Vue.Ref<{ total_amount: string } | null>).value?.total_amount, '12.3456')
      assert.equal((state.selected as Vue.Ref<{ lines: Array<{ quantity: string, unit_price: string }> } | null>).value?.lines[0]?.quantity, '0.2500')
      assert.equal((state.selected as Vue.Ref<{ total_amount: string } | null>).value?.total_amount === '999.0000', false)
    } finally {
      stop()
      storage.restore()
    }
  })

  it('reads the real sale-post response shape and follows fresh payment and obligation GETs without inventing paid state', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST') return { data: { id: 23, obligation_id: 81, payment_id: 71, financial_disposition: 'receivable' } }
      if (path.startsWith('/erp/sales?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/sales/23') return { data: {
        id: 23, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, status: 'posted', fiscal_status: 'none', currency: 'BRL', draft_revision: 1,
        subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', stock_reserved: false,
        needs_reconciliation: false, lines: [{ id: 92, description: 'Serviço', quantity: '1.0000', unit_price: '10.0000', line_total: '10.0000' }],
        finance: { currency: 'BRL', original_obligation_id: 81, gross_original_due: '7.0000', opposite_return_credit: '0.0000', open_return_credit: '0.0000', net_exposure: '7.0000', needs_reconciliation: false, automatic_setoff: false, automatic_refund: false, payment_ids: [71] },
        returnability: { needs_reconciliation: false }, returnable_lines: []
      } }
      if (path === '/erp/payments/71') return { data: { id: 71, amount: '3.0000', currency: 'BRL', effective_status: 'posted', method: 'manual' } }
      if (path === '/erp/obligations/81') return { data: { id: 81, amount: '10.0000', open_amount: '7.0000', status: 'open', direction: 'receivable', currency: 'BRL' } }
      if (path === '/erp/payments/71/receipt') return { data: { version: 1, reference: 'REC-71', company: { id: 7, name: 'Escritório' }, id: 71, amount: '3.0000', currency: 'BRL', direction: 'in', effective_status: 'posted', method: 'manual', posted_at: null, actor: null } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const permissions = ['erp.sales.post', 'erp.sales.index', 'erp.sales.show', 'erp.payments.store', 'erp.payments.allocate', 'erp.payments.show', 'erp.payments.receipt', 'erp.obligations.show']
    const { state, stop } = createSalesWorkspace(api, permissions)
    const row = { id: 23, erp_establishment_id: 3, status: 'draft', draft_revision: 2, currency: 'BRL', total_amount: '10.0000' }
    try {
      const result = await (state.postSale as (document: typeof row, payment?: Record<string, unknown>) => Promise<Record<string, unknown> | null>)(row, { direction: 'in', amount: '3.0000', method: 'manual', erp_establishment_id: 3 })
      assert.deepEqual(result, { id: 23, obligation_id: 81, payment_id: 71, financial_disposition: 'receivable' })
      const post = calls.find(call => call.options?.method === 'POST')
      assert.equal(post?.path, '/erp/sales/23/post')
      assert.deepEqual(post?.options?.body, { draft_revision: 2, expected_total: '10.0000', payment: { direction: 'in', amount: '3.0000', method: 'manual', erp_establishment_id: 3 } })
      assert.equal(calls.some(call => call.path === '/erp/payments/71'), true)
      assert.equal(calls.some(call => call.path === '/erp/obligations/81'), true)
      assert.equal((state.paymentDetails as Vue.Ref<Array<{ id: number }>>).value[0]?.id, 71)
      assert.equal((state.selectedObligation as Vue.Ref<{ status: string, open_amount: string } | null>).value?.status, 'open')
      assert.equal((state.selectedObligation as Vue.Ref<{ status: string, open_amount: string } | null>).value?.open_amount, '7.0000')
      await (state.loadReceipt as (id: number) => Promise<unknown>)(71)
      assert.equal((state.receipt as Vue.Ref<{ reference: string } | null>).value?.reference, 'REC-71')
    } finally {
      stop()
      storage.restore()
    }
  })

  it('keeps a lost write frozen through lookup 404 and retries the same key, path, and body', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let writes = 0
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST') {
        writes++
        if (writes === 1) throw { statusCode: 503, message: 'timeout' }
        return { data: { id: 66, total_amount: '4.0000' } }
      }
      if (path.startsWith('/erp/operations/lookup?')) throw { statusCode: 404 }
      if (path.startsWith('/erp/quotes?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/quotes/66') return { data: {
        id: 66, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, status: 'draft', currency: 'BRL', draft_revision: 1,
        subtotal_amount: '4.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '4.0000', stock_reserved: false,
        needs_reconciliation: false, lines: []
      } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    const payload = { erp_establishment_id: 3, currency: 'BRL' as const, lines: [{ erp_product_variant_id: null, erp_unit_id: null, description: 'Serviço', quantity: '1.0000', discount_amount: '0.0000', tax_amount: '0.0000' }] }
    try {
      const save = state.saveDraft as (kind: string, value: typeof payload) => Promise<unknown>
      await save('quote', payload)
      const pending = (state.selectedOperation as Vue.Ref<{ key: string, path: string, payload: Record<string, unknown> } | null>).value
      assert.ok(pending)
      assert.equal((state.needsReconciliation as Vue.Ref<boolean>).value, true)
      await (state.reconcile as () => Promise<void>)()
      assert.equal((state.canRetryExact as Vue.Ref<boolean>).value, true)
      await (state.retryExact as () => Promise<unknown>)()
      const posts = calls.filter(call => call.options?.method === 'POST')
      assert.equal(posts.length, 2)
      assert.equal(posts[0]?.path, posts[1]?.path)
      assert.deepEqual(posts[0]?.options?.body, posts[1]?.options?.body)
      assert.equal((posts[0]?.options?.headers as Record<string, unknown>)['Idempotency-Key'], pending.key)
      assert.equal((posts[1]?.options?.headers as Record<string, unknown>)['Idempotency-Key'], pending.key)
      assert.equal((state.selected as Vue.Ref<{ id: number } | null>).value?.id, 66)
    } finally {
      stop()
      storage.restore()
    }
  })

  it('resolves the original write after a newer selection without letting its detail reload take over', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let resolveWrite: ((value: unknown) => void) | undefined
    const write = new Promise((resolve) => { resolveWrite = resolve })
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST') return await write
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    const payload = { erp_establishment_id: 3, currency: 'BRL' as const, lines: [] }
    try {
      const saving = (state.saveDraft as (kind: string, value: typeof payload) => Promise<unknown>)('quote', payload)
      while (!calls.some(call => call.options?.method === 'POST')) await new Promise(resolve => setImmediate(resolve))
      ;(state.claimIntent as () => number)()
      resolveWrite?.({ data: { id: 79 } })
      assert.deepEqual(await saving, { id: 79 })
      assert.equal((state.selectedOperation as Vue.Ref<unknown>).value, null)
      assert.equal((state.mutating as Vue.Ref<boolean>).value, false)
      assert.equal(calls.some(call => call.path === '/erp/quotes/79'), false)
    } finally {
      stop()
      storage.restore()
    }
  })

  it('stores a return draft and posts it as a separate explicit operation', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST' && path === '/erp/sale-returns') return { data: { id: 37, erp_sale_id: 23, status: 'draft', currency: 'BRL', reason: 'Parcial', total_amount: '2.5000' } }
      if (options?.method === 'POST' && path === '/erp/sale-returns/37/post') return { data: { id: 37, erp_sale_id: 23, status: 'posted', currency: 'BRL', reason: 'Parcial', total_amount: '2.5000', credit_id: 91, financial_disposition: 'opposite_credit' } }
      if (path.startsWith('/erp/sale-returns?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/sale-returns/37') return { data: { id: 37, erp_sale_id: 23, erp_establishment_id: 3, status: calls.some(call => call.path.endsWith('/post') && call.options?.method === 'POST') ? 'posted' : 'draft', currency: 'BRL', reason: 'Parcial', total_amount: '2.5000', lines: [{ source_line_id: 81, quantity: '0.2500' }] } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api, ['erp.sale-returns.store', 'erp.sale-returns.post', 'erp.sale-returns.index', 'erp.sale-returns.show'])
    try {
      await (state.storeReturn as (saleId: number, reason: string, lines: Array<{ source_line_id: number, quantity: string }>) => Promise<unknown>)(23, 'Parcial', [{ source_line_id: 81, quantity: '0.2500' }])
      const store = calls.find(call => call.path === '/erp/sale-returns' && call.options?.method === 'POST')
      assert.deepEqual(store?.options?.body, { erp_sale_id: 23, reason: 'Parcial', lines: [{ source_line_id: 81, quantity: '0.2500' }] })
      const draft = (state.selected as Vue.Ref<{ id: number, status: string } | null>).value
      assert.deepEqual(draft && { id: draft.id, status: draft.status }, { id: 37, status: 'draft' })
      await (state.postReturn as (row: { id: number, status: string }) => Promise<unknown>)(draft!)
      const post = calls.find(call => call.path === '/erp/sale-returns/37/post' && call.options?.method === 'POST')
      assert.deepEqual(post?.options?.body, {})
      assert.equal(calls.some(call => call.options?.method === 'PATCH' || call.options?.method === 'DELETE'), false)
      assert.equal((state.selected as Vue.Ref<{ status: string } | null>).value?.status, 'posted')
    } finally {
      stop()
      storage.restore()
    }
  })
})
