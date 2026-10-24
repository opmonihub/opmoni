/* eslint-disable @stylistic/max-statements-per-line -- The local renderer implements independent Vue host callbacks. */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import * as salesHelpers from '../shared/utils/erpSales.ts'
import * as taxReviewHelpers from '../shared/utils/erpCommercialTaxReview.ts'

type Workspace = ReturnType<typeof import('../app/composables/useErpSalesWorkspace.ts').useErpSalesWorkspace>
type RequestApi = (path: string, options?: Record<string, unknown>) => Promise<unknown>
const html = '<!doctype html><html lang="pt-BR"><body>Venda comercial 0001 · Documento comercial sem validade fiscal.</body></html>'
const sale = { id: 5, erp_establishment_id: 3, invoice_no: '0001', status: 'draft', currency: 'BRL', draft_revision: 1, total_amount: '10.0000', lines: [], needs_reconciliation: false }
const grants = ['erp.sales.index', 'erp.sales.show', 'erp.sales.representation']

function runtime(api: RequestApi, permissions = grants) {
  const user = Vue.ref({ id: 91 })
  const currentCompany = Vue.ref({ id: 7, permissions, role: 'owner' })
  const session = Vue.ref(0)
  const key = Vue.computed(() => `${user.value.id}:${currentCompany.value.id}:${currentCompany.value.permissions.join(',')}`)
  const context = { key, capture: () => ({ key: key.value }), isCurrent: (token: { key: string }) => token.key === key.value }
  const scope = Vue.effectScope()
  const module = loadAppComposable<{ useErpSalesWorkspace: () => Workspace }>('composables/useErpSalesWorkspace.ts', {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose, onMounted: () => {},
    useState: (_key: string, initializer: () => unknown) => _key === 'access-session-generation' ? session : Vue.ref(initializer()),
    useAccessApi: () => ({ api, refreshCsrf: async () => {} }),
    useAuth: () => ({ user, currentCompany, refreshWithStatus: async () => ({ committed: false }) }),
    useErpAdministrationContext: () => context
  })
  const state = scope.run(() => module.useErpSalesWorkspace())!
  return { state, currentCompany, session, context, stop: () => scope.stop() }
}

function defaultApi(path: string): Promise<unknown> {
  if (path === '/erp/sales/5/representation') return Promise.resolve(html)
  if (/^\/erp\/sales\/\d+$/.test(path)) return Promise.resolve({ data: { ...sale, id: Number(path.split('/').at(-1)) } })
  return Promise.resolve({ data: [sale], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
}

type HostNode = { tag?: string, text?: string, props: Record<string, unknown>, children: HostNode[], parent: HostNode | null, getAttribute?: (name: string) => unknown, contentWindow?: { focus: () => void, print: () => void } }
function component(path: string, imports: Record<string, unknown>, bindings: Record<string, unknown>): Vue.Component {
  const source = readFileSync(new URL(`../app/components/erp/${path}.vue`, import.meta.url), 'utf8')
  const script = compileScript(parse(source).descriptor, { id: path, inlineTemplate: true })
  const output = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', ...Object.keys(bindings), output)((name: string) => {
    assert.ok(name in imports, `Unexpected component import: ${name}`)
    return imports[name]
  }, exports, ...Object.values(bindings))
  return exports.default as Vue.Component
}

