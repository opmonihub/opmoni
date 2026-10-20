import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import type { useErpCatalogLabels as CreateLabels } from '../app/composables/useErpCatalogLabels.ts'
import type { useErpCatalogList as CreateList } from '../app/composables/useErpCatalogList.ts'
import type { ErpProductRecord } from '../shared/types/erpCatalogs.ts'
import { sampleErpProductFixture } from '../shared/fixtures/erpCoreCatalogs.ts'
import type { LabelElement } from './helpers/erpLabelDocument.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { installLabelDocument, labelResponse } from './helpers/erpLabelDocument.ts'

type Request = {
  url: string
  options: {
    method?: string
    body?: unknown
    signal?: AbortSignal
  }
  resolve(value: unknown): void
  reject(reason: unknown): void
}
function runtime(permissions: string[] = ['*'], role = 'owner') {
  const requests: Request[] = []
  const company = Vue.ref({ id: 41, status: 'active', access_kind: 'direct', role, permissions })
  const user = Vue.ref<{
    id: number
  } | null>({ id: 5 })
  const generation = Vue.ref(1)
  const scope = Vue.effectScope()
  const bindings = {
    computed: Vue.computed, ref: Vue.ref, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose,
    useAuth: () => ({ user, currentCompany: company, currentAccount: Vue.ref(null), refresh: async () => user.value }),
    useState: () => generation,
    useAccessApi: () => ({ refreshCsrf: async () => undefined, api: (url: string, options = {}) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })) })
  }
  const module = loadAppComposable<{
    useErpCatalogLabels: typeof CreateLabels
  }>('composables/useErpCatalogLabels.ts', bindings)
  const listModule = loadAppComposable<{
    useErpCatalogList: typeof CreateList
  }>('composables/useErpCatalogList.ts', bindings)
  const labels = scope.run(() => module.useErpCatalogLabels())!
  return { labels, requests, company, generation, scope, bindings, listModule }
}
async function settle() {
  await Promise.resolve()
  await Vue.nextTick()
  await Promise.resolve()
  await Vue.nextTick()
}
function resolveConfiguration(requests: Request[]) {
  requests.filter(request => !request.options.method).forEach(request => request.resolve({ data: request.url.endsWith('print-settings') ? { label_width_mm: 50, label_height_mm: 30, show_price: true, show_barcode: true } : [] }))
}
describe('ERP labels real composable authority and mutations', () => {
  it('releases the mutation fence after success and preserves exact fractional input after field422', async (t) => {
    const { labels, requests, scope } = runtime()
    t.after(() => scope.stop())
    resolveConfiguration(requests)
    await settle()
    labels.settings.value.label_width_mm = 60
    const save = labels.saveSettings()
    await settle()
    requests.at(-1)!.resolve({ data: { ...labels.settings.value } })
    await save
    assert.equal(labels.mutationBusy.value, false)
    labels.layoutDraft.value.label_width_mm = '45.1234'
    const layout = labels.saveLayout()
    await settle()
    requests.at(-1)!.reject({ statusCode: 422, data: { message: 'Dimensão inválida', errors: { paper_width_mm: ['A folha não comporta as etiquetas.'] } } })
    await layout
    assert.equal(labels.layoutDraft.value.label_width_mm, '45.1234')
    assert.deepEqual(labels.fieldErrors.value.paper_width_mm, ['A folha não comporta as etiquetas.'])
    assert.equal(labels.mutationBusy.value, false)
  })
  it('blocks denied generation and refuses download callbacks after authority loss', async (t) => {
    const { labels, requests, scope } = runtime()
    t.after(() => scope.stop())
    resolveConfiguration(requests)
    await settle()
    labels.toggleSelection({ product_id: 8, variant_id: 81, quantity: 3 }, true)
    const generated = labels.generateOutput()
    await settle()
    requests.at(-1)!.reject({ statusCode: 403, data: { message: 'Acesso revogado.' } })
    await generated
    assert.equal(labels.authorityBlocked.value, true)
    assert.deepEqual(labels.selectedLines.value, [])
    const count = requests.length
    labels.toggleSelection({ product_id: 8, quantity: 1 }, true)
    await labels.generateOutput()
    assert.equal(requests.length, count)
    assert.equal(labels.outputUrl.value, '')
    labels.downloadOutput()
  })
  it('cancels pending requests and clears selections/drafts when session generation changes', async (t) => {
    const { labels, requests, generation, scope } = runtime()
    t.after(() => scope.stop())
    resolveConfiguration(requests)
    await settle()
    labels.layoutDraft.value.name = 'Privado'
    labels.toggleSelection({ product_id: 1, quantity: 3 }, true)
    const save = labels.saveSettings()
    await settle()
    const pending = requests.at(-1)!
    generation.value++
    assert.equal(pending.options.signal?.aborted, true)
    pending.resolve({ data: { label_width_mm: 77, label_height_mm: 33, show_price: false, show_barcode: false } })
    await save
    assert.equal(labels.settings.value.label_width_mm, 50)
    assert.deepEqual(labels.selectedLines.value, [])
    assert.equal(labels.layoutDraft.value.name, '')
    assert.equal(labels.mutationBusy.value, false)
  })
})
type HostNode = {
  tag: string
  props: Record<string, unknown> & {
    onChange(value: unknown): void
  }
  children: HostNode[]
  parent: HostNode | null
  textContent: string
}
function mountPage(permissions?: string[], role?: string) {
  const runtime = runtimeFactory(permissions, role)
  const node = (tag: string, textContent = ''): HostNode => ({ tag, props: {}, children: [], parent: null, textContent, style: {} })
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    patchProp(node, key, _old, value) {
      node.props[key] = value
    },
    insert(node, parent, anchor = null) {
      if (node.parent)
        node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0)
        parent.children.push(node)
      else
        parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (node.parent)
        node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    createElement: tag => node(tag), createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText(node, text) {
      node.textContent = text
    }, setElementText(node, text) {
      node.textContent = text
      node.children = []
    },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insertStaticContent(content, parent, anchor) {
      const child = node('#static', content.replace(/<[^>]*>/g, ''))
      this.insert(child, parent, anchor)
      return [child, child]
    }
  })
  const page = compileVueComponent('pages/erp/cadastros/etiquetas.vue', {
    ...runtime.bindings, useHead() { }, definePageMeta() { }, useErpCatalogList: runtime.listModule.useErpCatalogList
  }, { '~/composables/useErpCatalogLabels': { useErpCatalogLabels: () => runtime.labels } })
  const app = renderer.createApp(page)
  function boundary(name: string, tag: string, slotsToRender = ['default']) {
    app.component(name, Vue.defineComponent({ setup(_, { attrs, slots }) {
      return () => Vue.h(tag, { ...attrs, 'data-component': name }, slotsToRender.flatMap(slot => slots[slot]?.() ?? []))
    } }))
  }
  boundary('UDashboardPanel', 'main', ['header', 'body'])
  boundary('UDashboardNavbar', 'header', ['leading', 'right'])
  boundary('UDashboardSidebarToggle', 'button')
  boundary('CompanyMenu', 'button')
  boundary('UserMenu', 'button')
  boundary('UCollapsible', 'section', ['default', 'content'])
  app.component('UButton', Vue.defineComponent({ props: ['label', 'loading', 'disabled', 'type'], setup(props, { attrs, slots }) {
    return () => Vue.h('button', { ...attrs, disabled: props.disabled, type: props.type ?? 'button' }, [props.label, slots.default?.()])
  } }))
  app.component('UAlert', Vue.defineComponent({ props: ['title'], setup(props, { slots }) {
    return () => Vue.h('aside', { role: 'alert' }, [props.title, slots.actions?.()])
  } }))
  app.component('UFormField', Vue.defineComponent({ props: ['label', 'error', 'name'], setup(props, { slots }) {
    return () => Vue.h('label', { 'data-field': props.name }, [props.label, slots.default?.(), props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null])
  } }))
  for (const name of ['UInput', 'UInputNumber', 'UCheckbox', 'USelect']) {
    app.component(name, Vue.defineComponent({ props: ['modelValue', 'label', 'disabled', 'items'], emits: ['update:modelValue'], setup(props, { attrs, emit }) {
      return () => Vue.h(name === 'USelect' ? 'select' : 'input', { ...attrs, 'data-component': name, 'value': props.modelValue, 'disabled': props.disabled, 'onChange': (value: unknown) => emit('update:modelValue', value) }, props.label)
    } }))
  }
  app.component('UTable', Vue.defineComponent({ props: ['data', 'columns'], setup(props, { slots }) {
    return () => Vue.h('table', {}, props.data.map((row: Record<string, unknown>) => Vue.h('tr', {}, props.columns.map((column: {
      id?: string
      accessorKey: string
    }) => Vue.h('td', {}, slots[`${column.id ?? column.accessorKey}-cell`]?.({ row: { original: row } }) ?? String(row[column.accessorKey] ?? ''))))))
  } }))
  app.component('UPagination', Vue.defineComponent({ props: ['page'], emits: ['update:page'], setup(props, { emit }) {
    return () => Vue.h('nav', { 'data-page': props.page, 'onChange': (page: number) => emit('update:page', page) })
  } }))
  const root = node('root')
  app.mount(root)
  const find = (predicate: (node: HostNode) => boolean) => {
    const found: HostNode[] = []
    const visit = (node: HostNode) => {
      if (predicate(node))
        found.push(node)
      node.children.forEach(visit)
    }
    visit(root)
    return found
  }
  const text = (node: HostNode): string => node.textContent + node.children.map(text).join('')
  return { ...runtime, root, find, text: () => text(root), button: (label: string) => find(node => node.tag === 'button' && text(node) === label)[0]!, unmount() {
    app.unmount()
    runtime.scope.stop()
  } }
}
const runtimeFactory = runtime
const product: ErpProductRecord = { ...sampleErpProductFixture, id: 8, name: 'Café', sku: 'SKU-8', barcode: '00012345678905', barcode_type: 'C128', variants: [{ id: 81, name: 'Caixa', status: 'active', barcode: '04252614', barcode_type: 'UPCE', sale_price: '0.0000', sku: 'CX' }] }
function resolvePageConfiguration(page: ReturnType<typeof mountPage>) {
  resolveConfiguration(page.requests.filter(request => !request.url.includes('/products?')))
  page.requests.find(request => request.url.includes('/products?'))!.resolve({ data: [product], meta: { current_page: 1, total: 20, per_page: 15, last_page: 2 } })
}
describe('ERP labels mounted real SFC', () => {
  it('keeps shell, search, ordered product/variant selection and quantities across pagination', async (t) => {
    const page = mountPage()
    t.after(page.unmount)
    resolvePageConfiguration(page)
    await settle()
    assert.equal(page.find(node => node.props['data-component'] === 'UDashboardSidebarToggle').length, 1)
    const boxes = page.find(node => node.props['data-component'] === 'UCheckbox' && String(node.props['aria-label']).startsWith('Selecionar'))
    assert.equal(boxes.length, 2)
    boxes[1]!.props.onChange(true)
    boxes[0]!.props.onChange(true)
    await settle()
    const quantity = page.find(node => node.props['data-field'] === 'items.0.quantity')[0]!.children.find(node => node.tag === 'input')!
    quantity.props.onChange(3)
    await settle()
    assert.equal(page.labels.selectedCopies.value, 4)
    assert.deepEqual(page.labels.selectedLines.value, [{ product_id: 8, variant_id: 81, quantity: 3 }, { product_id: 8, quantity: 1 }])
    page.find(node => node.tag === 'nav')[0]!.props.onChange(2)
    await settle()
    assert.match(page.requests.at(-1)!.url, /page=2/)
    page.requests.at(-1)!.resolve({ data: [], meta: { current_page: 2, total: 20, per_page: 15, last_page: 2 } })
    await settle()
    assert.match(page.text(), /Café · Caixa/)
    assert.match(page.text(), /4 etiquetas/)
    const search = page.find(node => node.props.placeholder === 'Nome, SKU ou código de barras')[0]!
    search.props.onChange('Novo')
    await new Promise(resolve => setTimeout(resolve, 280))
    await settle()
    assert.match(page.requests.at(-1)!.url, /page=1&search=Novo/)
    assert.equal(page.labels.selectedCopies.value, 4)
  })
  it('retains decimal layout text and field errors in mounted controls, and removes access on permission change', async (t) => {
    const page = mountPage()
    t.after(page.unmount)
    resolvePageConfiguration(page)
    await settle()
    const width = page.find(node => node.props['data-field'] === 'label_width_mm').at(-1)!.children.find(node => node.tag === 'input')!
    width.props.onChange('45.1234')
    await settle()
    assert.equal(page.labels.layoutDraft.value.label_width_mm, '45.1234')
    const save = page.labels.saveLayout()
    await settle()
    page.requests.at(-1)!.reject({ statusCode: 422, data: { errors: { label_width_mm: ['Medida inválida.'] } } })
    await save
    await settle()
    assert.match(page.text(), /Medida inválida/)
    assert.equal(width.props.value, '45.1234')
    page.company.value = { ...page.company.value, permissions: ['erp.read'], role: 'viewer' }
    await settle()
    assert.equal(page.button('Preparar etiquetas').props.disabled, true)
    assert.match(page.text(), /não tem permissão para gerar etiquetas/)
    assert.deepEqual(page.labels.selectedLines.value, [])
  })
  it('shows real renderer errors without opening a preview and prevents stale completion after context changes', async (t) => {
    const page = mountPage()
    t.after(page.unmount)
    resolvePageConfiguration(page)
    await settle()
    page.labels.toggleSelection({ product_id: 8, quantity: 3 }, true)
    const generation = page.labels.generateOutput()
    await settle()
    page.requests.at(-1)!.resolve({ data: [], meta: {} })
    await generation
    await settle()
    assert.match(page.text(), /só podem ser gerados no navegador/)
    assert.equal(page.find(node => node.tag === 'iframe').length, 0)
    const next = page.labels.generateOutput()
    await settle()
    const request = page.requests.at(-1)!
    page.generation.value++
    request.resolve({ data: [], meta: {} })
    await next
    await settle()
    assert.equal(page.find(node => node.tag === 'iframe').length, 0)
    assert.equal(page.labels.outputUrl.value, '')
  })
})
describe('ERP label output callbacks and stale rendering', () => {
  it('downloads and prints the owned output, then revokes it synchronously on edits and disposes callbacks', async (t) => {
    const dom = installLabelDocument()
    t.after(dom.restore)
    const app = runtime()
    t.after(() => app.scope.stop())
    resolveConfiguration(app.requests)
    await settle()
    const urls = t.mock.method(URL, 'createObjectURL', () => 'blob:labels-owned')
    const revoked = t.mock.method(URL, 'revokeObjectURL', () => undefined)
    app.labels.toggleSelection({ product_id: 8, variant_id: 81, quantity: 3 }, true)
    const output = app.labels.generateOutput()
    await settle()
    assert.deepEqual(app.requests.at(-1)!.options.body, { items: [{ product_id: 8, variant_id: 81, quantity: 3 }] })
    app.requests.at(-1)!.resolve(labelResponse())
    await output
    assert.equal(urls.mock.callCount(), 1)
    assert.equal(app.labels.outputUrl.value, 'blob:labels-owned')
    const anchors: Array<LabelElement & {
      download?: string
    }> = []
    const create = dom.doc.createElement
    dom.doc.createElement = (name) => {
      const node = create(name)
      if (name === 'a')
        anchors.push(node)
      return node
    }
    app.labels.downloadOutput()
    assert.equal(anchors.length, 1)
    assert.equal(anchors[0].clicked, true)
    assert.equal(anchors[0].download, 'etiquetas-produtos.html')
    let prints = 0
    app.labels.printOutput({ getAttribute: () => 'blob:obsolete', contentWindow: { focus() { }, print() {
      prints++
    } } })
    assert.equal(prints, 0)
    app.labels.printOutput({ getAttribute: () => 'blob:labels-owned', contentWindow: { focus() { }, print() {
      prints++
    } } })
    assert.equal(prints, 1)
    app.labels.settings.value.label_width_mm = 55
    assert.equal(app.labels.outputUrl.value, '')
    assert.equal(revoked.mock.callCount(), 1)
    app.labels.downloadOutput()
    assert.equal(anchors.length, 1)
    app.scope.stop()
    app.labels.downloadOutput()
    assert.equal(anchors.length, 1)
  })
  it('refuses a Blob URL when Company context changes during actual JsBarcode rendering', async (t) => {
    const dom = installLabelDocument()
    t.after(dom.restore)
    const app = runtime()
    t.after(() => app.scope.stop())
    resolveConfiguration(app.requests)
    await settle()
    const urls = t.mock.method(URL, 'createObjectURL', () => 'blob:must-never-exist')
    const create = dom.doc.createElementNS
    dom.doc.createElementNS = (namespace, name) => {
      if (name === 'svg')
        app.generation.value++
      return create(namespace, name)
    }
    app.labels.toggleSelection({ product_id: 8, quantity: 3 }, true)
    const output = app.labels.generateOutput()
    await settle()
    app.requests.at(-1)!.resolve(labelResponse())
    await output
    assert.equal(urls.mock.callCount(), 0)
    assert.equal(app.labels.outputUrl.value, '')
    assert.deepEqual(app.labels.selectedLines.value, [])
  })
})

