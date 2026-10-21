/* eslint-disable @stylistic/max-statements-per-line -- The compact local Vue renderer keeps its patch operations readable. */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import * as VueCompiler from 'vue/compiler-sfc'
import ts from 'typescript'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as operationRecovery from '../shared/utils/erpOperationRecovery.ts'
import {
  buildErpStockAdjustmentPayload,
  buildErpStockReversalPayload,
  buildErpStockTransferPayload,
  remainingStockQuantity,
  stockActionAllowed,
  stockCostDescription
} from '../shared/utils/erpStock.ts'

function compileModule(source: string, imports: Record<string, unknown>, bindings: Record<string, unknown>) {
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const exports: Record<string, unknown> = {}
  const runtime = new Function('require', 'exports', ...Object.keys(bindings), output)
  runtime((path: string) => {
    if (!(path in imports)) throw new Error(`Unexpected test import ${path}`)
    return imports[path]
  }, exports, ...Object.values(bindings))
  return exports
}

function createStockWorkspace(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, permissions = ['*']) {
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, permissions, role: 'owner' })
  const key = Vue.computed(() => JSON.stringify([user.value.id, currentCompany.value.id, currentCompany.value.role, ...currentCompany.value.permissions]))
  const context = { key, capture: () => ({ key: key.value }), isCurrent: (token: { key: string }) => token.key === key.value }
  const mounted: Array<() => void> = []
  const scope = Vue.effectScope()
  const exports = compileModule(readFileSync(new URL('../app/composables/useErpStockWorkspace.ts', import.meta.url), 'utf8'), {
    '#shared/types/erpBusiness': {},
    '~/composables/useApiError': apiErrors,
    '#shared/utils/erpOperationRecovery': operationRecovery,
    '#shared/utils/erpStock': { buildErpStockAdjustmentPayload, buildErpStockReversalPayload, buildErpStockTransferPayload, stockActionAllowed },
    '~/utils/erpModulesNav': {}
  }, {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch,
    onMounted: (callback: () => void) => mounted.push(callback), onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany, refreshWithStatus: async () => ({ identity: {}, committed: true }) }),
    useErpAdministrationContext: () => context
  })
  // eslint-disable-next-line @typescript-eslint/no-explicit-any -- The runtime-only harness reads the composable's Vue refs.
  const state = scope.run(() => (exports.useErpStockWorkspace as () => Record<string, any>)())!
  for (const callback of mounted) callback()
  return { state, user, currentCompany, stop: () => scope.stop() }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((done, fail) => { resolve = done; reject = fail })
  return { promise, resolve, reject }
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

