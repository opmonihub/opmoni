/* eslint-disable @stylistic/max-statements-per-line -- Compact renderer callbacks keep the local Vue test harness readable beside its workflow assertions. */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import * as VueCompiler from 'vue/compiler-sfc'
import ts from 'typescript'
import { erpCommercialDocumentToDraft, erpReturnLineError, erpSalePostPayload, erpSalesActionAllowed } from '../shared/utils/erpSales.ts'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as operationRecovery from '../shared/utils/erpOperationRecovery.ts'
import * as salesHelpers from '../shared/utils/erpSales.ts'
import * as taxReviewHelpers from '../shared/utils/erpCommercialTaxReview.ts'
import * as productVariantReferences from '../app/utils/erpProductVariantReferences.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

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
    if (path === '~/composables/useErpListNavigation') return loadAppComposable('composables/useErpListNavigation.ts', { ...bindings, useState: bindings.useState ?? ((_key: string, init: () => unknown) => Vue.ref(init())) })
    if (path === '~/composables/useErpReadScope') return loadAppComposable('composables/useErpReadScope.ts')
    if (!(path in imports)) throw new Error(`Unexpected import ${path}`)
    return imports[path]
  }, exports, ...Object.values(bindings))
  return exports
}

function createSalesWorkspace(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, permissions = ['*'], refreshWithStatus: () => Promise<{ committed: boolean, identity?: unknown }> = async () => ({ committed: true, identity: { id: 91 } })) {
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, permissions, role: 'owner' })
  const sessionGeneration = Vue.ref(0)
  const contextKey = Vue.computed(() => `${user.value.id}:${currentCompany.value.id}:${currentCompany.value.role}:${currentCompany.value.permissions.join(',')}`)
  const context = { key: contextKey, capture: () => ({ key: contextKey.value }), isCurrent: (token: { key: string }) => token.key === contextKey.value }
  const scope = Vue.effectScope()
  const exports = compileModule(readFileSync(new URL('../app/composables/useErpSalesWorkspace.ts', import.meta.url), 'utf8'), {
    '#shared/types/erpBusiness': {},
    '~/composables/useApiError': apiErrors,
    '#shared/utils/erpOperationRecovery': operationRecovery,
    '#shared/utils/erpCommercialTaxReview': taxReviewHelpers,
    '#shared/utils/erpSales': { erpSalesActionAllowed, erpSalePostPayload }
  }, {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted: (_callback: () => void) => {}, onScopeDispose: Vue.onScopeDispose,
    useState: (_key: string, initializer: () => unknown) => _key === 'access-session-generation' ? sessionGeneration : Vue.ref(initializer()),
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany, refreshWithStatus }),
    useErpAdministrationContext: () => context
  })
  const state = scope.run(() => (exports.useErpSalesWorkspace as (initialArea?: string) => Record<string, unknown>)('quotes'))!
  return { state, user, currentCompany, contextKey, sessionGeneration, stop: () => scope.stop() }
}

