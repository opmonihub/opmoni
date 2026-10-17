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
async function page(name: string, api: (...args: Runtime[]) => Promise<Runtime>, routePath = '/admin/contas', props: Record<string, Runtime> = {}) {
  const path = name === 'commercial' ? 'components/platform/AccountCommercialModal.vue' : name === 'shell' ? 'pages/admin.vue' : name === 'layout' ? 'layouts/default.vue' : `pages/admin/${name}.vue`
  const { descriptor } = parse(readFileSync(new URL(`../app/${path}`, import.meta.url), 'utf8'), { filename: path })
  const script = compileScript(descriptor, { id: name })
  const template = compileTemplate({ source: descriptor.template!.content, filename: path, id: name, compilerOptions: { bindingMetadata: script.bindings } })
  const emitted: string[] = []
  const mounted: (() => unknown)[] = []
  const unmounted: (() => unknown)[] = []
  const globals = {
    useRoute: () => ({ path: routePath }),
    ref: Vue.ref, computed: Vue.computed, reactive: Vue.reactive, watch: Vue.watch, definePageMeta() {
    }, useNuxtApp: () => ({ $api: api }), useAccessApi: () => ({ api }),
    useAuth: () => ({ user: Vue.ref({ is_super_admin: true }), canManageMembers: Vue.ref(true), accounts: Vue.ref([{ id: 1, status: 'active', role: 'user' }]), switchAccount: api }),
    useToast: () => ({ add() {
    } }), refDebounced: (value: unknown) => value,
    useAccountFilters: require('../app/composables/useAccountFilters.ts').useAccountFilters,
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
  assert.deepEqual(writes, [{ name: 'Basic', limits: { users: null, clients: 0, monitorings: null } }])
  pending.reject({ data: { errors: { name: ['Invalid'] } } })
  await Promise.all([first, second])
  assert.equal(view.state.editState.name, 'Basic')
  assert.equal(view.state.editOpen.value, true)
  view.stop()
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
  assert.ok(view.state.catalogError.value)
  view.state.openEdit(view.state.subscriptions.value[0])
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
it('plan finite limits clear to null, preserve zero on reload and reject invalid inputs', async () => {
  let plan: Runtime = { id: 1, name: 'Basic', slug: 'basic', limits: { users: 5, clients: 10, monitorings: 3 } }
  const view = await page('planos', async (_path, options) => {
    if (options?.method) {
      plan = { ...plan, ...options.body }
      return plan
    }
    return [plan]
  })
  await view.start()
  view.state.openEdit(plan)
  await view.state.onSave({ data: { name: 'Basic', users: null, clients: 0, monitorings: null } })
  assert.equal(view.state.limitValue(view.state.plans.value[0], 'users'), null)
  assert.equal(view.state.limitValue(view.state.plans.value[0], 'clients'), 0)
  for (const users of [-1, 1.5, '1'])
    assert.equal(view.state.editSchema.safeParse({ name: 'Basic', users, clients: null, monitorings: null }).success, false)
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