async function settle() {
  await Vue.nextTick()
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

function mountWorkspace(api: RequestApi = defaultApi, permissions = grants) {
  const current = runtime(api, permissions)
  const route = Vue.reactive({ path: '/erp/vendas', hash: '#sales', query: {} })
  const globals = { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted: Vue.onMounted, useRoute: () => route, useRouter: () => ({ replace: async () => {} }), useErpAdministrationContext: () => current.context }
  const detail = component('ErpSaleDetail', { 'vue': Vue, '#shared/utils/erpCommercialTaxReview': taxReviewHelpers }, globals)
  const workspace = component('ErpSalesWorkspace', {
    'vue': Vue,
    '~/composables/useErpSalesWorkspace': { useErpSalesWorkspace: () => current.state },
    '#shared/utils/erpSales': salesHelpers,
    '#shared/utils/erpCommercialTaxReview': taxReviewHelpers,
    '~/components/erp/erpPresentation': loadAppComposable('components/erp/erpPresentation.ts'),
    '~/utils/erpWorkspaceNav': loadAppComposable('utils/erpWorkspaceNav.ts')
  }, globals)
  let prints = 0
  const root: HostNode = { tag: 'root', props: {}, children: [], parent: null }
  const renderer = Vue.createRenderer<HostNode>({
    patchProp(node, name, _old, value) { node.props[name] = value },
    insert(node, parent, anchor) { node.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1; if (at < 0) parent.children.push(node); else parent.children.splice(at, 0, node) },
    remove(node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = null },
    createElement(tag) { const node: HostNode = { tag, props: {}, children: [], parent: null }; node.getAttribute = name => node.props[name]; if (tag === 'iframe') node.contentWindow = { focus() {}, print() { prints++ } }; return node },
    createText(text) { return { text, props: {}, children: [], parent: null } },
    createComment(text) { return { text, props: {}, children: [], parent: null } },
    setText(node, text) { node.text = text },
    setElementText(node, text) { node.text = text; node.children = [] },
    parentNode(node) { return node.parent },
    nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(workspace)
  const shell = Vue.defineComponent({ setup: (_props, context) => () => Vue.h('div', [...(context.slots.actions?.() ?? []), ...(context.slots.toolbar?.() ?? []), ...(context.slots.default?.() ?? [])]) })
  for (const name of ['ErpCatalogPageShell', 'UFormField', 'UDropdownMenu', 'USelect', 'UInput', 'UBadge', 'UIcon', 'NuxtLink', 'CompanyMenu', 'UserMenu', 'UCheckbox']) app.component(name, shell)
  app.component('UButton', Vue.defineComponent({ props: { label: String, disabled: Boolean, loading: Boolean }, setup: (props, context) => () => Vue.h('button', { ...context.attrs, disabled: props.disabled || props.loading }, props.label ?? context.slots.default?.()) }))
  app.component('UAlert', Vue.defineComponent({ props: { title: String, description: String }, setup: props => () => Vue.h('p', `${props.title ?? ''} ${props.description ?? ''}`) }))
  app.component('UModal', Vue.defineComponent({ props: { open: Boolean }, emits: ['update:open'], setup: (props, context) => () => props.open ? Vue.h('section', [Vue.h('button', { onClick: () => context.emit('update:open', false) }, 'Fechar modal'), ...(context.slots.body?.() ?? [])]) : null }))
  app.component('UNavigationMenu', Vue.defineComponent({
    props: ['items'],
    setup: props => () => Vue.h('nav', (props.items as Array<{ label: string }>).flat().map(item => Vue.h('span', item.label)))
  }))
  app.component('UTable', Vue.defineComponent({
    props: ['data', 'columns'],
    setup: props => () => Vue.h('table', (props.data as Record<string, unknown>[]).map(row => Vue.h('tr',
      (props.columns as Array<{ accessorKey?: string, cell?: (context: { row: { original: Record<string, unknown> } }) => unknown }>).map((column) => {
        const value = column.cell ? column.cell({ row: { original: row } }) : row[column.accessorKey ?? '']
        return Vue.h('td', {}, Vue.isVNode(value) ? [value] : String(value ?? ''))
      })
    )))
  }))
  app.component('UPagination', Vue.defineComponent({
    props: ['page', 'total', 'itemsPerPage', 'disabled'], emits: ['update:page'],
    setup: (props, context) => () => Vue.h('nav', { ...context.attrs, 'data-page': props.page })
  }))
  app.component('ErpListFooter', component('ErpListFooter', { vue: Vue }, globals))
  app.component('ErpSaleDetail', detail)
  for (const name of ['ErpCommercialDraftForm', 'ErpSaleReturnForm', 'ErpPendingOperationAlert', 'ErpCommercialTaxReview']) app.component(name, shell)
  app.mount(root)
  const text = (node: HostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`
  const find = (predicate: (node: HostNode) => boolean, node = root): HostNode[] => [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => find(predicate, child))]
  async function click(label: string) {
    const button = find(node => node.tag === 'button' && text(node).includes(label))[0]
    assert.ok(button, `Expected visible action ${label}`)
    assert.ok(!button.props.disabled, `Action ${label} must be enabled`)
    ;(button.props.onClick as () => unknown)()
    await settle()
  }
  return { ...current, text: () => text(root), find, click, prints: () => prints, dispose() { app.unmount(); current.stop() } }
}

describe('sale commercial representation real consumer', { timeout: 90_000 }, () => {
  it('opens frozen HTML from the visible detail action and prints only after explicit user action', async (t) => {
    const requests: Array<[string, Record<string, unknown> | undefined]> = []
    const page = mountWorkspace(async (path, options) => { requests.push([path, options]); return defaultApi(path) })
    t.after(() => page.dispose())
    await settle()
    await page.click('0001')
    await page.click('Representação comercial')
    const frames = page.find(node => node.tag === 'iframe')
    assert.equal(frames.length, 1)
    assert.equal(frames[0]!.props.srcdoc, html)
    assert.equal(frames[0]!.props.sandbox, 'allow-same-origin allow-modals')
    assert.equal(page.prints(), 0)
    assert.equal(requests.find(([path]) => path.endsWith('/representation'))?.[1]?.responseType, 'text')
    await page.click('Imprimir')
    assert.equal(page.prints(), 1)
    await page.click('Fechar modal')
    assert.equal(page.find(node => node.tag === 'iframe').length, 0)
    assert.equal(page.state.representationHtml.value, null)
  })

  it('does not expose the representation action without its current read grant', async (t) => {
    const page = mountWorkspace(defaultApi, ['erp.sales.index', 'erp.sales.show'])
    t.after(() => page.dispose())
    await settle()
    await page.click('0001')
    assert.equal(page.find(node => node.tag === 'button' && node.props.label === 'Representação comercial').length, 0)
    assert.ok(!page.text().includes('Representação comercial'))
  })

  it('shows a recoverable failure without stale HTML and retry loads the actual returned content', async (t) => {
    let fail = true
    const page = mountWorkspace(async (path) => {
      if (path.endsWith('/representation') && fail) throw { response: { status: 503, _data: JSON.stringify({ message: 'Impressão temporariamente indisponível.' }) } }
      return defaultApi(path)
    })
    t.after(() => page.dispose())
    await settle()
    await page.click('0001')
    await page.click('Representação comercial')
    assert.ok(page.text().includes('Impressão temporariamente indisponível.'))
    assert.equal(page.find(node => node.tag === 'iframe').length, 0)
    fail = false
    await page.click('Tentar novamente')
    assert.equal(page.find(node => node.tag === 'iframe')[0]?.props.srcdoc, html)
  })

  for (const boundary of ['company', 'close', 'selection', 'permission'] as const) {
    it(`discards late private HTML after ${boundary} changes`, async (t) => {
      let release!: (value: unknown) => void
      const current = runtime(path => path.endsWith('/representation') ? new Promise((resolve) => { release = resolve }) : defaultApi(path))
      t.after(() => current.stop())
      await current.state.inspect('sales', 5)
      const pending = current.state.loadRepresentation(5)
      if (boundary === 'company') current.currentCompany.value = { ...current.currentCompany.value, id: 8 }
      if (boundary === 'close') current.state.closeRepresentation()
      if (boundary === 'selection') await current.state.inspect('sales', 6)
      if (boundary === 'permission') current.currentCompany.value = { ...current.currentCompany.value, permissions: ['erp.sales.index'] }
      release(html)
      assert.equal(await pending, null)
      assert.equal(current.state.representationHtml.value, null)
      assert.equal(current.state.representationLoading.value, false)
    })
  }

  it('closes a displayed private document immediately on a denied refresh and prevents printing the old frame', async (t) => {
    let deny = false
    const current = runtime(async (path) => { if (deny && path.endsWith('/representation')) throw { response: { status: 409, _data: JSON.stringify({ code: 'company_context_changed' }) } }; return defaultApi(path) })
    t.after(() => current.stop())
    await current.state.inspect('sales', 5)
    await current.state.loadRepresentation(5)
    let prints = 0
    const frame = { getAttribute: () => html, contentWindow: { focus() {}, print() { prints++ } } } as unknown as HTMLIFrameElement
    deny = true
    await current.state.loadRepresentation(5)
    current.state.printRepresentation(frame)
    assert.equal(prints, 0)
    assert.equal(current.state.representationHtml.value, null)
    assert.equal(current.state.selected.value, null)
  })
})
