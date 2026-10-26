import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { describe, it } from 'node:test'
import { createServer } from 'node:http'
import { createRequire } from 'node:module'
import { once } from 'node:events'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { renderToString } from 'vue/server-renderer'
import type { ErpReportExport, ErpReportSnapshot } from '../shared/types/erpReports.ts'
import * as nav from '../app/utils/erpModulesNav.ts'
import * as errors from '../app/composables/useApiError.ts'
import * as business from '../shared/utils/business.ts'
import * as erp from '../shared/utils/erp.ts'
import * as ingress from '../shared/utils/ingress.ts'
import type { Identity } from '../shared/types/access.ts'
import * as recovery from '../shared/utils/erpOperationRecovery.ts'
import * as admission from '../app/utils/accessApiAdmissionQueue.ts'

const filters = { date_from: '2026-10-01', date_to: '2026-10-31', erp_establishment_id: 7 }
const report = { category: 'sales', filters, totals: { document_count: 2, posted_total: '9007199254740993.1234' } }
const exported = { id: 21, report_type: 'sales', filters, status: 'completed', expires_at: '2099-10-18T00:00:00Z' }
interface ViewState {
  filters: Record<string, unknown>
  load: () => Promise<void>
  report: Vue.Ref<ErpReportSnapshot | null>
  loadError: Vue.Ref<string>
  establishments: Vue.Ref<{ id: number, name: string }[]>
  canRead: Vue.ComputedRef<boolean>
  canExport: Vue.ComputedRef<boolean>
  isEmpty: Vue.ComputedRef<boolean>
  createExport: () => Promise<void>
  inspect: () => Promise<void>
  download: () => Promise<void>
  exportRecord: Vue.Ref<ErpReportExport | null>
  ambiguous: Vue.Ref<boolean>
  reference: Vue.Ref<string>
  error: Vue.Ref<string>
  ready: Vue.Ref<boolean>
  recover: () => Promise<void>
  previousRecords: Vue.Ref<ErpReportExport[]>
}

function deferred() {
  let resolve!: (value: unknown) => void
  const promise = new Promise((done) => {
    resolve = done
  })
  return { promise, resolve }
}
type TestApi = (path: string, options?: { method?: string, body?: unknown, headers?: Record<string, string>, signal?: AbortSignal }) => Promise<unknown>

function memoryStorage() {
  const values = new Map<string, string>()
  return {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => { values.set(key, value) },
    removeItem: (key: string) => { values.delete(key) }
  }
}

function view(name: string, api: TestApi, initial: Record<string, unknown> = {}, storage = memoryStorage()) {
  const file = new URL(`../app/components/erp/${name}.vue`, import.meta.url)
  assert.ok(existsSync(file), `${name} must implement the real report workflow`)
  const { descriptor } = parse(readFileSync(file, 'utf8'))
  const script = compileScript(descriptor, { id: name })
  const output = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const mounted: Array<() => void> = []
  const user = Vue.ref({ id: 10 })
  const bindings = { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch,
    onMounted: (callback: () => void) => { mounted.push(callback) },
    onScopeDispose: Vue.onScopeDispose, useRoute: () => Vue.reactive({ hash: '' }), useAuth: () => ({ user, currentCompany: company, currentAccount: Vue.ref(null) }), sessionStorage: storage, useAccessApi: () => ({ api }), document: { createElement: () => ({ href: '', download: '', click() {
      downloads++
    } }) }, URL: { createObjectURL: () => 'blob:private', revokeObjectURL() {} } }
  const company = Vue.ref({ id: 1, permissions: ['*'], access_kind: 'direct', role: 'owner' })
  let downloads = 0
  const exports: { default?: { setup: (props: unknown, context: unknown) => ViewState } } = {}
  new Function('require', 'exports', ...Object.keys(bindings), output)((path: string) => path.startsWith('~/composables/useErp') && !path.endsWith('useApiError') ? loadAppComposable(path.slice(2) + '.ts', { ...bindings, useState: (_key: string, init: () => unknown) => Vue.ref(init()) }) : ({ 'vue': Vue, '~/utils/erpModulesNav': nav, '~/composables/useApiError': errors, '#shared/utils/erpOperationRecovery': recovery })[path], exports, ...Object.values(bindings))
  const scope = Vue.effectScope()
  const props = Vue.reactive({ category: 'sales', filters: { ...filters }, contextKey: 'company-1', canExport: true, ...initial })
  const state = scope.run(() => exports.default!.setup(props, { expose() {} }))!
  scope.run(() => mounted.forEach(callback => callback()))
  return { state, props, company, user, downloads: () => downloads, stop: () => scope.stop() }
}
const settle = async () => {
  await Vue.nextTick()
  await Vue.nextTick()
}

