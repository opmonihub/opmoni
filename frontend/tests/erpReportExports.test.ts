import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { describe, it } from 'node:test'
import { createServer } from 'node:http'
import { createRequire } from 'node:module'
import { once } from 'node:events'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { renderToString } from 'vue/server-renderer'
import type { ErpReportExport, ErpReportSnapshot } from '../shared/types/erpReports.ts'
import * as nav from '../app/utils/erpModulesNav.ts'
import * as errors from '../app/composables/useApiError.ts'
import * as business from '../shared/utils/business.ts'
import * as erp from '../shared/utils/erp.ts'
import type { Identity } from '../shared/types/access.ts'

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
  createExport: () => Promise<void>
  inspect: () => Promise<void>
  download: () => Promise<void>
  exportRecord: Vue.Ref<ErpReportExport | null>
  ambiguous: Vue.Ref<boolean>
  reference: Vue.Ref<string>
  error: Vue.Ref<string>
}

function deferred() {
  let resolve!: (value: unknown) => void
  const promise = new Promise((done) => {
    resolve = done
  })
  return { promise, resolve }
}
type TestApi = (path: string, options?: { method?: string, body?: unknown, signal?: AbortSignal }) => Promise<unknown>

function view(name: string, api: TestApi, initial: Record<string, unknown> = {}) {
  const file = new URL(`../app/components/erp/${name}.vue`, import.meta.url)
  assert.ok(existsSync(file), `${name} must implement the real report workflow`)
  const { descriptor } = parse(readFileSync(file, 'utf8'))
  const script = compileScript(descriptor, { id: name })
  const output = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const bindings = { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted() {}, onScopeDispose: Vue.onScopeDispose, useRoute: () => Vue.reactive({ hash: '' }), useAuth: () => ({ currentCompany: company }), useAccessApi: () => ({ api }), document: { createElement: () => ({ href: '', download: '', click() {
    downloads++
  } }) }, URL: { createObjectURL: () => 'blob:private', revokeObjectURL() {} } }
  const company = Vue.ref({ id: 1, permissions: ['*'], access_kind: 'direct', role: 'owner' })
  let downloads = 0
  const exports: { default?: { setup: (props: unknown, context: unknown) => ViewState } } = {}
  new Function('require', 'exports', ...Object.keys(bindings), output)((path: string) => ({ 'vue': Vue, '~/utils/erpModulesNav': nav, '~/composables/useApiError': errors })[path], exports, ...Object.values(bindings))
  const scope = Vue.effectScope()
  const props = Vue.reactive({ category: 'sales', filters: { ...filters }, contextKey: 'company-1', canExport: true, ...initial })
  const state = scope.run(() => exports.default!.setup(props, { expose() {} }))!
  return { state, props, company, downloads: () => downloads, stop: () => scope.stop() }
}
const settle = async () => {
  await Vue.nextTick()
  await Vue.nextTick()
}

describe('authoritative report workspace', () => {
  it('renders its own report panel, heading and accessible mobile navigation', async () => {
    const { descriptor } = parse(readFileSync(new URL('../app/components/erp/ErpReportsWorkspace.vue', import.meta.url), 'utf8'))
    const script = compileScript(descriptor, { id: 'report-layout', inlineTemplate: true })
    const output = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const bindings = { ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose, useRoute: () => ({ hash: '' }), useAuth: () => ({ currentCompany: Vue.ref(null) }), useAccessApi: () => ({ api: async () => ({}) }) }
    const exports: { default?: Vue.Component } = {}
    const imports: Record<string, unknown> = { 'vue': Vue, '~/utils/erpModulesNav': nav, '~/composables/useApiError': errors }
    new Function('require', 'exports', ...Object.keys(bindings), output)((path: string) => imports[path], exports, ...Object.values(bindings))
    const app = Vue.createSSRApp(exports.default!)
    app.component('UDashboardPanel', { setup: (_, { slots, attrs }) => () => Vue.h('section', attrs, [slots.header?.(), slots.body?.()]) })
    app.component('UDashboardNavbar', { props: ['title'], setup: (props, { slots }) => () => Vue.h('header', [Vue.h('h1', String(props.title)), slots.leading?.()]) })
    app.component('UDashboardSidebarToggle', { setup: (_, { attrs }) => () => Vue.h('button', attrs, 'Navegação') })
    for (const name of ['UDashboardToolbar', 'UNavigationMenu', 'UAlert', 'UFormField', 'UInput', 'USelect', 'UButton', 'ErrorRetryAlert', 'UEmpty', 'ErpReportMetrics', 'ErpReportExportPanel']) {
      app.component(name, { setup: (_, { slots }) => () => Vue.h('div', slots.default?.()) })
    }
    const markup = await renderToString(app)
    assert.match(markup, /<section id="erp-relatorios"/)
    assert.match(markup, /<h1>Relatórios<\/h1>/)
    assert.match(markup, /<button aria-label="Abrir navegação e empresa"/)
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

describe('private report exports', () => {
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
      const posts: { company: string | undefined, body: string }[] = []
      const server = createServer((request, response) => {
        response.setHeader('Content-Type', 'application/json')
        if (request.url === '/api/v1/csrf') {
          releaseCsrf = () => response.end(JSON.stringify({ csrf_token: 'test-csrf' }))
          csrfSeen.resolve(null)
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
      const bindings = {
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
      const imports: Record<string, unknown> = { '#shared/utils/business': business, '#shared/utils/erp': erp }
      new Function('require', 'exports', ...Object.keys(bindings), output)((path: string) => imports[path], module, ...Object.values(bindings))
      const transport = module.useAccessApi!()
      const app = view('ErpReportExportPanel', transport.api, { filters: { date_from: '2026-10-01', date_to: '2026-10-31', erp_establishment_id: null } })
      t.after(app.stop)
      const pending = app.state.createExport()
      await csrfSeen.promise
      const nextCompany = { ...company, id: 2, name: 'Company two', permissions: ['*'] }
      identity.value = { ...identity.value, companies: [nextCompany], current_company: nextCompany }
      if (invalidation === 'company switch') app.props.contextKey = 'company-2'
      else app.stop()
      releaseCsrf()
      await pending
      assert.deepEqual(posts, [], 'No old intent may reach the real HTTP server under the next company')
      assert.equal(app.state.exportRecord.value, null)
      if (invalidation === 'company switch') {
        await app.state.createExport()
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
})