function mountStockChild(file: string, imports: Record<string, unknown>, props: Record<string, unknown>) {
  const source = readFileSync(new URL(file, import.meta.url), 'utf8')
  const { descriptor } = VueCompiler.parse(source)
  const script = VueCompiler.compileScript(descriptor, { id: file, inlineTemplate: true })
  const exports = compileModule(script.content, { vue: Vue, ...imports }, { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch })
  const component = exports.default as Vue.Component
  const root = { children: [] as unknown[] }
  const renderer = Vue.createRenderer({
    patchProp(node: { props: Record<string, unknown> }, key, _previous, next) { node.props[key] = next },
    insert(node: { parent?: unknown }, parent: { children: unknown[] }, anchor?: unknown) {
      const previousParent = node.parent as { children: unknown[] } | undefined
      if (previousParent) { const previousIndex = previousParent.children.indexOf(node); if (previousIndex >= 0) previousParent.children.splice(previousIndex, 1) }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node: { parent?: { children: unknown[] } }) { if (!node.parent) return; const index = node.parent.children.indexOf(node); if (index >= 0) node.parent.children.splice(index, 1) },
    createElement(tag) { return { tag, children: [] as unknown[], props: {} as Record<string, unknown>, parent: null as unknown } },
    createText(text) { return { text, parent: null as unknown } }, createComment(text) { return { text, parent: null as unknown } },
    setText(node: { text: string }, text) { node.text = text }, setElementText(node: { children: unknown[], text?: string }, text) { node.children = []; node.text = text },
    parentNode(node: { parent?: unknown }) { return node.parent ?? null }, nextSibling(node: { parent?: { children: unknown[] } }) { return node.parent ? node.parent.children[node.parent.children.indexOf(node) + 1] ?? null : null }
  })
  const componentRef = Vue.ref(null as unknown as Record<string, unknown>)
  const emitted: unknown[] = []
  const host = Vue.defineComponent({ setup: () => () => Vue.h(component, { ...props, ref: componentRef, onSubmit: (payload: unknown) => emitted.push(payload), onReverse: (payload: unknown) => emitted.push(payload) }) })
  const app = renderer.createApp(host)
  const passthrough = { setup: (_props: unknown, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('div', context.slots.default?.()) }
  for (const name of ['UAlert', 'UCheckbox', 'UForm', 'UFormField', 'UInput', 'USelect', 'UTextarea']) app.component(name, passthrough)
  app.component('UButton', { props: ['label'], setup: (componentProps: { label?: string }, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('button', componentProps.label ?? context.slots.default?.()) })
  const renderErrors: unknown[] = []
  app.config.errorHandler = error => renderErrors.push(error)
  app.mount(root)
  return { component: componentRef, emitted, renderErrors, root }
}

function compileStockComponent(file: string, imports: Record<string, unknown>, bindings: Record<string, unknown>) {
  const source = readFileSync(new URL(file, import.meta.url), 'utf8')
  const { descriptor } = VueCompiler.parse(source)
  const script = VueCompiler.compileScript(descriptor, { id: file, inlineTemplate: true })
  return compileModule(script.content, { vue: Vue, ...imports }, bindings).default as Vue.Component
}

function mountStockWorkspace(workspace: Record<string, unknown>, initialHash = '') {
  const route = Vue.reactive({ hash: initialHash, path: '/erp/estoque', query: {} })
  const workspaceComponent = compileStockComponent('../app/components/erp/ErpStockWorkspace.vue', {
    '#shared/types/erpBusiness': {},
    '#shared/utils/erpStock': { stockCostDescription },
    '~/composables/useErpStockWorkspace': { useErpStockWorkspace: () => workspace }
  }, {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted: Vue.onMounted, nextTick: Vue.nextTick,
    useRoute: () => route, useRouter: () => ({ replace: async (location: unknown) => { route.hash = (location as { hash?: string }).hash ?? '' } })
  })
  const adjustmentComponent = compileStockComponent('../app/components/erp/ErpStockAdjustmentForm.vue', {
    '#shared/types/erpBusiness': {}, '#shared/utils/erpStock': { buildErpStockAdjustmentPayload }
  }, { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch })
  const transferComponent = compileStockComponent('../app/components/erp/ErpStockTransferForm.vue', {
    '#shared/types/erpBusiness': {}, '#shared/utils/erpStock': { buildErpStockTransferPayload }
  }, { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch })
  const root = { children: [] as unknown[] }
  const renderer = Vue.createRenderer({
    patchProp(node: { props: Record<string, unknown> }, key, _previous, next) { node.props[key] = next },
    insert(node: { parent?: unknown }, parent: { children: unknown[] }, anchor?: unknown) {
      const previousParent = node.parent as { children: unknown[] } | undefined
      if (previousParent) { const previousIndex = previousParent.children.indexOf(node); if (previousIndex >= 0) previousParent.children.splice(previousIndex, 1) }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node: { parent?: { children: unknown[] } }) { if (!node.parent) return; const index = node.parent.children.indexOf(node); if (index >= 0) node.parent.children.splice(index, 1) },
    createElement(tag) { return { tag, children: [] as unknown[], props: {} as Record<string, unknown>, parent: null as unknown } },
    createText(text) { return { text, parent: null as unknown } }, createComment(text) { return { text, parent: null as unknown } },
    setText(node: { text: string }, text) { node.text = text }, setElementText(node: { children: unknown[], text?: string }, text) { node.children = []; node.text = text },
    parentNode(node: { parent?: unknown }) { return node.parent ?? null }, nextSibling(node: { parent?: { children: unknown[] } }) { return node.parent ? node.parent.children[node.parent.children.indexOf(node) + 1] ?? null : null }
  })
  const app = renderer.createApp(workspaceComponent)
  let adjustmentFormInstance: { state: Record<string, string>, submit: () => void, locked: boolean } | null = null
  let transferFormInstance: { state: Record<string, string>, submit: () => void, locked: boolean } | null = null
  const passthrough = { setup: (_props: unknown, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('div', context.slots.body?.() ?? context.slots.default?.()) }
  for (const name of ['UNavigationMenu', 'USelect', 'UInput', 'UForm', 'UFormField', 'UTextarea', 'UBadge', 'USkeleton', 'CompanyMenu', 'UserMenu', 'ErpStockMovementDetail', 'ErpStockTransferDetail']) app.component(name, passthrough)
  app.component('UAlert', { props: ['title'], setup: (props: { title?: string }, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('div', { 'data-alert-title': props.title }, [props.title ?? '', context.slots.description?.(), context.slots.actions?.()]) })
  app.component('UTable', { setup: (_props: unknown, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('table', { 'data-stock-table': 'true' }, context.slots.default?.()) })
  app.component('ErpCatalogPageShell', { setup: (_props: unknown, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('div', [context.slots.actions?.(), context.slots.toolbar?.(), context.slots.default?.()]) })
  app.component('ErpStockAdjustmentForm', { inheritAttrs: false, setup: (_props: unknown, context: { attrs: Record<string, unknown>, expose: (value: unknown) => void }) => {
    const child = Vue.ref(null as unknown as { state: Record<string, string>, submit: () => void })
    const exposed = { get state() { return child.value?.state }, get locked() { return context.attrs.locked === true }, submit: () => child.value?.submit() }
    context.expose(exposed)
    adjustmentFormInstance = exposed
    return () => Vue.h(adjustmentComponent, { ...context.attrs, ref: (value: unknown) => { child.value = value as typeof child.value } })
  } })
  app.component('ErpStockTransferForm', { inheritAttrs: false, setup: (_props: unknown, context: { attrs: Record<string, unknown>, expose: (value: unknown) => void }) => {
    const child = Vue.ref(null as unknown as { state: Record<string, string>, submit: () => void })
    const exposed = { get state() { return child.value?.state }, get locked() { return context.attrs.locked === true }, submit: () => child.value?.submit() }
    context.expose(exposed)
    transferFormInstance = exposed
    return () => Vue.h(transferComponent, { ...context.attrs, ref: (value: unknown) => { child.value = value as typeof child.value } })
  } })
  app.component('UButton', { props: ['label', 'disabled', 'aria-label'], setup: (props: { 'label'?: string, 'disabled'?: boolean, 'aria-label'?: string }, context: { attrs: Record<string, unknown>, slots: Record<string, () => unknown[]> }) => () => Vue.h('button', { ...context.attrs, 'disabled': props.disabled, 'aria-label': props['aria-label'], 'onClick': context.attrs.onClick }, context.slots.default?.() ?? props.label ?? props['aria-label']) })
  app.component('UModal', { props: ['open', 'title'], setup: (props: { open: boolean, title: string }, context: { attrs: Record<string, unknown>, slots: Record<string, () => unknown[]> }) => () => Vue.h('div', { 'data-modal-title': props.title, 'data-modal-open': String(props.open), 'data-on-update-open': context.attrs['onUpdate:open'] }, context.slots.body?.()) })
  const renderErrors: unknown[] = []
  app.config.errorHandler = error => renderErrors.push(error)
  app.mount(root)
  return { app, root, renderErrors, route, get adjustmentForm() { return adjustmentFormInstance }, get transferForm() { return transferFormInstance }, stop: () => app.unmount() }
}

function walkNodes(node: unknown): Array<Record<string, unknown>> {
  if (!node || typeof node !== 'object') return []
  const record = node as { children?: unknown[], props?: Record<string, unknown>, text?: string }
  return [record, ...(record.children ?? []).flatMap(walkNodes)]
}

function renderedText(node: Record<string, unknown>): string {
  const record = node as { text?: string, children?: unknown[] }
  return [record.text ?? '', ...(record.children ?? []).map(child => renderedText(child as Record<string, unknown>))].join(' ')
}

function createMountedStockWorkspace(permissions: string[]) {
  const pendingAdjustment = deferred<{ id: number }>()
  const selectedOperation = Vue.ref(null as Record<string, unknown> | null)
  const resolvedOperation = Vue.ref(null as Record<string, unknown> | null)
  const operationIntentSequence = Vue.ref(0)
  const mutationError = Vue.ref('')
  const authorityGeneration = Vue.ref(0)
  const mutating = Vue.ref(false)
  const fieldErrors = Vue.ref({})
  const grants = new Set(permissions)
  const workspace: Record<string, unknown> = {
    balances: Vue.ref([]), movements: Vue.ref([]), lots: Vue.ref([]), adjustments: Vue.ref([]), transfers: Vue.ref([]),
    metas: Vue.reactive({ balances: null, movements: null, lots: null, adjustments: null, transfers: null }),
    stale: Vue.reactive({ balances: false, movements: false, lots: false, adjustments: false, transfers: false }),
    loading: Vue.reactive({ balances: false, movements: false, lots: false, adjustments: false, transfers: false }),
    listErrors: Vue.reactive({ balances: '', movements: '', lots: '', adjustments: '', transfers: '' }),
    establishments: Vue.ref([]), variants: Vue.ref([]), referencesLoading: Vue.ref(false), referencesError: Vue.ref(''),
    detail: Vue.ref(null), detailLoading: Vue.ref(false), detailError: Vue.ref(''), detailOpen: Vue.ref(false),
    mutationError, fieldErrors, mutating, selectedOperation, needsReconciliation: Vue.ref(false), canRetryExact: Vue.ref(false),
    resolvedOperation, authorityGeneration, operationIntentSequence,
    filters: Vue.reactive({ erp_establishment_id: '', erp_product_variant_id: '', source_type: '', source_id: '', direction: '', date_from: '', date_to: '', status: '', page: 1, per_page: 15 }),
    allowed: (action: string) => grants.has('*') || grants.has(action), load: async () => {}, loadReferences: async () => {}, setPage: () => {},
    beginManualIntent: () => {}, selectArea: async () => {}, closeDetail: () => {},
    inspectMovement: async () => {}, inspectAdjustment: async () => {}, inspectTransfer: async () => {}, refreshCurrentDetail: async () => {}, openRecoveryDetail: () => {},
    postAdjustment: (input: Record<string, string>) => {
      const key = '00000000-0000-0000-0000-000000000019'
      selectedOperation.value = { key, operation: 'erp.stock.adjustments.store', payload: { erp_establishment_id: Number(input.erp_establishment_id), erp_product_variant_id: Number(input.erp_product_variant_id), quantity_delta: input.quantity_delta, reason: input.reason }, path: '/erp/stock/adjustments' }
      operationIntentSequence.value++
      mutating.value = true
      return pendingAdjustment.promise.then((result) => {
        selectedOperation.value = null
        mutationError.value = 'Operação confirmada.'
        resolvedOperation.value = { key, operation: 'erp.stock.adjustments.store', outcome: result }
        mutating.value = false
        return result
      })
    },
    postTransfer: async () => null, reverseMovement: async () => null, reverseTransfer: async () => null,
    reconcile: async () => null, retryExact: async () => null, clearOperationNotice: () => { mutationError.value = '' }
  }
  return { workspace, pendingAdjustment, selectedOperation, resolvedOperation }
}

const stockBalance = { id: 1, erp_establishment_id: 8, erp_product_variant_id: 21, quantity_on_hand: '2.5000', ledger_initialized_at: null, reconciliation: null, average_cost: null }
const emptyPage = { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }

describe('stock operation payloads', () => {
  it('requires the exact stock grant and blocks viewer writes', () => {
    assert.equal(stockActionAllowed(['erp.stock.balances.index'], 'manager', 'erp.stock.balances.index'), true)
    assert.equal(stockActionAllowed(['erp.read'], 'manager', 'erp.stock.movements.index'), false)
    assert.equal(stockActionAllowed(['erp.stock.transfers.store'], 'viewer', 'erp.stock.transfers.store'), false)
    assert.equal(stockActionAllowed(['*'], 'manager', 'erp.stock.transfers.reverse'), true)
  })

  it('keeps adjustment quantities signed and requires a reason', () => {
    assert.deepEqual(buildErpStockAdjustmentPayload({
      erp_establishment_id: '8',
      erp_product_variant_id: '21',
      quantity_delta: '-2.5000',
      reason: 'Contagem física'
    }), {
      erp_establishment_id: 8,
      erp_product_variant_id: 21,
      quantity_delta: '-2.5000',
      reason: 'Contagem física'
    })
    assert.throws(() => buildErpStockAdjustmentPayload({
      erp_establishment_id: '8',
      erp_product_variant_id: '21',
      quantity_delta: '0.0000',
      reason: 'Contagem física'
    }), /diferença/i)
    assert.throws(() => buildErpStockAdjustmentPayload({
      erp_establishment_id: '8',
      erp_product_variant_id: '21',
      quantity_delta: '1.0000',
      reason: '  '
    }), /motivo/i)
  })

  it('requires distinct transfer establishments and a positive exact quantity', () => {
    assert.deepEqual(buildErpStockTransferPayload({
      from_establishment_id: '8',
      to_establishment_id: '9',
      erp_product_variant_id: '21',
      quantity: '3.1250'
    }), {
      from_establishment_id: 8,
      to_establishment_id: 9,
      erp_product_variant_id: 21,
      quantity: '3.1250'
    })
    assert.throws(() => buildErpStockTransferPayload({
      from_establishment_id: '8',
      to_establishment_id: '8',
      erp_product_variant_id: '21',
      quantity: '1.0000'
    }), /estabelecimentos/i)
    assert.throws(() => buildErpStockTransferPayload({
      from_establishment_id: '8',
      to_establishment_id: '9',
      erp_product_variant_id: '21',
      quantity: '0.0000'
    }), /positiva/i)
  })

  it('sends an explicit reversal quantity bounded by remaining source quantity', () => {
    assert.deepEqual(buildErpStockReversalPayload({ quantity: '', remaining_quantity: '2.5000', reason: 'Correção' }), {
      quantity: '2.5000',
      reason: 'Correção'
    })
    assert.deepEqual(buildErpStockReversalPayload({ quantity: '1.2500', remaining_quantity: '2.5000', reason: 'Correção' }), {
      quantity: '1.2500',
      reason: 'Correção'
    })
    assert.throws(() => buildErpStockReversalPayload({ quantity: '2.5001', remaining_quantity: '2.5000', reason: 'Correção' }), /saldo restante/i)
    assert.equal(remainingStockQuantity('3.0000', '1.2500'), '1.7500')
    assert.throws(() => remainingStockQuantity('3.0000', '3.0001'), /excede/i)
  })
})

describe('stock cost presentation', () => {
  it('keeps unknown and mixed-currency cost distinct from a numeric total', () => {
    assert.equal(stockCostDescription({ cost_status: 'legacy_unknown', total_cost: null, known_cost: null, unvalued_quantity: '3.0000', currency: null, cost_breakdown: null }), 'Custo legado desconhecido; quantidade sem valor: 3.0000')
    assert.equal(stockCostDescription({ cost_status: 'mixed_currency', total_cost: null, known_cost: null, unvalued_quantity: '0.0000', currency: null, cost_breakdown: [{ currency: 'BRL', known_cost: '10.0000', unvalued_quantity: '0.0000', cost_status: 'known' }, { currency: 'USD', known_cost: '2.0000', unvalued_quantity: '0.0000', cost_status: 'known' }] }), 'Custos em múltiplas moedas: BRL conhecido 10.0000 · USD conhecido 2.0000')
    assert.equal(stockCostDescription({ cost_status: 'known', total_cost: '10.0000', known_cost: '10.0000', unvalued_quantity: '1.0000', currency: null, cost_breakdown: null }), 'Custo conhecido sem moeda identificada: 10.0000; quantidade sem valor: 1.0000')
    assert.equal(stockCostDescription({ cost_status: 'mixed_currency', total_cost: null, known_cost: null, unvalued_quantity: '1.0000', currency: null, cost_breakdown: [{ currency: 'BRL', known_cost: '30.0000', unvalued_quantity: '0.0000' }] }), 'Custos em múltiplas moedas: BRL conhecido 30.0000; quantidade sem valor: 1.0000')
    assert.equal(stockCostDescription({ cost_status: 'known', total_cost: null, known_cost: '30.0000', unvalued_quantity: '0.5000', currency: null, cost_breakdown: null }), 'Custo classificado como conhecido, total não disponível; parcela conhecida sem moeda identificada 30.0000; quantidade sem valor: 0.5000')
    assert.equal(stockCostDescription({ cost_status: 'unknown-new-status', total_cost: null, known_cost: null, unvalued_quantity: '1.2500', currency: null, cost_breakdown: null }), 'Status de custo não reconhecido; consulte as alocações; quantidade sem valor: 1.2500')
    assert.equal(stockCostDescription({ cost_status: 'known', total_cost: '0.0000', known_cost: '0.0000', unvalued_quantity: '0.0000', currency: 'BRL', cost_breakdown: null }), 'BRL 0.0000')
  })
})

describe('stock workspace request lifecycle', () => {
  it('posts a transfer with the authorized exact grant, decimal body, and stable key header', async () => {
    const storage = installSessionStorage()
    const requests: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      requests.push({ path, options })
      if (options?.method === 'POST') return { data: { id: 44 } }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.transfers.store'])
    try {
      assert.equal(workspace.state.allowed('erp.stock.adjustments.store'), false)
      assert.equal(workspace.state.allowed('erp.stock.transfers.store'), true)
      assert.deepEqual(await workspace.state.postTransfer({ from_establishment_id: '8', to_establishment_id: '9', erp_product_variant_id: '21', quantity: '3.1250' }), { id: 44 })
      const post = requests.find(request => request.options?.method === 'POST')
      assert.equal(post?.path, '/erp/stock/transfers')
      assert.deepEqual(post?.options?.body, { from_establishment_id: 8, to_establishment_id: 9, erp_product_variant_id: 21, quantity: '3.1250' })
      assert.match(String((post?.options?.headers as Record<string, unknown>)?.['Idempotency-Key']), /^[0-9a-f-]{36}$/i)
    } finally {
      workspace.stop()
      storage.restore()
    }
  })

  it('preserves an uncertain operation and blocks a second post without replacing its identity', async () => {
    const storage = installSessionStorage()
    const posts: Array<Record<string, unknown>> = []
    const api = async (_path: string, options?: Record<string, unknown>) => {
      if (options?.method === 'POST') {
        posts.push(options)
        throw new Error('network disconnected')
      }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.adjustments.store'])
    try {
      await workspace.state.postAdjustment({ erp_establishment_id: '8', erp_product_variant_id: '21', quantity_delta: '1.0000', reason: 'Ajuste' })
      const firstKey = workspace.state.selectedOperation.value.key
      await workspace.state.postAdjustment({ erp_establishment_id: '8', erp_product_variant_id: '21', quantity_delta: '2.0000', reason: 'Outro ajuste' })
      assert.equal(posts.length, 1)
      assert.equal(workspace.state.selectedOperation.value.key, firstKey)
      assert.deepEqual(workspace.state.selectedOperation.value.payload, { erp_establishment_id: 8, erp_product_variant_id: 21, quantity_delta: '1.0000', reason: 'Ajuste' })
      assert.equal(workspace.state.needsReconciliation.value, true)
    } finally {
      workspace.stop()
      storage.restore()
    }
  })

  it('keeps the newest cross-resource detail when an older request resolves last', async () => {
    const movementRequest = deferred<{ data: unknown }>()
    const transferRequest = deferred<{ data: unknown }>()
    const api = async (path: string) => path.endsWith('/movements/10') ? movementRequest.promise : transferRequest.promise
    const workspace = createStockWorkspace(api, ['erp.stock.movements.show', 'erp.stock.transfers.show'])
    try {
      const oldMovement = workspace.state.inspectMovement(10)
      const newTransfer = workspace.state.inspectTransfer(22)
      transferRequest.resolve({ data: { id: 22, from_establishment_id: 8, to_establishment_id: 9, erp_product_variant_id: 21, quantity: '1.0000', returned_quantity: '0.0000', movements: [], reversals: [] } })
      await newTransfer
      movementRequest.resolve({ data: { id: 10, direction: 'out', quantity: '1.0000' } })
      await oldMovement
      assert.equal(workspace.state.detail.value.kind, 'transfer')
      assert.equal(workspace.state.detail.value.row.id, 22)
      assert.equal(workspace.state.detailLoading.value, false)
    } finally {
      workspace.stop()
    }
  })

  it('invalidates a pending automatic detail read after a manual form intent', async () => {
    const movementRequest = deferred<{ data: unknown }>()
    const api = async () => movementRequest.promise
    const workspace = createStockWorkspace(api, ['erp.stock.movements.show'])
    try {
      const pending = workspace.state.inspectMovement(10)
      workspace.state.beginManualIntent()
      movementRequest.resolve({ data: { id: 10, direction: 'out', quantity: '1.0000' } })
      await pending
      assert.equal(workspace.state.detail.value, null)
      assert.equal(workspace.state.detailOpen.value, false)
    } finally {
      workspace.stop()
    }
  })

  it('invalidates detail and its grant after a silent automatic refresh receives 403', async () => {
    let denyRefresh = false
    const api = async (path: string) => {
      if (path === '/erp/stock/movements/10' && denyRefresh) throw { status: 403, message: 'Denied' }
      if (path === '/erp/stock/movements/10') return { data: { id: 10, direction: 'out', quantity: '1.0000' } }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.movements.show'])
    try {
      await workspace.state.inspectMovement(10)
      assert.ok(workspace.state.detail.value)
      denyRefresh = true
      await workspace.state.refreshCurrentDetail()
      assert.equal(workspace.state.detail.value, null)
      assert.equal(workspace.state.detailOpen.value, false)
      assert.equal(workspace.state.allowed('erp.stock.movements.show'), false)
    } finally {
      workspace.stop()
    }
  })

  it('uses the rejected reference request that actually returned 403', async () => {
    const api = async (path: string) => {
      if (path.startsWith('/erp/establishments')) throw { status: 503, message: 'Temporarily unavailable' }
      if (path.startsWith('/erp/products')) throw { status: 403, message: 'Denied' }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.establishments.index', 'erp.products.index'])
    try {
      await workspace.state.loadReferences()
      assert.equal(workspace.state.allowed('erp.products.index'), false)
      assert.equal(workspace.state.allowed('erp.establishments.index'), true)
      assert.match(workspace.state.mutationError.value, /Denied/)
    } finally {
      workspace.stop()
    }
  })

  it('retains last-good balance rows and marks them stale after a transient refresh failure', async () => {
    let fail = false
    const api = async () => {
      if (fail) throw new Error('temporary failure')
      return { data: [stockBalance], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
    }
    const workspace = createStockWorkspace(api, ['erp.stock.balances.index'])
    try {
      await workspace.state.load('balances')
      fail = true
      await workspace.state.load('balances')
      assert.deepEqual(workspace.state.balances.value, [stockBalance])
      assert.equal(workspace.state.stale.balances, true)
      assert.match(workspace.state.listErrors.balances, /temporary failure/i)
    } finally {
      workspace.stop()
    }
  })

  it('clears private selection and loaded balances when company authority changes', async () => {
    const api = async () => ({ data: [stockBalance], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
    const workspace = createStockWorkspace(api, ['erp.stock.balances.index'])
    try {
      await workspace.state.load('balances')
      workspace.currentCompany.value = { ...workspace.currentCompany.value, id: 8 }
      await Vue.nextTick()
      assert.deepEqual(workspace.state.balances.value, [])
      assert.equal(workspace.state.metas.balances, null)
      assert.equal(workspace.state.detail.value, null)
    } finally {
      workspace.stop()
    }
  })

  it('does not restore a pending write into an authority context that lost its grant', async () => {
    const storage = installSessionStorage()
    const api = async (_path: string, options?: Record<string, unknown>) => {
      if (options?.method === 'POST') throw new Error('network disconnected')
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.adjustments.store'])
    try {
      await workspace.state.postAdjustment({ erp_establishment_id: '8', erp_product_variant_id: '21', quantity_delta: '1.0000', reason: 'Ajuste' })
      assert.ok(workspace.state.selectedOperation.value)
      workspace.currentCompany.value = { ...workspace.currentCompany.value, permissions: [] }
      await Vue.nextTick()
      assert.equal(workspace.state.selectedOperation.value, null)
      assert.equal(workspace.state.mutationError.value, '')
      assert.ok([...storage.values.values()].some(value => JSON.parse(value).operation === 'erp.stock.adjustments.store'))
    } finally {
      workspace.stop()
      storage.restore()
    }
  })

  it('does not treat a restored key as an operation created by a blocked form submit', async () => {
    const storage = installSessionStorage()
    const payload = { erp_establishment_id: 8, erp_product_variant_id: 21, quantity_delta: '1.0000', reason: 'Ajuste' }
    const key = '00000000-0000-0000-0000-000000000007'
    const pending = operationRecovery.createPendingOperation('91:7:erp.stock.adjustments.store', 'erp.stock.adjustments.store', '/erp/stock/adjustments', payload, key)
    operationRecovery.writePendingOperation(sessionStorage, 'erp-stock:v1:91:7', pending)
    const api = async () => emptyPage
    const workspace = createStockWorkspace(api, ['erp.stock.adjustments.store'])
    try {
      assert.equal(workspace.state.selectedOperation.value.key, key)
      const intent = workspace.state.operationIntentSequence.value
      await workspace.state.postAdjustment({ erp_establishment_id: '8', erp_product_variant_id: '21', quantity_delta: '1.0000', reason: 'Ajuste' })
      assert.equal(workspace.state.operationIntentSequence.value, intent)
      assert.equal(workspace.state.selectedOperation.value.key, key)
    } finally {
      workspace.stop()
      storage.restore()
    }
  })
})

describe('mounted stock operation forms', () => {
  it('keeps adjustment entry values as strings and emits the signed exact payload', () => {
    const mounted = mountStockChild('../app/components/erp/ErpStockAdjustmentForm.vue', {
      '#shared/types/erpBusiness': {},
      '#shared/utils/erpStock': { buildErpStockAdjustmentPayload, stockCostDescription }
    }, { establishments: [{ id: 8, name: 'Centro', status: 'active' }], variants: [{ id: 21, name: 'Café', sku: null, status: 'active', product: { id: 3, name: 'Café', kind: 'goods', track_stock: true } }] })
    const form = mounted.component.value
    Object.assign(form.state as object, { erp_establishment_id: '8', erp_product_variant_id: '21', quantity_delta: '-1.2500', reason: '  Contagem  ' })
    ;(form.submit as () => void)()
    assert.deepEqual(mounted.emitted, [{ erp_establishment_id: '8', erp_product_variant_id: '21', quantity_delta: '-1.2500', reason: 'Contagem' }])
    assert.deepEqual(mounted.renderErrors, [])
  })

  it('clears a transfer destination that becomes the selected source', async () => {
    const mounted = mountStockChild('../app/components/erp/ErpStockTransferForm.vue', {
      '#shared/types/erpBusiness': {},
      '#shared/utils/erpStock': { buildErpStockTransferPayload }
    }, { establishments: [{ id: 8, name: 'Centro', status: 'active' }, { id: 9, name: 'Filial', status: 'active' }], variants: [] })
    const state = mounted.component.value.state as Record<string, string>
    state.from_establishment_id = '8'
    state.to_establishment_id = '8'
    await Vue.nextTick()
    assert.equal(state.to_establishment_id, '')
    assert.deepEqual(mounted.renderErrors, [])
  })

  it('confirms and emits an explicit remaining movement reversal quantity', () => {
    const detail = {
      id: 31, erp_establishment_id: 8, erp_product_variant_id: 21, direction: 'out', quantity: '3.0000', unit_cost: null,
      source_type: 'erp_stock_adjustment', source_id: 12, source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      source_line_id: null, reversal_of_id: null, valuation_method: 'fifo', currency: null, source_currency: null, cost_breakdown: null,
      total_cost: null, known_cost: null, unvalued_quantity: '3.0000', cost_status: 'legacy_unknown', reason: 'Ajuste',
      allocations: [], reversal: { workflow: 'standalone', remaining_quantity: '1.7500', reason_required: true, source_id: 12, permission: 'erp.stock.movements.reverse' },
      reversals: [], lots: [], settlements: []
    }
    const mounted = mountStockChild('../app/components/erp/ErpStockMovementDetail.vue', {
      '#shared/types/erpBusiness': {},
      '#shared/utils/erpStock': { buildErpStockReversalPayload, stockCostDescription }
    }, { detail, canReverse: true })
    const form = mounted.component.value
    assert.equal(form.quantity, '1.7500')
    form.reason = 'Estorno parcial'
    form.confirmed = true
    ;(form.submitReversal as () => void)()
    assert.deepEqual(mounted.emitted, [{ quantity: '1.7500', reason: 'Estorno parcial', remaining_quantity: '1.7500' }])
    assert.deepEqual(mounted.renderErrors, [])
  })

  it('uses the reduced transfer balance and emits an explicit paired reversal amount', () => {
    const detail = {
      id: 41, movement_ids: [51, 52], from_establishment_id: 8, to_establishment_id: 9, erp_product_variant_id: 21,
      quantity: '3.0000', status: 'posted', source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      returned_quantity: '1.2500', movements: [], reversals: []
    }
    const mounted = mountStockChild('../app/components/erp/ErpStockTransferDetail.vue', {
      '#shared/types/erpBusiness': {},
      '#shared/utils/erpStock': { buildErpStockReversalPayload, remainingStockQuantity, stockCostDescription }
    }, { detail, canReverse: true })
    const form = mounted.component.value
    assert.equal(form.quantity, '1.7500')
    form.reason = 'Correção de transferência'
    form.confirmed = true
    ;(form.submitReversal as () => void)()
    assert.deepEqual(mounted.emitted, [{ quantity: '1.7500', reason: 'Correção de transferência', remaining_quantity: '1.7500' }])
    assert.deepEqual(mounted.renderErrors, [])
  })
})

describe('mounted stock workspace recovery lifecycle', () => {
  it('does not show an unauthorized area as an empty table and sanitizes its hash', async () => {
    const mock = createMountedStockWorkspace([])
    const mounted = mountStockWorkspace(mock.workspace, '#balances')
    try {
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(walkNodes(mounted.root).some(node => String(node.props?.['data-alert-title'] ?? '').match(/não permite consultar o estoque/i)), true)
      assert.equal(walkNodes(mounted.root).some(node => node.props?.['data-stock-table'] === 'true'), false)
      assert.equal(mounted.route.hash, '')
      assert.deepEqual(mounted.renderErrors, [])
    } finally {
      mounted.stop()
    }
  })

  it('routes an unauthorized balances hash to the first permitted stock area', async () => {
    const mock = createMountedStockWorkspace(['erp.stock.movements.index'])
    const mounted = mountStockWorkspace(mock.workspace, '#balances')
    try {
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(mounted.route.hash, '#movements')
      assert.equal(walkNodes(mounted.root).some(node => node.props?.['data-stock-table'] === 'true'), true)
      assert.deepEqual(mounted.renderErrors, [])
    } finally {
      mounted.stop()
    }
  })

  it('retains a canceled same-authority entry while pending, then resets its matching completed recovery', async () => {
    const mock = createMountedStockWorkspace(['erp.stock.balances.index', 'erp.stock.adjustments.store'])
    const mounted = mountStockWorkspace(mock.workspace)
    const button = (label: string) => walkNodes(mounted.root).find(node => (node as { tag?: string }).tag === 'button' && renderedText(node).includes(label))
    const modal = () => walkNodes(mounted.root).find(node => (node.props as Record<string, unknown> | undefined)?.['data-modal-title'] === 'Novo ajuste de estoque')
    try {
      await Vue.nextTick()
      const create = button('Novo ajuste')
      assert.ok(create)
      ;(create!.props as Record<string, unknown>).onClick?.()
      await Vue.nextTick()
      assert.ok(mounted.adjustmentForm)
      Object.assign(mounted.adjustmentForm!.state, { erp_establishment_id: '8', erp_product_variant_id: '21', quantity_delta: '-1.2500', reason: 'Contagem' })
      mounted.adjustmentForm!.submit()
      await Vue.nextTick()
      assert.equal(mounted.adjustmentForm!.locked, true)
      const cancel = modal()?.props?.['data-on-update-open'] as ((open: boolean) => void) | undefined
      assert.equal(typeof cancel, 'function')
      cancel!(false)
      await Vue.nextTick()
      assert.equal(modal()?.props?.['data-modal-open'], 'false')
      assert.equal(mounted.adjustmentForm!.state.reason, 'Contagem')
      const resume = button('Retomar ajuste')
      assert.ok(resume)
      ;(resume!.props as Record<string, unknown>).onClick?.()
      await Vue.nextTick()
      assert.equal(modal()?.props?.['data-modal-open'], 'true')
      mock.pendingAdjustment.resolve({ id: 81 })
      await mock.pendingAdjustment.promise
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(modal()?.props?.['data-modal-open'], 'false')
      assert.equal(mounted.adjustmentForm?.state.reason, '')
      assert.equal(mock.selectedOperation.value, null)
      assert.equal((mock.resolvedOperation.value as Record<string, unknown>).key, '00000000-0000-0000-0000-000000000019')
      assert.deepEqual(mounted.renderErrors, [])
    } finally {
      mounted.stop()
    }
  })

  it('preserves a newer draft fingerprint when an older submitted operation completes', async () => {
    const mock = createMountedStockWorkspace(['erp.stock.balances.index', 'erp.stock.adjustments.store'])
    const mounted = mountStockWorkspace(mock.workspace)
    const button = (label: string) => walkNodes(mounted.root).find(node => (node as { tag?: string }).tag === 'button' && renderedText(node).includes(label))
    const modal = () => walkNodes(mounted.root).find(node => (node.props as Record<string, unknown> | undefined)?.['data-modal-title'] === 'Novo ajuste de estoque')
    try {
      await Vue.nextTick()
      const create = button('Novo ajuste')
      assert.ok(create)
      ;(create!.props as Record<string, unknown>).onClick?.()
      await Vue.nextTick()
      Object.assign(mounted.adjustmentForm!.state, { erp_establishment_id: '8', erp_product_variant_id: '21', quantity_delta: '-1.2500', reason: 'Contagem' })
      mounted.adjustmentForm!.submit()
      await Vue.nextTick()
      mounted.adjustmentForm!.state.reason = 'Rascunho posterior'
      mock.pendingAdjustment.resolve({ id: 82 })
      await mock.pendingAdjustment.promise
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(modal()?.props?.['data-modal-open'], 'true')
      assert.equal(mounted.adjustmentForm!.state.reason, 'Rascunho posterior')
      assert.deepEqual(mounted.renderErrors, [])
    } finally {
      mounted.stop()
    }
  })
})
