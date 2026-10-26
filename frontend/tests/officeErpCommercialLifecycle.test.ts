import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { it } from 'node:test'
import * as Vue from 'vue'
import { refDebounced } from '@vueuse/core'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

const caps = (key = 'cap-1') => ({
  can_read: true, can_maintain: true, can_configure: true, can_sell_new: true,
  can_provision: true, can_start_trial: true, can_manage_trial_link: true,
  capability_key: key, reasons: {},
  capability_reasons: { can_configure: [], can_sell_new: [], can_provision: [], can_start_trial: [], can_manage_trial_link: [] },
  allowance: { enabled: true, licence_limit: 10, module_keys: [] }, usage: { reserved: 0, occupied: 0 }
})
const flush = async () => {
  await Vue.nextTick()
  await new Promise<void>(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

function harness(override?: (path: string, options?: Record<string, unknown>) => Promise<unknown>, realDebounce = false) {
  const user = Vue.ref<{ id: number, current_account: { id: number, status: string, role: string }, accounts?: Array<{ id: number, status: string, role: string }> }>({ id: 1, current_account: { id: 8, status: 'active', role: 'admin' } })
  const states = new Map<string, Vue.Ref>()
  const route = Vue.reactive({ query: {} as Record<string, string>, params: { id: '12' } })
  const app = {}
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  let capabilityKey = 'cap-1'
  const bindings: Record<string, unknown> = {
    ref: Vue.ref, computed: Vue.computed, reactive: Vue.reactive, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose,
    useNuxtApp: () => app,
    useState: (key: string, init: () => unknown) => {
      if (key === 'access-user') return user
      if (!states.has(key)) states.set(key, Vue.ref(init()))
      return states.get(key)
    },
    useAuth: () => ({ user, currentAccount: Vue.computed(() => user.value.current_account) }),
    useAccessApi: () => ({ api: async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (override) return override(path, options)
      if (path === '/erp-commercial/context') return { data: caps(capabilityKey) }
      if (path === '/erp-commercial/settings') return { data: { mode: 'manual', grace_days: 2, billing_timezone: 'America/Sao_Paulo' } }
      if (path === '/erp-commercial/trial-link') return { data: { configured: false, enabled: false, revision: null, url: null, erp_plan_id: null, plan: null, plans: [] } }
      return { data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 25 } }
    } }),
    refDebounced: realDebounce ? refDebounced : (value: unknown) => value,
    useOfficeErpCommand: () => ({ pending: Vue.ref(false), recoveryKey: Vue.ref(null) }),
    useRoute: () => route,
    useToast: () => ({ add() {} }), definePageMeta() {}, useHead() {}
  }
  const { useOfficeErpContext } = loadAppComposable('composables/useOfficeErpContext.ts', bindings)
  let confirmations = 0
  bindings.useOfficeErpContext = () => {
    const context = (useOfficeErpContext as () => { refresh(): Promise<void> })()
    return { ...context, refresh: () => {
      confirmations++
      return context.refresh()
    } }
  }
  function mount(path: string) {
    const mounted: Array<() => void> = []
    const globals = { ...bindings, onMounted: (callback: () => void) => mounted.push(callback) }
    const source = readFileSync(new URL(`../app/pages/gestao-erp/${path}`, import.meta.url), 'utf8')
    const { descriptor } = parse(source)
    const script = compileScript(descriptor, { id: path })
    const compiled = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports: { default?: { setup: (props: object, context: object) => Record<string, Vue.Ref | (() => Promise<void>)> } } = {}
    new Function('require', 'exports', ...Object.keys(globals), compiled)((specifier: string) => {
      if (specifier === 'vue') return Vue
      if (specifier.endsWith('.vue')) return { __esModule: true, default: {} }
      const file = specifier.startsWith('~/') ? specifier.slice(2) : specifier.replace('#shared/', '../shared/')
      return loadAppComposable(file + '.ts', bindings)
    }, exports, ...Object.values(globals))
    const scope = Vue.effectScope()
    const state = scope.run(() => exports.default!.setup({}, { expose() {} }))!
    mounted.forEach(callback => scope.run(callback))
    return { state, stop: () => scope.stop() }
  }
  return { mount, calls, user, route, confirmations: () => confirmations, setCapability: (key: string) => {
    capabilityKey = key
  } }
}

