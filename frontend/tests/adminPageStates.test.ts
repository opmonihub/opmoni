import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { readFileSync } from 'node:fs'
import { it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
// Compiled SFC setup returns dynamic bindings; the runtime harness exercises their actual values.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Runtime = any
const require = createRequire(import.meta.url)
function deferred() {
  let resolve!: (value: unknown) => void
  let reject!: (value: unknown) => void
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
const flush = async () => {
  await Promise.resolve()
  await Vue.nextTick()
  await Promise.resolve()
}
function session() {
  const values = new Map<string, Vue.Ref>()
  const useState = (key: string, initial: () => unknown) => {
    if (!values.has(key)) values.set(key, Vue.ref(initial()))
    return values.get(key)!
  }
  return { values, useState }
}
async function page(name: string, api: (...args: Runtime[]) => Promise<Runtime>, routePath = '/admin/contas', props: Record<string, Runtime> = {}, app = session()) {
  const navigation = require('../app/composables/useListNavigationState.ts')
  Object.assign(globalThis, { useState: app.useState, useListNavigationState: navigation.useListNavigationState, listNavigationField: navigation.listNavigationField })
  const path = name === 'commercial' ? 'components/platform/AccountCommercialModal.vue' : name === 'shell' ? 'pages/admin.vue' : name === 'layout' ? 'layouts/default.vue' : `pages/admin/${name}.vue`
  const { descriptor } = parse(readFileSync(new URL(`../app/${path}`, import.meta.url), 'utf8'), { filename: path })
  const script = compileScript(descriptor, { id: name })
  const template = compileTemplate({ source: descriptor.template!.content, filename: path, id: name, compilerOptions: { bindingMetadata: script.bindings } })
  const emitted: string[] = []
  const mounted: (() => unknown)[] = []
  const unmounted: (() => unknown)[] = []
  const globals = {
    useRoute: () => ({ path: routePath }),
    useListNavigationState: navigation.useListNavigationState, listNavigationField: navigation.listNavigationField, useListNavigationRegistry: navigation.useListNavigationRegistry, useAdminReadAuthority: undefined as Runtime,
    ref: Vue.ref, computed: Vue.computed, reactive: Vue.reactive, watch: Vue.watch, definePageMeta() {
    }, useNuxtApp: () => ({ $api: api }), useAccessApi: () => ({ api }),
    useAuth: () => ({ user: Vue.ref({ is_super_admin: true }), currentAccount: Vue.ref({ id: 1, status: 'active', role: 'user' }), canManageMembers: Vue.ref(true), accounts: Vue.ref([{ id: 1, status: 'active', role: 'user' }]), switchAccount: api }),
    useToast: () => ({ add() {
    } }), refDebounced: (value: unknown) => value,
    useAccountFilters: undefined as Runtime,
    useRetryableLoad: (options: Runtime) => ({ showError: Vue.computed(() => !!options.error.value), isLoading: options.loading, retry: options.refresh }),
    onMounted: (fn: () => unknown) => mounted.push(fn), onBeforeUnmount: (fn: () => unknown) => unmounted.push(fn)
  }
  function evaluate(source: string) {
    const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext } }).outputText
    const exports: Runtime = {}
    new Function('require', 'exports', ...Object.keys(globals), compiled)((name: string) => {
      if (name === 'vue')
        return { ...Vue, useModel: (props: Runtime, name: string) => Vue.toRef(props, name), renderSlot: (slots: Record<string, () => Vue.VNode[]>, name: string) => Vue.h(Vue.Fragment, slots[name]?.() ?? []), resolveComponent: (name: string) => ({ name }) }
      if (name.endsWith('.vue'))
        return {}
      if (name.startsWith('~/'))
        return require(fileURLToPath(new URL(`../app/${name.slice(2)}.ts`, import.meta.url)))
      if (name.startsWith('#shared/'))
        return require(fileURLToPath(new URL(`../shared/${name.slice(8)}.ts`, import.meta.url)))
      return require(name)
    }, exports, ...Object.values(globals))
    return exports
  }
  globals.useAdminReadAuthority = evaluate(readFileSync(new URL('../app/composables/useAdminReadAuthority.ts', import.meta.url), 'utf8')).useAdminReadAuthority
  globals.useAccountFilters = evaluate(readFileSync(new URL('../app/composables/useAccountFilters.ts', import.meta.url), 'utf8')).useAccountFilters
  const scope = Vue.effectScope()
  const state = scope.run(() => evaluate(script.content).default.setup(props, { emit: (name: string) => emitted.push(name), expose() {
  } })) as Runtime
  const render = evaluate(template.code).render
  function nodes(vnode: Vue.VNode): Vue.VNode[] {
    const result = [vnode]
    if (Array.isArray(vnode.children))
      for (const child of vnode.children) {
        if (Vue.isVNode(child))
          result.push(...nodes(child))
      }
    else if (vnode.children && typeof vnode.children === 'object')
      for (const [slotName, slot] of Object.entries(vnode.children)) {
        if (typeof slot === 'function' && !slotName.endsWith('-cell')) {
          const children = slot({ close() {
          } })
          for (const child of Array.isArray(children) ? children : [children])
            if (Vue.isVNode(child))
              result.push(...nodes(child))
        }
      }
    return result
  }
  return { state, emitted, start: async () => {
    await Promise.all(mounted.map(fn => fn()))
    await flush()
  }, nodes: () => nodes(render({ $slots: { default: () => [] } }, [], props, Vue.proxyRefs(state), {}, {})), stop: () => {
    unmounted.forEach(fn => fn())
    scope.stop()
  } }
}
const resource = (data: unknown[], total = data.length, last = 1) => ({ data, meta: { current_page: 1, page: 1, last_page: last, per_page: 15, total } })
it('summary begins pending, consumes one summary endpoint and keeps data beside refresh failure', async () => {
  const calls: string[] = []
  let fail = false
  const view = await page('index', async (path) => {
    calls.push(path)
    if (fail)
      throw Error('offline')
    return { data: { totals: { accounts: 7, plans: 2, subscriptions: 6, users: 8 }, recent_support_logs: [] } }
  })
  assert.equal(view.state.loading.value, true)
  await view.start()
  assert.deepEqual(calls, ['/admin/summary'])
  assert.equal(view.state.totals.value.accounts, 7)
  fail = true
  await view.state.load()
  assert.equal(view.state.totals.value.accounts, 7)
  assert.ok(view.nodes().some(node => (node.type as Runtime)?.name === 'ErrorRetryAlert'))
  assert.ok(view.nodes().some(node => (node.type as Runtime)?.name === 'MetricCard'))
  view.stop()
})
for (const name of ['usuarios', 'assinaturas'])
  it(`${name} retains list, resets filter page, recovers last page and rejects stale errors`, async () => {
    let response: Runtime = resource([{ id: 1, name: 'first' }], 31, 3)
    const calls: Runtime[] = []
    const view = await page(name, async (path, options) => {
      calls.push([path, options])
      if (path === '/admin/plans')
        return [{ id: 1, name: 'Basic', slug: 'basic' }]
      return await response
    })
    await view.start()
    const data = name === 'usuarios' ? view.state.users : view.state.subscriptions
    const old = deferred()
    response = old.promise
    const first = view.state.load()
    response = resource([{ id: 2 }])
    await view.state.load()
    old.reject(Error('old'))
    await first
    assert.equal(data.value[0].id, 2)
    assert.equal(view.state.loadError.value, null)
    response = Promise.reject(Error('refresh'))
    await view.state.load()
    assert.equal(data.value[0].id, 2)
    assert.ok(view.nodes().some(node => (node.type as Runtime)?.name === 'UTable'))
    assert.ok(view.nodes().some(node => (node.type as Runtime)?.name === 'ErrorRetryAlert'))
    response = resource([], 1, 1)
    view.state.page.value = 3
    await flush()
    await flush()
    assert.equal(view.state.page.value, 1)
    view.state.page.value = 2
    await flush()
    view.state.q.value = 'new'
    await flush()
    assert.equal(view.state.page.value, 1)
    view.stop()
  })