describe('sales authoritative denial boundaries', () => {
  const boundaries = [
    ['list', '/erp/quotes?', 'load', []],
    ['detail', '/erp/sales/5', 'inspect', ['sales', 5]],
    ['references', '/erp/contacts?', 'loadReferences', []],
    ['payment', '/erp/payments/8', 'inspect', ['sales', 6]],
    ['obligation', '/erp/obligations/9', 'inspect', ['sales', 6]],
    ['receipt', '/erp/payments/8/receipt', 'loadReceipt', [8]],
    ['mutation', '/erp/quotes', 'saveDraft', ['quote', { erp_establishment_id: 3, currency: 'BRL', lines: [] }]],
    ['recovery', '/erp/operations/lookup?', 'reconcile', []]
  ] as const
  for (const [index, [label, deniedPath, method, args]] of boundaries.entries()) {
    it(`clears all private state before refresh at ${label} and keeps the durable original identity`, async () => {
      const storage = installSessionStorage()
      let settleIdentity!: (value: { committed: boolean, identity?: unknown }) => void
      const identityResult = new Promise<{ committed: boolean, identity?: unknown }>((resolve) => { settleIdentity = resolve })
      let refreshes = 0
      let releaseOldReferences!: (value: unknown) => void
      let oldReferenceRead = true
      const status = [401, 403, 409][index % 3]!
      const api = async (path: string) => {
        if (oldReferenceRead && path.startsWith('/erp/establishments?')) {
          oldReferenceRead = false
          return new Promise((resolve) => { releaseOldReferences = resolve })
        }
        if (path === deniedPath || (path.startsWith(deniedPath) && deniedPath.endsWith('?'))) throw { response: { status, _data: { code: status === 409 ? 'company_context_changed' : 'denied' } } }
        if (path === '/erp/sales/6') return { data: { id: 6, finance: { payment_ids: [8], original_obligation_id: 9 } } }
        if (path === '/erp/payments/8') return { data: { id: 8 } }
        if (path === '/erp/obligations/9') return { data: { id: 9 } }
        return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      }
      const created = createSalesWorkspace(api, ['*'], async () => { refreshes++; return identityResult })
      const state = Vue.proxyRefs(created.state)
      const invoke = (name: string, ...values: unknown[]) => (state[name] as (...values: unknown[]) => Promise<unknown>)(...values)
      const pending = { ...operationRecovery.createPendingOperation('91:7:erp.quotes.store', 'erp.quotes.store', '/erp/quotes', { private: 'durable-original' }, 'denied-original'), method: 'POST' }
      storage.values.set('erp-sales:v1:91:7', JSON.stringify(pending))
      state.selectedOperation = label === 'mutation' ? null : pending
      state.selected = { id: 44, private: 'detail' }
      state.paymentDetails = [{ id: 8 }]
      state.selectedObligation = { id: 9 }
      state.receipt = { private: 'receipt' }
      ;(state.rows as Record<string, unknown[]>).quotes = [{ id: 44 }]
      const references = state.references as { establishments: unknown[], contacts: unknown[] }
      references.establishments = [{ id: 3, name: 'Private' }]
      references.contacts = [{ id: 4 }]
      const late = invoke('loadReferences')
      const work = invoke(method, ...args)
      await new Promise(resolve => setImmediate(resolve))
      try {
        assert.equal(refreshes, 1)
        assert.equal(state.selected, null)
        assert.deepEqual(state.paymentDetails, [])
        assert.equal(state.selectedObligation, null)
        assert.equal(state.receipt, null)
        assert.deepEqual(references.establishments, [])
        assert.deepEqual(references.contacts, [])
        for (const name of ['quotes', 'orders', 'sales', 'returns']) assert.deepEqual((state.rows as Record<string, unknown>)[name], [])
        assert.equal((state.allowed as (action: string) => boolean)('erp.quotes.store'), false)
        assert.equal(state.selectedOperation, null, 'protected pending payload is not left in presentation state')
        assert.ok(storage.values.get('erp-sales:v1:91:7'), 'the original operation journal survives')
        await invoke('reconcile')
        assert.equal(state.selectedOperation, null, 'recovery lookup cannot rehydrate the private journal while authority is refused')
        releaseOldReferences({ data: [{ id: 100, name: 'Late private' }], meta: { last_page: 1 } })
        await late
        assert.deepEqual(references.establishments, [])
        settleIdentity({ committed: false })
        await work
        assert.equal((state.allowed as (action: string) => boolean)('erp.quotes.store'), false)
        assert.equal(state.mutating, false)
      } finally {
        settleIdentity({ committed: false })
        created.stop()
        storage.restore()
      }
    })
  }

  it('restores only the durable original operation after committed identity recovery', async () => {
    const storage = installSessionStorage()
    let settleIdentity!: (value: { committed: boolean, identity?: unknown }) => void
    const identityResult = new Promise<{ committed: boolean, identity?: unknown }>((resolve) => { settleIdentity = resolve })
    let denied = true
    const api = async (path: string) => {
      if (denied && path.startsWith('/erp/quotes?')) {
        denied = false
        throw { response: { status: 403, _data: { code: 'forbidden' } } }
      }
      return { data: [{ id: 12 }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
    }
    const created = createSalesWorkspace(api, ['*'], () => identityResult)
    const state = Vue.proxyRefs(created.state)
    const journal = operationRecovery.createPendingOperation('91:7:erp.quotes.store', 'erp.quotes.store', '/erp/quotes', { private: 'durable-original' }, '00000000-0000-4000-8000-000000000091')
    storage.values.set('erp-sales:v1:91:7', JSON.stringify({ ...journal, method: 'POST' }))
    state.rows.quotes = [{ id: 1, private: 'old-list' }]
    state.selected = { id: 1, private: 'old-detail' }
    await (state.load as () => Promise<void>)()
    assert.equal(state.authorityNeedsRefresh, true)
    assert.deepEqual(state.rows.quotes, [])
    assert.equal(state.selected, null)
    assert.equal(state.selectedOperation, null)
    settleIdentity({ committed: true, identity: { id: 91 } })
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(state.authorityNeedsRefresh, false)
    assert.equal((state.selectedOperation as { key?: string } | null)?.key, journal.key)
    assert.notDeepEqual(state.rows.quotes, [{ id: 1, private: 'old-list' }])
    created.stop()
    storage.restore()
  })

  it('does not treat an ordinary business 409 as authority invalidation', async () => {
    let refreshes = 0
    const api = async () => { throw { response: { status: 409, _data: { code: 'draft_revision_conflict', message: 'Revision changed' } } } }
    const created = createSalesWorkspace(api, ['*'], async () => { refreshes++; return { committed: true, identity: { id: 91 } } })
    const state = Vue.proxyRefs(created.state)
    state.rows.quotes = [{ id: 1 }]
    await (state.load as () => Promise<void>)()
    assert.equal(refreshes, 0)
    assert.equal(state.authorityNeedsRefresh, false)
    assert.deepEqual(state.rows.quotes, [{ id: 1 }])
    created.stop()
  })
})

function mountSalesWorkspace(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, hash = '#quotes', permissions = ['*']) {
  const { state, currentCompany, contextKey, stop } = createSalesWorkspace(api, permissions)
  const route = Vue.reactive({ path: '/erp/vendas', hash, query: {} })
  const router = { replace: async (location: { path?: string, query?: Record<string, string>, hash?: string }) => {
    if (location.path) route.path = location.path
    if (location.query) route.query = location.query
    if (location.hash) route.hash = location.hash
  } }
  const workspaceSource = readFileSync(new URL('../app/components/erp/ErpSalesWorkspace.vue', import.meta.url), 'utf8')
  const formSource = readFileSync(new URL('../app/components/erp/ErpCommercialDraftForm.vue', import.meta.url), 'utf8')
  const returnFormSource = readFileSync(new URL('../app/components/erp/ErpSaleReturnForm.vue', import.meta.url), 'utf8')
  const workspaceScript = VueCompiler.compileScript(VueCompiler.parse(workspaceSource).descriptor, { id: 'sales-workspace', inlineTemplate: true })
  const formScript = VueCompiler.compileScript(VueCompiler.parse(formSource).descriptor, { id: 'sales-workspace-draft', inlineTemplate: true })
  const returnFormScript = VueCompiler.compileScript(VueCompiler.parse(returnFormSource).descriptor, { id: 'sales-workspace-return', inlineTemplate: true })
  const globals = { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted: Vue.onMounted, useRoute: () => route, useRouter: () => router, useErpAdministrationContext: () => ({ key: contextKey }) }
  const formExports = compileModule(formScript.content, { 'vue': Vue, '~/utils/erpProductVariantReferences': productVariantReferences }, globals)
  const form = formExports.default as Vue.Component
  const returnFormExports = compileModule(returnFormScript.content, { 'vue': Vue, '#shared/utils/erpSales': salesHelpers }, globals)
  const returnForm = returnFormExports.default as Vue.Component
  const workspaceExports = compileModule(workspaceScript.content, {
    'vue': Vue,
    '~/composables/useErpSalesWorkspace': { useErpSalesWorkspace: () => state },
    '#shared/utils/erpCommercialTaxReview': taxReviewHelpers,
    '#shared/utils/erpSales': salesHelpers,
    '~/utils/erpWorkspaceNav': loadAppComposable('utils/erpWorkspaceNav.ts'),
    '~/components/erp/erpPresentation': loadAppComposable('components/erp/erpPresentation.ts')
  }, globals)
  const workspace = workspaceExports.default as Vue.Component
  type Node = { style?: Record<string, string>, tag?: string, text?: string, children: Node[], parent: Node | null, props: Record<string, unknown> }
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
    createElement(tag) { return { tag, style: {}, children: [], parent: null, props: {} } },
    createText(text) { return { text, children: [], parent: null, props: {} } },
    createComment(text) { return { text, children: [], parent: null, props: {} } },
    setText(node, text) { node.text = text },
    setElementText(node, text) { node.children = []; node.text = text },
    parentNode(node) { return node.parent },
    nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const passthrough = Vue.defineComponent({ setup: (_props, context) => () => Vue.h('div', context.slots.default?.() ?? []) })
  const button = Vue.defineComponent({ props: { label: String, disabled: Boolean }, setup: (props, context) => () => Vue.h('button', { ...context.attrs, disabled: props.disabled }, props.label ?? context.slots.default?.() ?? []) })
  const input = Vue.defineComponent({ props: { modelValue: [String, Number] }, setup: (props, context) => () => Vue.h('input', {
    ...context.attrs,
    value: props.modelValue ?? '',
    onInput: (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value)
  }) })
  const formShell = Vue.defineComponent({ setup: (_props, context) => () => Vue.h('form', { ...context.attrs, onSubmit: (event: { preventDefault: () => void }) => { event.preventDefault(); context.emit('submit', event) } }, context.slots.default?.() ?? []) })
  const modal = Vue.defineComponent({ props: { open: Boolean }, emits: ['update:open'], setup: (props, context) => () => props.open
    ? Vue.h('div', [
        Vue.h('button', { onClick: () => context.emit('update:open', false) }, 'Fechar modal'),
        ...(context.slots.body?.() ?? [])
      ])
    : null })
  const detail = Vue.defineComponent({ props: { area: String, canEdit: Boolean, canConvert: Boolean, canPostSale: Boolean, canReturn: Boolean, document: Object }, emits: ['edit', 'convert', 'postSale', 'returnSale'], setup: (props, context) => () => Vue.h('section', [
    Vue.h('span', null, (props.document as { status?: string } | undefined)?.status === 'converted' ? props.area === 'quotes' ? 'Orçamento convertido' : 'Pedido convertido' : ''),
    ...(props.canEdit ? [Vue.h('button', { onClick: () => context.emit('edit') }, 'Editar rascunho')] : []),
    ...(props.canConvert ? [Vue.h('button', { onClick: () => context.emit('convert') }, 'Converter')] : []),
    ...(props.canPostSale ? [Vue.h('button', { onClick: () => context.emit('postSale') }, 'Lançar venda')] : []),
    ...(props.canReturn ? [Vue.h('button', { onClick: () => context.emit('returnSale') }, 'Devolver item')] : []),
    Vue.h('span', null, (props.document as { status?: string } | undefined)?.status ?? '')
  ]) })
  const field = Vue.defineComponent({ props: { name: String, error: String }, setup: (props, context) => () => Vue.h('div', { 'data-field-name': props.name, 'data-field-error': props.error }, context.slots.default?.() ?? []) })
  const alert = Vue.defineComponent({ setup: (_props, context) => () => Vue.h('section', [
    ...(context.slots.default?.() ?? []), ...(context.slots.actions?.() ?? [])
  ]) })
  const pageShell = Vue.defineComponent({ setup: (_props, context) => () => Vue.h('main', [
    ...(context.slots.actions?.() ?? []), ...(context.slots.toolbar?.() ?? []), ...(context.slots.default?.() ?? [])
  ]) })
  const app = renderer.createApp(workspace)
  let submitDraft = () => {}
  let submitReturn = () => {}
  const instrumentedDraftForm = Vue.defineComponent({
    inheritAttrs: false,
    setup: (_props, context) => {
      const child = Vue.ref<{ submit?: () => void } | null>(null)
      Vue.onMounted(() => { submitDraft = () => child.value?.submit?.() })
      return () => Vue.h(form, { ...context.attrs, ref: child })
    }
  })
  const instrumentedReturnForm = Vue.defineComponent({
    inheritAttrs: false,
    emits: ['update:draft', 'submit', 'cancel'],
    setup: (_props, context) => {
      const child = Vue.ref<{ submit?: () => void } | null>(null)
      Vue.onMounted(() => { submitReturn = () => child.value?.submit?.() })
      return () => Vue.h(returnForm, {
        ...context.attrs,
        'ref': child,
        'onUpdate:draft': (draft: unknown) => context.emit('update:draft', draft),
        'onSubmit': (...values: unknown[]) => context.emit('submit', ...values),
        'onCancel': () => context.emit('cancel')
      })
    }
  })
  const select = Vue.defineComponent({ inheritAttrs: false, props: { items: Array, modelValue: [String, Number] }, emits: ['update:modelValue'], setup: (props, context) => () => Vue.h('select', {
    ...context.attrs,
    value: props.modelValue,
    onChange: (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value)
  }, (props.items ?? []).map((item: unknown) => Vue.h('option', { value: (item as { value: unknown }).value, disabled: (item as { disabled?: boolean }).disabled }, (item as { label?: string }).label ?? ''))) })
  for (const name of ['UCheckbox', 'UBadge', 'UIcon', 'CompanyMenu', 'UserMenu', 'UDropdownMenu']) app.component(name, passthrough)
  app.component('USelect', select)
  app.component('UAlert', alert)
  app.component('UFormField', field)
  app.component('ErpCatalogReferenceSelect', passthrough)
  app.component('ErpCommercialTaxReview', passthrough)
  app.component('UButton', button)
  app.component('UInput', input)
  app.component('UTextarea', input)
  app.component('UForm', formShell)
  app.component('UModal', modal)
  app.component('ErpCatalogPageShell', pageShell)
  app.component('UNavigationMenu', Vue.defineComponent({
    props: ['items'],
    setup: props => () => Vue.h('nav', (props.items as Array<Array<{ label: string, to?: { path?: string, query?: Record<string, string>, hash?: string } }>> ?? []).flat().map(item => Vue.h('button', {
      onClick: () => item.to && router.replace(item.to)
    }, item.label)))
  }))
  app.component('ErpListFooter', passthrough)
  app.component('UTable', passthrough)
  app.component('ErpSaleDetail', detail)
  app.component('ErpCommercialDraftForm', instrumentedDraftForm)
  app.component('ErpSaleReturnForm', instrumentedReturnForm)
  app.mount(root)
  function walk(node: Node): Node[] { return [node, ...node.children.flatMap(walk)] }
  function findButton(label: string) { return walk(root).find(node => node.tag === 'button' && node.text === label) }
  async function click(label: string) {
    const node = findButton(label)
    assert.ok(node, `Expected button ${label}`)
    const handler = node.props.onClick
    if (typeof handler === 'function') handler(new Event('click'))
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
  }
  function textContent(node: Node): string { return `${node.text ?? ''}${node.children.map(textContent).join('')}` }
  return { root, state, currentCompany, route, stop, app, walk, findButton, click, textContent, submitDraft: () => submitDraft(), submitReturn: () => submitReturn(), dispose: () => { app.unmount(); stop() } }
}

describe('sales permitted area projection', () => {
  const emptyPage = { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }

  it('selects Orders for an orders-only root without loading Quotes', async (t) => {
    const calls: string[] = []
    const mounted = mountSalesWorkspace(async (path) => { calls.push(path); return emptyPage }, '', ['erp.orders.index'])
    t.after(mounted.dispose)
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
    assert.equal((mounted.state.area as Vue.Ref<string>).value, 'orders')
    assert.equal(mounted.route.hash, '')
    assert.ok(mounted.findButton('Pedidos'))
    assert.equal(mounted.findButton('Orçamentos'), undefined)
    assert.equal(calls.some(path => path.startsWith('/erp/quotes?')), false)
    mounted.currentCompany.value.permissions.splice(0, 1, 'erp.quotes.index')
    await Vue.nextTick()
    assert.equal((mounted.state.area as Vue.Ref<string>).value, 'quotes')
    assert.equal(mounted.route.hash, '')
  })

  it('shows denial for a known denied Orders hash instead of the previous permitted Quotes body', async (t) => {
    const calls: string[] = []
    const mounted = mountSalesWorkspace(async (path) => { calls.push(path); return emptyPage }, '#orders', ['erp.quotes.index'])
    t.after(mounted.dispose)
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(mounted.route.hash, '#orders')
    assert.equal((mounted.state.area as Vue.Ref<string>).value, 'orders')
    assert.equal(mounted.findButton('Pedidos'), undefined)
    assert.match(mounted.textContent(mounted.root), /Seu acesso não permite consultar esta lista/)
    mounted.state.rows.orders = [{ id: 99, order_no: 'PRIVATE-ORDER-99' }]
    await Vue.nextTick()
    assert.doesNotMatch(mounted.textContent(mounted.root), /PRIVATE-ORDER-99/)
    assert.equal(calls.some(path => path.startsWith('/erp/orders?')), false)
  })
})

function mountSaleDetail(props: Record<string, unknown>) {
  const source = readFileSync(new URL('../app/components/erp/ErpSaleDetail.vue', import.meta.url), 'utf8')
  const script = VueCompiler.compileScript(VueCompiler.parse(source).descriptor, { id: 'sale-detail', inlineTemplate: true })
  const component = compileModule(script.content, { 'vue': Vue, '#shared/utils/erpCommercialTaxReview': taxReviewHelpers }, { ref: Vue.ref, computed: Vue.computed }).default as Vue.Component
  type Node = { tag?: string, text?: string, children: Node[], parent: Node | null, props: Record<string, unknown> }
  const root: Node = { tag: 'root', children: [], parent: null, props: {} }
  const renderer = Vue.createRenderer<Node>({
    patchProp(node, key, _previous, next) { node.props[key] = next },
    insert(node, parent, anchor) { node.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(node); else parent.children.splice(index, 0, node) },
    remove(node) { if (node.parent) { const index = node.parent.children.indexOf(node); if (index >= 0) node.parent.children.splice(index, 1) } },
    createElement(tag) { return { tag, children: [], parent: null, props: {} } },
    createText(text) { return { text, children: [], parent: null, props: {} } }, createComment(text) { return { text, children: [], parent: null, props: {} } },
    setText(node, text) { node.text = text }, setElementText(node, text) { node.children = []; node.text = text }, parentNode(node) { return node.parent }, nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp({ render: () => Vue.h(component, props) })
  for (const name of ['UBadge', 'UAlert', 'UButton', 'NuxtLink']) app.component(name, { setup: (_props, context) => () => Vue.h('span', context.slots.default?.() ?? context.slots.description?.() ?? []) })
  app.mount(root)
  const textContent = (node: Node): string => `${node.text ?? ''}${node.children.map(textContent).join('')}`
  return { text: textContent(root), dispose: () => app.unmount() }
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
  it('keeps document numbers editable on create', async (t) => {
    const api = async (path: string) => path.startsWith('/erp/') ? { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } } : { data: [] }
    const mounted = mountSalesWorkspace(api)
    t.after(() => mounted.dispose())
    await mounted.click('Novo registro')
    const createField = mounted.walk(mounted.root).find(node => node.props['data-field-name'] === 'quote_no')
    const createNumber = createField && mounted.walk(createField).find(node => node.tag === 'input')
    assert.ok(createNumber)
    assert.notEqual(createNumber.props.readonly, true, 'creation keeps the document number editable')
    await mounted.click('Cancelar')
  })

  it('labels known fiscal and payment states and keeps unknown values explicit', (t) => {
    const detail = mountSaleDetail({
      area: 'sales', document: { id: 23, status: 'posted', fiscal_status: 'authorized', currency: 'BRL', subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', lines: [], finance: {} },
      payments: [{ id: 7, amount: '10.0000', currency: 'BRL', effective_status: 'posted' }], paymentIds: [7], obligation: null, receipt: null,
      canEdit: false, canConvert: false, canPostSale: false, canReturn: false, canPostReturn: false, canReceipt: false,
      canReadPayments: true, canPaymentsIndex: false, canObligationsIndex: false, canFiscal: false, busy: false
    })
    t.after(detail.dispose)
    assert.match(detail.text, /Fiscal: Autorizada/)
    assert.match(detail.text, /Lançado/)
    const unknown = mountSaleDetail({
      area: 'sales', document: { id: 24, status: 'posted', fiscal_status: 'mystery', currency: 'BRL', subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', lines: [], finance: {} },
      payments: [{ id: 8, amount: '10.0000', currency: 'BRL', effective_status: 'pending_future' }], paymentIds: [8], obligation: null, receipt: null,
      canEdit: false, canConvert: false, canPostSale: false, canReturn: false, canPostReturn: false, canReceipt: false,
      canReadPayments: true, canPaymentsIndex: false, canObligationsIndex: false, canFiscal: false, busy: false
    })
    t.after(unknown.dispose)
    assert.match(unknown.text, /Situação fiscal não reconhecida \(mystery\)/)
    assert.match(unknown.text, /Situação do pagamento não reconhecida \(pending_future\)/)
  })

  it('uses nonempty status options in every mounted area and clears API filters without sending the UI sentinel', async () => {
    const storage = installSessionStorage()
    const calls: string[] = []
    const api = async (path: string) => {
      calls.push(path)
      return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
    }
    const mounted = mountSalesWorkspace(api)
    const scenarios = [
      { label: 'Orçamentos', path: '/erp/quotes', statuses: ['draft', 'converted'] },
      { label: 'Pedidos', path: '/erp/orders', statuses: ['confirmed', 'converted'] },
      { label: 'Vendas / balcão', path: '/erp/sales', statuses: ['draft', 'posted'] },
      { label: 'Devoluções', path: '/erp/sale-returns', statuses: ['draft', 'posted'] }
    ]
    function statusSelect() {
      const node = mounted.walk(mounted.root).find(node => node.tag === 'select')
      assert.ok(node, 'Expected the mounted status USelect')
      return node
    }
    async function chooseStatus(value: string) {
      const change = statusSelect().props.onChange as (event: { target: { value: string } }) => void
      change({ target: { value } })
      await Vue.nextTick()
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
    }
    function latestListQuery(path: string) {
      const request = calls.filter(call => call.startsWith(`${path}?`)).at(-1)
      assert.ok(request, `Expected list request for ${path}`)
      return new URL(request, 'http://localhost').searchParams
    }
    try {
      for (const scenario of scenarios) {
        await mounted.click(scenario.label)
        const options = mounted.walk(statusSelect()).filter(node => node.tag === 'option')
        assert.ok(options.every(option => typeof option.props.value === 'string' && option.props.value.length > 0), `${scenario.label} must never pass an empty item value to USelect`)
        assert.deepEqual(options.map(option => option.props.value), ['all', ...scenario.statuses])
        assert.equal(statusSelect().props.value, 'all')
        assert.equal(latestListQuery(scenario.path).has('status'), false)
        for (const status of scenario.statuses) {
          const beforeChange = calls.length
          await chooseStatus(status)
          assert.ok(calls.length > beforeChange, 'Changing the mounted select must load the list')
          assert.equal(statusSelect().props.value, status)
          assert.equal(latestListQuery(scenario.path).get('status'), status)
          await chooseStatus('all')
          assert.equal(statusSelect().props.value, 'all')
          assert.equal((mounted.state.filters as { status: string }).status, '')
          assert.equal(latestListQuery(scenario.path).has('status'), false)
        }
      }
      assert.equal(calls.some(path => new URL(path, 'http://localhost').searchParams.get('status') === 'all'), false)
    } finally {
      mounted.dispose()
      storage.restore()
    }
  })

  it('renders actual API unit names for a saved line and submits the selected unit ID', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const units = [
      { id: 1, short_name: 'UN', actual_name: 'Unidade', allow_decimal: true, base_unit_id: null, conversion_factor: null, status: 'active' },
      { id: 2, short_name: 'CX', actual_name: 'Caixa', allow_decimal: false, base_unit_id: 1, conversion_factor: '12.00000000', status: 'active' },
      { id: 3, short_name: 'ANT', actual_name: 'Unidade arquivada', allow_decimal: true, base_unit_id: null, conversion_factor: null, status: 'archived' }
    ]
    const document = { id: 66, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, quote_no: 'ORC-66', status: 'draft', currency: 'BRL', draft_revision: 3,
      subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', stock_reserved: false, needs_reconciliation: false,
      lines: [{ id: 88, erp_product_variant_id: 18, erp_unit_id: 1, quantity: '1.0000', unit_price: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', line_total: '10.0000', product_snapshot: { description: 'Produto congelado' } }] }
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path.startsWith('/erp/quotes?')) return { data: [document], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path.startsWith('/erp/units?')) return { data: units, meta: { current_page: 1, last_page: 1, per_page: 100, total: 3 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/erp/contacts?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/quotes/66') return { data: document }
      throw new Error(`Unexpected API path ${path}`)
    }
    const mounted = mountSalesWorkspace(api)
    try {
      await mounted.click('Orçamentos')
      const row = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes('ORC-66'))
      assert.ok(row)
      ;(row.props.onClick as () => void)()
      await Vue.nextTick()
      await new Promise(resolve => setImmediate(resolve))
      await mounted.click('Editar rascunho')
      const numberField = mounted.walk(mounted.root).find(node => node.props['data-field-name'] === 'quote_no')
      const quoteNumber = numberField && mounted.walk(numberField).find(node => node.tag === 'input')
      assert.ok(quoteNumber)
      assert.equal(quoteNumber.props.readonly, true, 'editing cannot change the assigned document number')
      function unitSelect() {
        const field = mounted.walk(mounted.root).find(node => node.props['data-field-name'] === 'lines.0.erp_unit_id')
        assert.ok(field)
        const select = mounted.walk(field).find(node => node.tag === 'select')
        assert.ok(select)
        return select
      }
      const options = mounted.walk(unitSelect()).filter(node => node.tag === 'option')
      assert.deepEqual(options.map(option => ({ value: option.props.value, label: mounted.textContent(option) })), [
        { value: 0, label: 'Unidade do catálogo' }, { value: 1, label: 'Unidade (UN)' }, { value: 2, label: 'Caixa (CX)' }
      ])
      assert.equal(unitSelect().props.value, 1)
      ;(unitSelect().props.onChange as (event: unknown) => void)({ target: { value: '2' } })
      await Vue.nextTick()
      assert.equal(unitSelect().props.value, 2)
      mounted.submitDraft()
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
      const saved = calls.find(call => call.path === '/erp/quotes/66' && call.options?.method === 'PATCH')
      assert.ok(saved)
      assert.equal((saved.options?.body as { lines: Array<{ erp_unit_id: number }> }).lines[0]?.erp_unit_id, 2)
    } finally {
      mounted.dispose()
      storage.restore()
    }
  })

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

  it('keeps mounted edits through cancel, reopen, revision reapply, and exact submit', async () => {
    const source = readFileSync(new URL('../app/components/erp/ErpCommercialDraftForm.vue', import.meta.url), 'utf8')
    const { descriptor } = VueCompiler.parse(source)
    const script = VueCompiler.compileScript(descriptor, { id: 'sales-draft', inlineTemplate: true })
    const bindings = { computed: Vue.computed, ref: Vue.ref, watch: Vue.watch }
    const exports = compileModule(script.content, { 'vue': Vue, '~/utils/erpProductVariantReferences': productVariantReferences }, bindings)
    const component = exports.default as { setup: (props: unknown, context: { emit: (event: string, value: unknown) => void, expose: (value: unknown) => void }) => unknown }
    const draft = Vue.reactive({
      erp_establishment_id: 7,
      draft_revision: 4,
      currency: 'BRL' as const,
      lines: [{ erp_product_variant_id: 18, erp_unit_id: 4, description: '', quantity: '0.2500', discount_amount: '0.0002', tax_amount: '0.0001', unit_price: '12.3400', unit_conversion_factor: '1.25000000', line_total: '15.4250' }]
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
    const instance = Vue.ref<{ submit: () => void, draft: typeof draft, selectVariant: (line: typeof draft.lines[number], value: unknown) => void, selectUnit: (line: typeof draft.lines[number], value: unknown) => void, useCurrentCatalog: (line: typeof draft.lines[number]) => void } | null>(null)
    const open = Vue.ref(true)
    const parentDraft = Vue.ref(draft)
    const host = Vue.defineComponent({ setup: () => () => open.value
      ? Vue.h(component, {
          'ref': instance,
          'kind': 'sale', 'draft': parentDraft.value, 'references': { establishments: [], contacts: [], units: [], variants: [] }, 'errors': {}, 'saving': false, 'canOverridePrice': false, 'canOverrideDiscount': false,
          'onUpdate:draft': (value: typeof draft) => { parentDraft.value = value },
          'onSave': (value: unknown) => { submitted = value }
        })
      : null })
    const app = renderer.createApp(host)
    const passthrough = Vue.defineComponent({ setup: (_props, context) => () => Vue.h('div', context.slots.default?.() ?? []) })
    const referenceSelect = Vue.defineComponent({
      inheritAttrs: false,
      props: ['path', 'query', 'modelValue'],
      emits: ['update:modelValue', 'selection-record', 'selection-validity'],
      setup: (_props, context) => () => Vue.h('div', { ...context.attrs, 'data-reference-select-stub': 'true' })
    })
    for (const name of ['UForm', 'UFormField', 'UInput', 'USelect', 'UAlert', 'UButton']) app.component(name, passthrough)
    app.component('ErpCatalogReferenceSelect', referenceSelect)
    app.mount(root)
    assert.ok(instance.value)
    instance.value.draft.lines[0]!.quantity = '0.5000'
    assert.equal(parentDraft.value.lines[0]!.quantity, '0.5000')
    instance.value.selectVariant(instance.value.draft.lines[0]!, { id: 18, source: 'selection' })
    instance.value.selectUnit(instance.value.draft.lines[0]!, 4)
    assert.equal(instance.value.draft.lines[0]!.unit_price, '12.3400')
    assert.equal(instance.value.draft.lines[0]!.unit_conversion_factor, '1.25000000')
    instance.value.selectUnit(instance.value.draft.lines[0]!, 5)
    assert.equal(instance.value.draft.lines[0]!.unit_price, undefined)
    assert.equal(instance.value.draft.lines[0]!.unit_conversion_factor, undefined)
    instance.value.draft.lines[0]!.erp_unit_id = 4
    instance.value.draft.lines[0]!.unit_price = '12.3400'
    instance.value.draft.lines[0]!.unit_conversion_factor = '1.25000000'
    instance.value.draft.lines[0]!.line_total = '15.4250'
    await Vue.nextTick()
    open.value = false
    await Vue.nextTick()
    open.value = true
    await Vue.nextTick()
    assert.equal(instance.value?.draft.lines[0]?.quantity, '0.5000')
    parentDraft.value = { ...parentDraft.value, draft_revision: 8 }
    open.value = false
    await Vue.nextTick()
    open.value = true
    await Vue.nextTick()
    assert.equal(instance.value?.draft.draft_revision, 8)
    assert.equal(instance.value?.draft.lines[0]?.quantity, '0.5000')
    instance.value.useCurrentCatalog(instance.value.draft.lines[0]!)
    assert.equal(parentDraft.value.lines[0]?.unit_price, undefined)
    instance.value.submit()
    assert.deepEqual(submitted, {
      erp_establishment_id: 7,
      draft_revision: 8,
      currency: 'BRL',
      lines: [{ erp_product_variant_id: 18, erp_unit_id: null, quantity: '0.5000', discount_amount: '0.0002', tax_amount: '0.0001' }]
    })
    app.unmount()
    assert.match(source, /inputmode="decimal"/)
    assert.doesNotMatch(source, /UInputNumber/)
  })

  it('keeps edits in the actual workspace across cancel, reopen, stale revision, and reapply', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let patches = 0
    const detail = (revision: number, quantity: string) => ({ data: {
      id: 66, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, status: 'draft', currency: 'BRL', draft_revision: revision,
      subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', stock_reserved: false,
      needs_reconciliation: false, lines: [{ id: 88, erp_product_variant_id: 18, erp_unit_id: null, quantity, unit_price: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', line_total: '10.0000', product_snapshot: { description: 'Produto congelado' } }]
    } })
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path.startsWith('/erp/quotes?')) return { data: [{ id: 66, erp_establishment_id: 3, quote_no: 'ORC-66', status: 'draft', currency: 'BRL', total_amount: '10.0000' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/quotes/66' && options?.method === 'PATCH') {
        patches++
        if (patches === 1) throw { statusCode: 409, message: 'Rascunho alterado.' }
        return { data: { id: 66 } }
      }
      if (path === '/erp/quotes/66') return detail(patches === 0 ? 3 : 4, '1.0000')
      throw new Error(`Unexpected API path ${path}`)
    }
    const mounted = mountSalesWorkspace(api)
    try {
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
      const row = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes('ORC-66'))
      assert.ok(row, 'actual workspace quote row is mounted')
      ;(row.props.onClick as () => void)()
      await Vue.nextTick()
      await new Promise(resolve => setImmediate(resolve))
      await mounted.click('Editar rascunho')
      const quantityInput = () => mounted.walk(mounted.root).find((node) => {
        if (node.tag !== 'input' || node.props.inputmode !== 'decimal') return false
        let parent = node.parent
        while (parent) { if (parent.props['data-field-name'] === 'lines.0.quantity') return true; parent = parent.parent }
        return false
      })
      const initialInput = quantityInput()
      assert.ok(initialInput)
      ;(initialInput.props.onInput as (event: unknown) => void)({ target: { value: '0.5000' } })
      await Vue.nextTick()
      await mounted.click('Cancelar')
      const reopenedRow = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes('ORC-66'))
      assert.ok(reopenedRow)
      ;(reopenedRow.props.onClick as () => void)()
      await Vue.nextTick()
      await new Promise(resolve => setImmediate(resolve))
      await mounted.click('Editar rascunho')
      assert.equal(quantityInput()?.props.value, '0.5000')
      mounted.submitDraft()
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
      assert.deepEqual((mounted.state.revisionConflict as Vue.Ref<unknown>).value, { area: 'quotes', id: 66, latestRevision: 4 }, JSON.stringify({ calls, error: (mounted.state.mutationError as Vue.Ref<string>).value }))
      await mounted.click('Reaplicar minhas alterações')
      assert.equal(quantityInput()?.props.value, '0.5000')
      mounted.submitDraft()
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
      const updates = calls.filter(call => call.path === '/erp/quotes/66' && call.options?.method === 'PATCH')
      assert.equal(updates.length, 2)
      assert.equal((updates[0]?.options?.body as { lines: Array<{ quantity: string }> }).lines[0]?.quantity, '0.5000')
      assert.equal((updates[1]?.options?.body as { lines: Array<{ quantity: string }> }).lines[0]?.quantity, '0.5000')
    } finally {
      mounted.dispose()
      storage.restore()
    }
  })

  it('labels converted quotes and orders and hides their edit and convert actions', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let quote6Reads = 0
    const document = (id: number, status: string, area: 'quotes' | 'orders') => ({
      data: { id, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, status, currency: 'BRL', draft_revision: 2,
        subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', stock_reserved: false, needs_reconciliation: false,
        ...(area === 'quotes' ? { quote_no: `ORC-${id}` } : { order_no: `PED-${id}` }), lines: [] }
    })
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path.startsWith('/erp/quotes?')) return { data: [
        { id: 5, erp_establishment_id: 3, quote_no: 'ORC-5', status: 'converted', currency: 'BRL', total_amount: '10.0000' },
        { id: 6, erp_establishment_id: 3, quote_no: 'ORC-6', status: 'draft', currency: 'BRL', total_amount: '10.0000' }
      ], meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 } }
      if (path.startsWith('/erp/orders?')) return { data: [
        { id: 8, erp_establishment_id: 3, order_no: 'PED-8', status: 'converted', currency: 'BRL', total_amount: '10.0000' },
        { id: 9, erp_establishment_id: 3, order_no: 'PED-9', status: 'confirmed', currency: 'BRL', total_amount: '10.0000' }
      ], meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/quotes/5') return document(5, 'converted', 'quotes')
      if (path === '/erp/quotes/6') return document(6, quote6Reads++ === 0 ? 'draft' : 'converted', 'quotes')
      if (path === '/erp/orders/8') return document(8, 'converted', 'orders')
      if (path === '/erp/orders/9') return document(9, 'confirmed', 'orders')
      throw new Error(`Unexpected API path ${path}`)
    }
    const mounted = mountSalesWorkspace(api)
    const openRow = async (number: string) => {
      const row = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes(number))
      assert.ok(row, `Expected row ${number}`)
      ;(row.props.onClick as () => void)()
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
    }
    try {
      await new Promise(resolve => setImmediate(resolve))
      assert.match(mounted.textContent(mounted.root), /Convertidos/)
      assert.match(mounted.textContent(mounted.root), /Convertido/)
      await openRow('ORC-5')
      assert.match(mounted.textContent(mounted.root), /Orçamento convertido/)
      assert.equal(mounted.findButton('Editar rascunho'), undefined)
      assert.equal(mounted.findButton('Converter'), undefined)
      await mounted.click('Fechar modal')

      await openRow('ORC-6')
      assert.ok(mounted.findButton('Editar rascunho'))
      assert.ok(mounted.findButton('Converter'))
      await mounted.click('Editar rascunho')
      assert.match(mounted.textContent(mounted.root), /Orçamento convertido/)
      assert.equal(mounted.findButton('Editar rascunho'), undefined)
      assert.equal(mounted.findButton('Converter'), undefined)
      await mounted.click('Fechar modal')

      await mounted.click('Pedidos')
      await new Promise(resolve => setImmediate(resolve))
      assert.match(mounted.textContent(mounted.root), /Convertidos/)
      await openRow('PED-8')
      assert.match(mounted.textContent(mounted.root), /Pedido convertido/)
      assert.equal(mounted.findButton('Editar rascunho'), undefined)
      assert.equal(mounted.findButton('Converter'), undefined)
      await mounted.click('Fechar modal')

      await openRow('PED-9')
      assert.ok(mounted.findButton('Editar rascunho'))
      assert.ok(mounted.findButton('Converter'))
      await mounted.click('Editar rascunho')
      assert.ok(mounted.findButton('Salvar pedido'))
      await mounted.click('Fechar modal')
      assert.equal(calls.some(call => call.options?.method === 'PATCH'), false)
    } finally {
      mounted.dispose()
      storage.restore()
    }
  })

  it('closes an edit after an exact consumed-document conflict is proven by an authorized detail GET', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let detailReads = 0
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path.startsWith('/erp/quotes?')) return { data: [{ id: 66, erp_establishment_id: 3, quote_no: 'ORC-66', status: 'draft', currency: 'BRL', total_amount: '10.0000' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/quotes/66' && options?.method === 'PATCH') throw { statusCode: 409, message: 'Documento já consumido ou lançado.' }
      if (path === '/erp/quotes/66') {
        detailReads++
        return { data: { id: 66, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, status: detailReads >= 3 ? 'converted' : 'draft', currency: 'BRL', draft_revision: 3,
          subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', stock_reserved: false, needs_reconciliation: false, lines: [] } }
      }
      throw new Error(`Unexpected API path ${path}`)
    }
    const mounted = mountSalesWorkspace(api)
    try {
      await new Promise(resolve => setImmediate(resolve))
      const row = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes('ORC-66'))
      assert.ok(row)
      ;(row.props.onClick as () => void)()
      await new Promise(resolve => setImmediate(resolve))
      assert.ok(mounted.findButton('Editar rascunho'))
      await mounted.click('Editar rascunho')
      mounted.submitDraft()
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
      assert.equal((mounted.state.selectedOperation as Vue.Ref<unknown>).value, null)
      assert.equal((mounted.state.needsReconciliation as Vue.Ref<boolean>).value, false)
      assert.equal((mounted.state.selected as Vue.Ref<{ status: string } | null>).value?.status, 'converted')
      assert.equal(mounted.findButton('Editar rascunho'), undefined)
      assert.equal(mounted.findButton('Converter'), undefined)
      assert.match((mounted.state.mutationError as Vue.Ref<string>).value, /Outro operador converteu ou lançou/)
      assert.equal(calls.filter(call => call.options?.method === 'PATCH').length, 1)
    } finally {
      mounted.dispose()
      storage.restore()
    }
  })

  it('reconciles an uncertain sale post in the same workspace modal using the exact captured payload', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let reconciled = false
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST') throw { statusCode: 503, message: 'timeout after server commit' }
      if (path.startsWith('/erp/operations/lookup?')) { reconciled = true; return { data: { state: 'completed', result: { id: 23, obligation_id: 81, payment_id: null, financial_disposition: 'receivable' } } } }
      if (path.startsWith('/erp/sales?')) return { data: [{ id: 23, erp_establishment_id: 3, status: 'draft', currency: 'BRL', total_amount: '10.0000' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/sales/23') return { data: { id: 23, erp_establishment_id: 3, status: reconciled ? 'posted' : 'draft', fiscal_status: 'none', currency: 'BRL', draft_revision: 2, total_amount: '10.0000', lines: [], finance: { payment_ids: [] } } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const mounted = mountSalesWorkspace(api, '#sales')
    try {
      await new Promise(resolve => setImmediate(resolve))
      const row = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes('Registro #23'))
      assert.ok(row)
      ;(row.props.onClick as () => void)()
      await new Promise(resolve => setImmediate(resolve))
      await mounted.click('Lançar venda')
      await mounted.click('Confirmar lançamento da venda')
      const pending = (mounted.state.selectedOperation as Vue.Ref<{ key: string, payload: Record<string, unknown> } | null>).value
      assert.ok(pending)
      assert.deepEqual(pending.payload, { draft_revision: 2, expected_total: '10.0000' })
      await mounted.click('Consultar operação original')
      assert.equal((mounted.state.resolvedOperation as Vue.Ref<{ key: string } | null>).value?.key, pending.key)
      assert.equal((mounted.state.selected as Vue.Ref<{ status: string } | null>).value?.status, 'posted')
      assert.equal(mounted.findButton('Confirmar lançamento da venda'), undefined)
      assert.equal(calls.find(call => call.options?.method === 'POST')?.path, '/erp/sales/23/post')
      const lookup = calls.find(call => call.path.startsWith('/erp/operations/lookup?'))
      assert.equal((lookup?.options?.headers as Record<string, unknown>)['Idempotency-Key'], pending.key)
    } finally {
      mounted.dispose()
      storage.restore()
    }
  })

  it('keeps the posted sale non-actionable in the open detail until the confirmed refresh succeeds', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let detailReads = 0
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST') return { data: { id: 23, obligation_id: 81, payment_id: null, financial_disposition: 'receivable' } }
      if (path.startsWith('/erp/sales?')) return { data: [{ id: 23, erp_establishment_id: 3, status: 'draft', currency: 'BRL', total_amount: '10.0000' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/sales/23') {
        detailReads++
        if (detailReads === 2) throw { statusCode: 503, message: 'detail unavailable' }
        return { data: { id: 23, erp_establishment_id: 3, status: detailReads > 2 ? 'posted' : 'draft', fiscal_status: 'none', currency: 'BRL', draft_revision: 2, total_amount: '10.0000', lines: [], finance: { payment_ids: [] } } }
      }
      throw new Error(`Unexpected API path ${path}`)
    }
    const mounted = mountSalesWorkspace(api, '#sales')
    try {
      await new Promise(resolve => setImmediate(resolve))
      const row = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes('Registro #23'))
      assert.ok(row)
      ;(row.props.onClick as () => void)()
      await new Promise(resolve => setImmediate(resolve))
      await mounted.click('Lançar venda')
      await mounted.click('Confirmar lançamento da venda')
      assert.equal((mounted.state.requiresFreshDetail as (area: string, id: number) => boolean)('sales', 23), true)
      assert.equal(mounted.findButton('Lançar venda'), undefined)
      assert.ok(mounted.walk(mounted.root).some(node => node.tag === 'button' && node.text === 'Atualizar detalhe'))
      await mounted.click('Atualizar detalhe')
      assert.equal((mounted.state.requiresFreshDetail as (area: string, id: number) => boolean)('sales', 23), false)
      assert.equal((mounted.state.selected as Vue.Ref<{ status: string } | null>).value?.status, 'posted')
      assert.equal(mounted.findButton('Lançar venda'), undefined)
      assert.equal(calls.filter(call => call.options?.method === 'POST').length, 1)
    } finally {
      mounted.dispose()
      storage.restore()
    }
  })

  it('submits only selected source lines and preserves exact return quantities', () => {
    const source = readFileSync(new URL('../app/components/erp/ErpSaleReturnForm.vue', import.meta.url), 'utf8')
    const { descriptor } = VueCompiler.parse(source)
    const script = VueCompiler.compileScript(descriptor, { id: 'sales-return', inlineTemplate: true })
    const exports = compileModule(script.content, { 'vue': Vue, '#shared/utils/erpSales': salesHelpers }, { computed: Vue.computed, reactive: Vue.reactive, ref: Vue.ref, watch: Vue.watch })
    const component = exports.default as Vue.Component
    const sale = { id: 23, erp_establishment_id: 3, currency: 'BRL', returnability: { needs_reconciliation: false }, finance: { needs_reconciliation: false }, returnable_lines: [
      { source_line_id: 81, quantity: '1.0000', returned_quantity: '0.0000', remaining_quantity: '1.0000', unit_conversion_factor: '1.00000000', line_total: '10.0000', physical_eligibility: 'validated_at_post' }
    ] }
    const parent = Vue.reactive({ draft: { reason: '', quantities: {} as Record<number, string> } })
    assert.equal(erpReturnLineError(82, [81, 82], { 'lines.1.quantity': 'Quantidade excede o saldo.' }), 'Quantidade excede o saldo.')
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
    const host = Vue.defineComponent({ setup: () => () => Vue.h(component, { 'ref': instance, sale, 'draft': parent.draft, 'saving': false, 'errors': {}, 'onUpdate:draft': (value: typeof parent.draft) => { parent.draft = value }, 'onSubmit': (...value: unknown[]) => { submitted = value } }) })
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

  it('keeps a compacted validation error attached to the submitted second return line after selection changes', async () => {
    const storage = installSessionStorage()
    const api = async (path: string, options?: Record<string, unknown>) => {
      if (path.startsWith('/erp/sales?')) return { data: [{ id: 23, erp_establishment_id: 3, status: 'posted', currency: 'BRL', total_amount: '20.0000' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/sales/23') return { data: {
        id: 23, erp_establishment_id: 3, status: 'posted', fiscal_status: 'none', currency: 'BRL', draft_revision: 2, total_amount: '20.0000', lines: [],
        finance: { needs_reconciliation: false }, returnability: { needs_reconciliation: false }, returnable_lines: [
          { source_line_id: 81, quantity: '1.0000', remaining_quantity: '1.0000', line_total: '10.0000' },
          { source_line_id: 82, quantity: '1.0000', remaining_quantity: '1.0000', line_total: '10.0000' }
        ]
      } }
      if (path === '/erp/sale-returns' && options?.method === 'POST') throw { statusCode: 422, data: { message: 'Revise a devolução.', errors: { 'lines.0.quantity': ['Quantidade excede o saldo.'] } } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const mounted = mountSalesWorkspace(api, '#sales')
    try {
      await new Promise(resolve => setImmediate(resolve))
      const row = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes('Registro #23'))
      assert.ok(row)
      ;(row.props.onClick as () => void)()
      await new Promise(resolve => setImmediate(resolve))
      await mounted.click('Devolver item')
      const setField = (name: string, value: string) => {
        const field = mounted.walk(mounted.root).find(node => node.props['data-field-name'] === name)
        assert.ok(field, `Expected field ${name}`)
        const inputNode = mounted.walk(field).find(node => node.tag === 'input')
        assert.ok(inputNode)
        ;(inputNode.props.onInput as (event: unknown) => void)({ target: { value } })
      }
      setField('reason', 'Parcial')
      setField('lines.82.quantity', '0.5000')
      mounted.submitReturn()
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
      const errorFor = (name: string) => mounted.walk(mounted.root).find(node => node.props['data-field-name'] === name)?.props['data-field-error']
      assert.equal(errorFor('lines.81.quantity'), '')
      assert.equal(errorFor('lines.82.quantity'), 'Quantidade excede o saldo.')
      setField('lines.81.quantity', '0.2500')
      await Vue.nextTick()
      assert.equal(errorFor('lines.81.quantity'), '')
      assert.equal(errorFor('lines.82.quantity'), 'Quantidade excede o saldo.')
    } finally {
      mounted.dispose()
      storage.restore()
    }
  })

  it('preserves return drafts by authority and sale, clears on authority change, and resets only after creation', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const sale = (id: number) => ({
      id, erp_establishment_id: 3, status: 'posted', fiscal_status: 'none', currency: 'BRL', draft_revision: 2, total_amount: '20.0000', lines: [],
      finance: { needs_reconciliation: false }, returnability: { needs_reconciliation: false }, returnable_lines: [
        { source_line_id: 81, quantity: '1.0000', remaining_quantity: '1.0000', line_total: '10.0000' },
        { source_line_id: 82, quantity: '1.0000', remaining_quantity: '1.0000', line_total: '10.0000' }
      ]
    })
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path.startsWith('/erp/sales?')) return { data: [23, 24].map(id => ({ id, erp_establishment_id: 3, status: 'posted', currency: 'BRL', total_amount: '20.0000' })), meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      if (path === '/erp/sales/23') return { data: sale(23) }
      if (path === '/erp/sales/24') return { data: sale(24) }
      if (path === '/erp/sale-returns' && options?.method === 'POST') return { data: { id: 37, erp_sale_id: 23, status: 'draft', currency: 'BRL', reason: (options.body as { reason: string }).reason, total_amount: '2.5000' } }
      if (path.startsWith('/erp/sale-returns?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/sale-returns/37') return { data: { id: 37, erp_sale_id: 23, erp_establishment_id: 3, status: 'draft', currency: 'BRL', reason: 'Novo motivo', total_amount: '2.5000', lines: [{ source_line_id: 82, quantity: '0.5000' }] } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const mounted = mountSalesWorkspace(api, '#sales')
    try {
      await new Promise(resolve => setImmediate(resolve))
      const openSale = async (id: number) => {
        const row = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes(`Registro #${id}`))
        assert.ok(row, `Expected sale row ${id}`)
        ;(row.props.onClick as () => void)()
        await new Promise(resolve => setImmediate(resolve))
      }
      const openReturn = async () => { await mounted.click('Devolver item') }
      const closeReturn = async () => { await mounted.click('Cancelar') }
      const setField = (name: string, value: string) => {
        const field = mounted.walk(mounted.root).find(node => node.props['data-field-name'] === name)
        assert.ok(field, `Expected field ${name}`)
        const inputNode = mounted.walk(field).find(node => node.tag === 'input')
        assert.ok(inputNode)
        ;(inputNode.props.onInput as (event: unknown) => void)({ target: { value } })
      }
      const fieldValue = (name: string) => {
        const field = mounted.walk(mounted.root).find(node => node.props['data-field-name'] === name)
        return field ? mounted.walk(field).find(node => node.tag === 'input')?.props.value : undefined
      }

      await openSale(23)
      await openReturn()
      setField('reason', 'Motivo salvo')
      setField('lines.82.quantity', '0.2500')
      await Vue.nextTick()
      assert.equal(fieldValue('reason'), 'Motivo salvo')
      assert.equal(fieldValue('lines.82.quantity'), '0.2500')
      await closeReturn()
      await openSale(23)
      await openReturn()
      assert.equal(fieldValue('reason'), 'Motivo salvo')
      assert.equal(fieldValue('lines.82.quantity'), '0.2500')

      await closeReturn()
      await openSale(24)
      await openReturn()
      assert.equal(fieldValue('reason'), '')
      assert.equal(fieldValue('lines.82.quantity'), '')
      await closeReturn()
      await openSale(23)
      await openReturn()
      assert.equal(fieldValue('reason'), 'Motivo salvo')

      await closeReturn()
      ;(mounted.state.authorityGeneration as Vue.Ref<number>).value++
      await Vue.nextTick()
      await openSale(23)
      await openReturn()
      assert.equal(fieldValue('reason'), '', 'same-identity authority invalidation unmounts the local return draft')
      await closeReturn()
      mounted.currentCompany.value = { id: 8, permissions: ['*'], role: 'owner' }
      await Vue.nextTick()
      await (mounted.state.load as (area: string) => Promise<void>)('sales')
      await openSale(23)
      await openReturn()
      assert.equal(fieldValue('reason'), '')
      assert.equal(fieldValue('lines.82.quantity'), '')
      setField('reason', 'Novo motivo')
      setField('lines.82.quantity', '0.5000')
      mounted.submitReturn()
      await mounted.click('Fechar modal')
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
      assert.equal((mounted.state.selected as Vue.Ref<{ id: number } | null>).value?.id, 37)
      assert.deepEqual((calls.find(call => call.path === '/erp/sale-returns' && call.options?.method === 'POST')?.options?.body), {
        erp_sale_id: 23, reason: 'Novo motivo', lines: [{ source_line_id: 82, quantity: '0.5000' }]
      })

      await mounted.click('Vendas / balcão')
      await openSale(23)
      await openReturn()
      assert.equal(fieldValue('reason'), '')
      assert.equal(fieldValue('lines.82.quantity'), '')
      setField('reason', 'Rascunho mais recente')
      setField('lines.82.quantity', '0.1250')
      await Vue.nextTick()
      assert.equal(fieldValue('reason'), 'Rascunho mais recente')
      assert.equal(fieldValue('lines.82.quantity'), '0.1250')
      await closeReturn()
      await openSale(23)
      await openReturn()
      assert.equal(fieldValue('reason'), 'Rascunho mais recente')
      assert.equal(fieldValue('lines.82.quantity'), '0.1250')
    } finally {
      mounted.dispose()
      storage.restore()
    }
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

  it('keeps an unrecognized update conflict attached to its original operation', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'PATCH') throw { statusCode: 409, message: 'Conflict.' }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    const payload = { erp_establishment_id: 3, currency: 'BRL' as const, draft_revision: 2, lines: [] }
    try {
      await (state.saveDraft as (kind: string, value: typeof payload, id: number) => Promise<unknown>)('quote', payload, 66)
      const pending = (state.selectedOperation as Vue.Ref<{ key: string, path: string, payload: Record<string, unknown> } | null>).value
      assert.ok(pending)
      assert.equal(pending.path, '/erp/quotes/66')
      assert.deepEqual(pending.payload, payload)
      assert.equal((state.needsReconciliation as Vue.Ref<boolean>).value, true)
      assert.equal((state.consumedOperationConflict as Vue.Ref<unknown>).value, null)
      assert.equal(calls.some(call => call.path === '/erp/quotes/66' && call.options?.method === undefined), false)
    } finally {
      stop()
      storage.restore()
    }
  })

  it('releases only exact pre-effect conversion and post conflicts proven by fresh terminal details', async () => {
    const cases = [
      { operation: 'erp.quotes.convert', path: '/erp/quotes/5/convert', detail: '/erp/quotes/5', area: 'quotes', id: 5, message: 'Documento já convertido.', status: 'converted', run: (state: Record<string, unknown>) => (state.convertQuote as (row: { id: number, draft_revision: number }) => Promise<unknown>)({ id: 5, draft_revision: 2 }) },
      { operation: 'erp.orders.convert', path: '/erp/orders/8/convert', detail: '/erp/orders/8', area: 'orders', id: 8, message: 'Documento já convertido.', status: 'converted', run: (state: Record<string, unknown>) => (state.convertOrder as (row: { id: number, draft_revision: number }) => Promise<unknown>)({ id: 8, draft_revision: 2 }) },
      { operation: 'erp.sales.post', path: '/erp/sales/23/post', detail: '/erp/sales/23', area: 'sales', id: 23, message: 'Venda já lançada.', status: 'posted', run: (state: Record<string, unknown>) => (state.postSale as (row: { id: number, erp_establishment_id: number, status: string, draft_revision: number, currency: string, total_amount: string }) => Promise<unknown>)({ id: 23, erp_establishment_id: 3, status: 'draft', draft_revision: 2, currency: 'BRL', total_amount: '10.0000' }) },
      { operation: 'erp.sale-returns.post', path: '/erp/sale-returns/37/post', detail: '/erp/sale-returns/37', area: 'returns', id: 37, message: 'Devolução já lançada.', status: 'posted', run: (state: Record<string, unknown>) => (state.postReturn as (row: { id: number, erp_sale_id: number, erp_establishment_id: number, status: string, currency: string, reason: string, total_amount: string }) => Promise<unknown>)({ id: 37, erp_sale_id: 23, erp_establishment_id: 3, status: 'draft', currency: 'BRL', reason: 'Parcial', total_amount: '2.5000' }) }
    ]
    for (const scenario of cases) {
      const storage = installSessionStorage()
      const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
      const api = async (path: string, options?: Record<string, unknown>) => {
        calls.push({ path, options })
        if (path === scenario.path) throw { statusCode: 409, message: scenario.message }
        if (path === scenario.detail) return { data: { id: scenario.id, status: scenario.status, erp_sale_id: scenario.area === 'returns' ? 23 : undefined } }
        throw new Error(`Unexpected API path ${path}`)
      }
      const { state, stop } = createSalesWorkspace(api)
      try {
        await scenario.run(state)
        assert.equal((state.selectedOperation as Vue.Ref<unknown>).value, null, scenario.operation)
        assert.equal((state.needsReconciliation as Vue.Ref<boolean>).value, false, scenario.operation)
        assert.deepEqual((state.consumedOperationConflict as Vue.Ref<unknown>).value, { operation: scenario.operation, area: scenario.area, id: scenario.id, status: scenario.status })
        assert.equal((state.resolvedOperation as Vue.Ref<unknown>).value, null, scenario.operation)
        assert.ok(calls.some(call => call.path === scenario.detail && call.options?.method === undefined), scenario.operation)
      } finally {
        stop()
        storage.restore()
      }
    }

    const storage = installSessionStorage()
    const api = async (path: string) => {
      if (path === '/erp/quotes/5/convert') throw { statusCode: 409, message: 'Documento já convertido.' }
      if (path === '/erp/quotes/5') return { data: { id: 5, status: 'draft' } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    try {
      await (state.convertQuote as (row: { id: number, draft_revision: number }) => Promise<unknown>)({ id: 5, draft_revision: 2 })
      assert.ok((state.selectedOperation as Vue.Ref<unknown>).value)
      assert.equal((state.needsReconciliation as Vue.Ref<boolean>).value, true)
      assert.equal((state.consumedOperationConflict as Vue.Ref<unknown>).value, null)
    } finally {
      stop()
      storage.restore()
    }

    const failedReadStorage = installSessionStorage()
    const failedReadApi = async (path: string) => {
      if (path === '/erp/quotes/5/convert') throw { statusCode: 409, message: 'Documento já convertido.' }
      if (path === '/erp/quotes/5') throw { statusCode: 503, message: 'Unavailable.' }
      throw new Error(`Unexpected API path ${path}`)
    }
    const failedRead = createSalesWorkspace(failedReadApi)
    try {
      await (failedRead.state.convertQuote as (row: { id: number, draft_revision: number }) => Promise<unknown>)({ id: 5, draft_revision: 2 })
      assert.ok((failedRead.state.selectedOperation as Vue.Ref<unknown>).value)
      assert.equal((failedRead.state.needsReconciliation as Vue.Ref<boolean>).value, true)
      assert.equal((failedRead.state.consumedOperationConflict as Vue.Ref<unknown>).value, null)
    } finally {
      failedRead.stop()
      failedReadStorage.restore()
    }

    const intentStorage = installSessionStorage()
    let resolveDetail: ((value: unknown) => void) | undefined
    let detailRequested = false
    const intentApi = async (path: string) => {
      if (path === '/erp/quotes/5/convert') throw { statusCode: 409, message: 'Documento já convertido.' }
      if (path === '/erp/quotes/5') {
        detailRequested = true
        return await new Promise((resolve) => { resolveDetail = resolve })
      }
      throw new Error(`Unexpected API path ${path}`)
    }
    const intentRead = createSalesWorkspace(intentApi)
    try {
      const operation = (intentRead.state.convertQuote as (row: { id: number, draft_revision: number }) => Promise<unknown>)({ id: 5, draft_revision: 2 })
      for (let attempt = 0; attempt < 10 && !detailRequested; attempt++) await new Promise(resolve => setImmediate(resolve))
      assert.equal(detailRequested, true)
      const originalPending = (intentRead.state.selectedOperation as Vue.Ref<unknown>).value
      ;(intentRead.state.claimIntent as () => number)()
      resolveDetail?.({ data: { id: 5, status: 'converted' } })
      await operation
      assert.deepEqual((intentRead.state.selectedOperation as Vue.Ref<unknown>).value, originalPending)
      assert.equal((intentRead.state.needsReconciliation as Vue.Ref<boolean>).value, true)
      assert.equal((intentRead.state.consumedOperationConflict as Vue.Ref<unknown>).value, null)
      assert.match((intentRead.state.mutationError as Vue.Ref<string>).value, /perdeu a seleção atual/)
    } finally {
      intentRead.stop()
      intentStorage.restore()
    }
  })

  it('converts quote to order and order to sale using HTTP 200 acknowledgements followed by authorized details', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let quoteConverted = false
    let orderConverted = false
    let orderReads = 0
    const sourceStatuses: string[] = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path === '/erp/quotes/5/convert') { quoteConverted = true; return { data: { quote: { id: 5 }, order: { id: 8 } } } }
      if (path === '/erp/orders/8/convert') { orderConverted = true; return { data: { order: { id: 8 }, sale: { id: 13 } } } }
      if (path.startsWith('/erp/quotes?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path.startsWith('/erp/orders?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path.startsWith('/erp/sales?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/quotes/5') { sourceStatuses.push(quoteConverted ? 'converted' : 'draft'); return { data: { id: 5, status: quoteConverted ? 'converted' : 'draft', draft_revision: 3, currency: 'BRL', total_amount: '10.0000', lines: [] } } }
      if (path === '/erp/orders/8') { orderReads++; sourceStatuses.push(orderConverted ? 'converted' : 'confirmed'); return { data: { id: 8, status: orderConverted ? 'converted' : 'confirmed', draft_revision: 4, currency: 'BRL', total_amount: '10.0000', lines: [] } } }
      if (path === '/erp/sales/13') return { data: { id: 13, status: 'draft', fiscal_status: 'none', draft_revision: 1, currency: 'BRL', total_amount: '10.0000', lines: [] } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    const quote = { id: 5, status: 'draft', draft_revision: 3, currency: 'BRL', total_amount: '10.0000' }
    try {
      await (state.convertQuote as (value: typeof quote, orderNo?: string) => Promise<unknown>)(quote)
      assert.equal((state.selected as Vue.Ref<{ id: number } | null>).value?.id, 8)
      assert.equal((state.selected as Vue.Ref<{ status: string } | null>).value?.status, 'confirmed')
      await (state.convertOrder as (value: { id: number, draft_revision: number }, invoiceNo?: string) => Promise<unknown>)({ id: 8, draft_revision: 4 })
      assert.equal((state.selected as Vue.Ref<{ id: number, status: string, fiscal_status: string } | null>).value?.id, 13)
      assert.equal((state.selected as Vue.Ref<{ status: string, fiscal_status: string } | null>).value?.status, 'draft')
      assert.equal((state.selected as Vue.Ref<{ status: string, fiscal_status: string } | null>).value?.fiscal_status, 'none')
      assert.deepEqual(calls.find(call => call.path === '/erp/quotes/5/convert')?.options?.body, { draft_revision: 3 })
      assert.deepEqual(calls.find(call => call.path === '/erp/orders/8/convert')?.options?.body, { draft_revision: 4 })
      assert.equal(calls.some(call => call.path === '/erp/orders/8'), true)
      assert.equal(calls.some(call => call.path === '/erp/quotes/5'), true)
      assert.deepEqual(sourceStatuses, ['converted', 'confirmed', 'converted'])
      assert.equal(orderReads, 2)
      assert.equal((state.requiresFreshDetail as (area: string, id: number) => boolean)('quotes', 5), false)
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
      assert.deepEqual(post?.options?.body, erpSalePostPayload(row, { direction: 'in', amount: '3.0000', method: 'manual', erp_establishment_id: 3 }))
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

  it('completes uncertain sale-post recovery with the exact owned payload and refreshed detail', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST') throw { statusCode: 503, message: 'timeout after server commit' }
      if (path.startsWith('/erp/operations/lookup?')) return { data: { state: 'completed', result: { id: 23, obligation_id: 81, payment_id: null, financial_disposition: 'receivable' } } }
      if (path.startsWith('/erp/sales?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/sales/23') return { data: {
        id: 23, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, status: 'posted', currency: 'BRL', draft_revision: 2,
        subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', stock_reserved: false,
        needs_reconciliation: false, lines: []
      } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const permissions = ['erp.sales.post', 'erp.sales.index', 'erp.sales.show']
    const { state, stop } = createSalesWorkspace(api, permissions)
    const row = { id: 23, erp_establishment_id: 3, status: 'draft', draft_revision: 2, currency: 'BRL', total_amount: '10.0000' }
    try {
      await (state.postSale as (document: typeof row) => Promise<unknown>)(row)
      const pending = (state.selectedOperation as Vue.Ref<{ key: string, payload: Record<string, unknown> } | null>).value
      assert.ok(pending)
      assert.deepEqual(pending.payload, erpSalePostPayload(row))
      await (state.reconcile as () => Promise<void>)()
      assert.equal((state.resolvedOperation as Vue.Ref<{ key: string } | null>).value?.key, pending.key)
      assert.equal((state.selected as Vue.Ref<{ status: string } | null>).value?.status, 'posted')
      assert.equal((state.selectedOperation as Vue.Ref<unknown>).value, null)
      const lookup = calls.find(call => call.path.startsWith('/erp/operations/lookup?'))
      assert.equal((lookup?.options?.headers as Record<string, unknown>)['Idempotency-Key'], pending.key)
    } finally {
      stop()
      storage.restore()
    }
  })

  it('blocks a second sale mutation when the acknowledged write detail refresh fails', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST') return { data: { id: 23, obligation_id: 81, payment_id: null, financial_disposition: 'receivable' } }
      if (path.startsWith('/erp/sales?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/sales/23') throw { statusCode: 503, message: 'detail unavailable' }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api, ['erp.sales.post', 'erp.sales.index', 'erp.sales.show'])
    const row = { id: 23, erp_establishment_id: 3, status: 'draft', draft_revision: 2, currency: 'BRL', total_amount: '10.0000' }
    ;(state.selected as Vue.Ref<typeof row | null>).value = row
    ;(state.area as Vue.Ref<string>).value = 'sales'
    try {
      await (state.postSale as (document: typeof row) => Promise<unknown>)(row)
      assert.equal((state.completedDetailRefresh as Vue.Ref<Array<{ area: string, id: number }>>).value.some(item => item.area === 'sales' && item.id === 23), true)
      assert.equal((state.detailError as Vue.Ref<string>).value.includes('operação foi confirmada'), true)
      await (state.postSale as (document: typeof row) => Promise<unknown>)(row)
      assert.equal(calls.filter(call => call.options?.method === 'POST').length, 1)
      assert.match((state.mutationError as Vue.Ref<string>).value, /Atualize o detalhe confirmado/)
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

  it('clears the previous success banner before a later validation failure', async () => {
    const storage = installSessionStorage()
    let writes = 0
    const api = async (path: string, options?: Record<string, unknown>) => {
      if (options?.method === 'POST') { writes++; return { data: { id: 55 } } }
      if (options?.method === 'PATCH') {
        writes++
        if (writes === 2) throw { statusCode: 422, message: 'Revise os campos destacados.', errors: { quote_no: ['Número inválido.'] } }
        throw { statusCode: 503, message: 'connection lost' }
      }
      if (path.startsWith('/erp/quotes?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/quotes/55') return { data: { id: 55, erp_establishment_id: 3, status: 'draft', currency: 'BRL', draft_revision: 1, subtotal_amount: '1.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '1.0000', stock_reserved: false, needs_reconciliation: false, lines: [] } }
      if (path.startsWith('/erp/establishments?') || path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api)
    const payload = { erp_establishment_id: 3, currency: 'BRL' as const, lines: [] }
    try {
      await (state.saveDraft as (kind: string, value: typeof payload) => Promise<unknown>)('quote', payload)
      assert.equal((state.resolvedOperation as Vue.Ref<unknown>).value !== null, true)
      await (state.saveDraft as (kind: string, value: Record<string, unknown>, id: number) => Promise<unknown>)('quote', { ...payload, quote_no: 'inválido', draft_revision: 1 }, 55)
      assert.equal(writes, 2)
      assert.equal((state.resolvedOperation as Vue.Ref<unknown>).value, null)
      assert.equal((state.needsReconciliation as Vue.Ref<boolean>).value, false)
      assert.equal((state.mutationError as Vue.Ref<string>).value, 'Revise os campos destacados.')
      await (state.saveDraft as (kind: string, value: Record<string, unknown>, id: number) => Promise<unknown>)('quote', { ...payload, quote_no: 'ORC-55', draft_revision: 1 }, 55)
      assert.equal(writes, 3)
      assert.equal((state.resolvedOperation as Vue.Ref<unknown>).value, null)
      assert.equal((state.needsReconciliation as Vue.Ref<boolean>).value, true)
      assert.match((state.mutationError as Vue.Ref<string>).value, /resultado/)
    } finally {
      stop()
      storage.restore()
    }
  })

  it('blocks another return post when its acknowledged detail GET fails', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let returnPosted = false
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path === '/erp/sale-returns' && options?.method === 'POST') return { data: { id: 37, erp_sale_id: 23, status: 'draft', currency: 'BRL', reason: 'Parcial', total_amount: '2.5000' } }
      if (path === '/erp/sale-returns/37/post' && options?.method === 'POST') { returnPosted = true; return { data: { id: 37, erp_sale_id: 23, status: 'posted', currency: 'BRL', reason: 'Parcial', total_amount: '2.5000', credit_id: 91 } } }
      if (path.startsWith('/erp/sale-returns?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      if (path === '/erp/sale-returns/37') {
        if (returnPosted) throw { statusCode: 503, message: 'detail unavailable' }
        return { data: { id: 37, erp_sale_id: 23, erp_establishment_id: 3, status: 'draft', currency: 'BRL', reason: 'Parcial', total_amount: '2.5000', lines: [{ source_line_id: 81, quantity: '0.2500' }] } }
      }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, stop } = createSalesWorkspace(api, ['erp.sale-returns.store', 'erp.sale-returns.post', 'erp.sale-returns.index', 'erp.sale-returns.show'])
    try {
      await (state.storeReturn as (saleId: number, reason: string, lines: Array<{ source_line_id: number, quantity: string }>) => Promise<unknown>)(23, 'Parcial', [{ source_line_id: 81, quantity: '0.2500' }])
      const draft = (state.selected as Vue.Ref<{ id: number, status: string } | null>).value!
      await (state.postReturn as (row: typeof draft) => Promise<unknown>)(draft)
      assert.equal((state.requiresFreshDetail as (area: string, id: number) => boolean)('returns', 37), true)
      assert.match((state.detailError as Vue.Ref<string>).value, /operação foi confirmada/)
      await (state.postReturn as (row: typeof draft) => Promise<unknown>)(draft)
      assert.equal(calls.filter(call => call.path === '/erp/sale-returns/37/post' && call.options?.method === 'POST').length, 1)
      assert.match((state.mutationError as Vue.Ref<string>).value, /Atualize o detalhe confirmado/)
    } finally {
      stop()
      storage.restore()
    }
  })

  it('does not let a late company A denial refresh overwrite company B pending operation state', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let releaseAuthority: ((value: { committed: boolean }) => void) | undefined
    const authorityRefresh = new Promise<{ committed: boolean }>((resolve) => { releaseAuthority = resolve })
    let authorityStarted = false
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (options?.method === 'POST' && (calls.filter(call => call.options?.method === 'POST').length === 1)) throw { statusCode: 403, message: 'forbidden in company A' }
      if (options?.method === 'POST') throw { statusCode: 503, message: 'company B write uncertain' }
      if (path.startsWith('/erp/establishments?') || path.startsWith('/erp/contacts?') || path.startsWith('/erp/units?') || path.startsWith('/erp/products?')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      throw new Error(`Unexpected API path ${path}`)
    }
    const { state, currentCompany, sessionGeneration, stop } = createSalesWorkspace(api, ['*'], async () => { authorityStarted = true; return authorityRefresh })
    const payload = { erp_establishment_id: 3, currency: 'BRL' as const, lines: [] }
    try {
      const deniedInA = (state.saveDraft as (kind: string, value: typeof payload) => Promise<unknown>)('quote', payload)
      while (!authorityStarted) await new Promise(resolve => setImmediate(resolve))
      sessionGeneration.value++
      currentCompany.value = { id: 8, permissions: ['*'], role: 'owner' }
      await Vue.nextTick()
      const uncertainInB = (state.saveDraft as (kind: string, value: typeof payload) => Promise<unknown>)('quote', payload)
      await uncertainInB
      const companyBPending = (state.selectedOperation as Vue.Ref<{ identity: string, key: string } | null>).value
      assert.ok(companyBPending)
      assert.equal(companyBPending.identity.includes(':8:'), true)
      const companyBError = (state.mutationError as Vue.Ref<string>).value
      releaseAuthority?.({ committed: true })
      await deniedInA
      assert.equal((state.selectedOperation as Vue.Ref<{ key: string } | null>).value?.key, companyBPending.key)
      assert.equal((state.mutationError as Vue.Ref<string>).value, companyBError)
      assert.equal((state.needsReconciliation as Vue.Ref<boolean>).value, true)
    } finally {
      stop()
      storage.restore()
    }
  })
})

describe('native acceptance typed commercial nature', () => {
  it('selects a scoped nature and retains its ID through validation failure and cancel/reopen', async () => {
    const storage = installSessionStorage()
    const writes: Array<Record<string, unknown>> = []
    const natureReads: number[] = []
    let natureOffline = false
    const page = (data: unknown[], last_page = 1) => ({ data, meta: { current_page: 1, last_page, total: data.length, per_page: 100 } })
    const api = async (path: string, options?: Record<string, unknown>) => {
      if (options?.method === 'POST') { writes.push(options.body as Record<string, unknown>); throw { status: 422, data: { message: 'Confira os itens.', errors: { lines: ['Confira os itens.'] } } } }
      if (path.startsWith('/erp/tax-settings/operation-natures?')) {
        if (natureOffline) throw new Error('Naturezas temporariamente indisponíveis')
        const currentPage = Number(new URL(path, 'http://localhost').searchParams.get('page'))
        natureReads.push(currentPage)
        if (currentPage === 2) return page([{ id: 22, erp_establishment_id: 3, cfop: '6102', description: 'Venda matriz', movement: 'out', status: 'active' }])
        return page([
          { id: 21, erp_establishment_id: null, cfop: '5102', description: 'Venda geral', movement: 'out', status: 'active' },
          { id: 23, erp_establishment_id: 4, cfop: '5101', description: 'Outra filial', movement: 'out', status: 'active' },
          { id: 24, erp_establishment_id: null, cfop: '5103', description: 'Arquivada', movement: 'out', status: 'archived' }
        ], 2)
      }
      if (path.startsWith('/erp/establishments?')) return page([{ id: 3, name: 'Matriz', status: 'active' }, { id: 4, name: 'Filial', status: 'active' }])
      return page([])
    }
    const mounted = mountSalesWorkspace(api)
    const selectFor = (name: string) => {
      const field = mounted.walk(mounted.root).find(node => node.props['data-field-name'] === name)
      assert.ok(field, `Expected field ${name}`)
      const select = mounted.walk(field).find(node => node.tag === 'select')
      assert.ok(select)
      return select
    }
    const choose = async (name: string, value: string) => { (selectFor(name).props.onChange as (event: unknown) => void)({ target: { value } }); await Vue.nextTick() }
    try {
      await mounted.click('Novo registro')
      await choose('erp_establishment_id', '3')
      assert.deepEqual(mounted.walk(selectFor('erp_operation_nature_id')).filter(node => node.tag === 'option').map(node => node.props.value), [0, 21, 22])
      await choose('erp_operation_nature_id', '22')
      mounted.submitDraft()
      await new Promise(resolve => setImmediate(resolve))
      await Vue.nextTick()
      assert.equal(writes[0]?.erp_operation_nature_id, 22)
      assert.equal(selectFor('erp_operation_nature_id').props.value, 22)
      await mounted.click('Cancelar')
      await mounted.click('Novo registro')
      assert.equal(selectFor('erp_operation_nature_id').props.value, 22)
      await choose('erp_establishment_id', '4')
      assert.equal(selectFor('erp_operation_nature_id').props.value, 0)
      await choose('erp_operation_nature_id', '21')
      await choose('erp_establishment_id', '3')
      assert.equal(selectFor('erp_operation_nature_id').props.value, 21, 'company-wide nature survives establishment changes')
      assert.deepEqual(natureReads, [1, 2], 'selectable scoped nature was loaded from the second page')
      natureOffline = true
      await (mounted.state.loadReferences as () => Promise<void>)()
      await Vue.nextTick()
      assert.equal(selectFor('erp_operation_nature_id').props.value, 21, 'failed reference refresh must preserve this draft')
      assert.equal(selectFor('erp_operation_nature_id').props.disabled, true)
      assert.match(mounted.textContent(mounted.root), /vínculo atual será preservado/)
      assert.doesNotMatch(mounted.textContent(mounted.root), /Nenhuma natureza ativa/)
    } finally { mounted.dispose(); storage.restore() }
  })
})

describe('native acceptance immutable commercial nature handoff', () => {
  it('restores edit from frozen line metadata and preserves that source through actual quote/order conversion actions', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const line = { id: 11, erp_product_variant_id: null, erp_unit_id: null, quantity: '1.0000', unit_price: '10.0000', unit_conversion_factor: '1.00000000', discount_amount: '0.0000', tax_amount: '0.0000', line_total: '10.0000', product_snapshot: { description: 'Fonte aprovada', operation_nature: { id: 21, cfop: '5102', description: 'CFOP congelado' } } }
    const base = { erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, currency: 'BRL' as const, draft_revision: 1, total_amount: '10.0000', subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', stock_reserved: false, needs_reconciliation: false, lines: [line] }
    const quote = { ...base, id: 5, quote_no: 'NATURE-5', status: 'draft' }
    const order = { ...base, id: 8, order_no: 'NATURE-8', status: 'confirmed', erp_quote_id: 5 }
    const sale = { ...base, id: 13, invoice_no: 'NATURE-13', status: 'draft', erp_order_id: 8 }
    const page = (data: unknown[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length } })
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path === '/erp/quotes/5/convert') { quote.status = 'converted'; return { data: { quote: { id: 5 }, order: { id: 8 } } } }
      if (path === '/erp/orders/8/convert') { order.status = 'converted'; return { data: { order: { id: 8 }, sale: { id: 13 } } } }
      if (path === '/erp/quotes/5' && options?.method === 'PATCH') return { data: { id: 5 } }
      if (path === '/erp/quotes/5') return { data: quote }
      if (path === '/erp/orders/8') return { data: order }
      if (path === '/erp/sales/13') return { data: sale }
      if (path.startsWith('/erp/quotes?')) return page([quote])
      if (path.startsWith('/erp/establishments?')) return page([{ id: 3, name: 'Matriz', status: 'active' }])
      if (path.startsWith('/erp/tax-settings/operation-natures?')) return page([{ id: 21, cfop: '6108', description: 'Catálogo atual diferente', erp_establishment_id: null, movement: 'out', status: 'active' }])
      return page([])
    }
    const mounted = mountSalesWorkspace(api)
    try {
      await mounted.click('Orçamentos')
      const row = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes('NATURE-5'))!
      ;(row.props.onClick as () => void)()
      await flushSettingsForSales()
      await mounted.click('Editar rascunho')
      const field = mounted.walk(mounted.root).find(node => node.props['data-field-name'] === 'erp_operation_nature_id')!
      assert.equal(mounted.walk(field).find(node => node.tag === 'select')?.props.value, 21, 'backend omits the header; frozen line identity restores selection')
      mounted.submitDraft()
      await flushSettingsForSales()
      const patch = calls.find(call => call.options?.method === 'PATCH')!
      assert.equal((patch.options?.body as Record<string, unknown>).erp_operation_nature_id, 21)
      await mounted.click('Fechar modal')
      const updatedRow = mounted.walk(mounted.root).find(node => node.tag === 'button' && mounted.textContent(node).includes('NATURE-5'))!
      ;(updatedRow.props.onClick as () => void)()
      await flushSettingsForSales()
      await mounted.click('Converter')
      await mounted.click('Confirmar conversão para pedido')
      assert.equal((mounted.state.selected as Vue.Ref<typeof order>).value.id, 8)
      await mounted.click('Converter')
      await mounted.click('Confirmar conversão para venda')
      const accepted = (mounted.state.selected as Vue.Ref<typeof sale>).value
      assert.equal(accepted.id, 13)
      assert.equal(accepted.lines[0]?.product_snapshot.operation_nature.cfop, '5102', 'fresh authorized destination retains immutable source rather than current catalog CFOP')
      assert.equal(erpCommercialDocumentToDraft(accepted).erp_operation_nature_id, 21)
      assert.deepEqual(calls.filter(call => call.path.endsWith('/convert')).map(call => call.options?.body), [{ draft_revision: 1 }, { draft_revision: 1 }], 'conversion never overrides frozen source with current catalog')
    } finally { mounted.dispose(); storage.restore() }
  })

  it('shows denied reads and unavailable historical nature without inventing a default or making a forbidden request', async () => {
    const calls: string[] = []
    const page = { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
    const mounted = mountSalesWorkspace(async (path) => { calls.push(path); return page }, '#quotes', ['erp.quotes.index', 'erp.quotes.store', 'erp.quotes.show'])
    try {
      mounted.currentCompany.value.permissions = ['erp.quotes.index', 'erp.quotes.store', 'erp.quotes.show']
      await mounted.click('Novo registro')
      assert.equal(calls.some(path => path.startsWith('/erp/tax-settings/operation-natures?')), false)
      assert.match(mounted.textContent(mounted.root), /não permite consultar naturezas/)
      assert.doesNotMatch(mounted.textContent(mounted.root), /Nenhuma natureza ativa/)
      const denied = mounted.walk(mounted.root).find(node => node.props['data-field-name'] === 'erp_operation_nature_id')!
      assert.equal(mounted.walk(denied).find(node => node.tag === 'select')?.props.disabled, true)
    } finally { mounted.dispose() }
    const historical = { id: 5, erp_establishment_id: 3, erp_contact_id: null, erp_price_group_id: null, currency: 'BRL' as const, draft_revision: 1, total_amount: '10.0000', subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', stock_reserved: false, status: 'draft' as const, lines: [{ id: 1, erp_product_variant_id: null, erp_unit_id: null, quantity: '1.0000', unit_price: '10.0000', unit_conversion_factor: '1.00000000', line_total: '10.0000', product_snapshot: { operation_nature: { id: 99, cfop: '5102' } } }] }
    assert.equal(erpCommercialDocumentToDraft(historical).erp_operation_nature_id, 99)
    assert.equal(erpCommercialDocumentToDraft({ ...historical, lines: [{ ...historical.lines[0]!, product_snapshot: {} }] }).erp_operation_nature_id, null, 'historical unselected source stays optional')
    assert.equal(erpCommercialDocumentToDraft({ ...historical, lines: [...historical.lines, { ...historical.lines[0]!, id: 2, product_snapshot: { operation_nature: { id: 100, cfop: '6102' } } }] }).erp_operation_nature_id, null, 'inconsistent snapshots never invent one header identity')
    const editor = mountSalesWorkspace(async path => path === '/erp/quotes/5' ? { data: historical } : path.startsWith('/erp/quotes?') ? { ...page, data: [{ ...historical, quote_no: 'HISTORICAL' }] } : page)
    try {
      await editor.click('Orçamentos')
      const row = editor.walk(editor.root).find(node => node.tag === 'button' && editor.textContent(node).includes('HISTORICAL'))!
      ;(row.props.onClick as () => void)()
      await flushSettingsForSales()
      await editor.click('Editar rascunho')
      const field = editor.walk(editor.root).find(node => node.props['data-field-name'] === 'erp_operation_nature_id')!
      const select = editor.walk(field).find(node => node.tag === 'select')!
      assert.equal(select.props.value, 99)
      assert.match(editor.textContent(select), /Natureza #99 indisponível/)
      assert.equal(editor.walk(select).find(node => node.tag === 'option' && node.props.value === 99)?.props.disabled, true)
    } finally { editor.dispose() }
  })

  it('drops delayed nature references from a prior company while accepting the current company response', async () => {
    let resolveOld!: (value: unknown) => void
    let first = true
    const currentNature = { id: 72, erp_establishment_id: null, cfop: '5102', description: 'Atual', movement: 'out', status: 'active' }
    const page = (data: unknown[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length } })
    const harness = createSalesWorkspace(async (path) => {
      if (path.startsWith('/erp/tax-settings/operation-natures?')) {
        if (first) { first = false; return new Promise((resolve) => { resolveOld = resolve }) }
        return page([currentNature])
      }
      return page([])
    })
    try {
      const request = (harness.state.loadReferences as () => Promise<void>)()
      harness.currentCompany.value.id = 8
      assert.deepEqual((harness.state.references as { operationNatures: unknown[] }).operationNatures, [])
      await flushSettingsForSales()
      resolveOld(page([{ ...currentNature, id: 71, description: 'Antiga' }]))
      await request
      assert.deepEqual((harness.state.references as { operationNatures: Array<{ id: number }> }).operationNatures.map(row => row.id), [72])
      assert.equal((harness.state.operationNatureState as { confirmed: boolean }).confirmed, true)
    } finally { harness.stop() }
  })
})

async function flushSettingsForSales() { await new Promise(resolve => setImmediate(resolve)); await Vue.nextTick() }
