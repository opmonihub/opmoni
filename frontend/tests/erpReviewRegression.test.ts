import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import { renderToString } from 'vue/server-renderer'
import ts from 'typescript'
import * as nav from '../app/utils/erpModulesNav.ts'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as purchase from '../shared/utils/erpPurchase.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

function evaluate(source: string, bindings: Record<string, unknown>, imports: Record<string, unknown> = {}) {
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', ...Object.keys(bindings), output)(
    (name: string) => imports[name], exports, ...Object.values(bindings)
  )
  return exports
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

function administrationBindings() {
  const auth = {
    user: Vue.ref({ id: 5 }),
    currentCompany: Vue.ref({
      id: 41,
      status: 'active',
      access_kind: 'direct',
      role: 'owner',
      permissions: ['erp.products.index', 'erp.purchases.xml-preview'],
      delegated_office_id: null
    }),
    currentAccount: Vue.ref({ id: 12, status: 'active', role: 'admin' })
  }
  const sessionGeneration = Vue.ref(7)
  const bindings = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose,
    useAuth: () => auth,
    useState: () => sessionGeneration
  }
  const { useErpAdministrationContext } = loadAppComposable<{
    useErpAdministrationContext: typeof import('../app/composables/useErpAdministrationContext.ts').useErpAdministrationContext
  }>('composables/useErpAdministrationContext.ts', bindings)
  return { ...bindings, useErpAdministrationContext }
}

function moduleHub(contract: nav.ErpModulePageContract, api: (path: string) => Promise<unknown>) {
  const path = new URL('../app/components/erp/ErpModuleHub.vue', import.meta.url)
  const { descriptor } = parse(readFileSync(path, 'utf8'))
  const script = compileScript(descriptor, { id: 'review-hub' })
  const scope = Vue.effectScope()
  const route = Vue.reactive({ hash: '' })
  const company = Vue.ref({ id: 1, permissions: ['*'] })
  const component = evaluate(script.content, {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch,
    onMounted() {}, onScopeDispose: Vue.onScopeDispose,
    useRoute: () => route,
    useAuth: () => ({ currentCompany: company }),
    useAccessApi: () => ({ api }),
    useRetryableLoad: () => ({ showError: Vue.ref(false), retry() {}, isLoading: Vue.ref(false) })
  }, {
    'vue': Vue,
    '~/utils/erpModulesNav': nav,
    '~/composables/useApiError': apiErrors,
    '~/components/data-table/panel': { panelTableUi: {} }
  }).default as { setup: (props: unknown, context: unknown) => Record<string, unknown> }
  const state = scope.run(() => component.setup({ contract }, { expose() {} })) as {
    load: () => Promise<void>
    rows: Vue.Ref<Record<string, unknown>[]>
    report?: Vue.Ref<{ totals?: Record<string, unknown> } | null>
    selectedTabId: Vue.Ref<string>
    page: Vue.Ref<number>
    loading: Vue.Ref<boolean>
  }
  return { state, stop: () => scope.stop() }
}