it('support uses one numeric detail and one text page, guards entry and rejects old audit results', async () => {
  const calls: Runtime[] = []
  let logs: Runtime = resource([{ id: 1 }])
  const accountResponse: Runtime = resource([{ id: 2, name: 'Old' }], 100, 7)
  const view = await page('suporte', async (path, options) => {
    calls.push([path, options])
    if (path.includes('logs'))
      return await logs
    if (path === '/admin/accounts/5')
      return { data: { id: 5, status: 'active' } }
    return await accountResponse
  })
  await view.start()
  view.state.query.value = '5'
  await view.state.search()
  assert.equal(calls.filter(([path]) => path === '/admin/accounts/5').length, 1)
  view.state.query.value = 'Name'
  await view.state.search()
  assert.equal(calls.filter(([path]) => path === '/admin/accounts').length, 1)
  assert.equal(view.state.canEnter({ id: 1, status: 'suspended' }), false)
  const old = deferred()
  logs = old.promise
  const first = view.state.loadLogs()
  logs = resource([{ id: 2 }])
  await view.state.loadLogs()
  old.resolve(resource([{ id: 3 }]))
  await first
  assert.equal(view.state.logs.value[0].id, 2)
  const before = calls.length
  view.state.accountQuery.value = 'bad'
  await flush()
  await view.state.loadLogs()
  assert.equal(calls.length, before)
  assert.ok(view.state.loadLogsError.value)
  view.stop()
})
it('plans submit explicit nullable limits once while busy and preserve invalid input', async () => {
  const writes: Runtime[] = []
  const pending = deferred()
  const view = await page('planos', async (_path, options) => {
    if (options?.method) {
      writes.push(options.body)
      return pending.promise
    }
    ;
    return []
  })
  await view.start()
  view.state.openEdit({ id: 1, name: 'Basic', limits: { users: 5 } })
  const first = view.state.onSave({ data: { name: 'Basic', users: null, clients: 0, monitorings: null } })
  const second = view.state.onSave({ data: { name: 'Basic', users: null, clients: 0, monitorings: null } })
  assert.deepEqual(writes, [{ name: 'Basic', limits: { users: null, clients: 0, monitorings: null }, erp_commercial: { enabled: false, licence_limit: 0, module_keys: [] } }])
  pending.reject({ data: { errors: { name: ['Invalid'] } } })
  await Promise.all([first, second])
  assert.equal(view.state.editState.name, 'Basic')
  assert.equal(view.state.editOpen.value, true)
  view.stop()
})
it('test_resale_editor_sends_explicit_allowance_and_legacy_limits', async () => {
  const writes: Runtime[] = []
  const view = await page('planos', async (path, options) => {
    if (options?.method) {
      assert.equal(path, '/admin/plans/1')
      assert.equal(options.method, 'PUT')
      writes.push(options.body)
    }
    return []
  })
  try {
    await view.start()
    view.state.openEdit({ id: 1, slug: 'basic', name: 'Basic', limits: { users: 5 } })
    const nodes = view.nodes()
    const enabled = nodes.find(node => (node.type as Runtime)?.name === 'USwitch')!
    const modules = nodes.find(node => (node.type as Runtime)?.name === 'USelectMenu')!
    assert.equal(modules.props!.multiple, '')
    assert.equal(modules.props!['value-key'], 'value')
    assert.deepEqual(modules.props!.items.map((item: Runtime) => item.value), ['purchases', 'add_sale', 'pos_sale', 'stock_transfers', 'stock_adjustment', 'expenses', 'cte', 'mdfe'])
    enabled.props!['onUpdate:modelValue'](true)
    modules.props!['onUpdate:modelValue'](['purchases'])
    const inputs = nodes.filter(node => (node.type as Runtime)?.name === 'UInputNumber')
    for (const input of inputs) {
      assert.equal(input.props!.min, 0)
      assert.equal(input.props!.step, 1)
      input.props!['onUpdate:modelValue'](undefined)
    }
    inputs[1]!.props!['onUpdate:modelValue'](0)
    const form = nodes.find(node => (node.type as Runtime)?.name === 'UForm')!
    const data = form.props!.schema.parse(form.props!.state)
    await form.props!.onSubmit({ data })
    assert.deepEqual(writes[0].erp_commercial, { enabled: true, licence_limit: null, module_keys: ['purchases'] })
    assert.deepEqual(writes[0].limits, { users: null, clients: 0, monitorings: null })
    assert.equal(view.state.editOpen.value, false)
  } finally {
    view.stop()
  }
})
it('test_resale_editor_preserves_values_and_errors_on_422', async () => {
  const view = await page('planos', async (_path, options) => {
    if (options?.method)
      throw { statusCode: 422, data: { errors: { 'erp_commercial.module_keys': ['Invalid module'] } } }
    return []
  })
  try {
    await view.start()
    view.state.openEdit({ id: 1, slug: 'basic', name: 'Basic', erp_commercial: { enabled: true, licence_limit: null, module_keys: ['purchases'] } })
    await view.state.onSave({ data: view.state.editState })
    assert.equal(view.state.editOpen.value, true)
    assert.equal(view.state.editState.erp_commercial?.licence_limit, null)
    assert.deepEqual(view.state.fieldErrors.value['erp_commercial.module_keys'], ['Invalid module'])
    const field = view.nodes().find(node => node.props?.name === 'erp_commercial.module_keys')!
    assert.equal(field.props!.error, 'Invalid module')
  } finally {
    view.stop()
  }
})
it('resale editor defaults legacy plans safely, detaches drafts and permits empty modules', async () => {
  const saved = { id: 1, slug: 'basic', name: 'Basic', erp_commercial: { enabled: true, licence_limit: 5, module_keys: ['purchases'] } }
  const writes: Runtime[] = []
  const view = await page('planos', async (_path, options) => {
    if (options?.method)
      writes.push(options.body)
    return [saved]
  })
  try {
    await view.start()
    view.state.openEdit(saved)
    assert.deepEqual(view.state.editState.erp_commercial, { enabled: true, licence_limit: 5, module_keys: ['purchases'] })
    view.state.editState.erp_commercial?.module_keys.push('expenses')
    assert.deepEqual(saved.erp_commercial.module_keys, ['purchases'])
    view.state.openEdit({ id: 2, slug: 'legacy', name: 'Legacy' })
    assert.deepEqual(view.state.editState.erp_commercial, { enabled: false, licence_limit: 0, module_keys: [] })
    const form = view.nodes().find(node => (node.type as Runtime)?.name === 'UForm')!
    assert.equal(form.props!.schema.safeParse({ ...view.state.editState, erp_commercial: { enabled: true, licence_limit: 1.5, module_keys: [] } }).success, false)
    const data = form.props!.schema.parse(view.state.editState)
    await form.props!.onSubmit({ data })
    assert.deepEqual(writes[0].erp_commercial, { enabled: false, licence_limit: 0, module_keys: [] })
  } finally {
    view.stop()
  }
})
it('accounts preserve results on refresh error and temporary link clears on close', async () => {
  let response: Runtime = resource([{ id: 1, name: 'Saved', status: 'active', members_count: 0 }])
  const view = await page('contas', async () => await response)
  await view.start()
  assert.equal(view.state.canEnter({ id: 1, status: 'active' }), true)
  assert.equal(view.state.canEnter({ id: 1, status: 'suspended' }), false)
  assert.equal(view.state.canEnter({ id: 2, status: 'active' }), false)
  response = Promise.reject(Error('refresh'))
  await view.state.load()
  assert.equal(view.state.result.value.data[0].name, 'Saved')
  view.state.link.value = { url: 'temporary', expires_at: '' }
  view.state.open.value = true
  await flush()
  view.state.open.value = false
  await flush()
  assert.equal(view.state.link.value, null)
  view.stop()
})
it('Admin shell renders exactly one panel/navbar for Account and nested ERP, and layout adds none', async () => {
  for (const route of ['/admin/contas', '/admin/erp/planos']) {
    const shell = await page('shell', async () => null, route)
    const view = shell.nodes()
    assert.equal(view.filter(node => (node.type as Runtime)?.name === 'UDashboardPanel').length, 1)
    assert.equal(view.filter(node => (node.type as Runtime)?.name === 'UDashboardNavbar').length, 1)
    assert.equal(view.filter(node => (node.type as Runtime)?.name === 'NuxtPage').length, 1)
    shell.stop()
    const layout = await page('layout', async () => null, route)
    assert.equal(layout.nodes().filter(node => (node.type as Runtime)?.name === 'UDashboardPanel').length, 0)
    layout.stop()
  }
})
it('summary initial failure offers retry and a successful empty result remains distinct', async () => {
  let fail = true
  const view = await page('index', async () => {
    if (fail)
      throw Error('offline')
    return { data: { totals: { accounts: 0, users: 0, plans: 0, subscriptions: 0 }, recent_support_logs: [] } }
  })
  await view.start()
  assert.ok(view.nodes().some(node => (node.type as Runtime)?.name === 'ErrorRetryAlert'))
  assert.equal(view.nodes().some(node => (node.type as Runtime)?.name === 'MetricCard'), false)
  fail = false
  await view.state.retry()
  assert.equal(view.state.hasLoaded.value, true)
  assert.equal(view.state.recentLogs.value.length, 0)
  assert.equal(view.nodes().filter(node => (node.type as Runtime)?.name === 'MetricCard').length, 4)
  view.stop()
})
it('subscription catalog retries independently, guards duplicate saves and retains field input/error', async () => {
  let catalogFailure = true
  const pending = deferred()
  const writes: Runtime[] = []
  const view = await page('assinaturas', async (path, options) => {
    if (options?.method) {
      writes.push(options.body)
      return pending.promise
    }
    if (path === '/admin/plans') {
      if (catalogFailure)
        throw Error('catalog offline')
      return [{ id: 9, slug: 'basic', name: 'Basic' }]
    }
    return resource([{ id: 3, status: 'canceled', plan: { id: 9 } }])
  })
  await view.start()
  assert.equal(view.state.subscriptions.value[0].status, 'canceled')
  assert.equal(view.state.catalogLoaded.value, false)
  assert.equal(view.state.catalogError.value, null)
  view.state.openEdit(view.state.subscriptions.value[0])
  await flush()
  assert.ok(view.state.catalogError.value)
  await view.state.onSave({ data: { plan_id: 9, status: 'active' } })
  assert.equal(writes.length, 0)
  catalogFailure = false
  await view.state.loadCatalog()
  const first = view.state.onSave({ data: { plan_id: 9, status: 'active' } })
  await view.state.onSave({ data: { plan_id: 9, status: 'active' } })
  assert.equal(writes.length, 1)
  pending.reject({ statusCode: 422, data: { errors: { plan_id: ['Unavailable'] } } })
  await first
  assert.equal(view.state.editOpen.value, true)
  assert.equal(view.state.editState.plan_id, 9)
  assert.deepEqual(view.state.fieldErrors.value.plan_id, ['Unavailable'])
  assert.equal(view.state.subscriptions.value[0].status, 'canceled')
  view.stop()
})
it('Account clipboard denial keeps temporary link available and validation keeps contact inputs', async () => {
  const view = await page('contas', async (_path, options) => {
    if (options?.method)
      throw { statusCode: 422, data: { errors: { phone: ['Invalid phone'] } } }
    return resource([])
  })
  await view.start()
  await view.state.begin('create')
  view.state.form.name = 'Office'
  view.state.form.phone = 'invalid'
  await view.state.save()
  assert.equal(view.state.form.phone, 'invalid')
  assert.deepEqual(view.state.fieldErrors.value.phone, ['Invalid phone'])
  view.state.link.value = { url: 'temporary', expires_at: '' }
  await view.state.copy()
  assert.equal(view.state.link.value.url, 'temporary')
  assert.match(view.state.operationError.value, /Selecione e copie/)
  view.stop()
  assert.equal(view.state.link.value, null)
})
it('support distinguishes empty/404 from failure and drops reversed search responses and rejected audit', async () => {
  let searchResponse: Runtime = resource([])
  let logResponse: Runtime = resource([])
  const view = await page('suporte', async (path) => {
    if (path.includes('logs'))
      return await logResponse
    if (path === '/admin/accounts/404')
      throw { statusCode: 404 }
    return await searchResponse
  })
  await view.start()
  view.state.query.value = '404'
  await view.state.search()
  assert.equal(view.state.searchLoaded.value, true)
  assert.equal(view.state.searchError.value, null)
  assert.deepEqual(view.state.found.value, [])
  const old = deferred()
  searchResponse = old.promise
  view.state.query.value = 'old'
  const first = view.state.search()
  searchResponse = resource([{ id: 6, name: 'Latest', status: 'active' }])
  view.state.query.value = 'new'
  await view.state.search()
  old.resolve(resource([{ id: 8 }]))
  await first
  assert.equal(view.state.found.value[0].id, 6)
  searchResponse = Promise.reject(Error('offline'))
  await view.state.search()
  assert.equal(view.state.found.value[0].id, 6)
  assert.ok(view.state.searchError.value)
  const staleLogs = deferred()
  logResponse = staleLogs.promise
  const logs = view.state.loadLogs()
  logResponse = resource([{ id: 9 }])
  await view.state.loadLogs()
  staleLogs.reject(Error('old'))
  await logs
  assert.equal(view.state.logs.value[0].id, 9)
  assert.equal(view.state.loadLogsError.value, null)
  view.stop()
})
it('in-flight user response cannot modify state after unmount; catalog is read only', async () => {
  const pending = deferred()
  const methods: unknown[] = []
  const view = await page('usuarios', async (_path, options) => {
    methods.push(options?.method)
    return pending.promise
  })
  const load = view.state.load()
  view.stop()
  pending.resolve(resource([{ id: 99 }]))
  await load
  assert.deepEqual(view.state.users.value, [])
  assert.deepEqual(methods, [undefined])
})
it('rendered Plan number inputs normalize cleared undefined before form validation and reload', async () => {
  let plan: Runtime = { id: 1, name: 'Basic', slug: 'basic', limits: { users: 5, clients: 10, monitorings: 3 } }
  const writes: Runtime[] = []
  const view = await page('planos', async (_path, options) => {
    if (options?.method) {
      writes.push(options.body)
      plan = { ...plan, ...options.body }
      return plan
    }
    return [plan]
  })
  await view.start()
  view.state.openEdit(plan)
  const inputs = view.nodes().filter(node => (node.type as Runtime)?.name === 'UInputNumber')
  assert.equal(inputs.length, 4)
  for (const input of inputs) input.props!['onUpdate:modelValue'](undefined)
  const form = view.nodes().find(node => node.props?.id === 'edit-plan-form')!
  const cleared = form.props!.schema.safeParse(form.props!.state)
  assert.equal(cleared.success, true, 'Empty values emitted by the rendered number inputs must pass UForm validation')
  assert.deepEqual(cleared.data, { name: 'Basic', users: null, clients: null, monitorings: null, erp_commercial: { enabled: false, licence_limit: null, module_keys: [] } })
  inputs[1]!.props!['onUpdate:modelValue'](0)
  const validated = form.props!.schema.safeParse(form.props!.state)
  assert.equal(validated.success, true)
  await form.props!.onSubmit({ data: validated.data })
  assert.deepEqual(writes, [{ name: 'Basic', limits: { users: null, clients: 0, monitorings: null }, erp_commercial: { enabled: false, licence_limit: null, module_keys: [] } }])
  assert.equal(view.state.limitValue(view.state.plans.value[0], 'users'), null)
  assert.equal(view.state.limitValue(view.state.plans.value[0], 'clients'), 0)
  assert.equal(view.state.limitValue(view.state.plans.value[0], 'monitorings'), null)
  for (const invalid of [-1, 1.5, '1', Number.NaN]) {
    inputs[0]!.props!['onUpdate:modelValue'](invalid)
    assert.equal(form.props!.schema.safeParse(form.props!.state).success, false)
  }
  view.stop()
})
it('Users keeps prior rows and count when out-of-range page recovery fails', async () => {
  let requests = 0
  const previous = [{ id: 7, name: 'Previous', email: 'previous@example.test', is_super_admin: false }]
  const view = await page('usuarios', async () => {
    requests++
    if (requests === 1) return resource(previous, 31, 3)
    if (requests === 2) return resource([], 1, 1)
    throw Error('Recovery unavailable')
  })
  await view.start()
  assert.equal(view.state.total.value, 31)
  view.state.page.value = 3
  for (let i = 0; i < 4; i++) await flush()
  assert.equal(requests, 3)
  assert.equal(view.state.page.value, 1)
  assert.deepEqual(view.state.users.value, previous)
  assert.equal(view.state.total.value, 31)
  assert.ok(view.state.loadError.value)
  const nodes = view.nodes()
  assert.ok(nodes.some(node => (node.type as Runtime)?.name === 'ErrorRetryAlert'))
  assert.deepEqual(nodes.find(node => (node.type as Runtime)?.name === 'UTable')?.props?.data, previous)
  view.stop()
})
it('commercial editor clears/reloads on reopen, rejects stale details and preserves inline validation', async () => {
  let response: Runtime = { data: { platform_billing_contact: { phone: '111', city: 'First' }, subscription: { status: 'active', plan: { name: 'Basic' } } } }
  let failSave = true
  const props = Vue.reactive({ account: { id: 1, name: 'Office' }, open: false })
  const view = await page('commercial', async (_path, options) => {
    if (options?.method && failSave)
      throw { statusCode: 422, data: { errors: { state: ['Invalid state'] } } }
    return await response
  }, '/admin/contas', props)
  props.open = true
  await flush()
  assert.equal(view.state.form.phone, '111')
  view.state.form.state = 'ZZ'
  await view.state.save()
  assert.equal(view.state.form.state, 'ZZ')
  assert.deepEqual(view.state.fieldErrors.value.state, ['Invalid state'])
  props.open = false
  await flush()
  assert.equal(view.state.form.phone, '')
  assert.equal(view.state.subscription.value, null)
  const stale = deferred()
  response = stale.promise
  props.open = true
  await flush()
  props.open = false
  await flush()
  stale.resolve({ data: { platform_billing_contact: { phone: 'stale' } } })
  await flush()
  assert.equal(view.state.form.phone, '')
  response = { data: { platform_billing_contact: { phone: '222' } } }
  props.open = true
  await flush()
  assert.equal(view.state.form.phone, '222')
  failSave = false
  await view.state.save()
  assert.deepEqual(view.emitted, ['saved'])
  assert.equal(props.open, false)
  view.stop()
})

