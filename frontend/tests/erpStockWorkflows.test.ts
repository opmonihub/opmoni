/* eslint-disable @stylistic/max-statements-per-line -- The compact local Vue renderer keeps its patch operations readable. */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
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
    if (path === '~/utils/erpWorkspaceNav') return loadAppComposable('utils/erpWorkspaceNav.ts')
    if (path === '~/components/erp/erpPresentation') return loadAppComposable('components/erp/erpPresentation.ts')
    if (!(path in imports)) throw new Error(`Unexpected test import ${path}`)
    return imports[path]
  }, exports, ...Object.values(bindings))
  return exports
}

function createStockWorkspace(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, permissions = ['*'], refreshWithStatus = async () => ({ identity: {}, committed: true })) {
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, permissions, role: 'owner' })
  const authorityContext = Vue.ref('account-3:session-1')
  const key = Vue.computed(() => JSON.stringify([authorityContext.value, user.value.id, currentCompany.value.id, currentCompany.value.role, ...currentCompany.value.permissions]))
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
    useAuth: () => ({ user, currentCompany, refreshWithStatus }),
    useErpAdministrationContext: () => context
  })
  // eslint-disable-next-line @typescript-eslint/no-explicit-any -- The runtime-only harness reads the composable's Vue refs.
  const state = scope.run(() => (exports.useErpStockWorkspace as () => Record<string, any>)())!
  for (const callback of mounted) callback()
  return { state, user, currentCompany, authorityContext, stop: () => scope.stop() }
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
  const host = Vue.defineComponent({ setup: () => () => Vue.h(component, { ...props, ref: componentRef, onSubmit: (payload: unknown) => emitted.push(payload), onReverse: (payload: unknown) => emitted.push(payload), onOpenTransfer: (id: number) => emitted.push({ openTransfer: id }), onReconcile: () => emitted.push('reconcile'), onRetry: () => emitted.push('retry') }) })
  const app = renderer.createApp(host)
  const passthrough = { setup: (_props: unknown, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('div', context.slots.default?.()) }
  for (const name of ['UAlert', 'UCheckbox', 'UForm', 'UFormField', 'UInput', 'USelect', 'UTextarea']) app.component(name, passthrough)
  app.component('UButton', { props: ['label'], setup: (componentProps: { label?: string }, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('button', componentProps.label ?? context.slots.default?.()) })
  const renderErrors: unknown[] = []
  const renderWarnings: string[] = []
  app.config.errorHandler = error => renderErrors.push(error)
  app.config.warnHandler = warning => renderWarnings.push(warning)
  app.mount(root)
  return { component: componentRef, emitted, renderErrors, renderWarnings, root }
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
  const movementDetailComponent = compileStockComponent('../app/components/erp/ErpStockMovementDetail.vue', {
    '#shared/types/erpBusiness': {},
    '#shared/utils/erpStock': { buildErpStockReversalPayload, stockCostDescription }
  }, { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch })
  const transferDetailComponent = compileStockComponent('../app/components/erp/ErpStockTransferDetail.vue', {
    '#shared/types/erpBusiness': {},
    '#shared/utils/erpStock': { buildErpStockReversalPayload, remainingStockQuantity, stockCostDescription }
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
  app.component('ErpListFooter', compileVueComponent('components/erp/ErpListFooter.vue'))
  app.component('UPagination', { setup: (_props, context) => () => Vue.h('nav', { ...context.attrs, 'data-pagination': true }) })
  let adjustmentFormInstance: { state: Record<string, string>, submit: () => void, locked: boolean } | null = null
  let transferFormInstance: { state: Record<string, string>, submit: () => void, locked: boolean } | null = null
  const passthrough = { setup: (_props: unknown, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('div', context.slots.body?.() ?? context.slots.default?.()) }
  for (const name of ['UNavigationMenu', 'USelect', 'UInput', 'UForm', 'UFormField', 'UTextarea', 'UCheckbox', 'UBadge', 'USkeleton', 'CompanyMenu', 'UserMenu']) app.component(name, passthrough)
  app.component('ErpStockMovementDetail', movementDetailComponent)
  app.component('ErpStockTransferDetail', transferDetailComponent)
  app.component('UAlert', { props: ['title'], setup: (props: { title?: string }, context: { slots: Record<string, () => unknown[]> }) => () => Vue.h('div', { 'data-alert-title': props.title }, [props.title ?? '', context.slots.description?.(), context.slots.actions?.()]) })
  app.component('UTable', { props: ['data'], setup: (props: { data?: unknown[] }, context: { slots: Record<string, (scope?: unknown) => unknown[]> }) => () => Vue.h('table', { 'data-stock-table': 'true' }, (props.data ?? []).map(original => Vue.h('tr', {}, ['record', 'context', 'value', 'actions'].map(name => Vue.h('td', {}, context.slots[`${name}-cell`]?.({ row: { original } }) ?? []))))) })
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
  const renderWarnings: string[] = []
  app.config.errorHandler = error => renderErrors.push(error)
  app.config.warnHandler = warning => renderWarnings.push(warning)
  app.mount(root)
  return { app, root, renderErrors, renderWarnings, route, get adjustmentForm() { return adjustmentFormInstance }, get transferForm() { return transferFormInstance }, stop: () => app.unmount() }
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
  it('releases only the proven no-effect legacy transfer rejection and retains unknown conflicts', async () => {
    const storage = installSessionStorage()
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path === '/erp/stock/transfers/41/reverse') throw { statusCode: 409, data: { message: 'Transferência antiga exige conciliação.' } }
      if (path === '/erp/stock/adjustments') return { data: { id: 71 } }
      if (path === '/erp/stock/movements/31/reverse') throw { statusCode: 409, data: { message: 'Chave reutilizada com conteúdo diferente.' } }
      if (path.startsWith('/erp/operations/lookup')) throw { statusCode: 404, data: { code: 'operation_not_recorded', message: 'Operação não registrada' } }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.transfers.reverse', 'erp.stock.adjustments.store', 'erp.stock.movements.reverse'])
    try {
      await workspace.state.reverseTransfer(41, { quantity: '1.0000', remaining_quantity: '1.0000', reason: 'Conciliação necessária' })
      assert.equal(workspace.state.selectedOperation.value, null)
      assert.equal(workspace.state.needsReconciliation.value, true)
      assert.match(workspace.state.mutationError.value, /transferência #41 antiga precisa de conciliação/)
      await workspace.state.postAdjustment({ erp_establishment_id: '8', erp_product_variant_id: '21', quantity_delta: '1.0000', reason: 'Entrada contada' })
      assert.equal(calls.some(call => call.path === '/erp/stock/adjustments'), true)

      const key = '00000000-0000-0000-0000-000000000031'
      const pending = operationRecovery.createPendingOperation('91:7:erp.stock.movements.reverse', 'erp.stock.movements.reverse', '/erp/stock/movements/31/reverse', { quantity: '1.0000', reason: 'Revisar' }, key)
      operationRecovery.writePendingOperation(sessionStorage, 'erp-stock:v1:91:7', pending)
      const restored = createStockWorkspace(api, ['erp.stock.movements.reverse'])
      try {
        await restored.state.reconcile()
        await restored.state.retryExact()
        assert.equal(restored.state.selectedOperation.value.key, key)
        assert.equal(restored.state.needsReconciliation.value, true)
        assert.equal(JSON.parse(storage.values.get('erp-stock:v1:91:7')!).key, key)
      } finally {
        restored.stop()
      }
    } finally {
      workspace.stop()
      storage.restore()
    }
  })

  it('does not let a stale legacy rejection clear the new authority pending operation', async () => {
    const storage = installSessionStorage()
    const adjustment = { erp_establishment_id: 8, erp_product_variant_id: 21, quantity_delta: '1.0000', reason: 'Ajuste da empresa B' }
    const companyBPending = operationRecovery.createPendingOperation('91:8:erp.stock.adjustments.store', 'erp.stock.adjustments.store', '/erp/stock/adjustments', adjustment, '00000000-0000-0000-0000-000000000208')
    operationRecovery.writePendingOperation(sessionStorage, 'erp-stock:v1:91:8', companyBPending)
    let workspace: ReturnType<typeof createStockWorkspace> | null = null
    let switched = false
    const legacyError = {
      get statusCode() {
        if (!switched) {
          switched = true
          queueMicrotask(() => {
            workspace!.currentCompany.value = { ...workspace!.currentCompany.value, id: 8 }
          })
        }
        return 409
      },
      data: { message: 'Transferência antiga exige conciliação.' }
    }
    const api = async (path: string, options?: Record<string, unknown>) => {
      if (path === '/erp/stock/transfers/41/reverse' && options?.method === 'POST') throw legacyError
      return emptyPage
    }
    workspace = createStockWorkspace(api, ['erp.stock.transfers.reverse', 'erp.stock.adjustments.store'])
    try {
      await workspace.state.reverseTransfer(41, { quantity: '1.0000', remaining_quantity: '1.0000', reason: 'Conciliação necessária' })
      await Vue.nextTick()
      assert.equal(workspace.currentCompany.value.id, 8)
      assert.equal(workspace.state.selectedOperation.value?.key, companyBPending.key)
      assert.equal(JSON.parse(storage.values.get('erp-stock:v1:91:8')!).key, companyBPending.key)
      assert.ok(storage.values.has('erp-stock:v1:91:7'), 'the original company A idempotency key remains scoped to company A')
      assert.equal(workspace.state.mutationError.value.includes('transferência #41 antiga precisa de conciliação'), false)
    } finally {
      workspace.stop()
      storage.restore()
    }
  })

  it('does not reopen an exhausted movement detail after a watcher dismisses the nested refresh', async () => {
    const storage = installSessionStorage()
    const detail = {
      id: 31, erp_establishment_id: 8, erp_product_variant_id: 21, direction: 'out', quantity: '1.0000', unit_cost: null,
      source_type: 'erp_stock_adjustment', source_id: 12, source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      source_line_id: null, reversal_of_id: null, valuation_method: 'fifo', currency: null, source_currency: null, cost_breakdown: null,
      total_cost: null, known_cost: null, unvalued_quantity: '1.0000', cost_status: 'legacy_unknown', reason: 'Ajuste',
      allocations: [], reversal: { workflow: 'standalone', remaining_quantity: '1.0000', reason_required: true, source_id: 12, permission: 'erp.stock.movements.reverse' },
      reversals: [], lots: [], settlements: []
    }
    const refreshed = { ...detail, reversal: { ...detail.reversal, remaining_quantity: '0.0000' } }
    const secondRead = deferred<{ data: typeof refreshed }>()
    let detailReads = 0
    const api = async (path: string, options?: Record<string, unknown>) => {
      if (path === '/erp/stock/movements/31' && !options?.method) {
        detailReads++
        return detailReads === 1 ? { data: detail } : secondRead.promise
      }
      if (path === '/erp/stock/movements/31/reverse') return { data: { id: 88 } }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.movements.show', 'erp.stock.movements.reverse', 'erp.stock.movements.index'])
    try {
      await workspace.state.inspectMovement(31)
      const dismissOnExhaustion = Vue.watch(workspace.state.detail, (selection: { row: typeof refreshed } | null) => {
        if (selection?.kind === 'movement' && selection.row.reversal.remaining_quantity === '0.0000') workspace.state.closeDetail()
      }, { flush: 'pre' })
      const operation = workspace.state.reverseMovement(31, { quantity: '1.0000', remaining_quantity: '1.0000', reason: 'Corrigir' })
      await new Promise(resolve => setTimeout(resolve, 0))
      secondRead.resolve({ data: refreshed })
      await operation
      await Vue.nextTick()
      assert.equal(workspace.state.detail.value, null)
      assert.equal(workspace.state.detailOpen.value, false)
      dismissOnExhaustion()
    } finally {
      workspace.stop()
      storage.restore()
    }
  })

  it('clears a denied read action when the company and grant context changes', async () => {
    const api = async (path: string) => {
      if (path === '/erp/stock/movements/31') throw { statusCode: 403, data: { message: 'Sem acesso' } }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.movements.show', 'erp.stock.movements.reverse'])
    try {
      await workspace.state.inspectMovement(31)
      assert.equal(workspace.state.allowed('erp.stock.movements.show'), false)
      workspace.currentCompany.value = { ...workspace.currentCompany.value, id: 8, permissions: [...workspace.currentCompany.value.permissions, 'erp.stock.lots.index'] }
      await Vue.nextTick()
      assert.equal(workspace.state.allowed('erp.stock.movements.show'), true)
    } finally {
      workspace.stop()
    }
  })

  it('ignores a delayed authorization refresh from the previous company', async () => {
    const storage = installSessionStorage()
    const refresh = deferred<{ identity: object, committed: boolean }>()
    const detail = {
      id: 31, erp_establishment_id: 8, erp_product_variant_id: 21, direction: 'out', quantity: '1.0000', unit_cost: null,
      source_type: 'erp_stock_adjustment', source_id: 12, source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      source_line_id: null, reversal_of_id: null, valuation_method: 'fifo', currency: null, source_currency: null, cost_breakdown: null,
      total_cost: null, known_cost: null, unvalued_quantity: '1.0000', cost_status: 'known', reason: 'Ajuste', allocations: [],
      reversal: { workflow: 'standalone', remaining_quantity: '1.0000', reason_required: true, source_id: 12, permission: 'erp.stock.movements.reverse' },
      reversals: [], lots: [], settlements: []
    }
    const api = async (path: string) => {
      if (path === '/erp/stock/movements/31') return { data: detail }
      if (path === '/erp/stock/movements/31/reverse') throw { statusCode: 403, data: { message: 'Sem acesso' } }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.movements.show', 'erp.stock.movements.reverse'], () => refresh.promise)
    try {
      await workspace.state.inspectMovement(31)
      const denied = workspace.state.reverseMovement(31, { quantity: '1.0000', remaining_quantity: '1.0000', reason: 'Corrigir' })
      await new Promise(resolve => setTimeout(resolve, 0))
      workspace.currentCompany.value = { ...workspace.currentCompany.value, id: 8 }
      await Vue.nextTick()
      refresh.resolve({ identity: {}, committed: true })
      await denied
      await Vue.nextTick()
      assert.equal(workspace.state.allowed('erp.stock.movements.reverse'), true)
      assert.equal(workspace.state.mutationError.value, '')
    } finally {
      workspace.stop()
      storage.restore()
    }
  })

  it('keeps a pending reversal reachable after detail dismissal and failed source refresh without an index grant', async () => {
    const storage = installSessionStorage()
    const key = '00000000-0000-0000-0000-000000000032'
    const payload = { quantity: '0.7500', reason: 'Correção parcial' }
    const pending = operationRecovery.createPendingOperation('91:7:erp.stock.movements.reverse', 'erp.stock.movements.reverse', '/erp/stock/movements/31/reverse', payload, key)
    operationRecovery.writePendingOperation(sessionStorage, 'erp-stock:v1:91:7', pending)
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path === '/erp/stock/movements/31') throw { statusCode: 503, data: { message: 'Falha de rede' } }
      if (path.startsWith('/erp/operations/lookup')) throw { statusCode: 404, data: { code: 'operation_not_recorded', message: 'Operação não registrada' } }
      if (path === '/erp/stock/movements/31/reverse' && options?.method === 'POST') return { data: { id: 87 } }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.movements.show', 'erp.stock.movements.reverse'])
    const mounted = mountStockWorkspace(workspace.state)
    const button = (label: string) => walkNodes(mounted.root).find(node => (node as { tag?: string }).tag === 'button' && renderedText(node).includes(label))
    try {
      await Vue.nextTick()
      const resume = button('Retomar correção pendente')
      assert.ok(resume)
      ;(resume!.props as Record<string, unknown>).onClick?.()
      await new Promise(resolve => setTimeout(resolve, 0))
      await Vue.nextTick()
      assert.match(workspace.state.detailError.value, /Falha de rede/)
      assert.equal(workspace.state.allowed('erp.stock.movements.index'), false)
      assert.ok(button('Consultar operação'))
      ;(button('Consultar operação')!.props as Record<string, unknown>).onClick?.()
      await new Promise(resolve => setTimeout(resolve, 0))
      await Vue.nextTick()
      assert.equal(workspace.state.canRetryExact.value, true)
      ;(button('Repetir exatamente')!.props as Record<string, unknown>).onClick?.()
      await new Promise(resolve => setTimeout(resolve, 0))
      await Vue.nextTick()
      const retry = calls.find(call => call.path === pending.path && call.options?.method === 'POST')
      assert.deepEqual(retry?.options?.body, payload)
      assert.equal((retry?.options?.headers as Record<string, string>)['Idempotency-Key'], key)
      assert.equal(workspace.state.selectedOperation.value, null)
      assert.deepEqual(mounted.renderErrors, [])
    } finally {
      mounted.stop()
      workspace.stop()
      storage.restore()
    }
  })

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
      assert.equal(JSON.parse(storage.values.get('erp-stock:v1:91:7')!).key, firstKey)
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

  it('renders raw allocation lineage and cost settlement facts from the movement API', () => {
    const detail = {
      id: 31, erp_establishment_id: 8, erp_product_variant_id: 21, direction: 'out', quantity: '3.0000', unit_cost: null,
      source_type: 'erp_sale', source_id: 12, source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      source_line_id: 55, reversal_of_id: null, valuation_method: 'fifo', currency: 'BRL', source_currency: 'BRL', cost_breakdown: null,
      total_cost: null, known_cost: '12.5000', unvalued_quantity: '1.5000', cost_status: 'mixed', reason: null,
      allocations: [{ id: 80, erp_company_id: 7, erp_establishment_id: 8, erp_product_variant_id: 21, erp_stock_movement_id: 31,
        erp_stock_lot_id: 90, original_allocation_id: 71, ordinal: 1, kind: 'issue', quantity: '1.5000', cost: '12.5000',
        returned_quantity: '0.2500', returned_cost: '3.1250', settled_quantity: '1.0000', currency: 'BRL', cost_status: 'known' },
      { id: 81, erp_company_id: 7, erp_establishment_id: 8, erp_product_variant_id: 21, erp_stock_movement_id: 31,
        erp_stock_lot_id: null, original_allocation_id: null, ordinal: 2, kind: 'deficit', quantity: '1.5000', cost: null,
        returned_quantity: '0.0000', returned_cost: '0.0000', settled_quantity: '0.0000', currency: null, cost_status: 'legacy_unknown' }],
      reversal: { workflow: 'sale_return', remaining_quantity: '0.0000', reason_required: true, source_id: 12, permission: 'erp.sale-returns.post' },
      reversals: [], lots: [], settlements: [{ id: 150, erp_company_id: 7, erp_establishment_id: 8, erp_product_variant_id: 21,
        erp_stock_allocation_id: 80, erp_stock_lot_id: 91, erp_stock_movement_id: 44, reversal_of_id: null, quantity: '1.5000',
        cost: '6.2500', returned_quantity: '0.0000', returned_cost: '0.0000', currency: 'BRL', posted_at: '2026-10-20T14:00:00Z' },
      { id: 151, erp_company_id: 7, erp_establishment_id: 8, erp_product_variant_id: 21, erp_stock_allocation_id: 80,
        erp_stock_lot_id: 90, erp_stock_movement_id: 45, reversal_of_id: 150, quantity: '0.2500', cost: null,
        returned_quantity: '0.0000', returned_cost: '0.0000', currency: 'BRL', posted_at: '2026-10-20T15:00:00Z' }]
    }
    const mounted = mountStockChild('../app/components/erp/ErpStockMovementDetail.vue', {
      '#shared/types/erpBusiness': {},
      '#shared/utils/erpStock': { buildErpStockReversalPayload, stockCostDescription }
    }, { detail, canReverse: false })
    const text = renderedText(mounted.root as unknown as Record<string, unknown>)
    assert.match(text, /BRL · 12\.5000/)
    assert.match(text, /alocação original #71/)
    assert.match(text, /Devolvida 0\.2500 · custo 3\.1250 · conciliada 1\.0000/)
    assert.match(text, /Custo desconhecido · legacy_unknown/)
    assert.match(text, /alocação original #não informada/)
    assert.match(text, /Conciliação #150/)
    assert.match(text, /movimento #44/)
    assert.match(text, /camada #91/)
    assert.match(text, /Conciliação #150 · quantidade 1\.5000 · BRL 6\.2500/)
    assert.match(text, /Estorno da conciliação #150/)
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

  it('distinguishes missing transfer reversal permission from an exhausted transfer', () => {
    const detail = {
      id: 41, movement_ids: [51, 52], from_establishment_id: 8, to_establishment_id: 9, erp_product_variant_id: 21,
      quantity: '3.0000', status: 'posted', source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      returned_quantity: '1.2500', movements: [], reversals: []
    }
    const forbidden = mountStockChild('../app/components/erp/ErpStockTransferDetail.vue', {
      '#shared/types/erpBusiness': {}, '#shared/utils/erpStock': { buildErpStockReversalPayload, remainingStockQuantity, stockCostDescription }
    }, { detail, canReverse: false })
    const forbiddenText = renderedText(forbidden.root as unknown as Record<string, unknown>)
    assert.match(forbiddenText, /Saldo ainda corrigível: 1\.7500\. Seu acesso não permite/)
    assert.doesNotMatch(forbiddenText, /Não há saldo restante/)
    assert.match(forbiddenText, /retira o estoque do destino e o devolve à origem/)
    const exhausted = mountStockChild('../app/components/erp/ErpStockTransferDetail.vue', {
      '#shared/types/erpBusiness': {}, '#shared/utils/erpStock': { buildErpStockReversalPayload, remainingStockQuantity, stockCostDescription }
    }, { detail: { ...detail, returned_quantity: '3.0000' }, canReverse: false })
    const exhaustedText = renderedText(exhausted.root as unknown as Record<string, unknown>)
    assert.match(exhaustedText, /Não há saldo restante para corrigir/)
    assert.doesNotMatch(exhaustedText, /Seu acesso não permite corrigir/)
  })

  it('routes only the server-approved source workflow to its canonical return area', () => {
    const movement = (workflow: string, permission: string | null, sourceId: number) => ({
      id: 31, erp_establishment_id: 8, erp_product_variant_id: 21, direction: 'out', quantity: '1.0000', unit_cost: null,
      source_type: 'erp_sale', source_id: sourceId, source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      source_line_id: 55, reversal_of_id: null, valuation_method: 'fifo', currency: 'BRL', source_currency: 'BRL', cost_breakdown: null,
      total_cost: null, known_cost: null, unvalued_quantity: '1.0000', cost_status: 'legacy_unknown', reason: null,
      allocations: [], reversal: { workflow, remaining_quantity: '0.0000', reason_required: true, source_id: sourceId, permission }, reversals: [], lots: [], settlements: []
    })
    const transfer = mountStockChild('../app/components/erp/ErpStockMovementDetail.vue', {
      '#shared/types/erpBusiness': {}, '#shared/utils/erpStock': { buildErpStockReversalPayload, stockCostDescription }
    }, { detail: movement('transfer', 'erp.stock.transfers.reverse', 72), canReverse: false, canOpenTransfer: true })
    const transferLink = walkNodes(transfer.root).find(node => (node as { tag?: string }).tag === 'button' && renderedText(node).includes('Abrir transferência vinculada #72'))
    assert.ok(transferLink)
    ;(transferLink!.props as Record<string, unknown>).onClick?.()
    assert.deepEqual(transfer.emitted, [{ openTransfer: 72 }])
    assert.equal(walkNodes(transfer.root).some(node => node.props?.['to']), false)

    const purchase = mountStockChild('../app/components/erp/ErpStockMovementDetail.vue', {
      '#shared/types/erpBusiness': {}, '#shared/utils/erpStock': { buildErpStockReversalPayload, stockCostDescription }
    }, { detail: movement('purchase_return', 'erp.purchase-returns.post', 81), canReverse: false, canOpenPurchaseReturn: true })
    const purchaseLink = walkNodes(purchase.root).find(node => node.props?.['to'] === '/erp/compras#returns')
    assert.ok(purchaseLink)
    assert.match(renderedText(purchaseLink!), /origem #81/)

    const sale = mountStockChild('../app/components/erp/ErpStockMovementDetail.vue', {
      '#shared/types/erpBusiness': {}, '#shared/utils/erpStock': { buildErpStockReversalPayload, stockCostDescription }
    }, { detail: movement('sale_return', 'erp.sale-returns.post', 91), canReverse: false, canOpenSaleReturn: true })
    const saleLink = walkNodes(sale.root).find(node => node.props?.['to'] === '/erp/vendas#returns')
    assert.ok(saleLink)
    assert.match(renderedText(saleLink!), /origem #91/)

    const mismatchedPolicy = mountStockChild('../app/components/erp/ErpStockMovementDetail.vue', {
      '#shared/types/erpBusiness': {}, '#shared/utils/erpStock': { buildErpStockReversalPayload, stockCostDescription }
    }, { detail: movement('sale_return', 'erp.purchase-returns.post', 92), canReverse: true, canOpenSaleReturn: true })
    assert.equal(walkNodes(mismatchedPolicy.root).some(node => node.props?.['to']), false)
    assert.match(renderedText(mismatchedPolicy.root as unknown as Record<string, unknown>), /Origem de venda #92/)
    assert.deepEqual(mismatchedPolicy.emitted, [])
  })

  it('keeps lookup and exact retry controls in the detail overlay after the source is exhausted', () => {
    const detail = {
      id: 31, erp_establishment_id: 8, erp_product_variant_id: 21, direction: 'out', quantity: '1.0000', unit_cost: null,
      source_type: 'erp_stock_adjustment', source_id: 12, source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      source_line_id: null, reversal_of_id: null, valuation_method: 'fifo', currency: null, source_currency: null, cost_breakdown: null,
      total_cost: null, known_cost: null, unvalued_quantity: '1.0000', cost_status: 'known', reason: 'Ajuste', allocations: [],
      reversal: { workflow: 'standalone', remaining_quantity: '0.0000', reason_required: true, source_id: 12, permission: 'erp.stock.movements.reverse' },
      reversals: [], lots: [], settlements: []
    }
    const mounted = mountStockChild('../app/components/erp/ErpStockMovementDetail.vue', {
      '#shared/types/erpBusiness': {}, '#shared/utils/erpStock': { buildErpStockReversalPayload, stockCostDescription }
    }, { detail, canReverse: true, operationPending: true, canRetryExact: true })
    for (const label of ['Consultar operação', 'Repetir exatamente']) {
      const button = walkNodes(mounted.root).find(node => (node as { tag?: string }).tag === 'button' && renderedText(node).includes(label))
      assert.ok(button)
      ;(button!.props as Record<string, unknown>).onClick?.()
    }
    assert.deepEqual(mounted.emitted, ['reconcile', 'retry'])
    assert.doesNotMatch(renderedText(mounted.root as unknown as Record<string, unknown>), /Corrigir este movimento/)
    assert.deepEqual(mounted.renderErrors, [])
  })
})

describe('mounted stock workspace recovery lifecycle', () => {
  it('selects cost layers on root entry with only the lots grant and follows hash history', async (t) => {
    const calls: string[] = []
    const workspace = createStockWorkspace(async (path) => { calls.push(path); return emptyPage }, ['erp.stock.lots.index'])
    const mounted = mountStockWorkspace(workspace.state)
    t.after(() => { mounted.stop(); workspace.stop() })
    for (let index = 0; index < 10; index++) await Vue.nextTick()
    assert.ok(calls.some(path => path.startsWith('/erp/stock/lots?')))
    assert.equal(calls.some(path => path.startsWith('/erp/stock/balances?')), false)
    assert.equal(walkNodes(mounted.root).some(node => node.props?.['data-stock-table'] === 'true'), true)
    mounted.route.hash = '#balances'
    await Vue.nextTick()
    assert.equal(walkNodes(mounted.root).some(node => node.props?.['data-stock-table'] === 'true'), false)
    mounted.route.hash = '#lots'
    await Vue.nextTick()
    assert.equal(walkNodes(mounted.root).some(node => node.props?.['data-stock-table'] === 'true'), true)
    assert.deepEqual(mounted.renderErrors, [])
  })

  it('labels known and unrecognized movement sources and lot cost states in Portuguese', async (t) => {
    const workspace = createStockWorkspace(async () => emptyPage, ['*'])
    const mounted = mountStockWorkspace(workspace.state, '#movements')
    t.after(() => { mounted.stop(); workspace.stop() })
    await Vue.nextTick()
    workspace.state.movements.value = [{ id: 51, erp_establishment_id: 8, erp_product_variant_id: 21, direction: 'in', quantity: '1.0000', source_type: 'erp_purchase', source_id: 10, cost_status: 'known', total_cost: '12.0000', known_cost: '12.0000', unvalued_quantity: '0.0000', currency: 'BRL', cost_breakdown: null }]
    await Vue.nextTick()
    assert.match(renderedText(mounted.root as unknown as Record<string, unknown>), /Origem: Compra #10/)
    workspace.state.movements.value = [{ ...workspace.state.movements.value[0], source_type: 'future_source' }]
    await Vue.nextTick()
    assert.match(renderedText(mounted.root as unknown as Record<string, unknown>), /Origem não reconhecida \(future_source\)/)
    mounted.route.hash = '#lots'
    await Vue.nextTick()
    workspace.state.lots.value = [{ id: 61, erp_establishment_id: 8, erp_product_variant_id: 21, erp_stock_movement_id: 51, quantity: '1.0000', available_quantity: '1.0000', total_cost: '12.0000', currency: 'BRL', cost_status: 'known' }]
    await Vue.nextTick()
    assert.match(renderedText(mounted.root as unknown as Record<string, unknown>), /Conhecido/)
    workspace.state.lots.value = [{ ...workspace.state.lots.value[0], cost_status: 'future_cost' }]
    await Vue.nextTick()
    assert.match(renderedText(mounted.root as unknown as Record<string, unknown>), /Situação de custo não reconhecida \(future_cost\)/)
  })

  it('follows a transfer source policy into the authorized paired transfer detail', async () => {
    const movement = {
      id: 31, erp_establishment_id: 8, erp_product_variant_id: 21, direction: 'out', quantity: '2.0000', unit_cost: null,
      source_type: 'erp_stock_transfer', source_id: 41, source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      source_line_id: null, reversal_of_id: null, valuation_method: 'fifo', currency: 'BRL', source_currency: 'BRL', cost_breakdown: null,
      total_cost: null, known_cost: null, unvalued_quantity: '2.0000', cost_status: 'known', reason: null, allocations: [],
      reversal: { workflow: 'transfer', remaining_quantity: '2.0000', reason_required: true, source_id: 41, permission: 'erp.stock.transfers.reverse' },
      reversals: [], lots: [], settlements: []
    }
    const transfer = { id: 41, movement_ids: [51, 52], from_establishment_id: 8, to_establishment_id: 9, erp_product_variant_id: 21,
      quantity: '2.0000', status: 'posted', source_operation_key: null, posted_by_user_id: 91, posted_at: null,
      returned_quantity: '0.0000', movements: [], reversals: [] }
    const calls: string[] = []
    const api = async (path: string) => {
      calls.push(path)
      if (path === '/erp/stock/movements/31') return { data: movement }
      if (path === '/erp/stock/transfers/41') return { data: transfer }
      return emptyPage
    }
    const workspace = createStockWorkspace(api, ['erp.stock.movements.index', 'erp.stock.movements.show', 'erp.stock.transfers.show'])
    await workspace.state.inspectMovement(31)
    const mounted = mountStockWorkspace(workspace.state, '#movements')
    try {
      await Vue.nextTick()
      const link = walkNodes(mounted.root).find(node => (node as { tag?: string }).tag === 'button' && renderedText(node).includes('Abrir transferência vinculada #41'))
      assert.ok(link)
      ;(link!.props as Record<string, unknown>).onClick?.()
      await new Promise(resolve => setTimeout(resolve, 0))
      await Vue.nextTick()
      assert.equal(calls.includes('/erp/stock/transfers/41'), true)
      assert.equal((workspace.state.detail.value as { kind: string, row: { id: number } }).kind, 'transfer')
      assert.equal((workspace.state.detail.value as { kind: string, row: { id: number } }).row.id, 41)
      assert.deepEqual(mounted.renderErrors, [])
      assert.deepEqual(mounted.renderWarnings, [])
    } finally {
      mounted.stop()
      workspace.stop()
    }
  })

  it('does not show an unauthorized area as an empty table and preserves its explicit hash', async () => {
    const mock = createMountedStockWorkspace([])
    const mounted = mountStockWorkspace(mock.workspace, '#balances')
    try {
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(walkNodes(mounted.root).some(node => String(node.props?.['data-alert-title'] ?? '').match(/não permite consultar o estoque/i)), true)
      assert.equal(walkNodes(mounted.root).some(node => node.props?.['data-stock-table'] === 'true'), false)
      assert.equal(mounted.route.hash, '#balances')
      assert.deepEqual(mounted.renderErrors, [])
    } finally {
      mounted.stop()
    }
  })

  it('keeps an explicit denied balances destination denied instead of selecting movements', async () => {
    const mock = createMountedStockWorkspace(['erp.stock.movements.index'])
    const mounted = mountStockWorkspace(mock.workspace, '#balances')
    try {
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(mounted.route.hash, '#balances')
      assert.equal(walkNodes(mounted.root).some(node => node.props?.['data-stock-table'] === 'true'), false)
      assert.match(renderedText(mounted.root as unknown as Record<string, unknown>), /não permite consultar o estoque/)
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
      assert.deepEqual(mounted.renderWarnings, [])
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

it('pages the real stock footer once for the selected area, preserving filters and denying revoked access', async (t) => {
  const calls: string[] = []
  const pending = deferred<unknown>()
  const workspace = createStockWorkspace(async (path) => {
    calls.push(path)
    const url = new URL(path, 'http://test')
    if (url.pathname === '/erp/stock/movements' && url.searchParams.get('page') === '2') return pending.promise
    return { data: [], meta: { current_page: 1, last_page: 3, per_page: 15, total: 35 } }
  })
  t.after(workspace.stop)
  Object.assign(workspace.state.filters, { erp_establishment_id: '3', erp_product_variant_id: '21', date_from: '2026-10-01', date_to: '2026-10-31' })
  await workspace.state.load('movements', 1)
  const mounted = mountStockWorkspace(workspace.state, '#movements')
  t.after(mounted.stop)
  for (let turn = 0; turn < 8; turn++) { await Promise.resolve(); await Vue.nextTick() }
  const pagination = () => (walkNodes(mounted.root).find(node => (node.props as Record<string, unknown>)?.['data-pagination'] === true)!.props as Record<string, unknown>)
  const change = (value: number) => (pagination()['onUpdate:page'] as (page: number) => void)(value)
  const before = calls.length
  change(1)
  change(0)
  assert.equal(calls.length, before)
  change(2)
  await Vue.nextTick()
  assert.equal(calls.length, before + 1)
  const url = new URL(calls.at(-1)!, 'http://test')
  assert.equal(url.pathname, '/erp/stock/movements')
  assert.equal(url.searchParams.get('page'), '2')
  for (const key of ['erp_establishment_id', 'erp_product_variant_id', 'date_from', 'date_to']) assert.equal(url.searchParams.get(key), workspace.state.filters[key])
  change(3)
  await Vue.nextTick()
  assert.equal(calls.length, before + 1)
  pending.resolve({ data: [], meta: { current_page: 2, last_page: 3, per_page: 15, total: 35 } })
  await pending.promise
  await Vue.nextTick()
  await Vue.nextTick()
  assert.equal(pagination().page, 2)
  assert.match(renderedText(mounted.root), /35 registros/)
  assert.equal(mounted.route.hash, '#movements')
  workspace.currentCompany.value = { ...workspace.currentCompany.value, role: 'viewer', permissions: [] }
  await Vue.nextTick()
  assert.equal(walkNodes(mounted.root).some(node => (node.props as Record<string, unknown>)?.['data-pagination'] === true), false)
  const deniedBefore = calls.length
  await workspace.state.load('movements', 3)
  assert.equal(calls.length, deniedBefore)
  assert.deepEqual(mounted.renderErrors, [])
})

for (const authorityChange of ['company', 'account', 'session']) it(`reloads the current stock destination after ${authorityChange} changes with identical grants and discards its late former list`, async (t) => {
  const requests: Array<ReturnType<typeof deferred<typeof emptyPage>>> = []
  const workspace = createStockWorkspace(async (path) => {
    if (!path.startsWith('/erp/stock/balances?')) return emptyPage
    const pending = deferred<typeof emptyPage>()
    requests.push(pending)
    return await pending.promise
  }, ['erp.stock.balances.index'])
  const mounted = mountStockWorkspace(workspace.state, '#balances')
  t.after(() => { mounted.stop(); workspace.stop() })
  for (let index = 0; index < 10; index++) await Vue.nextTick()
  assert.equal(requests.length, 1)
  if (authorityChange === 'company') workspace.currentCompany.value = { ...workspace.currentCompany.value, id: 8 }
  else workspace.authorityContext.value = authorityChange === 'account' ? 'account-4:session-1' : 'account-3:session-2'
  for (let index = 0; index < 10; index++) await Vue.nextTick()
  assert.equal(requests.length, 2)
  requests[1]!.resolve(emptyPage)
  await requests[1]!.promise
  await Vue.nextTick()
  requests[0]!.resolve({ ...emptyPage, data: [{ erp_establishment_id: 99 }] })
  await requests[0]!.promise
  await Vue.nextTick()
  assert.deepEqual(workspace.state.balances.value, [])
  assert.deepEqual(mounted.renderErrors, [])
})
