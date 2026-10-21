/* eslint-disable @stylistic/max-statements-per-line -- Compact callbacks keep the local Vue renderer readable beside the workflow assertions. */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as operationRecovery from '../shared/utils/erpOperationRecovery.ts'
import {
  buildPurchaseReturnPayload,
  buildXmlConfirmationItems,
  addPurchaseMoney,
  calculatePurchaseLineTotal,
  convertPurchaseQuantity,
  formatPurchaseDecimal,
  purchaseUnitFactor,
  replacePurchaseTax,
  samePurchaseDecimal
} from '../shared/utils/erpPurchase.ts'

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

function createWorkspace(
  api: (path: string, options?: Record<string, unknown>) => Promise<unknown>,
  permissions = ['*'],
  refreshWithStatus: () => Promise<unknown> = async () => ({ identity: { id: 91 }, committed: true })
) {
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, permissions, role: 'owner' })
  const key = Vue.computed(() => JSON.stringify([user.value.id, currentCompany.value.id, currentCompany.value.role, ...currentCompany.value.permissions]))
  const context = { key, capture: () => ({ key: key.value }), isCurrent: (token: { key: string }) => token.key === key.value }
  const scope = Vue.effectScope()
  const exports = compileModule(readFileSync(new URL('../app/composables/useErpPurchaseWorkspace.ts', import.meta.url), 'utf8'), {
    '#shared/types/erpBusiness': {},
    '~/composables/useApiError': apiErrors,
    '#shared/utils/erpPurchase': { buildPurchaseReturnPayload, buildXmlConfirmationItems },
    '#shared/utils/erpOperationRecovery': operationRecovery
  }, {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch,
    onMounted: () => {}, onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany, refreshWithStatus }),
    useErpAdministrationContext: () => context
  })
  // eslint-disable-next-line @typescript-eslint/no-explicit-any -- The production composable exposes Vue refs through a runtime-only test harness.
  const state = scope.run(() => (exports.useErpPurchaseWorkspace as () => Record<string, any>)())!
  return { state, user, currentCompany, stop: () => scope.stop() }
}

function preview(chave: string) {
  return { token: '11111111-2222-4333-8444-555555555555', chave, emit: { name: 'Fornecedor', cpf_cnpj: '11222333000181' }, dest: { name: 'Empresa', cpf_cnpj: '30288513000100' }, det: [{ line: 1, codigo: 'A', xProd: 'Produto', qCom: '3.0000', uCom: 'UN', vUnCom: '10.0000' }], total: {}, duplicatas: [], errors: [], duplicate: false, duplicate_purchase_id: null, expires_at: '2026-10-21T00:00:00Z', state: 'pending' }
}

function xmlFile(xml: string) {
  return { size: xml.length, text: async () => xml } as File
}

function textContent(node: { text?: string, children?: unknown[] }): string {
  return `${node.text ?? ''}${(node.children ?? []).map(child => typeof child === 'object' && child !== null ? textContent(child as { text?: string, children?: unknown[] }) : '').join('')}`
}

function realPurchaseChild(name: 'ErpPurchaseForm' | 'ErpPurchaseReturnForm') {
  const path = name === 'ErpPurchaseForm'
    ? '../app/components/erp/ErpPurchaseForm.vue'
    : '../app/components/erp/ErpPurchaseReturnForm.vue'
  const { descriptor } = parse(readFileSync(new URL(path, import.meta.url), 'utf8'))
  const script = compileScript(descriptor, { id: name, inlineTemplate: true })
  const exports = compileModule(script.content, {
    'vue': Vue,
    '#shared/types/erpBusiness': {},
    '#shared/utils/erpPurchase': { addPurchaseMoney, calculatePurchaseLineTotal, convertPurchaseQuantity, formatPurchaseDecimal, purchaseUnitFactor, replacePurchaseTax, samePurchaseDecimal, buildPurchaseReturnPayload }
  }, { ref: Vue.ref, computed: Vue.computed })
  return exports.default as Vue.Component
}

function realPurchaseRecovery() {
  const source = readFileSync(new URL('../app/components/erp/ErpPurchaseOperationRecovery.vue', import.meta.url), 'utf8')
  const script = compileScript(parse(source).descriptor, { id: 'ErpPurchaseOperationRecovery', inlineTemplate: true })
  return compileModule(script.content, { vue: Vue }, { ref: Vue.ref, computed: Vue.computed }).default as Vue.Component
}

function mountPurchaseWorkspace(initialHash = '#purchases') {
  const route = Vue.reactive({ path: '/erp/compras', query: {}, hash: initialHash })
  const replacements: Array<Record<string, unknown>> = []
  const authorityGeneration = Vue.ref(0)
  const mutationError = Vue.ref('')
  const draftSaveResults: unknown[] = []
  const loads: string[] = []
  const navItems = Vue.ref([] as Array<Array<Record<string, unknown>>>)
  const state: Record<string, unknown> = {
    purchases: Vue.ref([]), returns: Vue.ref([]), meta: Vue.ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 }), returnMeta: Vue.ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 }),
    suppliers: Vue.ref([]), establishments: Vue.ref([]), variants: Vue.ref([]), units: Vue.ref([]), selected: Vue.ref(null), selectedReturn: Vue.ref(null), previewState: Vue.ref(null),
    listLoading: Vue.ref(false), returnsLoading: Vue.ref(false), referencesLoading: Vue.ref(false), mutating: Vue.ref(false), loadingDetail: Vue.ref(false), pageError: Vue.ref(''), referenceError: Vue.ref(''), mutationError, detailError: Vue.ref(''), fieldErrors: Vue.ref({}),
    selectedOperation: Vue.ref(null), needsReconciliation: Vue.ref(false), canRetryExact: Vue.ref(false), staleRevisionRefreshed: Vue.ref(null), authorityGeneration, filters: Vue.reactive({ status: '', search: '', page: 1, per_page: 15 }), returnFilters: Vue.reactive({ status: '', page: 1, per_page: 15 }),
    allowed: () => true,
    load: async () => { loads.push('purchases') }, loadReturns: async () => { loads.push('returns') }, loadReferences: async () => {}, inspect: async () => null, closeDetail: () => {}, saveDraft: async () => { const result = draftSaveResults.shift() ?? null; if (!result) mutationError.value = 'A conexão caiu; confira a operação.'; return result },
    postReceipt: async () => null, inspectXml: async () => null, refreshXmlPreview: async () => null, cancelXmlPreview: () => {}, confirmXml: async () => null, createReturn: async () => null, inspectReturn: async () => null, postReturn: async () => null, reconcile: async () => {}, retryExact: async () => null, clearOperationNotice: () => { mutationError.value = '' }
  }
  const file = readFileSync(new URL('../app/components/erp/ErpPurchaseWorkspace.vue', import.meta.url), 'utf8')
  const { descriptor } = parse(file)
  const script = compileScript(descriptor, { id: 'ErpPurchaseWorkspace', inlineTemplate: true })
  const compiled = compileModule(script.content, {
    'vue': Vue,
    '#shared/types/erpBusiness': {},
    '#shared/utils/erpPurchase': { formatPurchaseDecimal },
    '~/composables/useErpPurchaseWorkspace': { useErpPurchaseWorkspace: () => state }
  }, {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted: Vue.onMounted, nextTick: Vue.nextTick,
    useRoute: () => route,
    useRouter: () => ({ replace(location: Record<string, unknown>) { replacements.push(location); route.hash = String(location.hash ?? ''); return Promise.resolve() } })
  })
  const components: Record<string, Vue.Component> = {
    ErpCatalogPageShell: { setup: (_props, context) => () => Vue.h('main', {}, [context.slots.actions?.(), context.slots.toolbar?.(), context.slots.default?.()]) },
    UButton: { props: ['label'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, onClick: context.attrs.onClick }, context.slots.default?.() ?? String(props.label ?? '')) },
    UModal: { props: ['open', 'title'], setup: (props, context) => () => props.open ? Vue.h('section', { 'data-modal': props.title }, context.slots.body?.()) : Vue.createCommentVNode() },
    UNavigationMenu: { setup: (_props, context) => () => { navItems.value = context.attrs.items as Array<Array<Record<string, unknown>>>; return Vue.h('nav') } },
    UTable: { props: ['data', 'columns'], setup: (props, context) => () => Vue.h('div', {}, (props.data as Array<Record<string, unknown>> ?? []).flatMap((original) => {
      const row = { original }
      return [context.slots['actions-cell']?.({ row }) ?? null, context.slots['amount-cell']?.({ row }) ?? null]
    })) },
    UAlert: { props: ['title'], setup: (props, _context) => () => Vue.h('aside', { 'data-alert': props.title }, String(props.title ?? '')) },
    UBadge: { setup: (_props, context) => () => Vue.h('span', {}, context.slots.default?.()) },
    ErpPurchaseForm: {
      props: ['modelValue', 'operationError'],
      emits: ['update:modelValue', 'submit', 'cancel'],
      setup: (props, context) => () => Vue.h('div', {}, [
        Vue.h('input', { 'aria-label': 'Número do documento', 'value': (props.modelValue as { document_no?: string })?.document_no, 'onInput': (event: { target: { value: string } }) => context.emit('update:modelValue', { ...(props.modelValue as Record<string, unknown>), document_no: event.target.value }) }),
        Vue.h('button', { 'aria-label': 'Fechar formulário', 'onClick': () => context.emit('cancel') }, 'Fechar'),
        Vue.h('button', { 'aria-label': 'Salvar rascunho', 'onClick': () => context.emit('submit', props.modelValue) }, 'Salvar'),
        props.operationError ? Vue.h('aside', {}, String(props.operationError)) : null
      ])
    },
    ErpPurchaseReturnForm: { setup: () => () => Vue.h('div') },
    ErpPurchaseXmlPreview: { setup: () => () => Vue.h('div') }
  }
  components.ErpPurchaseOperationRecovery = realPurchaseRecovery()
  components.NuxtLink = { setup: (_props, context) => () => Vue.h('a', context.attrs, context.slots.default?.()) }
  components.ErpPurchaseForm = realPurchaseChild('ErpPurchaseForm')
  components.ErpPurchaseReturnForm = realPurchaseChild('ErpPurchaseReturnForm')
  components.UFormField = { props: ['label', 'error', 'name'], setup: (props, context) => () => Vue.h('label', { 'data-field': props.name }, [Vue.h('span', {}, String(props.label ?? '')), context.slots.default?.(), props.error ? Vue.h('small', {}, String(props.error)) : null]) }
  for (const name of ['UInput', 'UInputMenu', 'USelect', 'UTextarea']) components[name] = {
    props: ['modelValue', 'items'], emits: ['update:modelValue'],
    setup: (props, context) => () => Vue.h(name === 'UTextarea' ? 'textarea' : 'input', {
      ...context.attrs, value: props.modelValue,
      onInput: (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value),
      onSelect: (value: unknown) => context.emit('update:modelValue', value)
    })
  }
  components.UAlert ??= { props: ['title'], setup: props => () => Vue.h('aside', { 'data-alert': props.title }, String(props.title ?? '')) }
  const root = { children: [] as unknown[] }
  const renderer = Vue.createRenderer({
    patchProp(node: { props: Record<string, unknown> }, key, _previous, next) { node.props[key] = next },
    insert(node: { parent?: unknown }, parent: { children: unknown[] }, anchor?: unknown) {
      const previousParent = node.parent as { children: unknown[] } | undefined
      if (previousParent) {
        const previousIndex = previousParent.children.indexOf(node)
        if (previousIndex >= 0) previousParent.children.splice(previousIndex, 1)
      }
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
  const app = renderer.createApp(compiled.default as Vue.Component)
  const renderErrors: unknown[] = []
  app.config.errorHandler = error => renderErrors.push(error)
  for (const [name, component] of Object.entries(components)) app.component(name, component)
  app.mount(root)
  function find(node: unknown, predicate: (node: { tag?: string, props?: Record<string, unknown> }) => boolean): { tag?: string, props?: Record<string, unknown> } | null {
    if (typeof node !== 'object' || node === null) return null
    const record = node as { tag?: string, props?: Record<string, unknown>, children?: unknown[] }
    if (predicate(record)) return record
    for (const child of record.children ?? []) { const result = find(child, predicate); if (result) return result }
    return null
  }
  function findInModal(title: string, predicate: (node: { tag?: string, props?: Record<string, unknown> }) => boolean) {
    const modal = find(root, node => node.props?.['data-modal'] === title)
    return modal ? find(modal, predicate) : null
  }
  return { app, route, replacements, authorityGeneration, mutationError, draftSaveResults, loads, navItems, root, state, find, findInModal, textContent, renderErrors }
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
  const storage = {
    getItem(key: string) { return values.get(key) ?? null },
    setItem(key: string, value: string) { values.set(key, value) },
    removeItem(key: string) { values.delete(key) }
  }
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: storage })
  return {
    values,
    restore() { if (previous) Object.defineProperty(globalThis, 'sessionStorage', previous); else Reflect.deleteProperty(globalThis, 'sessionStorage') }
  }
}