for (const name of ['usuarios', 'assinaturas', 'contas', 'planos'])
  it(`${name} restores only navigation criteria on same-session return`, async () => {
    const app = session()
    const calls: Runtime[] = []
    const api = async (path: string, options: Runtime) => {
      calls.push([path, options])
      return name === 'planos' ? [{ id: 1, name: 'Basic', slug: 'basic' }] : resource([{ id: 1 }], 61, 5)
    }
    const first = await page(name, api, '/admin/' + name, {}, app)
    await first.start()
    first.state.q.value = 'remembered'
    await flush()
    if (first.state.page) first.state.page.value = 3
    await flush()
    first.stop()
    calls.length = 0
    const second = await page(name, api, '/admin/' + name, {}, app)
    assert.equal(second.state.q.value, 'remembered')
    if (second.state.page) assert.equal(second.state.page.value, 3)
    await second.start()
    assert.equal(calls.length, 1)
    if (name !== 'planos') assert.equal((calls[0][1].params ?? calls[0][1].query).page, 3)
    assert.ok(!JSON.stringify(app.values.get('list-navigation-state')!.value).includes('Basic'))
    second.stop()
  })
for (const name of ['usuarios', 'assinaturas'])
  it(`${name} normalizes a changed filter before the only admitted page-one read`, async () => {
    const calls: Runtime[] = []
    const view = await page(name, async (_path, options) => {
      calls.push({ ...options.params })
      return resource([], 61, 5)
    })
    await view.start()
    view.state.page.value = 3
    await flush()
    calls.length = 0
    view.state.page.value = 4
    view.state.q.value = 'changed'
    await flush()
    await flush()
    assert.deepEqual(calls, [{ page: 1, q: 'changed' }])
    view.stop()
  })