it('cold configuration confirms context once before each distinct card read', async (t) => {
  const h = harness()
  const page = h.mount('configuracoes.vue')
  t.after(page.stop)
  await flush()
  assert.deepEqual(h.calls.map(call => call.path).sort(), ['/erp-commercial/context', '/erp-commercial/settings', '/erp-commercial/trial-link'].sort())
  assert.equal(h.confirmations(), 2)
  assert.equal((page.state.loaded as Vue.Ref).value, true)
  assert.equal((page.state.loading as Vue.Ref).value, false)
  assert.equal((page.state.trialLinkLoading as Vue.Ref).value, false)
})

for (const [path, endpoint] of [['planos.vue', 'plans'], ['clientes/index.vue', 'clients'], ['assinaturas/index.vue', 'contracts'], ['faturas/index.vue', 'invoices'], ['recebimentos.vue', 'receipts']]) {
  it(`cold ${path} confirms once and reads its list once`, async (t) => {
    const h = harness()
    const page = h.mount(path!)
    t.after(page.stop)
    await flush()
    assert.equal(h.confirmations(), 1)
    assert.equal(h.calls.filter(call => call.path === '/erp-commercial/context').length, 1)
    assert.equal(h.calls.filter(call => call.path === '/erp-commercial/' + endpoint).length, 1)
  })
}

it('a real grant change invalidates protected reads without repeating its confirmation', async (t) => {
  const h = harness()
  const page = h.mount('planos.vue')
  t.after(page.stop)
  await flush()
  h.setCapability('cap-2')
  await (page.state.loadOffers as () => Promise<void>)()
  await flush()
  assert.equal(h.calls.filter(call => call.path === '/erp-commercial/context').length, 2)
  assert.equal(h.calls.filter(call => call.path === '/erp-commercial/plans').length, 2)
  assert.equal((page.state.loading as Vue.Ref).value, false)
})

it('a transient context failure remains retryable without a false empty result', async (t) => {
  let attempts = 0
  const h = harness(async (path) => {
    if (path === '/erp-commercial/context') {
      if (++attempts === 1) throw new Error('temporary')
      return { data: caps() }
    }
    return { data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 25 } }
  })
  const page = h.mount('planos.vue')
  t.after(page.stop)
  await flush()
  assert.match((page.state.loadError as Vue.Ref).value, /confirmar/)
  assert.equal((page.state.confirmedEmpty as Vue.Ref).value, false)
  await (page.state.loadOffers as () => Promise<void>)()
  await flush()
  assert.equal((page.state.loadError as Vue.Ref).value, '')
  assert.equal((page.state.confirmedEmpty as Vue.Ref).value, true)
  assert.equal(attempts, 2)
})

it('superseded and disposed resource reads abort transport and cannot publish errors', async (t) => {
  const signals: AbortSignal[] = []
  const h = harness(async (path, options) => {
    if (path === '/erp-commercial/context') return { data: caps() }
    const signal = options!.signal as AbortSignal
    signals.push(signal)
    return new Promise((_resolve, reject) => signal.addEventListener('abort', () => reject(new Error('ofetch wrapper', { cause: new DOMException('Cancelled', 'AbortError') })), { once: true }))
  })
  const page = h.mount('planos.vue')
  t.after(page.stop)
  await flush()
  const retry = (page.state.loadOffers as () => Promise<void>)()
  await flush()
  assert.equal(signals[0]!.aborted, true)
  assert.equal(signals[1]!.aborted, false)
  page.stop()
  await retry
  assert.equal(signals[1]!.aborted, true)
  assert.equal((page.state.loadError as Vue.Ref).value, '')
})

it('leaving during shared confirmation cancels only the departed resource consumer', async () => {
  let resolve!: (value: unknown) => void
  const confirmation = new Promise((done) => {
    resolve = done
  })
  const h = harness(async path => path === '/erp-commercial/context'
    ? confirmation
    : { data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 25 } })
  const departed = h.mount('planos.vue')
  const current = h.mount('clientes/index.vue')
  departed.stop()
  resolve({ data: caps() })
  await flush()
  assert.deepEqual(h.calls.map(call => call.path), ['/erp-commercial/context', '/erp-commercial/clients'])
  current.stop()
})