describe('authoritative report workspace', () => {
  it('renders current-position basis, aging, grouped currencies and linked compensation detail without coercing decimals', async () => {
    const { descriptor } = parse(readFileSync(new URL('../app/components/erp/ErpReportMetrics.vue', import.meta.url), 'utf8'))
    const script = compileScript(descriptor, { id: 'metrics-detail', inlineTemplate: true })
    const output = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports: { default?: Vue.Component } = {}
    new Function('require', 'exports', 'computed', output)((path: string) => path === 'vue' ? Vue : path === '~/components/erp/erpPresentation' ? loadAppComposable('components/erp/erpPresentation.ts') : undefined, exports, Vue.computed)
    const app = Vue.createSSRApp(exports.default!, { report: {
      category: 'financial', filters, generated_at: '2026-10-22T12:00:00+00:00', historical_position: 'unavailable',
      totals: { obligations_open_amount: '9007199254740993.1234' },
      ledgers: {
        obligations: { basis: 'current_position', position_at: '2026-10-22T12:00:00+00:00', timestamp_field: 'posted_at', period: null,
          row_count: 1, detail_truncated: false, detail_limit: 200, currency: 'BRL', status_basis: 'current_at_generation', status_observed_at: '2026-10-22T12:00:00+00:00',
          totals: { gross_original_due: '9007199254740993.1234', opposite_return_credit: '25.0000', net_exposure: '9007199254740968.1234', overdue: '40.0000', not_yet_due: '25.0000', undated: '0.0000', known_cost_by_currency: { BRL: '6.6667', USD: '9.0000' } },
          rows: [{ id: 12, direction: 'payable', due_on: '2026-10-10', days_overdue: 12, return_of_id: 11, source_type: 'erp_sale_return', source_id: 13, open_amount: '25.0000', total_amount: '42.1234' }]
        }
      }
    } })
    app.component('UCard', { setup: (_, { slots }) => () => Vue.h('section', [slots.header?.(), slots.default?.()]) })
    app.component('UTable', {
      props: ['data', 'columns'],
      setup: props => () => Vue.h('table', (props.data as Record<string, string>[]).map(row => Vue.h('tr', (props.columns as { accessorKey: string }[]).map(column => Vue.h('td', row[column.accessorKey])))))
    })
    app.component('UButton', { props: ['to', 'label'], setup: props => () => Vue.h('a', { href: props.to }, String(props.label)) })
    const html = await renderToString(app)
    assert.match(html, /Posição atual/)
    assert.match(html, /Status observado em/)
    assert.match(html, /href="\/erp\/vendas#returns"[^>]*>Devolução de venda #13/)
    assert.match(html, /2026-10-22T12:00:00\+00:00/)
    assert.match(html, /9007199254740993\.1234/)
    assert.match(html, /Crédito de devolução/)
    assert.match(html, /BRL[^<]*6\.6667/)
    assert.match(html, /USD[^<]*9\.0000/)
    assert.match(html, /<table\b[^>]*>.*25\.0000/s)
    assert.match(html, /<td>42\.1234<\/td>/)
    assert.match(html, /erp_sale_return.*13|Devolução de venda.*13/s)
    assert.match(html, /12/)
  })

  it('uses the shared ERP shell once and places report navigation in its toolbar', async () => {
    const { descriptor } = parse(readFileSync(new URL('../app/components/erp/ErpReportsWorkspace.vue', import.meta.url), 'utf8'))
    const script = compileScript(descriptor, { id: 'report-layout', inlineTemplate: true })
    const output = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const bindings = { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose, useRoute: () => ({ hash: '#finance' }), useAuth: () => ({ user: Vue.ref({ id: 10 }), currentAccount: Vue.ref(null), currentCompany: Vue.ref({ id: 1, permissions: ['*'], access_kind: 'direct', role: 'owner' }) }), useAccessApi: () => ({ api: async () => ({ data: [], meta: { last_page: 1 } }) }) }
    const exports: { default?: Vue.Component } = {}
    const imports: Record<string, unknown> = { 'vue': Vue, '~/utils/erpModulesNav': nav, '~/composables/useApiError': errors }
    new Function('require', 'exports', ...Object.keys(bindings), output)((path: string) => path.startsWith('~/composables/useErp') && !path.endsWith('useApiError') ? loadAppComposable(path.slice(2) + '.ts', { ...bindings, useState: (_key: string, init: () => unknown) => Vue.ref(init()) }) : imports[path], exports, ...Object.values(bindings))
    const app = Vue.createSSRApp(exports.default!)
    app.component('ErpCatalogPageShell', Vue.defineComponent({
      props: ['title', 'description'],
      setup(props, { slots }) {
        return () => Vue.h('section', { id: 'erp-relatorios' }, [
          Vue.h('header', [Vue.h('button', { 'aria-label': 'Abrir navegação e empresa' }, 'Navegação'), Vue.h('h1', String(props.title)), ...(slots.actions?.() ?? [])]),
          Vue.h('nav', slots.toolbar?.()),
          Vue.h('p', String(props.description)),
          ...(slots.default?.() ?? [])
        ])
      }
    }))
    app.component('UDashboardPanel', { setup: (_, { slots, attrs }) => () => Vue.h('section', attrs, [slots.header?.(), slots.body?.()]) })
    app.component('UDashboardNavbar', { props: ['title'], setup: (props, { slots }) => () => Vue.h('header', [slots.toggle?.(), slots.leading?.(), Vue.h('h1', String(props.title))]) })
    app.component('UDashboardSidebarToggle', { setup: (_, { attrs }) => () => Vue.h('button', attrs, 'Navegação') })
    app.component('USelect', { props: ['items', 'modelValue'], setup: (props) => {
      const items = props.items as Array<{ value: string }>
      assert.ok(items.every(item => item.value !== ''), 'Reka SelectItem reserves the empty value for clearing')
      assert.ok(items.some(item => item.value === props.modelValue), 'the all-records selection must match a valid item')
      return () => Vue.h('select')
    } })
    app.component('UNavigationMenu', Vue.defineComponent({
      props: ['items'],
      setup(props) {
        return () => Vue.h('div', (props.items as Array<Array<{ label: string, to: string }>>).flat().map(item => Vue.h('a', { href: item.to }, item.label)))
      }
    }))
    for (const name of ['UDashboardToolbar', 'UAlert', 'UFormField', 'UInput', 'UButton', 'ErrorRetryAlert', 'UEmpty', 'ErpReportMetrics', 'ErpReportExportPanel']) {
      app.component(name, { setup: (_, { slots }) => () => Vue.h('div', slots.default?.()) })
    }
    const markup = await renderToString(app)
    assert.match(markup, /<section id="erp-relatorios"/)
    assert.match(markup, /<h1>Relatórios<\/h1>/)
    assert.match(markup, /<button aria-label="Abrir navegação e empresa"/)
    assert.equal((markup.match(/<section id="erp-relatorios"/g) ?? []).length, 1)
    assert.match(markup, /<nav><div[^>]*>.*Vendas/s)
  })
  it('submits all report filters and preserves exact server totals on a refresh rejection', async (t) => {
    let fail = false
    let requested = ''
    const app = view('ErpReportsWorkspace', async (path) => {
      if (path.includes('/establishments')) return { data: [{ id: 7, name: 'Matriz' }], meta: { last_page: 1 } }
      requested = path
      if (fail) throw { statusCode: 422, data: { errors: { erp_establishment_id: ['Estabelecimento inválido'] } } }
      return { data: report }
    })
    t.after(app.stop)
    Object.assign(app.state.filters, filters)
    await app.state.load()
    assert.equal(requested, '/erp/reports/sales?date_from=2026-10-01&date_to=2026-10-31&erp_establishment_id=7&currency=BRL')
    assert.equal(app.state.report.value?.totals?.posted_total, '9007199254740993.1234')
    fail = true
    await app.state.load()
    assert.equal(app.state.report.value?.totals?.posted_total, '9007199254740993.1234')
    assert.match(app.state.loadError.value, /Estabelecimento inválido/)
  })
  it('never turns initial failure into an empty report and discards responses from old companies', async (t) => {
    const old = deferred()
    let fail = true
    const app = view('ErpReportsWorkspace', async (path) => {
      if (path.includes('/establishments')) return { data: [], meta: { last_page: 1 } }
      if (fail) throw { statusCode: 503, data: { message: 'Indisponível' } }
      return old.promise
    })
    t.after(app.stop)
    await app.state.load()
    assert.equal(app.state.report.value, null)
    assert.match(app.state.loadError.value, /Indisponível/)
    fail = false
    const pending = app.state.load()
    app.company.value = { ...app.company.value, id: 2, permissions: [] }
    old.resolve({ data: report })
    await pending
    assert.equal(app.state.report.value, null)
    assert.equal(app.state.establishments.value.length, 0)
  })
  it('shows consultation but no export creation for delegated readers and viewers', async (t) => {
    const app = view('ErpReportsWorkspace', async path => path.includes('/establishments') ? { data: [], meta: { last_page: 1 } } : { data: report })
    t.after(app.stop)
    app.company.value = { ...app.company.value, access_kind: 'delegated', permissions: ['erp.read'] }
    assert.equal(app.state.canRead.value, true)
    assert.equal(app.state.canExport.value, false)
    app.company.value = { ...app.company.value, access_kind: 'direct', role: 'viewer' }
    assert.equal(app.state.canExport.value, false)
    app.company.value = { ...app.company.value, role: 'owner', permissions: ['erp.reports.*'] }
    assert.equal(app.state.canRead.value, true)
    assert.equal(app.state.canExport.value, false)
  })
})

describe('report activity completeness', () => {
  it('does not call a report empty when returns or cash activity exist outside zero headline totals', async (t) => {
    const app = view('ErpReportsWorkspace', async path => path.includes('/establishments')
      ? { data: [], meta: { last_page: 1 } }
      : { data: { category: 'sales', filters, totals: { document_count: 0, posted_total: '0.0000' }, ledgers: {
          documents: { basis: 'posted_activity', row_count: 0 }, returns: { basis: 'posted_activity', row_count: 1 }
        } } })
    t.after(app.stop)
    await app.state.load()
    assert.equal(app.state.isEmpty.value, false)
  })
})

describe('private report exports', () => {
  it('fails closed before POST when tab storage cannot preserve the request identity', async (t) => {
    let posts = 0
    const app = view('ErpReportExportPanel', async () => {
      posts++
      return { data: exported }
    }, {}, { getItem: () => null, removeItem() {}, setItem() { throw new Error('blocked storage') } })
    t.after(app.stop)
    await app.state.createExport()
    assert.equal(posts, 0)
    assert.match(app.state.error.value, /preservar a identificação/)
  })
  it('blocks duplicate POSTs and downloads only a freshly verified completed snapshot', async (t) => {
    const pending = deferred()
    let posts = 0
    let gets = 0
    const app = view('ErpReportExportPanel', async (_path, options) => {
      if (options?.method === 'POST') {
        posts++
        return pending.promise
      }
      gets++
      return { data: { export: exported, report } }
    })
    t.after(app.stop)
    const request = app.state.createExport()
    await app.state.createExport()
    assert.equal(posts, 1)
    pending.resolve({ data: exported })
    await request
    assert.equal(app.downloads(), 0)
    await app.state.download()
    assert.equal(gets, 1)
    assert.equal(app.downloads(), 1)
  })
  it('keeps a pending export locked while allowing read-only inspection', async (t) => {
    let posts = 0
    const app = view('ErpReportExportPanel', async (_path, options) => {
      if (options?.method === 'POST') {
        posts++
        return { data: { ...exported, status: 'pending' } }
      }
      throw { statusCode: 409, data: { message: 'Ainda pendente', data: { ...exported, status: 'pending' } } }
    })
    t.after(app.stop)
    await app.state.createExport()
    await app.state.createExport()
    assert.equal(posts, 1)
    await app.state.inspect()
    assert.equal(app.state.exportRecord.value?.status, 'pending')
    assert.equal(app.downloads(), 0)
  })
  it('retains failed intent and inputs and allows inspection of the failure without downloading', async (t) => {
    const failure = { statusCode: 503, data: { code: 'export_artifact_invalid', message: 'Exportação indisponível', data: { ...exported, status: 'failed', failure_code: 'export_artifact_invalid' } } }
    const app = view('ErpReportExportPanel', async () => {
      throw failure
    })
    t.after(app.stop)
    await app.state.createExport()
    assert.equal(app.state.exportRecord.value?.id, 21)
    assert.equal(app.state.exportRecord.value?.status, 'failed')
    assert.deepEqual(app.props.filters, filters)
    await app.state.inspect()
    assert.match(app.state.error.value, /Exportação indisponível/)
    await app.state.download()
    assert.equal(app.downloads(), 0)
  })
  it('locks ambiguous POST failure against blind retry and supports explicit reference inspection', async (t) => {
    let posts = 0
    let inspected = { export: exported, report }
    const app = view('ErpReportExportPanel', async (_path, options) => {
      if (options?.method === 'POST') {
        posts++
        throw new TypeError('network lost')
      }
      return { data: inspected }
    })
    t.after(app.stop)
    await app.state.createExport()
    await app.state.createExport()
    assert.equal(posts, 1)
    assert.equal(app.state.ambiguous.value, true)
    app.state.reference.value = '21'
    await app.state.inspect()
    assert.equal(app.state.exportRecord.value?.id, 21)
    assert.equal(app.downloads(), 0)
    assert.equal(app.state.ambiguous.value, true, 'An inspected reference cannot identify the lost POST, even when filters match')
    await app.state.createExport()
    assert.equal(posts, 1, 'Inspecting an older export must not enable a second POST')
    const unrelatedFilters = { ...filters, erp_establishment_id: 9 }
    inspected = { export: { ...exported, id: 22, report_type: 'purchases', filters: unrelatedFilters }, report: { ...report, category: 'purchases', filters: unrelatedFilters } }
    app.state.reference.value = '22'
    await app.state.inspect()
    assert.equal(app.state.exportRecord.value?.id, 22)
    assert.equal(app.state.error.value, '')
    await app.state.createExport()
    assert.equal(posts, 1, 'A different category/filter lookup must leave the original intent unresolved')
    assert.equal(app.state.ambiguous.value, true)
  })
  for (const invalidation of ['company switch', 'disposal']) {
    it(`does not dispatch an old POST after delayed real CSRF and ${invalidation}`, async (t) => {
      const csrfSeen = deferred()
      let releaseCsrf!: () => void
      let csrfRequests = 0
      const posts: { company: string | undefined, body: string }[] = []
      const server = createServer((request, response) => {
        response.setHeader('Content-Type', 'application/json')
        if (request.url === '/api/v1/csrf') {
          csrfRequests++
          const respond = () => response.end(JSON.stringify({ csrf_token: 'test-csrf' }))
          if (csrfRequests === 1) {
            releaseCsrf = respond
            csrfSeen.resolve(null)
          } else respond()
          return
        }
        let body = ''
        request.on('data', (chunk) => {
          body += chunk
        })
        request.on('end', () => {
          posts.push({ company: request.headers['x-company-id'] as string | undefined, body })
          response.end(JSON.stringify({ data: exported }))
        })
      })
      server.listen(0, '127.0.0.1')
      await once(server, 'listening')
      t.after(() => {
        server.closeAllConnections()
        server.close()
      })
      const address = server.address()
      assert.ok(address && typeof address !== 'string')
      const require = createRequire(import.meta.resolve('nuxt/package.json'))
      const { $fetch } = require('ofetch') as { $fetch: { create: (options: { baseURL: string }) => TestApi } }
      const request = $fetch.create({ baseURL: `http://127.0.0.1:${address.port}` })
      const company = { id: 1, name: 'Company one', status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'], delegated_office_id: null } as const
      const identity = Vue.ref<Identity>({ id: 1, name: 'Actor', email: 'actor@example.test', is_super_admin: false, accounts: [], current_account: null, companies: [{ ...company, permissions: ['*'] }], current_company: { ...company, permissions: ['*'] } })
      const states = new Map<string, Vue.Ref<unknown>>([['access-user', identity]])
      const nuxtApp = {}
      const bindings = {
        useNuxtApp: () => nuxtApp,
        useRequestFetch: () => request,
        useRequestEvent: () => null,
        useState: (key: string, initial: () => unknown) => {
          if (!states.has(key)) states.set(key, Vue.ref(initial()))
          return states.get(key)
        }
      }
      const source = readFileSync(new URL('../app/composables/useAccessApi.ts', import.meta.url), 'utf8').replaceAll('import.meta.server', 'false')
      const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
      const module: { useAccessApi?: () => { api: TestApi } } = {}
      const imports: Record<string, unknown> = { '#shared/utils/business': business, '#shared/utils/erp': erp, '#shared/utils/ingress': ingress, '~/utils/accessApiAdmissionQueue': admission }
      new Function('require', 'exports', ...Object.keys(bindings), output)((path: string) => imports[path], module, ...Object.values(bindings))
      const transport = module.useAccessApi!()
      const app = view('ErpReportExportPanel', transport.api, { filters: { date_from: '2026-10-01', date_to: '2026-10-31', erp_establishment_id: null } })
      t.after(app.stop)
      const pending = app.state.createExport()
      await csrfSeen.promise
      const nextCompany = { ...company, id: 2, name: 'Company two', permissions: ['*'] }
      identity.value = { ...identity.value, companies: [nextCompany], current_company: nextCompany }
      if (invalidation === 'company switch') {
        app.company.value = { ...app.company.value, id: 2 }
        app.props.contextKey = 'company-2'
      } else app.stop()
      releaseCsrf()
      await pending
      assert.equal(csrfRequests, 1, 'The old intent only bootstrapped CSRF once')
      assert.deepEqual(posts, [], 'No old intent may reach the real HTTP server under the next company')
      assert.equal(app.state.exportRecord.value, null)
      if (invalidation === 'company switch') {
        await app.state.createExport()
        assert.equal(csrfRequests, 2, 'The current company bootstraps a fresh token after the stale response')
        assert.equal(posts.length, 1, 'The current context gets a fresh, usable cancellation signal')
        assert.equal(posts[0]?.company, '2')
        assert.equal(JSON.parse(posts[0]!.body).erp_establishment_id, null)
      }
    })
  }
  it('rechecks server authorization and expiry before exposing a download', async (t) => {
    let result: unknown = { data: { export: exported, report } }
    let reject = false
    const app = view('ErpReportExportPanel', async (_path, options) => {
      if (options?.method === 'POST') return { data: exported }
      if (reject) throw { statusCode: 403, data: { message: 'Permissão revogada' } }
      return result
    })
    t.after(app.stop)
    await app.state.createExport()
    reject = true
    await app.state.download()
    assert.equal(app.downloads(), 0)
    assert.match(app.state.error.value, /Permissão revogada/)
    reject = false
    result = { data: { export: { ...exported, expires_at: '2020-01-01T00:00:00Z' }, report } }
    await app.state.download()
    assert.equal(app.downloads(), 0)
    assert.match(app.state.error.value, /expirada/)
  })
  it('explains actual generic HTTP410 expiry and retains the original reference until deliberate new generation', async (t) => {
    const storage = memoryStorage()
    const keys: string[] = []
    let expired = false
    const api: TestApi = async (_path, options) => {
      if (options?.method === 'POST') {
        keys.push(options.headers?.['Idempotency-Key'] ?? '')
        return { data: { ...exported, id: keys.length === 1 ? 21 : 22 } }
      }
      if (expired) throw {
        statusCode: 410,
        response: { status: 410, headers: new Headers({ 'Cache-Control': 'no-store', 'code': 'export_expired' }) },
        data: { code: 'service_unavailable', message: 'Serviço temporariamente indisponível. Tente novamente.' }
      }
      return { data: { export: exported, report } }
    }
    const app = view('ErpReportExportPanel', api, {}, storage)
    t.after(app.stop)
    await app.state.createExport()
    await app.state.download()
    assert.equal(app.state.ready.value, true)
    assert.equal(app.downloads(), 1)
    const preserved = storage.getItem('opmoni:erp-report-export:v1:10:1')
    expired = true
    await app.state.download()
    assert.equal(app.state.ready.value, false, 'An expired protected GET clears the previous verified-file notice')
    assert.equal(app.downloads(), 1, 'The expired request must not trigger another browser download')
    assert.equal(app.state.reference.value, '21')
    assert.deepEqual(app.state.exportRecord.value, exported, 'Expiry must not rewrite the historical acknowledgement')
    assert.equal(storage.getItem('opmoni:erp-report-export:v1:10:1'), preserved, 'Expiry retains the original key, reference and history')
    assert.equal(keys.length, 1, 'Expiry never dispatches another POST')
    assert.match(app.state.error.value, /exportação expirou/i)
    assert.match(app.state.error.value, /referência original.*preservada/i)
    assert.match(app.state.error.value, /Gerar nova exportação/)
    assert.match(app.state.error.value, /nova geração/i)
    assert.match(app.state.error.value, /não.*nova tentativa.*arquivo expirado/i)
    assert.doesNotMatch(app.state.error.value, /Serviço temporariamente indisponível/)
    await app.state.inspect()
    assert.equal(keys.length, 1, 'Inspecting the expired reference is read-only')
    assert.equal(app.downloads(), 1)
    app.stop()
    const restored = view('ErpReportExportPanel', api, {}, storage)
    t.after(restored.stop)
    assert.equal(restored.state.reference.value, '21')
    assert.equal(restored.state.exportRecord.value?.id, 21)
    assert.equal(restored.state.ready.value, false)
    assert.equal(keys.length, 1, 'Reload preserves the original reference without generating again')
    await restored.state.createExport()
    assert.equal(keys.length, 2, 'Only the separate deliberate new-generation action submits a second POST')
    assert.ok(keys[0])
    assert.notEqual(keys[0], keys[1])
    assert.equal(restored.state.exportRecord.value?.id, 22)
    assert.deepEqual(restored.state.previousRecords.value, [exported])
    assert.equal(restored.state.ready.value, false)
    assert.equal(restored.downloads(), 0)
    assert.ok(!storage.getItem('opmoni:erp-report-export:v1:10:1')?.includes(report.totals.posted_total), 'Report contents must not be persisted')
  })
  it('rejects empty/partial artifacts and download failures and invalidates late export responses', async (t) => {
    const old = deferred()
    let response: unknown = { data: exported }
    let downloadSignal: AbortSignal | undefined
    const app = view('ErpReportExportPanel', async (_path, options) => {
      if (options?.method === 'POST') return { data: exported }
      downloadSignal = options?.signal
      return response
    })
    t.after(app.stop)
    await app.state.createExport()
    for (const partial of [null, {}, { ...report, totals: {} }, { ...report, totals: { document_count: 2 } }, { ...report, totals: { document_count: 2, posted_total: 12.34 } }]) {
      response = { data: { export: exported, report: partial } }
      await app.state.download()
      assert.equal(app.downloads(), 0)
      assert.ok(app.state.error.value)
    }
    response = old.promise
    const downloading = app.state.download()
    app.props.contextKey = 'company-2'
    assert.equal(downloadSignal?.aborted, true, 'The protected GET is also cancelled on context change')
    await settle()
    old.resolve({ data: { export: exported, report } })
    await downloading
    assert.equal(app.state.exportRecord.value, null)
    assert.equal(app.downloads(), 0)
  })

  it('persists frozen actor/company/key/category and user filters before POST and recovers after a lost response and reload', async (t) => {
    const storage = memoryStorage()
    const sent: Array<{ path: string, body: unknown, key: string | undefined }> = []
    const first = view('ErpReportExportPanel', async (path, options) => {
      sent.push({ path, body: options?.body, key: options?.headers?.['Idempotency-Key'] })
      assert.ok(storage.getItem('opmoni:erp-report-export:v1:10:1'), 'Recovery identity must be durable before dispatch')
      throw new TypeError('lost response')
    }, { filters: { ...filters, authorized_establishment_ids: [7], authorized_companywide_finance: true, toString: 'not-a-user-filter' } }, storage)
    await first.state.createExport()
    first.stop()
    let lookups = 0
    const restored = view('ErpReportExportPanel', async (path, options) => {
      assert.equal(options?.method, undefined, 'Reload and recovery never repeat POST')
      assert.equal(path, '/erp/operations/lookup?operation=erp.reports.exports.store')
      assert.equal(options?.headers?.['Idempotency-Key'], sent[0]?.key)
      lookups++
      if (lookups === 1) throw { statusCode: 404, data: { code: 'operation_not_recorded' } }
      return { data: { state: 'completed', result: exported } }
    }, { category: 'purchases', filters: { currency: 'USD' } }, storage)
    t.after(restored.stop)
    assert.equal(restored.state.ambiguous.value, true)
    await restored.state.createExport()
    await restored.state.recover()
    assert.equal(restored.state.ambiguous.value, true)
    assert.ok(storage.getItem('opmoni:erp-report-export:v1:10:1'))
    await restored.state.createExport()
    await restored.state.recover()
    assert.equal(restored.state.ambiguous.value, false)
    assert.equal(restored.state.exportRecord.value?.id, 21)
    assert.equal(sent.length, 1)
    assert.match(sent[0]!.key!, /^[0-9a-f-]{36}$/i)
    assert.deepEqual(sent[0]?.body, filters, 'Server authorization scope never becomes a user filter')
  })

  it('preserves the newer reference when inspecting an older known failure and reloading', async (t) => {
    const storage = memoryStorage()
    let posts = 0
    const app = view('ErpReportExportPanel', async (_path, options) => {
      if (options?.method === 'POST') {
        posts++
        if (posts === 1) throw { statusCode: 503, data: { data: { ...exported, status: 'failed' } } }
        return { data: { ...exported, id: 22 } }
      }
      throw { statusCode: 409, data: { data: { ...exported, status: 'failed' } } }
    }, {}, storage)
    await app.state.createExport()
    await app.state.createExport()
    app.state.reference.value = '21'
    await app.state.inspect()
    app.stop()
    const restored = view('ErpReportExportPanel', async () => {
      throw new Error('must not send POST')
    }, {}, storage)
    t.after(restored.stop)
    assert.equal(restored.state.exportRecord.value?.id, 21)
    assert.ok(restored.state.previousRecords.value.some(item => item.id === 22), 'Inspecting a failure must not discard a newer export reference')
  })

  it('keeps a known failed reference when deliberately generating a new key and never leaks recovery across actors', async (t) => {
    const storage = memoryStorage()
    const keys: string[] = []
    const app = view('ErpReportExportPanel', async (_path, options) => {
      keys.push(options?.headers?.['Idempotency-Key'] ?? '')
      if (keys.length === 1) throw { statusCode: 503, data: { data: { ...exported, status: 'failed', failure_code: 'export_storage_unavailable' } } }
      return { data: { ...exported, id: 22 } }
    }, {}, storage)
    t.after(app.stop)
    await app.state.createExport()
    await app.state.createExport()
    assert.equal(app.state.previousRecords.value[0]?.id, 21)
    assert.equal(app.state.previousRecords.value[0]?.status, 'failed')
    assert.ok(keys[0])
    assert.notEqual(keys[0], keys[1])
    app.user.value = { id: 11 }
    await settle()
    assert.equal(app.state.exportRecord.value, null)
    assert.deepEqual(app.state.previousRecords.value, [])
    assert.equal(app.state.ambiguous.value, false)
  })

  it('compares structured normalized scope by value and accepts explicit unavailable stock valuation', async (t) => {
    const scoped = { ...filters, authorized_establishment_ids: [7], authorized_establishment_scope: 'selected' }
    const metadata = { ...exported, report_type: 'stock', filters: scoped }
    const stock = { category: 'stock', filters: structuredClone(scoped), totals: { balance_lines: 1, quantity_on_hand: '4.0000', inventory_value: null } }
    const app = view('ErpReportExportPanel', async (_path, options) => options?.method === 'POST' ? { data: metadata } : { data: { export: metadata, report: stock } })
    t.after(app.stop)
    await app.state.createExport()
    await app.state.download()
    assert.equal(app.downloads(), 1)
  })
})