describe('purchase decimal and source helpers', () => {
  it('keeps four-place money and quantity strings exact through conversion and line totals', () => {
    assert.equal(convertPurchaseQuantity('1.2345', '2.00000000'), '2.4690')
    assert.equal(purchaseUnitFactor(9, 2, [
      { id: 9, base_unit_id: 3, conversion_factor: '2.00000000' },
      { id: 3, base_unit_id: 2, conversion_factor: '5.00000000' },
      { id: 2, base_unit_id: null, conversion_factor: '1.00000000' }
    ]), '10.00000000')
    assert.equal(calculatePurchaseLineTotal('3.3333', '3.3333', '0.0000', '0.0001'), '11.1109')
    assert.equal(formatPurchaseDecimal('0000123.4500'), '123,4500')
    assert.equal(formatPurchaseDecimal('-123.4500'), '-123,4500')
    assert.equal(samePurchaseDecimal('3', '3.0000'), true)
    assert.equal(samePurchaseDecimal('0.33330', '0.3333'), false)
    assert.throws(() => convertPurchaseQuantity('1.0000', '0.00000000'), /fator/i)
    assert.throws(() => convertPurchaseQuantity('0.0001', '0.00010000'), /precisão/i)
  })

  it('builds return lines only from remaining source quantities and preserves source IDs', () => {
    const payload = buildPurchaseReturnPayload({
      erp_purchase_id: 41,
      currency: 'BRL',
      returnable_lines: [
        { source_line_id: 501, quantity: '3.0000', returned_quantity: '1.0000', remaining_quantity: '2.0000', line_total: '10.0000' },
        { source_line_id: 502, quantity: '1.0000', returned_quantity: '0.0000', remaining_quantity: '1.0000', line_total: '0.0000' }
      ]
    }, { 501: '1.0000', 502: '' }, 'Divergência na entrega')
    assert.deepEqual(payload, {
      erp_purchase_id: 41,
      reason: 'Divergência na entrega',
      lines: [{ source_line_id: 501, quantity: '1.0000' }]
    })
    assert.throws(() => buildPurchaseReturnPayload({
      erp_purchase_id: 41,
      currency: 'BRL',
      returnable_lines: [{ source_line_id: 501, quantity: '3.0000', returned_quantity: '2.0000', remaining_quantity: '1.0000', line_total: '10.0000' }]
    }, { 501: '1.0001' }, 'Excesso'), /saldo disponível/i)
  })

  it('requires an explicit product and unit choice for every XML line', () => {
    const lines = [
      { line: 1, erp_product_variant_id: 71, erp_unit_id: 8, unit_conversion_factor: '2.00000000' },
      { line: 2, erp_product_variant_id: 72, erp_unit_id: 9, unit_conversion_factor: '1.00000000' }
    ]
    assert.deepEqual(buildXmlConfirmationItems(2, lines), lines)
    assert.throws(() => buildXmlConfirmationItems(2, lines.slice(0, 1)), /mapeie todos/i)
    assert.throws(() => buildXmlConfirmationItems(2, [{ ...lines[0], erp_product_variant_id: 0 }, lines[1]]), /produto ativo/i)
  })

  it('checks preview-read as its own write-family action and respects each catalog family', async () => {
    const file = readFileSync(new URL('../app/composables/useErpPurchaseWorkspace.ts', import.meta.url), 'utf8')
    const exports = compileModule(file, {
      '#shared/types/erpBusiness': {}, '~/composables/useApiError': apiErrors,
      '#shared/utils/erpPurchase': { buildPurchaseReturnPayload, buildXmlConfirmationItems },
      '#shared/utils/erpOperationRecovery': operationRecovery
    }, { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted: () => {}, onScopeDispose: Vue.onScopeDispose, useAccessApi: () => ({}), useAuth: () => ({}), useErpAdministrationContext: () => ({}) })
    const allowed = exports.erpPurchaseAllowed as (grants: string[], role: string, action: string) => boolean
    assert.equal(allowed(['erp.purchases.xml-preview.write'], 'owner', 'erp.purchases.xml-preview.read'), true)
    assert.equal(allowed(['erp.purchases.write'], 'owner', 'erp.purchases.xml-preview.read'), false)
    assert.equal(allowed(['erp.contacts.read'], 'owner', 'erp.contacts.index'), true)
    assert.equal(allowed(['erp.products.write'], 'owner', 'erp.products.index'), false)
    assert.equal(allowed(['erp.purchases.store.write'], 'viewer', 'erp.purchases.store'), false)
  })

  it('fences late XML responses by preview sequence and cancel, keeping the newest explicit mapping', async () => {
    const calls = new Map<string, ReturnType<typeof deferred<unknown>>>()
    const workspace = createWorkspace(async (_path, options) => {
      const xml = String((options?.body as { xml: string }).xml)
      const pending = deferred<unknown>()
      calls.set(xml, pending)
      return pending.promise
    }, ['erp.purchases.write', 'erp.purchases.xml-preview.write'])
    const state = workspace.state
    const first = state.inspectXml(xmlFile('file-a'), 3)
    await Promise.resolve()
    const second = state.inspectXml(xmlFile('file-b'), 3)
    await Promise.resolve()
    calls.get('file-b')!.resolve({ data: preview('B') })
    await second
    state.previewState.value.mappings[0].erp_product_variant_id = 71
    calls.get('file-a')!.resolve({ data: preview('A') })
    await first
    assert.equal(state.previewState.value.preview.chave, 'B')
    assert.equal(state.previewState.value.mappings[0].erp_product_variant_id, 71)

    const canceled = state.inspectXml(xmlFile('file-c'), 3)
    await Promise.resolve()
    state.cancelXmlPreview()
    calls.get('file-c')!.resolve({ data: preview('C') })
    await canceled
    assert.equal(state.previewState.value, null)
    workspace.stop()
  })

  it('ignores an obsolete XML upload failure after a newer preview has succeeded', async () => {
    const oldRequest = deferred<unknown>()
    let uploads = 0
    const workspace = createWorkspace(async () => {
      uploads++
      return uploads === 1 ? oldRequest.promise : { data: preview('NEW') }
    }, ['erp.purchases.write', 'erp.purchases.xml-preview.write'])
    const oldPreview = workspace.state.inspectXml(xmlFile('old'), 3)
    await Promise.resolve()
    await workspace.state.inspectXml(xmlFile('new'), 3)
    oldRequest.reject({ statusCode: 503, data: { message: 'Old upload unavailable' } })
    await oldPreview
    assert.equal(workspace.state.previewState.value.preview.chave, 'NEW')
    assert.equal(workspace.state.mutationError.value, '')
    workspace.stop()
  })

  it('persists supplier and item mapping edits and restores them after a transient XML refresh error', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    let failRead = false
    const workspace = createWorkspace(async (path) => {
      if (path === '/erp/purchases/xml-preview') return { data: preview('MAP') }
      if (path === '/erp/purchases/xml-preview/read' && failRead) throw { statusCode: 503, data: { message: 'Read temporarily unavailable' } }
      if (path === '/erp/purchases/xml-preview/read') return { data: preview('MAP') }
      throw new Error(`Unexpected request ${path}`)
    })
    await workspace.state.inspectXml(xmlFile('<invoice/>'), 3)
    workspace.state.previewState.value.erp_contact_id = 8
    workspace.state.previewState.value.mappings[0] = { line: 1, erp_product_variant_id: 71, erp_unit_id: 2, unit_conversion_factor: '2.00000000' }
    await Vue.nextTick()
    const storageKey = 'erp-purchases:v1:91:7:xml-preview'
    const saved = JSON.parse(session.values.get(storageKey) ?? 'null')
    assert.equal(saved.erp_contact_id, 8)
    assert.deepEqual(saved.mappings[0], { line: 1, erp_product_variant_id: 71, erp_unit_id: 2, unit_conversion_factor: '2.00000000' })
    assert.equal(JSON.stringify(saved).includes('<invoice/>'), false)
    failRead = true
    await workspace.state.refreshXmlPreview()
    assert.equal(workspace.state.previewState.value.mappings[0].erp_product_variant_id, 71)
    workspace.stop()

    const restored = createWorkspace(async () => { throw new Error('No upload should be required') })
    restored.state.restorePreview()
    assert.equal(restored.state.previewState.value.erp_contact_id, 8)
    assert.equal(restored.state.previewState.value.mappings[0].erp_product_variant_id, 71)
    restored.stop()
  })

  it('does not let an old-company preview result repopulate private state after context changes', async () => {
    const pending = deferred<unknown>()
    const workspace = createWorkspace(async () => pending.promise, ['erp.purchases.write', 'erp.purchases.xml-preview.write'])
    const request = workspace.state.inspectXml(xmlFile('company-a'), 3)
    workspace.currentCompany.value = { id: 8, permissions: ['erp.purchases.xml-preview.write'], role: 'owner' }
    await Vue.nextTick()
    pending.resolve({ data: preview('A') })
    await request
    assert.equal(workspace.state.previewState.value, null)
    assert.deepEqual(workspace.state.purchases.value, [])
    workspace.stop()
  })

  it('creates, reloads, edits by revision, and posts a receipt through the existing purchase endpoints', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const source = {
      id: 41, erp_establishment_id: 3, erp_contact_id: 8, status: 'draft', currency: 'BRL', total_amount: '30.0000', draft_revision: 1,
      document_no: 'NF-41', supplier_snapshot: { name: 'Fornecedor Alfa' }, source_access_key: null, source_sha256: null, source_byte_length: null, source_operation_key: null,
      needs_reconciliation: false, lines: [{ id: 51, erp_product_variant_id: 71, erp_unit_id: 2, description: 'Produto A', quantity: '3.0000', unit_cost: '10.0000', unit_conversion_factor: '1.00000000', line_total: '30.0000' }]
    }
    let current = source
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const workspace = createWorkspace(async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/purchases' && options?.method === 'POST') { current = { ...source, ...(options.body as object) }; return { data: current } }
      if (path === '/erp/purchases/41' && options?.method === 'PATCH') { current = { ...current, ...(options.body as object), draft_revision: 2 }; return { data: current } }
      if (path === '/erp/purchases/41/post') { current = { ...current, status: 'posted', draft_revision: null }; return { data: current, obligation_id: 61, financial_disposition: 'payable' } }
      if (path === '/erp/purchases?page=1&per_page=15') return { data: [current], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path === '/erp/purchases/41') return { data: current }
      throw new Error(`Unexpected request ${path}`)
    })
    const payload = { erp_establishment_id: 3, erp_contact_id: 8, currency: 'BRL', document_no: 'NF-41', expected_total: '30.0000', lines: [{ erp_product_variant_id: 71, erp_unit_id: 2, description: 'Produto A', quantity: '3.0000', unit_cost: '10.0000', unit_conversion_factor: '1.00000000', discount_amount: '0.0000', tax_amount: '0.0000' }] }
    const created = await workspace.state.saveDraft(payload)
    assert.equal((created as { id: number }).id, 41)
    assert.equal(workspace.state.selected.value.id, 41)
    const createCall = calls.find(call => call.path === '/erp/purchases' && call.options?.method === 'POST')!
    assert.deepEqual(createCall.options?.body, payload)
    assert.match(String((createCall.options?.headers as Record<string, string>)['Idempotency-Key']), /^[0-9a-f-]{36}$/i)

    await workspace.state.saveDraft({ ...payload, draft_revision: 1 }, 41)
    const editCall = calls.find(call => call.path === '/erp/purchases/41' && call.options?.method === 'PATCH')!
    assert.equal((editCall.options?.body as { draft_revision: number }).draft_revision, 1)
    assert.equal(workspace.state.selected.value.draft_revision, 2)
    await workspace.state.postReceipt(workspace.state.selected.value)
    const postCall = calls.find(call => call.path === '/erp/purchases/41/post')!
    assert.deepEqual(postCall.options?.body, { draft_revision: 2, expected_total: '30.0000' })
    assert.equal(workspace.state.selected.value.status, 'posted')
    assert.equal(calls.filter(call => call.path === '/erp/purchases/41' && !call.options?.method).length, 3, 'authorized current detail GET follows each committed mutation')
    workspace.stop()
  })

  it('recovers a lost create response through lookup and refreshes the current purchase detail', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const purchase = { id: 44, erp_establishment_id: 3, erp_contact_id: 8, status: 'draft', currency: 'BRL', total_amount: '12.0000', draft_revision: 1, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [] }
    const workspace = createWorkspace(async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/purchases' && options?.method === 'POST') throw new Error('connection lost after commit')
      if (path.startsWith('/erp/operations/lookup?')) return { data: { state: 'completed', result: purchase } }
      if (path.startsWith('/erp/purchases?')) return { data: [purchase], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path === '/erp/purchases/44') return { data: purchase }
      throw new Error(`Unexpected request ${path}`)
    })
    const payload = { erp_establishment_id: 3, erp_contact_id: 8, currency: 'BRL', document_no: 'LOST-44', lines: [{ description: 'Item', quantity: '1.0000', unit_cost: '12.0000' }] }
    assert.equal(await workspace.state.saveDraft(payload), null)
    const pending = workspace.state.selectedOperation.value
    assert.ok(pending)
    assert.equal(pending.payload.document_no, 'LOST-44')
    await workspace.state.reconcile()
    assert.equal(workspace.state.selectedOperation.value, null)
    assert.equal(workspace.state.selected.value.id, 44)
    assert.ok(calls.some(call => call.path.startsWith('/erp/operations/lookup?') && (call.options?.headers as Record<string, string>)['Idempotency-Key'] === pending.key))
    assert.ok(calls.some(call => call.path === '/erp/purchases/44'), 'the recovered outcome is followed by an authorized current GET')
    workspace.stop()
  })

  it('retries a not-yet-recorded operation with the original method, body, path, and key', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const writes: Array<{ path: string, options?: Record<string, unknown> }> = []
    const purchase = { id: 45, erp_establishment_id: 3, erp_contact_id: 8, status: 'draft', currency: 'BRL', total_amount: '7.0000', draft_revision: 1, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [] }
    const workspace = createWorkspace(async (path, options) => {
      if (path === '/erp/purchases' && options?.method === 'POST') {
        writes.push({ path, options })
        if (writes.length === 1) throw new Error('response lost')
        return { data: purchase }
      }
      if (path.startsWith('/erp/operations/lookup?')) throw { statusCode: 404, data: { message: 'Not found' } }
      if (path.startsWith('/erp/purchases?')) return { data: [purchase], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path === '/erp/purchases/45') return { data: purchase }
      throw new Error(`Unexpected request ${path}`)
    })
    const payload = { erp_establishment_id: 3, erp_contact_id: 8, currency: 'BRL', document_no: 'RETRY-45', lines: [{ description: 'Item', quantity: '1.0000', unit_cost: '7.0000' }] }
    await workspace.state.saveDraft(payload)
    const pending = workspace.state.selectedOperation.value
    await workspace.state.reconcile()
    assert.equal(workspace.state.canRetryExact.value, true)
    await workspace.state.retryExact()
    assert.equal(writes.length, 2)
    assert.equal(writes[1].path, writes[0].path)
    assert.equal(writes[1].options?.method, writes[0].options?.method)
    assert.deepEqual(writes[1].options?.body, writes[0].options?.body)
    assert.equal((writes[1].options?.headers as Record<string, string>)['Idempotency-Key'], pending.key)
    workspace.stop()
  })

  it('releases only an explicitly stale purchase revision after lookup 404 and a fresh authorized changed revision', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const paths: string[] = []
    const source = { id: 44, erp_establishment_id: 3, erp_contact_id: 8, status: 'draft', currency: 'BRL', total_amount: '21.0000', draft_revision: 2, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [] }
    const workspace = createWorkspace(async (path, options) => {
      paths.push(`${options?.method ?? 'GET'} ${path}`)
      if (path === '/erp/purchases/44' && options?.method === 'PATCH') throw { statusCode: 409, data: { message: 'Rascunho alterado.' } }
      if (path.startsWith('/erp/operations/lookup?')) throw { statusCode: 404, data: { code: 'operation_not_recorded', message: 'Operação não registrada' } }
      if (path === '/erp/purchases/44') return { data: source }
      if (path.startsWith('/erp/purchases?')) return { data: [source], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      throw new Error(`Unexpected request ${path}`)
    })
    workspace.state.selected.value = { ...source, draft_revision: 1 }
    const stalePayload = { erp_establishment_id: 3, erp_contact_id: 8, currency: 'BRL', document_no: 'PO-44', draft_revision: 1, lines: [] }
    await workspace.state.saveDraft(stalePayload, 44)
    assert.ok(workspace.state.selectedOperation.value)
    await workspace.state.reconcile()
    assert.equal(workspace.state.selectedOperation.value, null)
    assert.equal(workspace.state.selected.value.draft_revision, 2)
    assert.equal(workspace.state.canRetryExact.value, false)
    assert.ok(paths.includes('GET /erp/purchases/44'), 'the exact current purchase is re-read before clearing the rejected operation')
    assert.match(workspace.state.mutationError.value, /versão|atualizado|revis/i)
    workspace.stop()
  })

  it('keeps an uncertain write pending when a lookup is not recorded even if the draft revision changed', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const source = { id: 46, erp_establishment_id: 3, erp_contact_id: 8, status: 'draft', currency: 'BRL', total_amount: '8.0000', draft_revision: 2, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [] }
    const workspace = createWorkspace(async (path, options) => {
      if (path === '/erp/purchases/46' && options?.method === 'PATCH') throw new Error('response connection lost')
      if (path.startsWith('/erp/operations/lookup?')) throw { statusCode: 404, data: { code: 'operation_not_recorded' } }
      if (path === '/erp/purchases/46') return { data: source }
      if (path.startsWith('/erp/purchases?')) return { data: [source], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      throw new Error(`Unexpected request ${path}`)
    })
    workspace.state.selected.value = { ...source, draft_revision: 1 }
    await workspace.state.saveDraft({ erp_establishment_id: 3, erp_contact_id: 8, draft_revision: 1, lines: [] }, 46)
    await workspace.state.reconcile()
    assert.ok(workspace.state.selectedOperation.value)
    assert.equal(workspace.state.canRetryExact.value, true)
    assert.ok(!workspace.state.selectedOperation.value.rejection)
    workspace.stop()
  })

  it('posts a partial supplier return, reloads source residuals, and preserves original payable identity', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const original = {
      id: 41, erp_establishment_id: 3, erp_contact_id: 8, status: 'posted', currency: 'BRL', total_amount: '30.0000', draft_revision: null, supplier_snapshot: { name: 'Fornecedor Alfa' }, source_access_key: null, needs_reconciliation: false,
      returnability: { needs_reconciliation: false, reconciliation_reason: null }, returnable_lines: [{ source_line_id: 51, quantity: '3.0000', returned_quantity: '0.0000', remaining_quantity: '3.0000', unit_conversion_factor: '1.00000000', line_total: '30.0000', physical_eligibility: 'validated_at_post' }],
      finance: { currency: 'BRL', original_obligation_id: 61, gross_original_due: '20.0000', original_principal: '30.0000', opposite_return_credit: '0.0000', open_return_credit: '0.0000', net_exposure: '-20.0000', needs_reconciliation: false, reconciliation_reason: null, automatic_setoff: false, automatic_refund: false, return_credits: [] },
      lines: [{ id: 51, erp_product_variant_id: 71, erp_unit_id: 2, description: 'Item', quantity: '3.0000', unit_cost: '10.0000', unit_conversion_factor: '1.00000000', line_total: '30.0000' }]
    }
    const refreshed = { ...original, returnable_lines: [{ ...original.returnable_lines[0], returned_quantity: '1.0000', remaining_quantity: '2.0000' }], finance: { ...original.finance, opposite_return_credit: '10.0000', open_return_credit: '10.0000', net_exposure: '-10.0000', return_credits: [{ id: 62, source_type: 'erp_purchase_return', source_id: 90, amount: '10.0000', open_amount: '10.0000', due_on: null }] } }
    const returnDraft = { id: 90, erp_purchase_id: 41, erp_establishment_id: 3, status: 'draft', currency: 'BRL', reason: 'Item excedente', subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', credit_id: null, financial_disposition: null, lines: [{ id: 91, source_line_id: 51, quantity: '1.0000', total_amount: '10.0000' }] }
    const postedReturn = { ...returnDraft, status: 'posted', credit_id: 62, financial_disposition: 'receivable' }
    let posted = false
    const calls: string[] = []
    const workspace = createWorkspace(async (path, options) => {
      calls.push(`${options?.method ?? 'GET'} ${path}`)
      if (path === '/erp/purchase-returns' && options?.method === 'POST') return { data: returnDraft }
      if (path === '/erp/purchase-returns/90') return { data: posted ? postedReturn : returnDraft }
      if (path === '/erp/purchase-returns/90/post') { posted = true; return { data: postedReturn, credit_id: 62, financial_disposition: 'receivable' } }
      if (path.startsWith('/erp/purchases?')) return { data: [posted ? refreshed : original], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path === '/erp/purchases/41') return { data: posted ? refreshed : original }
      if (path.startsWith('/erp/purchase-returns?')) return { data: [postedReturn], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      throw new Error(`Unexpected request ${path}`)
    })
    workspace.state.selected.value = original
    const created = await workspace.state.createReturn(original, { 51: '1.0000' }, 'Item excedente')
    assert.equal((created as { erp_purchase_id: number }).erp_purchase_id, 41)
    assert.equal(workspace.state.selectedReturn.value.status, 'draft')
    await workspace.state.postReturn(workspace.state.selectedReturn.value)
    assert.equal(workspace.state.selected.value.finance.original_obligation_id, 61)
    assert.equal(workspace.state.selected.value.finance.gross_original_due, '20.0000')
    assert.equal(workspace.state.selected.value.returnable_lines[0].remaining_quantity, '2.0000')
    assert.equal(workspace.state.selected.value.finance.return_credits[0].id, 62)
    assert.equal(workspace.state.selectedReturn.value.status, 'posted')
    assert.equal(original.finance.open_return_credit, '0.0000')
    assert.equal(original.returnable_lines[0].remaining_quantity, '3.0000')
    assert.ok(calls.includes('POST /erp/purchase-returns/90/post'))
    assert.ok(calls.includes('GET /erp/purchases/41'), 'posting the credit is followed by a fresh source-purchase GET')
    workspace.stop()
  })

  it('shows an authorized return detail without requiring unrelated purchase-show access', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const returnDetail = {
      id: 90, erp_purchase_id: 41, erp_establishment_id: 3, status: 'draft', currency: 'BRL', reason: 'Item excedente',
      subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000',
      credit_id: null, financial_disposition: null, lines: [{ id: 91, source_line_id: 51, quantity: '1.0000', total_amount: '10.0000' }]
    }
    const calls: string[] = []
    const workspace = createWorkspace(async (path) => {
      calls.push(path)
      if (path === '/erp/purchase-returns/90') return { data: returnDetail }
      throw new Error(`Unexpected request ${path}`)
    }, ['erp.purchase-returns.show'])

    const result = await workspace.state.inspectReturn(90, null)

    assert.equal(result.id, 90)
    assert.equal(workspace.state.selectedReturn.value.erp_purchase_id, 41)
    assert.equal(workspace.state.selected.value, null)
    assert.deepEqual(calls, ['/erp/purchase-returns/90'])
    workspace.stop()
  })

  it('fences out-of-order return-detail reads for the same selected source', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const first = deferred<{ data: { id: number, erp_purchase_id: number, status: string, currency: string, reason: string, total_amount: string, lines: unknown[] } }>()
    const second = deferred<{ data: { id: number, erp_purchase_id: number, status: string, currency: string, reason: string, total_amount: string, lines: unknown[] } }>()
    const workspace = createWorkspace(async path => path.endsWith('/90') ? first.promise : path.endsWith('/91') ? second.promise : Promise.reject(new Error(`Unexpected request ${path}`)))
    const source = { id: 41, status: 'posted', currency: 'BRL' }
    workspace.state.selected.value = source
    const oldRead = workspace.state.inspectReturn(90, 41)
    const currentRead = workspace.state.inspectReturn(91, 41)
    second.resolve({ data: { id: 91, erp_purchase_id: 41, status: 'draft', currency: 'BRL', reason: 'Atual', total_amount: '2.0000', lines: [] } })
    assert.equal((await currentRead)?.id, 91)
    first.resolve({ data: { id: 90, erp_purchase_id: 41, status: 'draft', currency: 'BRL', reason: 'Antiga', total_amount: '1.0000', lines: [] } })
    assert.equal(await oldRead, null)
    assert.equal(workspace.state.selectedReturn.value.id, 91)
    workspace.stop()
  })

  it('keeps a last-good reference family and XML token/mappings when refreshes fail transiently', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    let failProducts = false
    let failPreviewRead = false
    const workspace = createWorkspace(async (path, options) => {
      if (path.startsWith('/erp/contacts?')) return { data: [{ id: 8, name: failProducts ? 'Fornecedor atualizado' : 'Fornecedor Alfa', is_supplier: true, status: 'active' }], meta: { current_page: 1, last_page: 1 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 3, name: 'Matriz', status: 'active' }], meta: { current_page: 1, last_page: 1 } }
      if (path.startsWith('/erp/products?')) {
        if (failProducts) throw { statusCode: 503, data: { message: 'Temporarily unavailable' } }
        return { data: [{ id: 2, name: 'Produto Alfa', status: 'active', kind: 'goods', track_stock: true, erp_unit_id: 1, variants: [{ id: 71, name: 'Produto Alfa', sku: 'A-1', status: 'active' }] }], meta: { current_page: 1, last_page: 1 } }
      }
      if (path.startsWith('/erp/units?')) return { data: [{ id: 1, actual_name: 'Unidade', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: null, status: 'active' }], meta: { current_page: 1, last_page: 1 } }
      if (path === '/erp/purchases/xml-preview') return { data: preview('P') }
      if (path === '/erp/purchases/xml-preview/read' && failPreviewRead) throw { statusCode: 503, data: { message: 'Temporarily unavailable' } }
      if (path === '/erp/purchases/xml-preview/read') return { data: preview('P') }
      throw new Error(`Unexpected request ${path} ${String(options?.method ?? '')}`)
    })
    await workspace.state.loadReferences()
    const variants = JSON.parse(JSON.stringify(workspace.state.variants.value)) as unknown[]
    failProducts = true
    await workspace.state.loadReferences()
    assert.deepEqual(workspace.state.variants.value, variants)
    assert.equal(workspace.state.suppliers.value[0].name, 'Fornecedor atualizado')
    await workspace.state.inspectXml(xmlFile('<xml/>'), 3)
    workspace.state.previewState.value.erp_contact_id = 8
    workspace.state.previewState.value.mappings[0].erp_product_variant_id = 71
    const token = workspace.state.previewState.value.preview.token
    failPreviewRead = true
    await workspace.state.refreshXmlPreview()
    assert.equal(workspace.state.previewState.value.preview.token, token)
    assert.equal(workspace.state.previewState.value.mappings[0].erp_product_variant_id, 71)
    assert.match(workspace.state.mutationError.value, /Temporarily unavailable/)
    workspace.stop()
  })

  it('keeps XML token and explicit mappings after a field-level confirmation rejection', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const workspace = createWorkspace(async (path) => {
      if (path === '/erp/purchases/xml-preview') return { data: preview('A') }
      if (path === '/erp/purchases/xml-confirm') throw { statusCode: 422, data: { errors: { 'items.0.erp_product_variant_id': ['Selecione um produto ativo.'] } } }
      throw new Error(`Unexpected request ${path}`)
    })
    await workspace.state.inspectXml(xmlFile('<xml/>'), 3)
    workspace.state.previewState.value.erp_contact_id = 8
    workspace.state.previewState.value.mappings[0] = { line: 1, erp_product_variant_id: 71, erp_unit_id: 2, unit_conversion_factor: '2.00000000' }
    const token = workspace.state.previewState.value.preview.token
    assert.equal(await workspace.state.confirmXml(), null)
    assert.equal(workspace.state.previewState.value.preview.token, token)
    assert.deepEqual(workspace.state.previewState.value.mappings[0], { line: 1, erp_product_variant_id: 71, erp_unit_id: 2, unit_conversion_factor: '2.00000000' })
    assert.equal(workspace.state.fieldErrors.value['items.0.erp_product_variant_id'], 'Selecione um produto ativo.')
    assert.equal(workspace.state.selectedOperation.value, null)
    workspace.stop()
  })

  it('clears private data after 403/context-changed 409 when auth refresh is rejected, while retaining operation identity', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    for (const failure of [
      { statusCode: 403, data: { message: 'Forbidden' } },
      { statusCode: 409, data: { code: 'company_context_changed', message: 'Company changed' } }
    ]) {
      const workspace = createWorkspace(async () => { throw failure }, ['*'], async () => { throw new Error('identity refresh rejected') })
      workspace.state.selected.value = { id: 17, status: 'draft' }
      const result = await workspace.state.saveDraft({ erp_establishment_id: 3, erp_contact_id: 8, lines: [] }, 17)
      assert.equal(result, null)
      assert.equal(workspace.state.selected.value, null)
      assert.equal(workspace.state.allowed('erp.purchases.store'), false)
      assert.ok(workspace.state.selectedOperation.value, 'the original operation identity remains available for reconciliation')
      assert.match(workspace.state.mutationError.value, /autorização|sessão/i)
      workspace.stop()
      session.values.clear()
    }
  })

  it('fences a late write after selection or company changes without refreshing or restoring stale detail', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const post = deferred<unknown>()
    const paths: string[] = []
    const workspace = createWorkspace(async (path) => {
      paths.push(path)
      if (path === '/erp/purchases/17/post') return post.promise
      throw new Error(`Unexpected request ${path}`)
    })
    const first = { id: 17, status: 'draft', draft_revision: 1, total_amount: '9.0000', currency: 'BRL' }
    const second = { id: 22, status: 'draft', draft_revision: 3, total_amount: '4.0000', currency: 'BRL' }
    workspace.state.selected.value = first
    const resultPromise = workspace.state.postReceipt(first)
    await Promise.resolve()
    workspace.state.selected.value = second
    post.resolve({ data: { id: 17, status: 'posted' } })
    const result = await resultPromise
    assert.equal((result as { id: number }).id, 17)
    assert.equal(workspace.state.selected.value.id, 22)
    assert.equal(paths.filter(path => path.startsWith('/erp/purchases?') || path === '/erp/purchases/17').length, 0)
    workspace.stop()

    const session2 = installSessionStorage()
    const pending = deferred<unknown>()
    const companyWorkspace = createWorkspace(async (path) => {
      if (path === '/erp/purchases/17/post') return pending.promise
      throw new Error(`Unexpected request ${path}`)
    })
    companyWorkspace.state.selected.value = first
    const late = companyWorkspace.state.postReceipt(first)
    await Promise.resolve()
    companyWorkspace.currentCompany.value = { id: 8, permissions: ['*'], role: 'owner' }
    await Vue.nextTick()
    pending.resolve({ data: { id: 17, status: 'posted' } })
    assert.equal(await late, null)
    assert.equal(companyWorkspace.state.selected.value, null)
    companyWorkspace.stop()
    session2.restore()
  })

  it('retains a canceled draft across same-authority reopen, shows the save error, and clears it on authority change', async (t) => {
    const app = mountPurchaseWorkspace()
    t.after(() => app.app.unmount())
    ;(app.state.variants as Vue.Ref<unknown[]>).value = [{ id: 71, name: 'Item retido', sku: 'RET', status: 'active', product: { id: 7, name: 'Item retido', kind: 'goods', track_stock: true, erp_unit_id: 2 } }]
    ;(app.state.units as Vue.Ref<unknown[]>).value = [{ id: 2, actual_name: 'Unidade', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: null, status: 'active' }]
    await Vue.nextTick()
    const open = () => app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Nova compra')
    open()?.props?.onClick?.()
    await Vue.nextTick()
    let input = app.find(app.root, node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')
    assert.ok(input)
    ;(input.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'PO-RETAIN' } })
    await Vue.nextTick()
    app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Fechar formulário')?.props?.onClick?.()
    await Vue.nextTick()
    open()?.props?.onClick?.()
    await Vue.nextTick()
    input = app.find(app.root, node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')
    assert.equal(input?.props?.value, 'PO-RETAIN')
    app.find(app.root, node => node.tag === 'input' && node.props?.['aria-label'] === 'Estabelecimento da compra')?.props?.onSelect?.(3)
    app.find(app.root, node => node.tag === 'input' && node.props?.['aria-label'] === 'Fornecedor da compra')?.props?.onSelect?.(8)
    app.find(app.root, node => node.tag === 'input' && node.props?.['aria-label'] === 'Produto do item 1')?.props?.onSelect?.(71)
    await Vue.nextTick()
    app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Salvar rascunho')?.props?.onClick?.()
    app.find(app.root, node => node.tag === 'form')?.props?.onSubmit?.({ preventDefault() {} })
    await Vue.nextTick()
    assert.match(app.textContent(app.root as { text?: string, children?: unknown[] }), /A conexão caiu; confira a operação/)
    app.authorityGeneration.value++
    await Vue.nextTick()
    open()?.props?.onClick?.()
    await Vue.nextTick()
    input = app.find(app.root, node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')
    assert.equal(input?.props?.value, '')
  })

  it('keeps pending-operation recovery actions visible and usable inside the active create modal', async (t) => {
    const app = mountPurchaseWorkspace()
    t.after(() => app.app.unmount())
    await Vue.nextTick()
    let reconciles = 0
    app.state.reconcile = async () => { reconciles++ }
    ;(app.state.selectedOperation as Vue.Ref<unknown>).value = { key: 'pending-key' }
    app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Nova compra')?.props?.onClick?.()
    await Vue.nextTick()
    app.mutationError.value = 'A conexão caiu; confira a operação.'
    await Vue.nextTick()
    const recover = app.findInModal('Nova compra', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Consultar operação'))
    assert.ok(recover, 'the recovery control is rendered in the active overlay')
    assert.match(app.textContent(app.find(app.root, node => node.props?.['data-modal'] === 'Nova compra') as { text?: string, children?: unknown[] }), /A conexão caiu; confira a operação/)
    ;(recover.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    assert.equal(reconciles, 1)
  })

  it('keeps a new draft identity separate when editing another purchase between create attempts', async (t) => {
    const app = mountPurchaseWorkspace()
    t.after(() => app.app.unmount())
    const draft = { id: 44, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'EXISTING', status: 'draft', currency: 'BRL', total_amount: '1.0000', draft_revision: 2, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [{ id: 4, erp_product_variant_id: null, erp_unit_id: null, description: 'Existing item', quantity: '1.0000', unit_cost: '1.0000', unit_conversion_factor: '1.00000000', line_total: '1.0000' }] }
    ;(app.state.purchases as Vue.Ref<unknown[]>).value = [draft]
    app.state.inspect = async () => { (app.state.selected as Vue.Ref<unknown>).value = draft; return draft }
    await Vue.nextTick()
    const openCreate = () => app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Nova compra')
    openCreate()?.props?.onClick?.()
    await Vue.nextTick()
    const documentField = app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')
    ;(documentField?.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'NEW-RETAIN' } })
    await Vue.nextTick()
    app.findInModal('Nova compra', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Fechar'))?.props?.onClick?.()
    await Vue.nextTick()
    const openDetail = app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Abrir compra EXISTING')
    await (openDetail?.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    app.findInModal('Compra #44', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Editar rascunho'))?.props?.onClick?.()
    await Vue.nextTick()
    app.findInModal('Editar rascunho', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Fechar'))?.props?.onClick?.()
    await Vue.nextTick()
    openCreate()?.props?.onClick?.()
    await Vue.nextTick()
    assert.equal(app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')?.props?.value, 'NEW-RETAIN')
  })

  it('preserves native hash areas and loads the authorized paginated return area on entry', async (t) => {
    const app = mountPurchaseWorkspace('#returns')
    t.after(() => app.app.unmount())
    await Vue.nextTick()
    assert.equal(app.route.hash, '#returns')
    assert.ok(app.loads.includes('returns'))
    assert.equal(app.navItems.value[0]?.find(item => item.active)?.label, 'Devoluções')
    app.route.hash = '#xml-preview'
    await Vue.nextTick()
    assert.equal(app.navItems.value[0]?.find(item => item.active)?.label, 'Prévia XML')
    app.route.hash = '#purchases'
    await Vue.nextTick()
    assert.equal(app.navItems.value[0]?.find(item => item.active)?.label, 'Compras')
    assert.ok(app.loads.includes('purchases'))
    const moduleHub = readFileSync(new URL('../app/components/erp/ErpModuleHub.vue', import.meta.url), 'utf8')
    assert.match(moduleHub, /to="\/erp\/compras#xml-preview"/)
  })

  it('opens a new supplier return with the real form and emits the selected source quantity and reason', async (t) => {
    const app = mountPurchaseWorkspace('#purchases')
    t.after(() => app.app.unmount())
    const source = { id: 41, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'NF-41', status: 'posted', currency: 'BRL', total_amount: '30.0000', draft_revision: null, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [], returnability: { needs_reconciliation: false, reconciliation_reason: null }, returnable_lines: [{ source_line_id: 51, quantity: '3.0000', returned_quantity: '0.0000', remaining_quantity: '3.0000', unit_conversion_factor: '1.00000000', line_total: '30.0000', physical_eligibility: 'validated_at_post' }] }
    ;(app.state.purchases as Vue.Ref<unknown[]>).value = [source]
    app.state.inspect = async () => { (app.state.selected as Vue.Ref<unknown>).value = source; return source }
    const submitted: unknown[] = []
    app.state.createReturn = async (_source: unknown, quantities: unknown, reason: unknown) => { submitted.push({ quantities, reason }); return { id: 90, erp_purchase_id: 41 } }
    await Vue.nextTick()
    const open = app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Abrir compra NF-41')
    assert.ok(open)
    await (open.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    app.find(app.root, node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Preparar devolução'))?.props?.onClick?.()
    await Vue.nextTick()
    const quantity = app.find(app.root, node => node.tag === 'input' && node.props?.['aria-label'] === 'Quantidade a devolver da linha 51')
    const reason = app.find(app.root, node => node.tag === 'textarea' && node.props?.['aria-label'] === 'Motivo da devolução')
    assert.ok(quantity, 'the fresh-return modal exposes a quantity input')
    assert.ok(reason, 'the fresh-return modal exposes a reason input')
    ;(quantity.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: '1.0000' } })
    ;(reason.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Item excedente' } })
    await Vue.nextTick()
    app.findInModal('Devolução de fornecedor', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Preparar devolução'))?.props?.onClick?.()
    await Vue.nextTick()
    assert.deepEqual(submitted, [{ quantities: { 51: '1.0000' }, reason: 'Item excedente' }])
  })

  it('does not reopen a return-create overlay after the user closes it during the request', async (t) => {
    const app = mountPurchaseWorkspace('#purchases')
    t.after(() => app.app.unmount())
    const source = { id: 41, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'NF-41', status: 'posted', currency: 'BRL', total_amount: '30.0000', draft_revision: null, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [], returnability: { needs_reconciliation: false, reconciliation_reason: null }, returnable_lines: [{ source_line_id: 51, quantity: '3.0000', returned_quantity: '0.0000', remaining_quantity: '3.0000', unit_conversion_factor: '1.00000000', line_total: '30.0000', physical_eligibility: 'validated_at_post' }] }
    ;(app.state.purchases as Vue.Ref<unknown[]>).value = [source]
    app.state.inspect = async () => { (app.state.selected as Vue.Ref<unknown>).value = source; return source }
    const pending = deferred<unknown>()
    app.state.createReturn = () => pending.promise
    await Vue.nextTick()
    const open = app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Abrir compra NF-41')
    assert.ok(open)
    await (open.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    app.find(app.root, node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Preparar devolução'))?.props?.onClick?.()
    await Vue.nextTick()
    const quantity = app.findInModal('Devolução de fornecedor', node => node.tag === 'input' && node.props?.['aria-label'] === 'Quantidade a devolver da linha 51')
    const reason = app.findInModal('Devolução de fornecedor', node => node.tag === 'textarea' && node.props?.['aria-label'] === 'Motivo da devolução')
    ;(quantity?.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: '1.0000' } })
    ;(reason?.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Item excedente' } })
    await Vue.nextTick()
    const submit = app.findInModal('Devolução de fornecedor', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Preparar devolução'))
    submit?.props?.onClick?.()
    await Vue.nextTick()
    app.findInModal('Devolução de fornecedor', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }) === 'Fechar')?.props?.onClick?.()
    await Vue.nextTick()
    pending.resolve({ id: 90, erp_purchase_id: 41 })
    await pending.promise
    await Vue.nextTick()
    assert.equal(app.find(app.root, node => node.props?.['data-modal'] === 'Devolução de fornecedor'), null)
  })

  it('does not reopen a return-detail overlay after the user closes it during posting', async (t) => {
    const app = mountPurchaseWorkspace('#returns')
    t.after(() => app.app.unmount())
    const source = { id: 41, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'NF-41', status: 'posted', currency: 'BRL', total_amount: '30.0000', draft_revision: null, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [], returnability: { needs_reconciliation: false, reconciliation_reason: null }, returnable_lines: [{ source_line_id: 51, quantity: '3.0000', returned_quantity: '1.0000', remaining_quantity: '2.0000', unit_conversion_factor: '1.00000000', line_total: '30.0000', physical_eligibility: 'validated_at_post' }] }
    const returnDraft = { id: 90, erp_purchase_id: 41, erp_establishment_id: 3, status: 'draft', currency: 'BRL', reason: 'Item excedente', subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000', credit_id: null, financial_disposition: null, lines: [{ id: 91, source_line_id: 51, quantity: '1.0000', total_amount: '10.0000' }] }
    ;(app.state.returns as Vue.Ref<unknown[]>).value = [returnDraft]
    app.state.inspectReturn = async () => {
      ;(app.state.selected as Vue.Ref<unknown>).value = source
      ;(app.state.selectedReturn as Vue.Ref<unknown>).value = returnDraft
      return returnDraft
    }
    const pending = deferred<unknown>()
    app.state.postReturn = () => pending.promise
    await Vue.nextTick()
    const open = app.find(app.root, node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Abrir devolução'))
    assert.ok(open)
    await (open.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    const post = app.findInModal('Devolução de fornecedor', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Lançar devolução e crédito'))
    assert.ok(post)
    post.props?.onClick?.()
    await Vue.nextTick()
    app.findInModal('Devolução de fornecedor', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Fechar'))?.props?.onClick?.()
    await Vue.nextTick()
    pending.resolve({ id: 90, status: 'posted' })
    await pending.promise
    await Vue.nextTick()
    assert.equal(app.find(app.root, node => node.props?.['data-modal'] === 'Devolução de fornecedor'), null)
  })

  it('round-trips API snapshot discounts, taxes and references through mounted draft edit', async (t) => {
    const app = mountPurchaseWorkspace('#purchases')
    t.after(() => app.app.unmount())
    const source = { id: 77, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'MANUAL-77', status: 'draft', currency: 'BRL', total_amount: '21.0000', draft_revision: 4, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [{ id: 501, erp_product_variant_id: 71, erp_unit_id: 9, description: 'Manual item', quantity: '2.0000', unit_cost: '10.0000', unit_conversion_factor: '1.00000000', line_total: '21.0000', product_snapshot: { amounts: { quantity: '2.0000', unit_cost: '10.0000', discount_amount: '1.0000', tax_amount: '2.0000', line_total: '21.0000' }, tax_rate: { id: 17 }, operation_nature: { id: 28 } } }] }
    ;(app.state.purchases as Vue.Ref<unknown[]>).value = [source]
    ;(app.state.variants as Vue.Ref<unknown[]>).value = [{ id: 71, name: 'Item', sku: 'I-1', status: 'active', product: { id: 7, name: 'Item', kind: 'goods', track_stock: true, erp_unit_id: 2 } }]
    ;(app.state.units as Vue.Ref<unknown[]>).value = [{ id: 9, actual_name: 'Unidade', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: 2, status: 'active' }, { id: 2, actual_name: 'Base', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: null, status: 'active' }]
    app.state.inspect = async () => { (app.state.selected as Vue.Ref<unknown>).value = source; return source }
    const saved: Array<{ payload: Record<string, unknown>, id: number | null }> = []
    app.state.saveDraft = async (payload: Record<string, unknown>, id: number | null) => { saved.push({ payload, id }); return source }
    await Vue.nextTick()
    const open = app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Abrir compra MANUAL-77')
    assert.ok(open)
    await (open.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    app.findInModal('Compra #77', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Editar rascunho'))?.props?.onClick?.()
    await Vue.nextTick()
    assert.equal((app.state.selected as Vue.Ref<{ id: number } | null>).value?.id, 77, 'the edit action loads the current authorized draft')
    assert.ok(app.find(app.root, node => node.props?.['data-modal'] === 'Editar rascunho'), `edit modal did not remain open: ${JSON.stringify(app.renderErrors)}`)
    assert.equal((app.state.selected as Vue.Ref<{ id: number } | null>).value?.id, 77)
    const form = app.find(app.root, node => node.tag === 'form')
    assert.ok(form, `form absent in edit modal: ${app.textContent(app.find(app.root, node => node.props?.['data-modal'] === 'Editar rascunho') as { text?: string, children?: unknown[] })}`)
    const taxField = app.find(form, node => node.props?.['data-field'] === 'lines.0.tax_amount')
    assert.equal(app.find(taxField, node => node.tag === 'input')?.props?.value, '2.0000', 'the snapshot tax amount is hydrated for review')
    ;(form.props?.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await Vue.nextTick()
    assert.equal(saved.length, 1)
    assert.equal(saved[0]?.id, 77)
    const payload = saved[0]?.payload as { expected_total?: string, draft_revision?: number, lines: Array<Record<string, unknown>> }
    assert.equal(payload.draft_revision, 4)
    assert.equal(payload.expected_total, undefined, 'server-managed tax means the client cannot assert a total')
    assert.deepEqual(payload.lines[0], { id: 501, erp_product_variant_id: 71, erp_unit_id: 9, description: 'Manual item', quantity: '2.0000', unit_cost: '10.0000', unit_conversion_factor: '1.00000000', discount_amount: '1.0000', erp_tax_rate_id: 17, erp_operation_nature_id: 28 })
  })

  it('discards a stale edit form only after fresh revision proof and reopens with the current revision', async (t) => {
    const app = mountPurchaseWorkspace('#purchases')
    t.after(() => app.app.unmount())
    const makeDraft = (revision: number) => ({ id: 79, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'STALE-79', status: 'draft', currency: 'BRL', total_amount: '5.0000', draft_revision: revision, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [{ id: 503, erp_product_variant_id: null, erp_unit_id: null, description: 'Stable item', quantity: '1.0000', unit_cost: '5.0000', unit_conversion_factor: '1.00000000', line_total: '5.0000' }] })
    let latest = makeDraft(1)
    ;(app.state.purchases as Vue.Ref<unknown[]>).value = [latest]
    app.state.inspect = async () => { (app.state.selected as Vue.Ref<unknown>).value = latest; return latest }
    const saved: Array<Record<string, unknown>> = []
    app.state.saveDraft = async (payload: Record<string, unknown>) => { saved.push(payload); return latest }
    await Vue.nextTick()
    const open = app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Abrir compra STALE-79')
    await (open?.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    app.findInModal('Compra #79', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Editar rascunho'))?.props?.onClick?.()
    await Vue.nextTick()
    assert.ok(app.find(app.root, node => node.props?.['data-modal'] === 'Editar rascunho'))
    latest = makeDraft(2)
    ;(app.state.selected as Vue.Ref<unknown>).value = latest
    ;(app.state.staleRevisionRefreshed as Vue.Ref<unknown>).value = { id: 79, revision: 2 }
    await Vue.nextTick()
    assert.equal(app.find(app.root, node => node.props?.['data-modal'] === 'Editar rascunho'), null)
    assert.match(app.textContent(app.root as { text?: string, children?: unknown[] }), /Reabra o rascunho para conferir a versão atual/)
    app.findInModal('Compra #79', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Editar rascunho'))?.props?.onClick?.()
    await Vue.nextTick()
    const refreshedForm = app.find(app.root, node => node.tag === 'form')
    assert.ok(refreshedForm)
    ;(refreshedForm.props?.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await Vue.nextTick()
    assert.equal(saved[0]?.draft_revision, 2)
  })

  it('ignores a stale-revision notification for another purchase while the selected draft is being edited', async (t) => {
    const app = mountPurchaseWorkspace('#purchases')
    t.after(() => app.app.unmount())
    const selected = { id: 80, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'CURRENT-80', status: 'draft', currency: 'BRL', total_amount: '5.0000', draft_revision: 7, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [{ id: 504, erp_product_variant_id: null, erp_unit_id: null, description: 'Current item', quantity: '1.0000', unit_cost: '5.0000', unit_conversion_factor: '1.00000000', line_total: '5.0000' }] }
    ;(app.state.purchases as Vue.Ref<unknown[]>).value = [selected]
    app.state.inspect = async () => { (app.state.selected as Vue.Ref<unknown>).value = selected; return selected }
    await Vue.nextTick()
    const open = app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Abrir compra CURRENT-80')
    await (open?.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    app.findInModal('Compra #80', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Editar rascunho'))?.props?.onClick?.()
    await Vue.nextTick()
    assert.ok(app.find(app.root, node => node.props?.['data-modal'] === 'Editar rascunho'))
    ;(app.state.staleRevisionRefreshed as Vue.Ref<unknown>).value = { id: 79, revision: 3 }
    await Vue.nextTick()
    assert.ok(app.find(app.root, node => node.props?.['data-modal'] === 'Editar rascunho'), 'a background resolution for purchase 79 must not close the editor for purchase 80')
    assert.doesNotMatch(app.textContent(app.root as { text?: string, children?: unknown[] }), /A revisão desatualizada foi rejeitada/)
  })

  it('preserves the accepted XML total for the exact 3 x 0.3333 source basis on metadata-only edit', async (t) => {
    const app = mountPurchaseWorkspace('#purchases')
    t.after(() => app.app.unmount())
    const source = { id: 78, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'XML-78', status: 'draft', currency: 'BRL', total_amount: '1.0000', draft_revision: 2, supplier_snapshot: null, source_access_key: 'accepted-key', needs_reconciliation: false, lines: [{ id: 502, erp_product_variant_id: 71, erp_unit_id: 9, description: 'XML item', quantity: '3.0000', unit_cost: '0.3333', unit_conversion_factor: '1.00000000', line_total: '1.0000', product_snapshot: { amounts: { quantity: '3.0000', unit_cost: '0.3333', discount_amount: '0.0000', tax_amount: '0.0000', line_total: '1.0000' }, source_item: { line: 1 } } }] }
    ;(app.state.purchases as Vue.Ref<unknown[]>).value = [source]
    ;(app.state.variants as Vue.Ref<unknown[]>).value = [{ id: 71, name: 'XML item', sku: 'X-1', status: 'active', product: { id: 7, name: 'XML item', kind: 'goods', track_stock: true, erp_unit_id: 2 } }]
    ;(app.state.units as Vue.Ref<unknown[]>).value = [{ id: 9, actual_name: 'Unidade', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: 2, status: 'active' }, { id: 2, actual_name: 'Base', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: null, status: 'active' }]
    app.state.inspect = async () => { (app.state.selected as Vue.Ref<unknown>).value = source; return source }
    const saved: Record<string, unknown>[] = []
    app.state.saveDraft = async (payload: Record<string, unknown>) => { saved.push(payload); return source }
    await Vue.nextTick()
    const edit = app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Editar compra XML-78')
    assert.ok(edit)
    await (edit.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    const form = app.find(app.root, node => node.tag === 'form')
    assert.ok(form, app.textContent(app.root as { text?: string, children?: unknown[] }))
    const quantity = app.find(app.find(form, node => node.props?.['data-field'] === 'lines.0.quantity'), node => node.tag === 'input')
    ;(quantity?.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: '3' } })
    await Vue.nextTick()
    ;(form.props?.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await Vue.nextTick()
    assert.equal(saved[0]?.expected_total, '1.0000')
    assert.equal(((saved[0]?.lines as Array<Record<string, unknown>>)[0])?.id, 502)
  })

  it('mounts the real purchase form and emits exact decimal fields without coercion', async (t) => {
    const source = readFileSync(new URL('../app/components/erp/ErpPurchaseForm.vue', import.meta.url), 'utf8')
    const script = compileScript(parse(source).descriptor, { id: 'ErpPurchaseForm' })
    const component = compileModule(script.content, {
      'vue': Vue,
      '#shared/types/erpBusiness': {},
      '#shared/utils/erpPurchase': { addPurchaseMoney, calculatePurchaseLineTotal, convertPurchaseQuantity, formatPurchaseDecimal, purchaseUnitFactor, replacePurchaseTax, samePurchaseDecimal }
    }, { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed }).default as Vue.Component & { render?: () => null }
    component.render = () => null
    const draft = Vue.ref({
      erp_establishment_id: 3, erp_contact_id: 8, currency: 'BRL', document_no: ' NF-EXATO ', draft_revision: 6,
      lines: [{ id: 51, erp_product_variant_id: 71, erp_unit_id: 9, description: ' Item original ', quantity: '1.2345', unit_cost: '10.0001', unit_conversion_factor: '1.00000000', discount_amount: '0.0001', tax_amount: '0.0002' }]
    })
    const variant = { id: 71, name: 'Variante A', sku: 'A-1', status: 'active', product: { id: 2, name: 'Produto A', kind: 'goods', track_stock: true, erp_unit_id: 2 } }
    const units = [
      { id: 9, actual_name: 'Caixa', short_name: 'CX', allow_decimal: true, conversion_factor: '2.00000000', base_unit_id: 2, status: 'active' },
      { id: 2, actual_name: 'Unidade', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: null, status: 'active' }
    ]
    const emitted: unknown[] = []
    const publicForm = Vue.ref<Record<string, () => void> | null>(null)
    const renderer = Vue.createRenderer({
      patchProp() {}, insert(node: { parent?: unknown }, parent: { children: unknown[] }, anchor?: unknown) {
        node.parent = parent
        const index = anchor ? parent.children.indexOf(anchor) : -1
        if (index < 0) parent.children.push(node)
        else parent.children.splice(index, 0, node)
      },
      remove() {}, createElement(tag) { return { tag, children: [] as unknown[], props: {} as Record<string, unknown>, parent: null as unknown } },
      createText(text) { return { text, parent: null as unknown } }, createComment(text) { return { text, parent: null as unknown } }, setText(node: { text: string }, text) { node.text = text },
      setElementText(node: { children: unknown[], text?: string }, text) { node.children = []; node.text = text }, parentNode(node: { parent?: unknown }) { return node.parent ?? null }, nextSibling() { return null }
    })
    const root = { children: [] as unknown[] }
    const app = renderer.createApp({ setup() {
      const form = Vue.ref(null)
      Vue.onMounted(() => { publicForm.value = form.value as Record<string, () => void> })
      return () => Vue.h(component, {
        'ref': form,
        'modelValue': draft.value,
        'onUpdate:modelValue': (value: typeof draft.value) => { draft.value = value },
        'suppliers': [{ id: 8, name: 'Fornecedor Alfa', trade_name: 'Alfa', tax_id: '11222333000181', is_supplier: true, status: 'active' }],
        'establishments': [{ id: 3, name: 'Matriz', status: 'active' }], 'variants': [variant], units,
        'onSubmit': (payload: unknown) => emitted.push(payload)
      })
    } })
    app.mount(root)
    t.after(() => app.unmount())
    await Vue.nextTick()
    publicForm.value?.submit()
    assert.equal(emitted.length, 1)
    assert.deepEqual(emitted[0], {
      erp_establishment_id: 3, erp_contact_id: 8, currency: 'BRL', document_no: 'NF-EXATO', draft_revision: 6,
      expected_total: '12.3452',
      lines: [{ id: 51, erp_product_variant_id: 71, erp_unit_id: 9, description: 'Item original', quantity: '1.2345', unit_cost: '10.0001', unit_conversion_factor: '2.00000000', discount_amount: '0.0001', tax_amount: '0.0002' }]
    })
  })
})