for (const [path, endpoint, filter, value, parameter] of [
  ['planos.vue', 'plans', 'query', 'Plano anual', 'q'],
  ['clientes/index.vue', 'clients', 'query', 'Cliente matriz', 'q'],
  ['assinaturas/index.vue', 'contracts', 'query', 'Contrato anual', 'q'],
  ['faturas/index.vue', 'invoices', 'status', 'issued', 'status'],
  ['recebimentos.vue', 'receipts', 'from', '2026-10-01', 'received_from']
]) {
  it(`${path} restores only criteria after a tab round trip and resets a later page once`, async () => {
    const h = harness(async (url, options) => url === '/erp-commercial/context'
      ? { data: caps() }
      : {
          data: [], meta: { total: 150, current_page: (options?.params as Record<string, number>)?.page ?? 1, last_page: 6, per_page: 25 }
        })
    const first = h.mount(path!)
    await flush()
    ;(first.state[filter!] as Vue.Ref).value = value
    await flush()
    ;(first.state.page as Vue.Ref).value = 3
    await flush()
    first.stop()
    const adjacent = h.mount('planos.vue')
    await flush()
    adjacent.stop()
    const returned = h.mount(path!)
    await flush()
    assert.equal((returned.state[filter!] as Vue.Ref).value, value)
    assert.equal((returned.state.page as Vue.Ref).value, 3)
    const restored = h.calls.filter(call => call.path === '/erp-commercial/' + endpoint).at(-1)!
    assert.equal((restored.options?.params as Record<string, unknown>)[parameter!], value)
    assert.equal((restored.options?.params as Record<string, unknown>).page, 3)
    const before = h.calls.length
    ;(returned.state[filter!] as Vue.Ref).value = filter === 'from' ? '2026-10-02' : filter === 'status' ? 'draft' : 'Nova busca'
    await flush()
    const reads = h.calls.slice(before).filter(call => call.path === '/erp-commercial/' + endpoint)
    assert.equal(reads.length, 1)
    assert.equal((reads[0]!.options?.params as Record<string, number>).page, 1)
    returned.stop()
  })
}

it('portfolio explicit q/pagina and history take priority over remembered criteria', async () => {
  const h = harness(async (url, options) => url === '/erp-commercial/context'
    ? { data: caps() }
    : {
        data: [], meta: { total: 150, current_page: (options?.params as Record<string, number>)?.page ?? 1, last_page: 6, per_page: 25 }
      })
  const first = h.mount('clientes/index.vue')
  await flush()
  ;(first.state.query as Vue.Ref).value = 'Lembrada'
  await flush()
  first.stop()
  h.route.query = { q: 'URL', pagina: '4', origem: 'existing' }
  const current = h.mount('clientes/index.vue')
  await flush()
  assert.equal((current.state.query as Vue.Ref).value, 'URL')
  assert.equal((current.state.page as Vue.Ref).value, 4)
  const before = h.calls.length
  h.route.query = { q: 'Histórico', pagina: '2', origem: 'existing' }
  await flush()
  assert.equal((current.state.query as Vue.Ref).value, 'Histórico')
  assert.equal((current.state.page as Vue.Ref).value, 2)
  const reads = h.calls.slice(before).filter(call => call.path === '/erp-commercial/clients')
  assert.equal(reads.length, 1)
  assert.deepEqual(reads[0]!.options?.params, { q: 'Histórico', page: 2, per_page: 25 })
  assert.equal(h.route.query.origem, 'existing')
  current.stop()
})

it('server totals bound a remembered page and fetch the last available page', async () => {
  const h = harness(async (url, options) => url === '/erp-commercial/context'
    ? { data: caps() }
    : {
        data: [], meta: { total: 30, current_page: (options?.params as Record<string, number>)?.page ?? 1, last_page: 2, per_page: 25 }
      })
  h.route.query = { q: 'URL', pagina: '6' }
  const current = h.mount('clientes/index.vue')
  await flush()
  await flush()
  assert.equal((current.state.page as Vue.Ref).value, 2)
  assert.equal((current.state.total as Vue.Ref).value, 30)
  assert.deepEqual(h.calls.filter(call => call.path === '/erp-commercial/clients').map(call => (call.options?.params as Record<string, number>).page), [6, 2])
  current.stop()
})

it('receipt invoice choices belong only to an open editor and cancel on close', async () => {
  let catalogSignal: AbortSignal | undefined
  const h = harness(async (url, options) => {
    if (url === '/erp-commercial/context') return { data: caps() }
    if (url === '/erp-commercial/invoices') {
      catalogSignal = options!.signal as AbortSignal
      return new Promise((_resolve, reject) => catalogSignal!.addEventListener('abort', () => reject(new DOMException('cancelled', 'AbortError'))))
    }
    return { data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 25 } }
  })
  const page = h.mount('recebimentos.vue')
  await flush()
  assert.equal(h.calls.filter(call => call.path === '/erp-commercial/invoices').length, 0)
  ;(page.state.open as Vue.Ref).value = true
  await flush()
  assert.equal(h.calls.filter(call => call.path === '/erp-commercial/invoices').length, 1)
  assert.equal(catalogSignal!.aborted, false)
  ;(page.state.open as Vue.Ref).value = false
  await flush()
  assert.equal(catalogSignal!.aborted, true)
  assert.equal((page.state.error as Vue.Ref).value, '')
  page.stop()
})

