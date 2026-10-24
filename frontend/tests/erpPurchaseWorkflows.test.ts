/* eslint-disable @stylistic/max-statements-per-line -- Compact callbacks keep the local Vue renderer readable beside the workflow assertions. */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as operationRecovery from '../shared/utils/erpOperationRecovery.ts'
import * as productVariantReferences from '../app/utils/erpProductVariantReferences.ts'
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
    if (path === '~/components/erp/erpPresentation') return loadAppComposable('components/erp/erpPresentation.ts')
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
    '#shared/utils/erpPurchase': { addPurchaseMoney, calculatePurchaseLineTotal, convertPurchaseQuantity, formatPurchaseDecimal, purchaseUnitFactor, replacePurchaseTax, samePurchaseDecimal, buildPurchaseReturnPayload },
    '~/utils/erpProductVariantReferences': productVariantReferences
  }, { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed })
  return exports.default as Vue.Component
}

function realPurchaseRecovery() {
  const source = readFileSync(new URL('../app/components/erp/ErpPurchaseOperationRecovery.vue', import.meta.url), 'utf8')
  const script = compileScript(parse(source).descriptor, { id: 'ErpPurchaseOperationRecovery', inlineTemplate: true })
  return compileModule(script.content, { vue: Vue }, { ref: Vue.ref, computed: Vue.computed }).default as Vue.Component
}

function mountXmlPreview(total: Record<string, string>) {
  const source = readFileSync(new URL('../app/components/erp/ErpPurchaseXmlPreview.vue', import.meta.url), 'utf8')
  const script = compileScript(parse(source).descriptor, { id: 'ErpPurchaseXmlPreview', inlineTemplate: true })
  const component = compileModule(script.content, {
    'vue': Vue, '#shared/types/erpBusiness': {}, '#shared/utils/erpPurchase': { formatPurchaseDecimal, purchaseUnitFactor },
    '~/utils/erpProductVariantReferences': productVariantReferences
  }, { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed }).default as Vue.Component
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
  const previewState = { token: 'token', preview: { state: 'pending', chave: 'key', expires_at: 'later', emit: { name: 'Fornecedor', cpf_cnpj: '1' }, dest: { name: 'Empresa', cpf_cnpj: '2' }, det: [], total, errors: [], duplicate: false, duplicate_purchase_id: null }, mappings: [], erp_contact_id: null }
  const app = renderer.createApp({ render: () => Vue.h(component, {
    'modelValue': previewState, 'onUpdate:modelValue': () => {}, 'establishmentId': 3, 'onUpdate:establishmentId': () => {},
    'establishments': [], 'suppliers': [], 'variants': [], 'units': []
  }) })
  for (const name of ['UFormField', 'UInputMenu', 'UInput', 'UAlert', 'UButton', 'ErpCatalogReferenceSelect']) app.component(name, { props: ['label', 'title'], setup: (props, context) => () => Vue.h('div', {}, [props.label ?? props.title ?? '', ...(context.slots.default?.() ?? [])]) })
  app.mount(root)
  const textContent = (node: Node): string => `${node.text ?? ''}${node.children.map(textContent).join('')}`
  return { text: textContent(root), dispose: () => app.unmount() }
}

function mountPurchaseWorkspace(initialHash = '#purchases', workspaceState?: Record<string, unknown>) {
  const route = Vue.reactive({ path: '/erp/compras', query: {}, hash: initialHash })
  const replacements: Array<Record<string, unknown>> = []
  const modalLeaves: Array<() => void> = []
  const authorityGeneration = Vue.ref(0)
  const mutationError = Vue.ref('')
  const draftSaveResults: unknown[] = []
  const loads: string[] = []
  const navItems = Vue.ref([] as Array<Array<Record<string, unknown>>>)
  const state: Record<string, unknown> = {
    purchases: Vue.ref([]), returns: Vue.ref([]), meta: Vue.ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 }), returnMeta: Vue.ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 }),
    suppliers: Vue.ref([]), establishments: Vue.ref([]), variants: Vue.ref([]), units: Vue.ref([]), selected: Vue.ref(null), selectedReturn: Vue.ref(null), previewState: Vue.ref(null),
    listLoading: Vue.ref(false), returnsLoading: Vue.ref(false), referencesLoading: Vue.ref(false), mutating: Vue.ref(false), loadingDetail: Vue.ref(false), listError: Vue.ref(''), returnListError: Vue.ref(''), referenceError: Vue.ref(''), mutationError, detailError: Vue.ref(''), fieldErrors: Vue.ref({}),
    selectedOperation: Vue.ref(null), needsReconciliation: Vue.ref(false), canRetryExact: Vue.ref(false), staleRevisionRefreshed: Vue.ref(null), resolvedOperation: Vue.ref(null), authorityGeneration, filters: Vue.reactive({ status: '', search: '', page: 1, per_page: 15 }), returnFilters: Vue.reactive({ status: '', page: 1, per_page: 15 }),
    allowed: () => true, isPurchaseConfirmedPosted: () => false,
    load: async () => { loads.push('purchases') }, loadReturns: async () => { loads.push('returns') }, loadReferences: async () => {}, inspect: async () => null, closeDetail: () => {}, saveDraft: async () => { const result = draftSaveResults.shift() ?? null; if (!result) mutationError.value = 'A conexão caiu; confira a operação.'; return result },
    postReceipt: async () => null, inspectXml: async () => null, refreshXmlPreview: async () => null, cancelXmlPreview: () => {}, confirmXml: async () => null, createReturn: async () => null, inspectReturn: async () => null, postReturn: async () => null, reconcile: async () => {}, retryExact: async () => null, clearOperationNotice: () => { mutationError.value = '' }
  }
  Object.assign(state, workspaceState)
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
    ErpListFooter: compileVueComponent('components/erp/ErpListFooter.vue'),
    UPagination: { setup: (_props, context) => () => Vue.h('nav', { ...context.attrs, 'data-pagination': true }) },
    ErpCatalogPageShell: { setup: (_props, context) => () => Vue.h('main', {}, [context.slots.actions?.(), context.slots.toolbar?.(), context.slots.default?.()]) },
    UButton: { props: ['label'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, onClick: context.attrs.onClick }, context.slots.default?.() ?? String(props.label ?? '')) },
    UModal: { props: ['open', 'title'], emits: ['after:leave', 'update:open'], setup: (props, context) => { Vue.watch(() => props.open, (open, previous) => { if (previous && !open) modalLeaves.push(() => context.emit('after:leave')) }); return () => props.open ? Vue.h('section', { 'data-modal': props.title }, context.slots.body?.()) : Vue.createCommentVNode() } },
    UNavigationMenu: { setup: (_props, context) => () => { navItems.value = context.attrs.items as Array<Array<Record<string, unknown>>>; return Vue.h('nav') } },
    UTable: { props: ['data', 'columns', 'empty'], setup: (props, context) => () => Vue.h('div', {}, (props.data as Array<Record<string, unknown>> ?? []).length === 0
      ? String(props.empty ?? '')
      : (props.data as Array<Record<string, unknown>>).flatMap((original) => {
          const row = { original }
          return [context.slots['actions-cell']?.({ row }) ?? null, context.slots['amount-cell']?.({ row }) ?? null]
        })) },
    UAlert: { props: ['title', 'description', 'color'], setup: props => () => Vue.h('aside', { 'data-alert': props.title, 'data-color': props.color }, `${props.title ?? ''}${props.description ?? ''}`) },
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
  components.ErpCatalogReferenceSelect = Vue.defineComponent({
    props: ['modelValue', 'path', 'query', 'name', 'label'],
    emits: ['selection-record'],
    setup: (props, context) => {
      const recordFor = (id: number): Record<string, unknown> => {
        const variants = (state.variants as Vue.Ref<Array<Record<string, unknown>>>).value
        return variants.find(variant => variant.id === id) ?? {
          id, name: 'Item selecionado', status: 'active',
          product: { id: 7, name: 'Item selecionado', kind: 'goods', track_stock: true, erp_unit_id: 2, status: 'active' }
        }
      }
      Vue.watch(() => props.modelValue, (id) => {
        if (typeof id === 'number')
          context.emit('selection-record', { id, record: recordFor(id), source: 'hydration' })
      }, { immediate: true, flush: 'sync' })
      return () => Vue.h('input', {
        'aria-label': props.label,
        'data-erp-field-name': props.name,
        'data-erp-path': props.path,
        'data-erp-usage': (props.query as { usage?: string } | undefined)?.usage,
        'value': props.modelValue ?? '',
        'onSelect': (value: unknown) => {
          const id = Number(value)
          context.emit('selection-record', { id, record: recordFor(id), source: 'selection' })
        }
      })
    }
  })
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
  components.UAlert ??= { props: ['title', 'color'], setup: props => () => Vue.h('aside', { 'data-alert': props.title, 'data-color': props.color }, String(props.title ?? '')) }
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
  function findAll(node: unknown, predicate: (node: { tag?: string, props?: Record<string, unknown> }) => boolean): Array<{ tag?: string, props?: Record<string, unknown> }> {
    if (typeof node !== 'object' || node === null) return []
    const record = node as { tag?: string, props?: Record<string, unknown>, children?: unknown[] }
    return [...(predicate(record) ? [record] : []), ...(record.children ?? []).flatMap(child => findAll(child, predicate))]
  }
  return { app, route, replacements, modalLeaves, authorityGeneration, mutationError, draftSaveResults, loads, navItems, root, state, find, findAll, findInModal, textContent, renderErrors }
}