describe('ERP labels independent review regressions', () => {
  it('allows exact generate-only grants, consumes the output snapshot and recovers same-context authority', async (t) => {
    const dom = installLabelDocument()
    t.after(dom.restore)
    const page = mountPage(['erp.products.index', 'erp.catalog.product-labels'], 'operator')
    t.after(page.unmount)
    assert.equal(page.requests.length, 1, 'optional configuration reads are not requested')
    resolvePageConfiguration(page)
    await settle()
    assert.equal(page.labels.error.value, '')
    assert.doesNotMatch(page.text(), /Configuração básica|Gerenciar layouts|Gerenciar perfis/)
    const checkbox = page.find(node => node.props['aria-label'] === 'Selecionar Café · Caixa')[0]!
    checkbox.props.onChange(true)
    await settle()
    assert.equal(page.button('Preparar etiquetas').props.disabled, false)
    const first = page.labels.generateOutput()
    await settle()
    page.requests.at(-1)!.reject({ statusCode: 403, data: { message: 'Confirme o acesso.' } })
    await first
    await settle()
    assert.equal(page.labels.authorityBlocked.value, true)
    await page.labels.retryConfiguration()
    assert.equal(page.labels.authorityBlocked.value, false)
    assert.equal(page.labels.error.value, '')
    page.labels.toggleSelection({ product_id: 8, variant_id: 81, quantity: 3 }, true)
    const output = page.labels.generateOutput()
    await settle()
    page.requests.at(-1)!.resolve(labelResponse())
    await output
    await settle()
    assert.equal(page.labels.outputCopies.value, 3)
    assert.equal(page.labels.outputMeta.value?.layout.label_width_mm, '80.1250')
    assert.equal(page.find(node => node.tag === 'iframe').length, 1)
  })
  it('keeps target-specific422 fields out of unrelated settings, layout, profile and selection controls', async (t) => {
    const page = mountPage()
    t.after(page.unmount)
    resolvePageConfiguration(page)
    await settle()
    page.labels.layoutDraft.value.label_width_mm = '45.1234'
    const layout = page.labels.saveLayout()
    await settle()
    page.requests.at(-1)!.reject({ statusCode: 422, data: { errors: { label_width_mm: ['Layout não cabe.'] } } })
    await layout
    await settle()
    const widths = page.find(node => node.props['data-field'] === 'label_width_mm')
    assert.equal(widths[0]!.children.some(node => node.props.role === 'alert'), false, 'basic width is not invalid')
    assert.equal(widths[1]!.children.some(node => node.props.role === 'alert'), true, 'only named layout width is invalid')
    assert.equal(page.labels.layoutDraft.value.label_width_mm, '45.1234')
    const profile = page.labels.saveProfile()
    await settle()
    page.requests.at(-1)!.reject({ statusCode: 422, data: { errors: { name: ['Nome da impressora inválido.'] } } })
    await profile
    await settle()
    const names = page.find(node => node.props['data-field'] === 'name')
    assert.equal(names[0]!.children.some(node => node.props.role === 'alert'), false, 'layout name remains valid')
    assert.equal(names[1]!.children.some(node => node.props.role === 'alert'), true, 'profile field owns its error')
  })
})