describe('ERP review regressions', () => {
  it('shows the field rejection returned by the XML preview API instead of the fetch status line', async () => {
    const { descriptor } = parse(readFileSync(new URL('../app/components/erp/ErpPurchaseXmlPreview.vue', import.meta.url), 'utf8'))
    const script = compileScript(descriptor, { id: 'preview-errors', inlineTemplate: true })
    const message = 'Destinatário do XML não corresponde ao CNPJ da empresa.'
    const failure = Object.assign(new Error('[POST] 422 Unprocessable Entity'), {
      statusCode: 422,
      data: { message: 'The given data was invalid.', errors: { xml: [message] } }
    })
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const { useErpPurchaseWorkspace } = loadAppComposable<{
      useErpPurchaseWorkspace: typeof import('../app/composables/useErpPurchaseWorkspace.ts').useErpPurchaseWorkspace
    }>('composables/useErpPurchaseWorkspace.ts', {
      ...administrationBindings(),
      onMounted() {},
      useAccessApi: () => ({
        api: async (path: string, options?: Record<string, unknown>) => {
          calls.push({ path, options })
          throw failure
        }
      })
    })
    const component = evaluate(script.content, {
      ref: Vue.ref, computed: Vue.computed
    }, { 'vue': Vue, '#shared/utils/erpPurchase': purchase }).default as Vue.Component
    const scope = Vue.effectScope()
    try {
      const workspace = scope.run(useErpPurchaseWorkspace)!
      const result = await workspace.inspectXml(new File(['<xml/>'], 'invoice.xml', { type: 'application/xml' }), 7)
      assert.equal(result, null)
      assert.deepEqual(calls, [{
        path: '/erp/purchases/xml-preview',
        options: { method: 'POST', body: { erp_establishment_id: 7, xml: '<xml/>' } }
      }])
      assert.equal(workspace.previewState.value, null)
      assert.equal(workspace.mutationError.value, message)

      const app = Vue.createSSRApp({
        render: () => Vue.h(component, {
          modelValue: workspace.previewState.value,
          establishmentId: 7,
          establishments: [{ id: 7, name: 'Matriz' }],
          suppliers: [], variants: [], units: [],
          error: workspace.mutationError.value
        })
      })
      app.component('UFormField', {
        setup: (_props, context) => () => Vue.h('label', {}, context.slots.default?.())
      })
      app.component('UInputMenu', { render: () => Vue.h('input') })
      app.component('UInput', { render: () => Vue.h('input') })
      app.component('UButton', { render: () => Vue.h('button') })
      app.component('UAlert', {
        props: ['title'],
        setup: props => () => Vue.h('aside', { role: 'alert' }, String(props.title ?? ''))
      })
      const html = await renderToString(app)
      assert.ok(html.includes(`<aside role="alert" color="error">${message}</aside>`), html)
      assert.equal(html.includes('[POST] 422 Unprocessable Entity'), false)
      assert.equal(html.includes('The given data was invalid.'), false)
    } finally { scope.stop() }
  })

  it('allows unauthenticated visitors to reach the ERP registration page', async () => {
    const source = readFileSync(new URL('../app/middleware/access.global.ts', import.meta.url), 'utf8')
    const middleware = evaluate(source, {
      defineNuxtRouteMiddleware: (callback: unknown) => callback,
      useAuth: () => ({ user: Vue.ref(null), resolved: Vue.ref(true), refresh: async () => null }),
      useAccessApi: () => ({ api: async () => ({ registration_available: false }) }),
      navigateTo: (path: string) => path
    }, { '#shared/utils/access': { accessDestination: () => '/erp' } }).default as (route: { path: string }) => Promise<unknown>
    assert.equal(await middleware({ path: '/erp/cadastro' }), undefined)
    assert.equal(await middleware({ path: '/erp/financeiro' }), '/login')
  })

  it('preserves report aggregates instead of claiming a successful report is empty', async (t) => {
    const view = moduleHub(nav.erpRelatoriosContract, async () => ({ data: { category: 'sales', totals: { document_count: 2, posted_total: '150.0000' } } }))
    t.after(view.stop)
    await view.state.load()
    assert.deepEqual(view.state.report?.value?.totals, { document_count: 2, posted_total: '150.0000' })
  })

  it('discards a late response from the previous tab', async (t) => {
    const obligations = deferred<unknown>()
    const view = moduleHub(nav.erpFinanceiroContract, async path => path.includes('/obligations') ? obligations.promise : { data: [{ id: 2, kind: 'payment' }], meta: { total: 1 } })
    t.after(view.stop)
    view.state.selectedTabId.value = 'obligations'
    await Vue.nextTick()
    view.state.selectedTabId.value = 'payments'
    await Vue.nextTick()
    await view.state.load()
    obligations.resolve({ data: [{ id: 1, kind: 'obligation' }], meta: { total: 1 } })
    await Vue.nextTick()
    await Vue.nextTick()
    assert.equal(view.state.rows.value[0]?.kind, 'payment')
  })

  it('requests the selected list page instead of keeping every module on page one', async (t) => {
    const calls: string[] = []
    const view = moduleHub(nav.erpFinanceiroContract, async (path) => {
      calls.push(path)
      return { data: [], meta: { total: 30, per_page: 15 } }
    })
    t.after(view.stop)
    assert.ok(view.state.page, 'The module must expose pagination')
    view.state.page.value = 2
    await Vue.nextTick()
    await view.state.load()
    assert.ok(calls.some(path => path.includes('page=2')))
  })

  it('uses the registered financial and overview report categories', () => {
    assert.equal(nav.erpRelatoriosContract.tabs.find(tab => tab.id === 'finance')?.apiPath, '/erp/reports/financial')
    assert.equal(nav.erpRelatoriosContract.tabs.find(tab => tab.id === 'dashboard')?.apiPath, '/erp/reports/overview')
  })

  it('does not issue a GET against the XML preview mutation', async (t) => {
    const calls: string[] = []
    const view = moduleHub(nav.erpComprasContract, async (path) => {
      calls.push(path)
      return { data: [] }
    })
    t.after(view.stop)
    view.state.selectedTabId.value = 'xml-preview'
    await Vue.nextTick()
    await view.state.load()
    assert.equal(calls.some(path => path.includes('/xml-preview')), false)
  })

  it('keeps catalog search results from being overwritten by an older request', async () => {
    const old = deferred<unknown>()
    const latest = deferred<unknown>()
    const requests: Array<{ path: string, signal?: AbortSignal }> = []
    const response = (name: string) => ({
      data: [{ id: 1, name }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    const { useErpCatalogList } = loadAppComposable<{
      useErpCatalogList: typeof import('../app/composables/useErpCatalogList.ts').useErpCatalogList
    }>('composables/useErpCatalogList.ts', {
      ...administrationBindings(),
      useAccessApi: () => ({
        api: (path: string, options: { signal?: AbortSignal } = {}) => {
          requests.push({ path, signal: options.signal })
          // This transport deliberately ignores abort so the real sequence guard must reject the late result.
          if (path.includes('search=old')) return old.promise
          if (path.includes('search=new')) return latest.promise
          return Promise.resolve(response('initial'))
        }
      })
    })
    const scope = Vue.effectScope()
    try {
      const state = scope.run(() => useErpCatalogList<{ id: number, name: string }>('/erp/products'))!
      await Vue.nextTick()
      assert.equal(state.canRead.value, true)
      assert.equal(state.state.value?.data[0]?.name, 'initial')
      state.search.value = 'old'
      assert.equal(state.searchPending.value, true)
      assert.equal(requests.length, 1, 'typing alone must still be waiting for the debounce')
      const oldRefresh = state.refresh()
      assert.equal(state.searchPending.value, false, 'explicit refresh cancels the debounce timer')
      assert.equal(requests.length, 2, 'the old search must actually start before the next search')
      const oldRequest = requests[1]!
      assert.equal(oldRequest.path, '/erp/products?page=1&search=old')
      assert.equal(oldRequest.signal?.aborted, false)
      assert.equal(state.pending.value, true)

      state.search.value = 'new'
      assert.equal(oldRequest.signal?.aborted, true)
      const currentRefresh = state.refresh()
      assert.deepEqual(requests.map(request => request.path), [
        '/erp/products?page=1',
        '/erp/products?page=1&search=old',
        '/erp/products?page=1&search=new'
      ])
      latest.resolve(response('current'))
      await currentRefresh
      assert.equal(state.pending.value, false)
      assert.equal(state.state.value?.data[0]?.name, 'current', 'the new request must finish before the old result')

      old.resolve(response('obsolete'))
      await oldRefresh
      assert.equal(state.state.value?.data[0]?.name, 'current')
      assert.equal(state.pending.value, false)
      assert.equal(state.error.value, '')
      assert.equal(requests.length, 3)
    } finally { scope.stop() }
  })
})