for (const name of ['usuarios', 'assinaturas', 'contas', 'planos', 'index'])
  it(`${name} aborts active safe reads when its page leaves`, async () => {
    const pending = deferred()
    let signal: AbortSignal | undefined
    const view = await page(name, async (_path, options) => {
      signal = options?.signal
      return await pending.promise
    })
    const loading = view.start()
    await flush()
    assert.ok(signal instanceof AbortSignal)
    view.stop()
    assert.equal(signal.aborted, true)
    pending.resolve(name === 'planos' ? [] : name === 'index' ? { data: { totals: {}, recent_support_logs: [] } } : resource([]))
    await loading
  })

it('subscription plans stay lazy and obsolete queued catalog never dispatches before current Users read', async () => {
  const { createAccessApiAdmissionQueue } = await import('../app/utils/accessApiAdmissionQueue.ts')
  const queue = createAccessApiAdmissionQueue()
  const blocker = deferred()
  const active = queue.run(async () => await blocker.promise)
  await flush()
  const dispatched: string[] = []
  const api = (path: string, options: Runtime) => queue.run(async () => {
    dispatched.push(path)
    return path === '/admin/plans' ? [] : resource([])
  }, { signal: options?.signal })
  const subscriptions = await page('assinaturas', api)
  const initial = subscriptions.start()
  await flush()
  assert.equal(subscriptions.state.catalogLoading.value, false)
  subscriptions.state.openEdit({ id: 1, status: 'active', plan: { id: 2 } })
  await flush()
  subscriptions.stop()
  const users = await page('usuarios', api)
  const current = users.start()
  blocker.resolve(null)
  await Promise.all([active, initial, current])
  assert.deepEqual(dispatched, ['/admin/users'])
  users.stop()
})

