import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as nav from '../app/utils/erpModulesNav.ts'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as catalog from '../shared/utils/erpCatalogs.ts'
import { apiFailure } from '../shared/utils/access.ts'

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
    const script = compileScript(descriptor, { id: 'preview-errors' })
    const failure = Object.assign(new Error('[POST] 422 Unprocessable Entity'), { statusCode: 422, data: { message: 'Destinatário do XML não corresponde ao CNPJ da empresa.' } })
    const component = evaluate(script.content, {
      ref: Vue.ref, onScopeDispose: Vue.onScopeDispose,
      useAccessApi: () => ({ api: async () => { throw failure } })
    }, { 'vue': Vue, '#shared/utils/access': { apiFailure } }).default as { setup: (props: unknown, context: unknown) => { inspect: (event: Event) => Promise<void>, error: Vue.Ref<string> } }
    const scope = Vue.effectScope()
    const view = scope.run(() => component.setup({}, { expose() {} }))!
    try {
      await view.inspect({ target: { files: [{ size: 10, text: async () => '<xml/>' }], value: 'invoice.xml' } } as unknown as Event)
      assert.equal(view.error.value, 'Destinatário do XML não corresponde ao CNPJ da empresa.')
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
    const source = readFileSync(new URL('../app/composables/useErpCatalogList.ts', import.meta.url), 'utf8')
    const scope = Vue.effectScope()
    const module = evaluate(source, {
      ref: Vue.ref, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose, apiFailure,
      useAccessApi: () => ({ api: async (path: string) => path.includes('search=old') ? old.promise : { data: [{ name: 'current' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } } })
    }, { '#shared/utils/erpCatalogs': catalog })
    const useList = module.useErpCatalogList as (path: string) => { search: Vue.Ref<string>, state: Vue.Ref<{ data: { name: string }[] }>, refresh: () => Promise<void> }
    const state = scope.run(() => useList('/erp/products'))!
    try {
      state.search.value = 'old'
      await Vue.nextTick()
      state.search.value = 'new'
      await Vue.nextTick()
      await state.refresh()
      old.resolve({ data: [{ name: 'obsolete' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(state.state.value.data[0]?.name, 'current')
    } finally { scope.stop() }
  })
})