function selectPurchaseVariant(app: ReturnType<typeof mountPurchaseWorkspace>, modalTitle: string, id: number) {
  const selector = app.findInModal(modalTitle, node => node.props?.['data-erp-field-name'] === 'lines.0.erp_product_variant_id')
  assert.ok(selector)
  assert.equal(selector.props?.['data-erp-path'], '/erp/product-variants')
  assert.equal(selector.props?.['data-erp-usage'], 'purchase')
  const select = selector.props?.['onSelect']
  assert.equal(typeof select, 'function')
  ;(select as (value: number) => void)(id)
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

describe('resource-owned purchase list read errors', () => {
  for (const resource of ['purchases', 'returns'] as const) {
    it(`keeps the ${resource} failure visible through an unrelated pending and successful read`, async (t) => {
      const unrelated = deferred<unknown>()
      const recovery = deferred<unknown>()
      const failure = resource === 'returns' ? 'Devoluções indisponíveis.' : 'Compras indisponíveis.'
      const ownPath = resource === 'returns' ? '/erp/purchase-returns?' : '/erp/purchases?'
      const paths: string[] = []
      let ownReads = 0
      const workspace = createWorkspace(async (path) => {
        paths.push(path)
        if (!path.startsWith(ownPath)) return unrelated.promise
        if (++ownReads === 1) throw { statusCode: 503, data: { message: failure } }
        return recovery.promise
      })
      t.after(() => workspace.stop())
      const app = mountPurchaseWorkspace(`#${resource}`, workspace.state)
      t.after(() => app.app.unmount())
      await Vue.nextTick()
      await Vue.nextTick()
      assert.deepEqual(app.renderErrors, [])
      assert.ok(app.textContent(app.root).includes(failure), 'the actual initial failure is visible')
      assert.equal(app.textContent(app.root).includes('Nenhuma'), false, 'initial failure is not a successful empty list')
      assert.equal(workspace.state[resource === 'returns' ? 'returnMeta' : 'meta'].value, null)
      const siblingRead = resource === 'returns' ? workspace.state.load() : workspace.state.loadReturns()
      await Vue.nextTick()
      assert.ok(app.textContent(app.root).includes(failure), 'an unrelated pending read must not hide the failure')
      unrelated.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } })
      await siblingRead
      await Vue.nextTick()
      assert.ok(app.textContent(app.root).includes(failure), 'an unrelated success is not resource recovery')
      assert.equal(ownReads, 1, 'the sibling retry does not force another read of the failed resource')
      assert.equal(paths.length, 2)
      const retry = resource === 'returns' ? workspace.state.loadReturns() : workspace.state.load()
      await Vue.nextTick()
      assert.ok(app.textContent(app.root).includes(failure), 'a pending same-resource retry is not yet recovery')
      recovery.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } })
      await retry
      await Vue.nextTick()
      assert.equal(app.textContent(app.root).includes(failure), false, 'only confirmed same-resource success clears the failure')
      assert.deepEqual(app.renderErrors, [])
    })

    it(`retains the last confirmed ${resource} snapshot and marks it stale after refresh failure`, async (t) => {
      const row = { id: 41, erp_purchase_id: 31, status: 'posted', currency: 'BRL', total_amount: '123.4567', document_no: 'NF-41' }
      const page = { current_page: 2, last_page: 3, per_page: 15, total: 31 }
      const ownPath = resource === 'returns' ? '/erp/purchase-returns?' : '/erp/purchases?'
      let reads = 0
      const workspace = createWorkspace(async (path) => {
        if (!path.startsWith(ownPath)) return { data: [], meta: { ...page, current_page: 1, last_page: 1, total: 0 } }
        if (++reads === 1) return { data: [row], meta: page }
        throw { statusCode: 503, data: { message: 'Atualização indisponível.' } }
      })
      t.after(() => workspace.stop())
      const read = resource === 'returns' ? workspace.state.loadReturns : workspace.state.load
      await read()
      const app = mountPurchaseWorkspace(`#${resource}`, workspace.state)
      t.after(() => app.app.unmount())
      await Vue.nextTick()
      await Vue.nextTick()
      await (resource === 'returns' ? workspace.state.load() : workspace.state.loadReturns())
      await Vue.nextTick()
      assert.deepEqual(workspace.state[resource].value, [row])
      assert.deepEqual(workspace.state[resource === 'returns' ? 'returnMeta' : 'meta'].value, page)
      const text = app.textContent(app.root)
      assert.ok(text.includes('BRL 123,4567'), 'the actual SFC retains the last confirmed row')
      assert.equal(text.split('Atualização indisponível.').length - 1, 1, 'the relevant error is rendered once')
      assert.match(text, /última lista confirmada/i)
      assert.deepEqual(app.renderErrors, [])
    })

    for (const lateResult of ['success', 'error'] as const) {
      it(`fences superseded ${resource} ${lateResult} without replacing the current error or snapshot`, async (t) => {
        const old = deferred<unknown>()
        let reads = 0
        const workspace = createWorkspace(async () => {
          if (++reads === 1) return old.promise
          if (lateResult === 'success') throw { statusCode: 503, data: { message: 'Falha atual.' } }
          return { data: [{ id: 42 }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
        })
        t.after(() => workspace.stop())
        const read = resource === 'returns' ? workspace.state.loadReturns : workspace.state.load
        const oldRead = read()
        await read()
        const error = resource === 'returns' ? workspace.state.returnListError : workspace.state.listError
        assert.equal(error.value, lateResult === 'success' ? 'Falha atual.' : '')
        if (lateResult === 'success') old.resolve({ data: [{ id: 1 }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
        else old.reject({ statusCode: 503, data: { message: 'Falha antiga.' } })
        await oldRead
        assert.equal(error.value, lateResult === 'success' ? 'Falha atual.' : '')
        assert.deepEqual(workspace.state[resource].value, lateResult === 'success' ? [] : [{ id: 42 }])
        assert.equal(workspace.state[resource === 'returns' ? 'returnsLoading' : 'listLoading'].value, false)
      })
    }
  }

  for (const invalidation of ['company', 'actor', 'session'] as const) {
    for (const lateResult of ['success', 'error'] as const) {
      it(`clears both lists and errors on ${invalidation} invalidation and fences late ${lateResult}`, async (t) => {
        const latePurchases = deferred<unknown>()
        const lateReturns = deferred<unknown>()
        const counts = { purchases: 0, returns: 0 }
        const workspace = createWorkspace(async (path) => {
          if (path === '/erp/purchases/99') throw { statusCode: 401 }
          if (!path.startsWith('/erp/purchases?') && !path.startsWith('/erp/purchase-returns?')) return { data: [] }
          const resource = path.startsWith('/erp/purchases?') ? 'purchases' : 'returns'
          const count = ++counts[resource]
          if (count === 1) return { data: [{ id: resource === 'purchases' ? 41 : 90 }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
          if (count === 2) throw { statusCode: 503, data: { message: `${resource} indisponível` } }
          if (count > 3) return deferred<unknown>().promise
          return resource === 'purchases' ? latePurchases.promise : lateReturns.promise
        })
        t.after(() => workspace.stop())
        await workspace.state.load()
        await workspace.state.loadReturns()
        await workspace.state.load()
        await workspace.state.loadReturns()
        assert.ok(workspace.state.listError.value)
        assert.ok(workspace.state.returnListError.value)
        const oldPurchaseRead = workspace.state.load()
        const oldReturnRead = workspace.state.loadReturns()
        if (invalidation === 'company') workspace.currentCompany.value = { id: 8, permissions: [], role: 'owner' }
        else if (invalidation === 'actor') workspace.user.value = { id: 92 }
        else await workspace.state.inspect(99)
        for (const field of ['purchases', 'returns']) assert.deepEqual(workspace.state[field].value, [])
        for (const field of ['meta', 'returnMeta']) assert.equal(workspace.state[field].value, null)
        for (const field of ['listError', 'returnListError']) assert.equal(workspace.state[field].value, '')
        if (lateResult === 'success') {
          latePurchases.resolve({ data: [{ id: 1 }] })
          lateReturns.resolve({ data: [{ id: 2 }] })
        } else {
          latePurchases.reject({ statusCode: 503, data: { message: 'Falha antiga de compras' } })
          lateReturns.reject({ statusCode: 503, data: { message: 'Falha antiga de devoluções' } })
        }
        await Promise.all([oldPurchaseRead, oldReturnRead])
        for (const field of ['purchases', 'returns']) assert.deepEqual(workspace.state[field].value, [])
        for (const field of ['meta', 'returnMeta']) assert.equal(workspace.state[field].value, null)
        for (const field of ['listError', 'returnListError']) assert.equal(workspace.state[field].value, '')
      })
    }
  }

  it('routes one relevant alert to each list area while preserving XML operation failures', async (t) => {
    const app = mountPurchaseWorkspace()
    t.after(() => app.app.unmount())
    ;(app.state.listError as Vue.Ref<string>).value = 'Falha de compras.'
    ;(app.state.returnListError as Vue.Ref<string>).value = 'Falha de devoluções.'
    for (const [hash, relevant, irrelevant] of [
      ['#purchases', 'Falha de compras.', 'Falha de devoluções.'],
      ['#returns', 'Falha de devoluções.', 'Falha de compras.'],
      ['#purchases', 'Falha de compras.', 'Falha de devoluções.']
    ]) {
      app.route.hash = hash!
      await Vue.nextTick()
      const text = app.textContent(app.root)
      assert.equal(text.split(relevant!).length - 1, 1)
      assert.equal(text.includes(irrelevant!), false)
    }
    app.route.hash = '#xml-preview'
    app.mutationError.value = 'Falha ao atualizar prévia XML.'
    await Vue.nextTick()
    const text = app.textContent(app.root)
    assert.equal(text.includes('Falha de compras.'), false)
    assert.equal(text.includes('Falha de devoluções.'), false)
    assert.ok(text.includes('Falha ao atualizar prévia XML.'))
    assert.deepEqual(app.renderErrors, [])
  })
})

describe('purchase decimal and source helpers', () => {
  it('keeps a pending recovery notice in the attention color before any message arrives', async (t) => {
    const mutationError = Vue.ref('')
    const selectedOperation = Vue.ref({ key: 'pending', operation: 'erp.purchases.store', method: 'POST', path: '/erp/purchases', payload: {} })
    const app = mountPurchaseWorkspace('#purchases', { mutationError, selectedOperation })
    t.after(() => app.app.unmount())
    await Vue.nextTick()
    app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Nova compra')?.props?.['onClick']?.()
    await Vue.nextTick()
    const notice = app.find(app.root, node => node.props?.['aria-label'] === 'Estado da operação de compra')
    assert.ok(notice)
    assert.match(String(notice.props?.class), /border-warning\/40/)
  })

  it('shows Portuguese XML total labels while preserving values and exposing unknown fields', (t) => {
    const mounted = mountXmlPreview({ currency: 'BRL', vProd: '10.0000', vNF: '12.0000', futureTotal: '12.0000' })
    t.after(mounted.dispose)
    assert.match(mounted.text, /MoedaBRL/)
    assert.match(mounted.text, /Produtos10\.0000/)
    assert.match(mounted.text, /Total do documento12\.0000/)
    assert.match(mounted.text, /Campo do XML não reconhecido \(futureTotal\)12\.0000/)
  })

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

  it('resolves a first-attempt stale rejection when the authorized current GET proves the purchase was posted', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const posted = { id: 48, erp_establishment_id: 3, erp_contact_id: 8, status: 'posted', currency: 'BRL', total_amount: '8.0000', draft_revision: null, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [] }
    const writes: string[] = []
    const workspace = createWorkspace(async (path, _options) => {
      if (path === '/erp/purchases/48/post') {
        writes.push(path)
        throw { statusCode: 409, data: { message: 'Rascunho alterado.' } }
      }
      if (path.startsWith('/erp/operations/lookup?')) throw { statusCode: 404, data: { code: 'operation_not_recorded' } }
      if (path === '/erp/purchases/48') return { data: posted }
      throw new Error(`Unexpected request ${path}`)
    })
    workspace.state.selected.value = { ...posted, status: 'draft', draft_revision: 1 }
    await workspace.state.postReceipt(workspace.state.selected.value)
    assert.ok(workspace.state.selectedOperation.value)
    await workspace.state.reconcile()
    assert.equal(workspace.state.selectedOperation.value, null)
    assert.equal(workspace.state.selected.value.status, 'posted')
    assert.equal(workspace.state.staleRevisionRefreshed.value.status, 'posted')
    assert.equal(workspace.state.canRetryExact.value, false)
    assert.equal(await workspace.state.postReceipt(workspace.state.selected.value), null)
    assert.deepEqual(writes, ['/erp/purchases/48/post'], 'a posted resource cannot be submitted again as a receipt')
    workspace.stop()
  })

  it('keeps a completed receipt from being posted again when its confirming detail GET fails', async (t) => {
    const session = installSessionStorage()
    t.after(() => session.restore())
    const draft = { id: 49, erp_establishment_id: 3, erp_contact_id: 8, status: 'draft', currency: 'BRL', total_amount: '9.0000', draft_revision: 3, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [] }
    let posts = 0
    const workspace = createWorkspace(async (path, _options) => {
      if (path === '/erp/purchases/49/post') {
        posts++
        throw new Error('response lost after commit')
      }
      if (path.startsWith('/erp/operations/lookup?')) return { data: { state: 'completed', result: { id: 49, status: 'posted' } } }
      if (path === '/erp/purchases?page=1&per_page=15') return { data: [draft], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
      if (path === '/erp/purchases/49') throw { statusCode: 503, data: { message: 'Leitura indisponível' } }
      throw new Error(`Unexpected request ${path}`)
    })
    workspace.state.selected.value = draft
    assert.equal(await workspace.state.postReceipt(draft), null)
    await workspace.state.reconcile()
    assert.equal(workspace.state.selectedOperation.value, null)
    assert.equal(workspace.state.selected.value.status, 'draft', 'the failed GET does not fabricate a posted purchase')
    assert.match(workspace.state.detailError.value, /leitura indisponível/i)
    assert.equal(workspace.state.isPurchaseConfirmedPosted(49), true)
    assert.equal(await workspace.state.postReceipt(workspace.state.selected.value), null)
    assert.equal(posts, 1, 'the known completed receipt cannot be posted again from stale draft detail')
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

  for (const operation of ['create', 'post', 'lookup', 'retryExact'] as const) {
    for (const phase of ['operation', 'list', 'source', 'return', 'newer-return', 'normal'] as const) {
      it(`preserves selection ownership through ${operation} return continuation at ${phase}`, async (t) => {
        const session = installSessionStorage()
        t.after(() => session.restore())
        const original = {
          id: 41, erp_establishment_id: 3, erp_contact_id: 8, status: 'posted', currency: 'BRL', total_amount: '30.0000', draft_revision: null,
          supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [],
          returnability: { needs_reconciliation: false, reconciliation_reason: null },
          returnable_lines: [{ source_line_id: 51, quantity: '3.0000', returned_quantity: '0.0000', remaining_quantity: '3.0000', unit_conversion_factor: '1.00000000', line_total: '30.0000', physical_eligibility: 'validated_at_post' }]
        }
        const refreshed = { ...original, returnable_lines: [{ ...original.returnable_lines[0], returned_quantity: '1.0000', remaining_quantity: '2.0000' }] }
        const other = { ...original, id: 42 }
        const returnDraft = {
          id: 90, erp_purchase_id: 41, erp_establishment_id: 3, status: 'draft', currency: 'BRL', reason: 'Item excedente',
          subtotal_amount: '10.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '10.0000',
          credit_id: null, financial_disposition: null, lines: [{ id: 91, source_line_id: 51, quantity: '1.0000', total_amount: '10.0000' }]
        }
        const outcome = operation === 'post' ? { ...returnDraft, status: 'posted' } : returnDraft
        const otherReturn = { ...returnDraft, id: 92, erp_purchase_id: 42 }
        const reached = deferred<boolean>()
        const release = deferred<boolean>()
        const newerPurchase = deferred<{ data: typeof other }>()
        const newerReturn = deferred<{ data: typeof otherReturn }>()
        const paths: string[] = []
        const writes: Array<{ path: string, options?: Record<string, unknown> }> = []
        let recovering = operation === 'lookup' || operation === 'retryExact'
        const gate = async (stage: string) => {
          if (phase === stage || (phase === 'newer-return' && stage === 'operation')) {
            reached.resolve(true)
            await release.promise
          }
        }
        const workspace = createWorkspace(async (path, options) => {
          paths.push(path)
          if (path === '/erp/purchases/42') return newerPurchase.promise
          if (path === '/erp/purchase-returns/92') return newerReturn.promise
          if (path === '/erp/purchase-returns' || path === '/erp/purchase-returns/90/post') {
            writes.push({ path, options })
            if (recovering) throw new Error('response lost after commit')
            await gate('operation')
            return { data: outcome }
          }
          if (path.startsWith('/erp/operations/lookup?')) {
            if (recovering) throw { statusCode: 404, data: { code: 'operation_not_recorded' } }
            await gate('operation')
            return { data: { state: 'completed', result: outcome } }
          }
          if (path.startsWith('/erp/purchases?')) { await gate('list'); return { data: [refreshed] } }
          if (path === '/erp/purchases/41') { await gate('source'); return { data: refreshed } }
          if (path === '/erp/purchase-returns/90') { await gate('return'); return { data: outcome } }
          if (path.startsWith('/erp/purchase-returns?')) return { data: [outcome] }
          throw new Error(`Unexpected request ${path}`)
        })
        t.after(() => workspace.stop())
        workspace.state.selected.value = original
        if (operation === 'post') workspace.state.selectedReturn.value = returnDraft
        let pending: operationRecovery.ErpPendingOperation | null = null
        if (recovering) {
          assert.equal(await workspace.state.createReturn(original, { 51: '1.0000' }, 'Item excedente'), null)
          pending = workspace.state.selectedOperation.value
          assert.ok(pending)
          if (operation === 'retryExact') await workspace.state.reconcile()
          recovering = false
        }
        const completion = operation === 'create'
          ? workspace.state.createReturn(original, { 51: '1.0000' }, 'Item excedente')
          : operation === 'post'
            ? workspace.state.postReturn(returnDraft)
            : operation === 'lookup' ? workspace.state.reconcile() : workspace.state.retryExact()
        let newerSelection: Promise<{ id: number } | null> | null = null
        if (phase !== 'normal') {
          await reached.promise
          newerSelection = phase === 'newer-return' ? workspace.state.inspectReturn(92, 41) : workspace.state.inspect(42)
          release.resolve(true)
        }
        const result = await completion
        assert.equal(workspace.state.selectedOperation.value, null, 'the old operation remains honestly confirmed despite a later selection')
        assert.equal(workspace.state.needsReconciliation.value, false)
        if (operation !== 'lookup') assert.equal(result.id, 90)
        if (pending) {
          assert.equal(workspace.state.resolvedOperation.value.key, pending.key)
          assert.equal(workspace.state.resolvedOperation.value.outcome.id, 90)
          if (operation === 'retryExact') {
            assert.equal(writes[1]!.path, pending.path)
            assert.deepEqual(writes[1]!.options?.body, pending.payload)
            assert.equal((writes[1]!.options?.headers as Record<string, string>)['Idempotency-Key'], pending.key)
          }
        }
        if (phase === 'normal') {
          assert.equal(workspace.state.selected.value.id, 41)
          assert.equal(workspace.state.selected.value.returnable_lines[0].remaining_quantity, '2.0000')
          assert.equal(workspace.state.selectedReturn.value.id, 90, 'the operation keeps ownership through its own authorized source refresh')
          assert.equal(workspace.state.selectedReturn.value.status, outcome.status)
          assert.ok(paths.includes('/erp/purchases/41'))
          assert.ok(paths.includes('/erp/purchase-returns/90'))
        } else {
          assert.equal(workspace.state.selectedReturn.value?.id ?? null, operation === 'post' ? 90 : null, 'an obsolete continuation cannot install return detail')
          if (phase !== 'return') assert.ok(!paths.includes('/erp/purchase-returns/90'), 'an obsolete operation cannot start an automatic return GET')
          if (phase !== 'newer-return') assert.equal(workspace.state.loadingDetail.value, true, 'the newer manual read retains loading ownership')
          newerPurchase.resolve({ data: other })
          newerReturn.resolve({ data: otherReturn })
          assert.equal((await newerSelection)?.id, phase === 'newer-return' ? 92 : 42)
          assert.equal(workspace.state.selected.value.id, 42)
          if (phase === 'newer-return') assert.equal(workspace.state.selectedReturn.value.id, 92)
          assert.equal(workspace.state.loadingDetail.value, false)
        }
      })
    }
  }

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

  it('does not let an obsolete return intent commit its nested source GET for same or different purchase', async () => {
    for (const currentSourceId of [41, 42]) {
      const firstReturn = deferred<{ data: { id: number, erp_purchase_id: number, status: string, currency: string, reason: string, total_amount: string, lines: unknown[] } }>()
      const secondReturn = deferred<{ data: { id: number, erp_purchase_id: number, status: string, currency: string, reason: string, total_amount: string, lines: unknown[] } }>()
      const sourceReads: Array<{ resolve(value: { data: { id: number, status: string, currency: string, lines: unknown[] } }): void }> = []
      const workspace = createWorkspace(async (path) => {
        if (path === '/erp/purchase-returns/90') return firstReturn.promise
        if (path === '/erp/purchase-returns/91') return secondReturn.promise
        if (path.startsWith('/erp/purchases/')) {
          const read = deferred<{ data: { id: number, status: string, currency: string, lines: unknown[] } }>()
          sourceReads.push(read)
          return read.promise
        }
        throw new Error(`Unexpected request ${path}`)
      })
      const oldIntent = workspace.state.inspectReturn(90, null)
      firstReturn.resolve({ data: { id: 90, erp_purchase_id: 41, status: 'draft', currency: 'BRL', reason: 'Antiga', total_amount: '1.0000', lines: [] } })
      for (let turn = 0; turn < 8 && sourceReads.length < 1; turn++) await Promise.resolve()
      assert.equal(sourceReads.length, 1, 'the old return has entered its nested source GET')
      const latestIntent = workspace.state.inspectReturn(91, null)
      sourceReads[0]!.resolve({ data: { id: 41, status: 'posted', currency: 'BRL', lines: [] } })
      assert.equal(await oldIntent, null)
      assert.equal(workspace.state.selected.value, null, 'an obsolete source GET cannot alter shared purchase selection')
      secondReturn.resolve({ data: { id: 91, erp_purchase_id: currentSourceId, status: 'draft', currency: 'BRL', reason: 'Atual', total_amount: '2.0000', lines: [] } })
      for (let turn = 0; turn < 8 && sourceReads.length < 2; turn++) await Promise.resolve()
      assert.equal(sourceReads.length, 2, 'the latest return proceeds to its own authorized source GET')
      sourceReads[1]!.resolve({ data: { id: currentSourceId, status: 'posted', currency: 'BRL', lines: [] } })
      assert.equal((await latestIntent)?.id, 91)
      assert.equal(workspace.state.selected.value?.id, currentSourceId)
      assert.equal(workspace.state.selectedReturn.value.id, 91)
      workspace.stop()
    }
  })

  it('lets a current return source GET supersede an older purchase detail request', async () => {
    const oldPurchase = deferred<{ data: { id: number, status: string, currency: string, lines: unknown[] } }>()
    const returnDetail = deferred<{ data: { id: number, erp_purchase_id: number, status: string, currency: string, reason: string, total_amount: string, lines: unknown[] } }>()
    const sourcePurchase = deferred<{ data: { id: number, status: string, currency: string, lines: unknown[] } }>()
    let sourceRequested = false
    const workspace = createWorkspace(async (path) => {
      if (path === '/erp/purchases/41') return oldPurchase.promise
      if (path === '/erp/purchase-returns/90') return returnDetail.promise
      if (path === '/erp/purchases/42') { sourceRequested = true; return sourcePurchase.promise }
      throw new Error(`Unexpected request ${path}`)
    })
    const oldRead = workspace.state.inspect(41)
    const returnRead = workspace.state.inspectReturn(90, null)
    returnDetail.resolve({ data: { id: 90, erp_purchase_id: 42, status: 'draft', currency: 'BRL', reason: 'Atual', total_amount: '2.0000', lines: [] } })
    for (let turn = 0; turn < 8 && !sourceRequested; turn++) await Promise.resolve()
    assert.equal(sourceRequested, true, 'the return has started its nested source GET')
    assert.equal(workspace.state.loadingDetail.value, true)
    sourcePurchase.resolve({ data: { id: 42, status: 'posted', currency: 'BRL', lines: [] } })
    assert.equal((await returnRead)?.id, 90)
    assert.equal(workspace.state.selected.value?.id, 42)
    assert.equal(workspace.state.selectedReturn.value?.id, 90)
    assert.equal(workspace.state.loadingDetail.value, false, 'the owning source load clears its loading state')
    oldPurchase.resolve({ data: { id: 41, status: 'posted', currency: 'BRL', lines: [] } })
    assert.equal(await oldRead, null, 'the superseded manual purchase read cannot commit')
    assert.equal(workspace.state.selected.value?.id, 42)
    assert.equal(workspace.state.selectedReturn.value?.id, 90)
    assert.equal(workspace.state.loadingDetail.value, false, 'the invalidated request cannot leave loading stuck')
    workspace.stop()
  })

  it('lets a newer manual detail intent supersede a pending initial return GET', async () => {
    const returnDetail = deferred<{ data: { id: number, erp_purchase_id: number, status: string, currency: string, reason: string, total_amount: string, lines: unknown[] } }>()
    const manualPurchase = deferred<{ data: { id: number, status: string, currency: string, lines: unknown[] } }>()
    let sourceRequested = false
    const workspace = createWorkspace(async (path) => {
      if (path === '/erp/purchase-returns/90') return returnDetail.promise
      if (path === '/erp/purchases/41') return manualPurchase.promise
      if (path === '/erp/purchases/42') { sourceRequested = true; return { data: { id: 42, status: 'posted', currency: 'BRL', lines: [] } } }
      throw new Error(`Unexpected request ${path}`)
    })
    const returnRead = workspace.state.inspectReturn(90, null)
    const manualRead = workspace.state.inspect(41)
    returnDetail.resolve({ data: { id: 90, erp_purchase_id: 42, status: 'draft', currency: 'BRL', reason: 'Retorno', total_amount: '2.0000', lines: [] } })
    assert.equal(await returnRead, null, 'the return response cannot supersede a newer manual detail intent')
    assert.equal(sourceRequested, false, 'the obsolete return does not start its nested source request')
    assert.equal(workspace.state.loadingDetail.value, true, 'the current manual request retains loading ownership')
    manualPurchase.resolve({ data: { id: 41, status: 'posted', currency: 'BRL', lines: [] } })
    assert.equal((await manualRead)?.id, 41)
    assert.equal(workspace.state.selected.value?.id, 41)
    assert.equal(workspace.state.selectedReturn.value, null)
    assert.equal(workspace.state.loadingDetail.value, false)
    workspace.stop()
  })

  it('lets a newer manual purchase selection supersede an in-flight return source GET', async () => {
    const returnDetail = deferred<{ data: { id: number, erp_purchase_id: number, status: string, currency: string, reason: string, total_amount: string, lines: unknown[] } }>()
    const returnSource = deferred<{ data: { id: number, status: string, currency: string, lines: unknown[] } }>()
    const manualPurchase = deferred<{ data: { id: number, status: string, currency: string, lines: unknown[] } }>()
    let sourceRequested = false
    const workspace = createWorkspace(async (path) => {
      if (path === '/erp/purchase-returns/90') return returnDetail.promise
      if (path === '/erp/purchases/42') { sourceRequested = true; return returnSource.promise }
      if (path === '/erp/purchases/43') return manualPurchase.promise
      throw new Error(`Unexpected request ${path}`)
    })
    const returnRead = workspace.state.inspectReturn(90, null)
    returnDetail.resolve({ data: { id: 90, erp_purchase_id: 42, status: 'draft', currency: 'BRL', reason: 'Retorno', total_amount: '2.0000', lines: [] } })
    for (let turn = 0; turn < 8 && !sourceRequested; turn++) await Promise.resolve()
    assert.equal(sourceRequested, true, 'the return has started its nested source GET')
    assert.equal(workspace.state.loadingDetail.value, true)
    const manualRead = workspace.state.inspect(43)
    returnSource.resolve({ data: { id: 42, status: 'posted', currency: 'BRL', lines: [] } })
    assert.equal(await returnRead, null, 'the return cannot overwrite the later manual selection')
    assert.equal(workspace.state.selected.value, null)
    assert.equal(workspace.state.loadingDetail.value, true, 'the stale nested read cannot clear the newer manual load')
    manualPurchase.resolve({ data: { id: 43, status: 'posted', currency: 'BRL', lines: [] } })
    assert.equal((await manualRead)?.id, 43)
    assert.equal(workspace.state.selected.value?.id, 43)
    assert.equal(workspace.state.selectedReturn.value, null)
    assert.equal(workspace.state.loadingDetail.value, false, 'the latest manual load clears its loading state')
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
    selectPurchaseVariant(app, 'Nova compra', 71)
    await Vue.nextTick()
    const descriptionField = app.find(app.root, node => node.props?.['data-field'] === 'lines.0.description')
    assert.equal(app.find(descriptionField, node => node.tag === 'input')?.props?.value, 'Item retido', 'the actual user selection initializes the untouched description')
    const unitField = app.find(app.root, node => node.props?.['aria-label'] === 'Unidade do item 1')
    assert.equal(unitField?.props?.value, 2, 'the actual user selection initializes the base unit')
    app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Salvar rascunho')?.props?.onClick?.()
    app.find(app.root, node => node.tag === 'form')?.props?.onSubmit?.({ preventDefault() {} })
    await Vue.nextTick()
    assert.match(app.textContent(app.root as { text?: string, children?: unknown[] }), /A conexão caiu; confira a operação/)
    const operationAlerts = app.findAll(app.root, node => node.props?.['data-alert'] === 'A conexão caiu; confira a operação.')
    assert.equal(operationAlerts.length, 1, 'the recovery modal presents one contextual notice')
    assert.equal(operationAlerts[0]?.props?.['data-color'], 'error')
    app.mutationError.value = 'Operação confirmada após consulta.'
    await Vue.nextTick()
    const confirmedAlerts = app.findAll(app.root, node => node.props?.['data-alert'] === 'Operação confirmada após consulta.')
    assert.equal(confirmedAlerts.length, 1)
    assert.equal(confirmedAlerts[0]?.props?.['data-color'], 'success')
    app.authorityGeneration.value++
    await Vue.nextTick()
    open()?.props?.onClick?.()
    await Vue.nextTick()
    input = app.find(app.root, node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')
    assert.equal(input?.props?.value, '')
  })

  it('closes and resets the original create draft after the mounted recovery action confirms it', async (t) => {
    for (const action of ['reconcile', 'retryExact'] as const) {
      const app = mountPurchaseWorkspace()
      t.after(() => app.app.unmount())
      await Vue.nextTick()
      ;(app.state.establishments as Vue.Ref<unknown[]>).value = [{ id: 3, name: 'Unidade teste', status: 'active' }]
      ;(app.state.suppliers as Vue.Ref<unknown[]>).value = [{ id: 8, name: 'Fornecedor teste', trade_name: 'Fornecedor teste', status: 'active' }]
      ;(app.state.variants as Vue.Ref<unknown[]>).value = [{ id: 71, name: 'Produto teste', sku: 'TEST', status: 'active', product: { id: 7, name: 'Produto teste', kind: 'goods', track_stock: true, erp_unit_id: 2 } }]
      ;(app.state.units as Vue.Ref<unknown[]>).value = [{ id: 2, actual_name: 'Unidade', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: null, status: 'active' }]
      await Vue.nextTick()
      let writes = 0
      app.state.saveDraft = async (payload: Record<string, unknown>) => {
        writes++
        ;(app.state.selectedOperation as Vue.Ref<unknown>).value = { key: 'pending-key', operation: 'erp.purchases.store', payload }
        app.mutationError.value = 'A conexão caiu; confira a operação.'
        return null
      }
      app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Nova compra')?.props?.onClick?.()
      await Vue.nextTick()
      const field = () => app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')
      ;(field()?.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'CREATE-RECOVERED' } })
      app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Estabelecimento da compra')?.props?.onSelect?.(3)
      app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Fornecedor da compra')?.props?.onSelect?.(8)
      selectPurchaseVariant(app, 'Nova compra', 71)
      await Vue.nextTick()
      app.findInModal('Nova compra', node => node.tag === 'form')?.props?.onSubmit?.({ preventDefault() {} })
      await Vue.nextTick()
      assert.ok((app.state.selectedOperation as Vue.Ref<unknown>).value, 'the simulated write leaves its exact operation pending')
      if (action === 'retryExact') (app.state.canRetryExact as Vue.Ref<boolean>).value = true
      await Vue.nextTick()
      const buttonLabel = action === 'reconcile' ? 'Consultar operação' : 'Repetir conteúdo original'
      const recover = () => app.findInModal('Nova compra', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes(buttonLabel))
      assert.ok(recover(), 'the recovery control is rendered in the active overlay')
      app.findInModal('Nova compra', node => node.tag === 'button' && node.props?.['aria-label'] === 'Fechar formulário')?.props?.onClick?.()
      await Vue.nextTick()
      app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Nova compra')?.props?.onClick?.()
      await Vue.nextTick()
      const retainedRecovery = recover()
      assert.ok(retainedRecovery, 'an unchanged retained draft remains associated with the pending operation after reopen')
      app.state[action] = async () => {
        ;(app.state.selectedOperation as Vue.Ref<unknown>).value = null
        ;(app.state.resolvedOperation as Vue.Ref<unknown>).value = { key: 'pending-key' }
        return { id: 61 }
      }
      await (retainedRecovery.props?.onClick as () => Promise<void>)()
      await Vue.nextTick()
      assert.equal(app.findInModal('Nova compra', () => true), null, 'the confirmed create overlay closes')
      assert.equal(writes, 1, 'recovery does not submit a second create POST')
      app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Nova compra')?.props?.onClick?.()
      await Vue.nextTick()
      assert.equal(app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')?.props?.value, '', 'the resolved draft cannot be resubmitted with a new key')
    }
  })

  it('preserves a newer create draft when recovery completes an older closed submission', async (t) => {
    const app = mountPurchaseWorkspace()
    t.after(() => app.app.unmount())
    await Vue.nextTick()
    ;(app.state.establishments as Vue.Ref<unknown[]>).value = [{ id: 3, name: 'Unidade teste', status: 'active' }]
    ;(app.state.suppliers as Vue.Ref<unknown[]>).value = [{ id: 8, name: 'Fornecedor teste', trade_name: 'Fornecedor teste', status: 'active' }]
    ;(app.state.variants as Vue.Ref<unknown[]>).value = [{ id: 71, name: 'Produto teste', sku: 'TEST', status: 'active', product: { id: 7, name: 'Produto teste', kind: 'goods', track_stock: true, erp_unit_id: 2 } }]
    ;(app.state.units as Vue.Ref<unknown[]>).value = [{ id: 2, actual_name: 'Unidade', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: null, status: 'active' }]
    await Vue.nextTick()
    let writes = 0
    let attempts = 0
    app.state.saveDraft = async (payload: Record<string, unknown>) => {
      attempts++
      if ((app.state.selectedOperation as Vue.Ref<unknown>).value) return null
      writes++
      ;(app.state.selectedOperation as Vue.Ref<unknown>).value = { key: 'older-key', operation: 'erp.purchases.store', payload }
      app.mutationError.value = 'A conexão caiu; confira a operação.'
      return null
    }
    const open = () => app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Nova compra')
    open()?.props?.onClick?.()
    await Vue.nextTick()
    const doc = () => app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')
    ;(doc()?.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'OLDER-A' } })
    app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Estabelecimento da compra')?.props?.onSelect?.(3)
    app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Fornecedor da compra')?.props?.onSelect?.(8)
    selectPurchaseVariant(app, 'Nova compra', 71)
    await Vue.nextTick()
    app.findInModal('Nova compra', node => node.tag === 'form')?.props?.onSubmit?.({ preventDefault() {} })
    await Vue.nextTick()
    assert.ok((app.state.selectedOperation as Vue.Ref<unknown>).value, 'the simulated write leaves its exact operation pending')
    app.findInModal('Nova compra', node => node.tag === 'button' && node.props?.['aria-label'] === 'Fechar formulário')?.props?.onClick?.()
    await Vue.nextTick()
    open()?.props?.onClick?.()
    await Vue.nextTick()
    ;(doc()?.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'NEWER-B' } })
    await Vue.nextTick()
    app.findInModal('Nova compra', node => node.tag === 'form')?.props?.onSubmit?.({ preventDefault() {} })
    await Vue.nextTick()
    assert.equal(attempts, 2, 'the newer submit is attempted while the old operation is pending')
    assert.equal(writes, 1, 'the pending-operation gate starts no second POST')
    assert.equal((app.state.selectedOperation as Vue.Ref<{ key: string }>).value?.key, 'older-key', 'the blocked B submit does not adopt A operation ownership')
    app.state.reconcile = async () => {
      ;(app.state.selectedOperation as Vue.Ref<unknown>).value = null
      ;(app.state.resolvedOperation as Vue.Ref<unknown>).value = { key: 'older-key' }
    }
    const recover = app.findInModal('Nova compra', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Consultar operação'))
    assert.ok(recover)
    await (recover.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    assert.equal(writes, 1)
    assert.equal(doc()?.props?.value, 'NEWER-B')
    assert.ok(app.find(app.root, node => node.props?.['data-modal'] === 'Nova compra'), 'recovery does not close the newer draft overlay')
  })

  it('does not assign a restored pending operation to a create draft submitted after reload', async (t) => {
    const app = mountPurchaseWorkspace()
    t.after(() => app.app.unmount())
    ;(app.state.establishments as Vue.Ref<unknown[]>).value = [{ id: 3, name: 'Unidade teste', status: 'active' }]
    ;(app.state.suppliers as Vue.Ref<unknown[]>).value = [{ id: 8, name: 'Fornecedor teste', trade_name: 'Fornecedor teste', status: 'active' }]
    ;(app.state.variants as Vue.Ref<unknown[]>).value = [{ id: 71, name: 'Produto teste', sku: 'TEST', status: 'active', product: { id: 7, name: 'Produto teste', kind: 'goods', track_stock: true, erp_unit_id: 2 } }]
    ;(app.state.units as Vue.Ref<unknown[]>).value = [{ id: 2, actual_name: 'Unidade', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: null, status: 'active' }]
    ;(app.state.selectedOperation as Vue.Ref<unknown>).value = { key: 'restored-key', operation: 'erp.purchases.store', payload: { document_no: 'OLD' } }
    app.state.saveDraft = async () => null
    await Vue.nextTick()
    app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Nova compra')?.props?.onClick?.()
    await Vue.nextTick()
    const doc = () => app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Número do documento')
    ;(doc()?.props?.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'NEW-AFTER-RELOAD' } })
    app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Estabelecimento da compra')?.props?.onSelect?.(3)
    app.findInModal('Nova compra', node => node.tag === 'input' && node.props?.['aria-label'] === 'Fornecedor da compra')?.props?.onSelect?.(8)
    selectPurchaseVariant(app, 'Nova compra', 71)
    await Vue.nextTick()
    app.findInModal('Nova compra', node => node.tag === 'form')?.props?.onSubmit?.({ preventDefault() {} })
    await Vue.nextTick()
    app.state.reconcile = async () => {
      ;(app.state.selectedOperation as Vue.Ref<unknown>).value = null
      ;(app.state.resolvedOperation as Vue.Ref<unknown>).value = { key: 'restored-key' }
    }
    const recover = app.findInModal('Nova compra', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Consultar operação'))
    assert.ok(recover)
    await (recover.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    assert.equal(doc()?.props?.value, 'NEW-AFTER-RELOAD')
    assert.ok(app.find(app.root, node => node.props?.['data-modal'] === 'Nova compra'))
  })

  it('closes only the matching edited purchase after mounted recovery confirms its update', async (t) => {
    const app = mountPurchaseWorkspace()
    t.after(() => app.app.unmount())
    const draft = { id: 52, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'EDIT-52', status: 'draft', currency: 'BRL', total_amount: '5.0000', draft_revision: 4, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [{ id: 9, erp_product_variant_id: 71, erp_unit_id: 2, description: 'Item', quantity: '1.0000', unit_cost: '5.0000', unit_conversion_factor: '1.00000000', discount_amount: '0.0000', tax_amount: '0.0000', line_total: '5.0000' }] }
    ;(app.state.purchases as Vue.Ref<unknown[]>).value = [draft]
    ;(app.state.variants as Vue.Ref<unknown[]>).value = [{ id: 71, name: 'Produto teste', sku: 'TEST', status: 'active', product: { id: 7, name: 'Produto teste', kind: 'goods', track_stock: true, erp_unit_id: 2 } }]
    ;(app.state.units as Vue.Ref<unknown[]>).value = [{ id: 2, actual_name: 'Unidade', short_name: 'UN', allow_decimal: true, conversion_factor: '1.00000000', base_unit_id: null, status: 'active' }]
    app.state.inspect = async () => { (app.state.selected as Vue.Ref<unknown>).value = draft; return draft }
    await Vue.nextTick()
    let writes = 0
    app.state.saveDraft = async (payload: Record<string, unknown>, _id: number) => {
      writes++
      ;(app.state.selectedOperation as Vue.Ref<unknown>).value = { key: 'edit-key', operation: 'erp.purchases.update', payload }
      app.mutationError.value = 'A conexão caiu; confira a operação.'
      return null
    }
    await (app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Editar compra EDIT-52')?.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    app.findInModal('Editar rascunho', node => node.tag === 'form')?.props?.onSubmit?.({ preventDefault() {} })
    await Vue.nextTick()
    assert.equal(writes, 1)
    app.state.reconcile = async () => {
      ;(app.state.selectedOperation as Vue.Ref<unknown>).value = null
      ;(app.state.resolvedOperation as Vue.Ref<unknown>).value = { key: 'edit-key' }
    }
    const recover = app.findInModal('Editar rascunho', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Consultar operação'))
    assert.ok(recover)
    await (recover.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    assert.equal(app.findInModal('Editar rascunho', () => true), null)
    assert.equal(writes, 1, 'recovered edit does not issue another PATCH')
  })

  it('closes the receipt overlay after mounted recovery confirms the original post', async (t) => {
    const app = mountPurchaseWorkspace()
    t.after(() => app.app.unmount())
    const draft = { id: 53, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'POST-53', status: 'draft', currency: 'BRL', total_amount: '5.0000', draft_revision: 2, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [{ id: 10, erp_product_variant_id: 71, erp_unit_id: 2, description: 'Item', quantity: '1.0000', unit_cost: '5.0000', unit_conversion_factor: '1.00000000', line_total: '5.0000' }] }
    ;(app.state.purchases as Vue.Ref<unknown[]>).value = [draft]
    app.state.inspect = async () => { (app.state.selected as Vue.Ref<unknown>).value = draft; return draft }
    await Vue.nextTick()
    let posts = 0
    app.state.postReceipt = async () => {
      posts++
      ;(app.state.selectedOperation as Vue.Ref<unknown>).value = { key: 'post-key', operation: 'erp.purchases.post', payload: { draft_revision: 2, expected_total: '5.0000' } }
      app.mutationError.value = 'A conexão caiu; confira a operação.'
      return null
    }
    await (app.find(app.root, node => node.tag === 'button' && node.props?.['aria-label'] === 'Abrir compra POST-53')?.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    app.findInModal('Compra #53', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Lançar recebimento'))?.props?.onClick?.()
    await Vue.nextTick()
    app.findInModal('Lançar recebimento', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Confirmar recebimento'))?.props?.onClick?.()
    await Vue.nextTick()
    assert.equal(posts, 1)
    app.state.reconcile = async () => {
      ;(app.state.selectedOperation as Vue.Ref<unknown>).value = null
      ;(app.state.resolvedOperation as Vue.Ref<unknown>).value = { key: 'post-key' }
      app.state.isPurchaseConfirmedPosted = (id: number) => id === 53
      ;(app.state.detailError as Vue.Ref<string>).value = 'A leitura atual falhou.'
    }
    const recover = app.findInModal('Lançar recebimento', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Consultar operação'))
    assert.ok(recover)
    await (recover.props?.onClick as () => Promise<void>)()
    await Vue.nextTick()
    assert.equal(app.findInModal('Lançar recebimento', () => true), null)
    assert.equal((app.state.selected as Vue.Ref<Record<string, unknown>>).value?.status, 'draft', 'a missing current GET does not fabricate the posted detail')
    assert.match(app.textContent(app.findInModal('Compra #53', () => true) as { text?: string, children?: unknown[] }), /Lançamento confirmado|leitura atual falhou/i)
    assert.equal(app.findInModal('Compra #53', node => node.tag === 'button' && app.textContent(node as { text?: string, children?: unknown[] }).includes('Lançar recebimento')), null)
    assert.equal(posts, 1, 'recovery never posts the receipt twice')
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
    for (const leave of app.modalLeaves.splice(0)) leave()
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
    for (const leave of app.modalLeaves.splice(0)) leave()
    await Vue.nextTick()
    assert.ok(app.find(app.root, node => node.props?.['data-modal'] === 'Editar rascunho'))
    latest = makeDraft(2)
    ;(app.state.selected as Vue.Ref<unknown>).value = latest
    ;(app.state.staleRevisionRefreshed as Vue.Ref<unknown>).value = { id: 79, revision: 2 }
    await Vue.nextTick()
    assert.equal(app.find(app.root, node => node.props?.['data-modal'] === 'Editar rascunho'), null)
    assert.match(app.textContent(app.root as { text?: string, children?: unknown[] }), /Reabra o rascunho para conferir a versão atual/)
    await (app.find(app.root, node => node.props?.['aria-label'] === 'Editar compra STALE-79')?.props?.onClick as () => Promise<void>)()
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
    for (const leave of app.modalLeaves.splice(0)) leave()
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
      '#shared/utils/erpPurchase': { addPurchaseMoney, calculatePurchaseLineTotal, convertPurchaseQuantity, formatPurchaseDecimal, purchaseUnitFactor, replacePurchaseTax, samePurchaseDecimal },
      '~/utils/erpProductVariantReferences': productVariantReferences
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

describe('native acceptance purchase dialog handoff', () => {
  it('leaves detail before editing while retaining source and rejects a stale leave callback', async () => {
    const row = { id: 77, erp_establishment_id: 3, erp_contact_id: 8, document_no: 'NATIVE-77', status: 'draft', currency: 'BRL', total_amount: '5.0000', draft_revision: 4, supplier_snapshot: null, source_access_key: null, needs_reconciliation: false, lines: [{ id: 1, erp_product_variant_id: null, erp_unit_id: null, description: 'Compra nativa', quantity: '1.0000', unit_cost: '5.0000', unit_conversion_factor: '1.00000000', discount_amount: '0.0000', tax_amount: '0.0000' }] }
    const selected = Vue.ref<typeof row | null>(null)
    const saved: Array<Record<string, unknown>> = []
    let acceptSave = false
    const mounted = mountPurchaseWorkspace('#purchases', { saveDraft: async (payload: Record<string, unknown>) => { saved.push(payload); return acceptSave ? row : null }, purchases: Vue.ref([row]), selected, inspect: async () => { selected.value = row; return row }, closeDetail: () => { selected.value = null } })
    const click = async (predicate: (node: { tag?: string, props?: Record<string, unknown> }) => boolean) => {
      const node = mounted.find(mounted.root, predicate)
      assert.ok(node)
      await (node.props?.onClick as () => unknown)()
      await Vue.nextTick()
    }
    try {
      await click(node => node.props?.['aria-label'] === 'Abrir compra NATIVE-77')
      await click(node => node.tag === 'button' && mounted.textContent(node).includes('Editar rascunho'))
      assert.equal(mounted.findAll(mounted.root, node => Boolean(node.props?.['data-modal'])).length, 0, 'editor waits for the detail leave transition')
      assert.equal(selected.value?.id, 77, 'handoff must not clear the authoritative selected purchase')
      assert.equal(mounted.modalLeaves.length, 1)
      mounted.modalLeaves.shift()!()
      await Vue.nextTick()
      assert.equal(mounted.findAll(mounted.root, node => Boolean(node.props?.['data-modal'])).length, 1)
      assert.ok(mounted.findInModal('Editar rascunho', node => node.tag === 'form'))
      const documentField = () => mounted.findInModal('Editar rascunho', node => node.props?.['aria-label'] === 'Número do documento')!
      ;(documentField().props?.onInput as (event: unknown) => void)({ target: { value: 'EDIT-RETAINED' } })
      await Vue.nextTick()
      ;(mounted.findInModal('Editar rascunho', node => node.tag === 'form')!.props?.onSubmit as (event: unknown) => void)({ preventDefault() {} })
      await Vue.nextTick()
      assert.equal(saved[0]?.document_no, 'EDIT-RETAINED')
      assert.equal(saved[0]?.draft_revision, 4)
      assert.ok(mounted.findInModal('Editar rascunho', node => node.tag === 'form'), 'failed save keeps the editor')
      await click(node => node.tag === 'button' && mounted.textContent(node) === 'Fechar')
      await click(node => node.props?.['aria-label'] === 'Editar compra NATIVE-77')
      assert.equal(documentField().props?.value, 'EDIT-RETAINED', 'list edit opens immediately and retains this draft')
      acceptSave = true
      ;(mounted.findInModal('Editar rascunho', node => node.tag === 'form')!.props?.onSubmit as (event: unknown) => void)({ preventDefault() {} })
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(mounted.findInModal('Editar rascunho', () => true), null, 'confirmed save closes the editor')
      mounted.modalLeaves.splice(0)
      await click(node => node.props?.['aria-label'] === 'Abrir compra NATIVE-77')
      await click(node => node.tag === 'button' && mounted.textContent(node).includes('Editar rascunho'))
      mounted.authorityGeneration.value++
      await Vue.nextTick()
      for (const leave of mounted.modalLeaves.splice(0)) leave()
      await Vue.nextTick()
      assert.equal(mounted.findAll(mounted.root, node => Boolean(node.props?.['data-modal'])).length, 0)
      assert.deepEqual(mounted.renderErrors, [])
    } finally { mounted.app.unmount() }
  })
})

describe('real purchase list footers', () => {
  for (const area of ['purchases', 'returns'] as const) {
    it(`pages ${area} once, preserving the active area and filters through pending and denial`, async (t) => {
      const calls: string[] = []
      const pending = deferred<unknown>()
      let denied = false
      const workspace = createWorkspace(async (path) => {
        calls.push(path)
        if (denied) throw { statusCode: 403 }
        const current = Number(new URL(path, 'http://test').searchParams.get('page') ?? 1)
        if (current === 2) return pending.promise
        return { data: [], meta: { current_page: current, last_page: 3, per_page: 15, total: 35 } }
      })
      t.after(workspace.stop)
      const filters = area === 'purchases' ? workspace.state.filters : workspace.state.returnFilters
      Object.assign(filters, { status: 'draft', ...(area === 'purchases' ? { search: 'fornecedor' } : {}) })
      await (area === 'purchases' ? workspace.state.load() : workspace.state.loadReturns())
      const mounted = mountPurchaseWorkspace(`#${area}`, workspace.state)
      t.after(() => mounted.app.unmount())
      await Vue.nextTick()
      await Vue.nextTick()
      const pagination = () => (mounted.find(mounted.root, node => (node.props as Record<string, unknown>)?.['data-pagination'] === true) as { props: Record<string, unknown> }).props
      const change = (value: number) => (pagination()['onUpdate:page'] as (page: number) => void)(value)
      const before = calls.length
      change(1)
      change(0)
      assert.equal(calls.length, before)
      change(2)
      await Vue.nextTick()
      assert.equal(calls.length, before + 1)
      const url = new URL(calls.at(-1)!, 'http://test')
      assert.equal(url.pathname, area === 'purchases' ? '/erp/purchases' : '/erp/purchase-returns')
      for (const [key, value] of Object.entries(filters)) assert.equal(url.searchParams.get(key), String(value))
      change(3)
      await Vue.nextTick()
      assert.equal(calls.length, before + 1)
      pending.resolve({ data: [], meta: { current_page: 2, last_page: 3, per_page: 15, total: 35 } })
      await pending.promise
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(pagination().page, 2)
      assert.equal(mounted.route.hash, `#${area}`)
      assert.match(textContent(mounted.root), area === 'purchases' ? /35 compras/ : /35 devoluções/)
      denied = true
      change(3)
      for (let turn = 0; turn < 8; turn++) { await Promise.resolve(); await Vue.nextTick() }
      const deniedPageRequests = calls.filter((path) => { const url = new URL(path, 'http://test'); return url.pathname === (area === 'purchases' ? '/erp/purchases' : '/erp/purchase-returns') && url.searchParams.get('page') === '3' })
      assert.equal(deniedPageRequests.length, 1)
      assert.equal(calls.length, before + 3, 'the existing denial watcher checks the other permitted list once')
      assert.equal(mounted.findAll(mounted.root, node => (node.props as Record<string, unknown>)?.['data-pagination'] === true).length, 0)
      assert.equal(mounted.route.hash, '#xml-preview', 'existing authority handling falls back after both list denials')
      assert.equal(filters.status, 'draft')
      assert.deepEqual(mounted.renderErrors, [])
    })
  }
})