it('commercial grant loss and Account changes clear remembered criteria synchronously', async () => {
  const h = harness()
  const page = h.mount('planos.vue')
  await flush()
  ;(page.state.query as Vue.Ref).value = 'Privada'
  await flush()
  h.setCapability('reduced-grant')
  await (page.state.loadOffers as () => Promise<void>)()
  await flush()
  assert.equal((page.state.query as Vue.Ref).value, '')
  ;(page.state.query as Vue.Ref).value = 'Outro contexto'
  await flush()
  h.user.value.current_account = { id: 9, status: 'active', role: 'admin' }
  assert.equal((page.state.query as Vue.Ref).value, '')
  await flush()
  page.stop()
})

for (const field of ['role', 'status', 'removed'] as const) {
  it(`effective membership ${field} clears default-filter rows, private editor and pending real-context reads synchronously`, async () => {
    let changed = false
    let pending = false
    let resolve!: (value: unknown) => void
    let obsoleteSignal: AbortSignal | undefined
    const response = new Promise((done) => {
      resolve = done
    })
    const h = harness(async (url, options) => {
      if (url === '/erp-commercial/context') return { data: { ...caps(changed ? 'changed' : 'original'), can_read: !changed } }
      if (pending) {
        obsoleteSignal = options!.signal as AbortSignal
        return response
      }
      return { data: [{ id: 12, name: 'Protected plan' }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } }
    })
    h.user.value.accounts = [{ id: 8, status: 'active', role: 'admin' }]
    const page = h.mount('planos.vue')
    await flush()
    assert.equal((page.state.offers as Vue.Ref).value.length, 1)
    ;(page.state.editorOpen as Vue.Ref).value = true
    pending = true
    const oldRead = (page.state.loadOffers as () => Promise<void>)()
    await flush()
    changed = true
    if (field === 'removed') h.user.value.accounts = []
    else h.user.value.accounts[0]![field] = field === 'role' ? 'user' : 'suspended'
    assert.equal(obsoleteSignal!.aborted, true)
    assert.deepEqual((page.state.offers as Vue.Ref).value, [])
    assert.equal((page.state.editorOpen as Vue.Ref).value, false)
    resolve({ data: [{ id: 13, name: 'Obsolete result' }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } })
    await oldRead
    await flush()
    assert.deepEqual((page.state.offers as Vue.Ref).value, [])
    assert.equal((page.state.query as Vue.Ref).value, '')
    assert.equal((page.state.page as Vue.Ref).value, 1)
    page.stop()
  })
}

it('portfolio history applies q and pagina atomically with the real VueUse debounce', async () => {
  const h = harness(async (url, options) => url === '/erp-commercial/context'
    ? { data: caps() }
    : {
        data: [], meta: { total: 150, current_page: (options?.params as Record<string, number>)?.page ?? 1, last_page: 6, per_page: 25 }
      }, true)
  h.route.query = { q: 'Primeiro', pagina: '4' }
  const page = h.mount('clientes/index.vue')
  await flush()
  const before = h.calls.length
  h.route.query = { q: 'Histórico', pagina: '2' }
  await flush()
  await new Promise(resolve => setTimeout(resolve, 350))
  await flush()
  const reads = h.calls.slice(before).filter(call => call.path === '/erp-commercial/clients')
  assert.equal(reads.length, 1)
  assert.deepEqual(reads[0]!.options?.params, { q: 'Histórico', page: 2, per_page: 25 })
  assert.equal((page.state.query as Vue.Ref).value, 'Histórico')
  assert.equal((page.state.page as Vue.Ref).value, 2)
  const beforeManual = h.calls.length
  ;(page.state.query as Vue.Ref).value = 'Manual'
  await flush()
  assert.equal(h.calls.length, beforeManual)
  await new Promise(resolve => setTimeout(resolve, 350))
  await flush()
  const manualReads = h.calls.slice(beforeManual).filter(call => call.path === '/erp-commercial/clients')
  assert.equal(manualReads.length, 1)
  assert.deepEqual(manualReads[0]!.options?.params, { q: 'Manual', page: 1, per_page: 25 })
  page.stop()
})