it('support restores search/audit criteria and bounded pages, without retaining result records', async () => {
  const app = session()
  const requests: Runtime[] = []
  const api = async (path: string, options: Runtime) => {
    requests.push([path, options])
    return resource([{ id: 1, name: 'private-record' }], 61, 5)
  }
  const first = await page('suporte', api, '/admin/suporte', {}, app)
  await first.start()
  first.state.query.value = 'Needle'
  await first.state.search()
  first.state.searchPage.value = 3
  first.state.accountQuery.value = '27'
  await flush()
  first.state.logsPage.value = 2
  await flush()
  first.stop()
  requests.length = 0
  const returned = await page('suporte', api, '/admin/suporte', {}, app)
  assert.equal(returned.state.query.value, 'Needle')
  assert.equal(returned.state.searchPage.value, 3)
  assert.equal(returned.state.accountQuery.value, '27')
  assert.equal(returned.state.logsPage.value, 2)
  await returned.start()
  assert.equal(requests.length, 2)
  assert.ok(!JSON.stringify(app.values.get('list-navigation-state')!.value).includes('private-record'))
  returned.stop()
})

for (const name of ['usuarios', 'assinaturas', 'contas', 'planos', 'suporte'])
  it(`${name} clears private rows synchronously on a session authority transition`, async () => {
    const app = session()
    const view = await page(name, async () => name === 'planos' ? [{ id: 1, name: 'Private', slug: 'private' }] : resource([{ id: 1, name: 'Private' }]), '/admin/' + name, {}, app)
    await view.start()
    const rows = name === 'usuarios' ? view.state.users : name === 'assinaturas' ? view.state.subscriptions : name === 'contas' ? view.state.result : name === 'planos' ? view.state.plans : view.state.logs
    assert.equal(name === 'contas' ? rows.value.data.length : rows.value.length, 1)
    app.values.get('access-session-generation')!.value++
    assert.equal(name === 'contas' ? rows.value : rows.value.length, name === 'contas' ? null : 0)
    view.stop()
  })
