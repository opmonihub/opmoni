import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as pageShell from '../app/utils/pageShell.ts'
import * as requestRunner from '../app/utils/adminListFilters.ts'
import type { ClientPortfolioAnalytics, ClientPortfolioSummary } from '../app/types/client.ts'

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

const summary: ClientPortfolioSummary = {
  total: 12, active: 11, inactive: 1,
  certificate: { missing: 2, valid: 7, expired: 2, expiring: 1 },
  poa: { missing: 2, valid: 7, expired: 1, expiring: 2 }
}
const analytics: ClientPortfolioAnalytics = {
  by_state: [], by_region: [], by_city: [], by_tax_regime: [], by_legal_nature: [], by_activity: [], growth_by_month: [],
  attention: {
    certificate: [{ id: 1, name: 'Cliente com A1 vencido', tax_id: null, status: 'expired', expires_at: '2026-10-01' }],
    poa: [{ id: 2, name: 'Cliente com procuração vencida', tax_id: null, status: 'expired', expires_at: '2026-10-01' }]
  }
}

function page() {
  const mounted: (() => unknown)[] = []
  const reads: { summary: ReturnType<typeof deferred<ClientPortfolioSummary>>, analytics: ReturnType<typeof deferred<ClientPortfolioAnalytics>> }[] = []
  const toasts: unknown[] = []
  const globals: Record<string, unknown> = {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch,
    definePageMeta() {},
    useOfficeReadLifecycle: () => ({ own() {} }),
    onBeforeUnmount: Vue.onScopeDispose,
    useToast: () => ({ add: (toast: unknown) => toasts.push(toast) }),
    onMounted: (callback: () => unknown) => mounted.push(callback),
    useClients: () => ({
      portfolioSummary: () => {
        const read = { summary: deferred<ClientPortfolioSummary>(), analytics: deferred<ClientPortfolioAnalytics>() }
        reads.push(read)
        return read.summary.promise
      },
      portfolioAnalytics: () => reads.at(-1)!.analytics.promise
    })
  }
  function evaluate(code: string) {
    const compiled = ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext } }).outputText
    const exports: Record<string, unknown> = {}
    new Function('require', 'exports', ...Object.keys(globals), compiled)((name: string) => {
      if (name === 'vue') return { ...Vue, resolveComponent: (component: string) => ({ name: component }) }
      if (name === '~/utils/pageShell') return pageShell
      if (name === '~/utils/adminListFilters') return requestRunner
      if (name === '~/composables/useApiError') return apiErrors
      return { name: name.split('/').at(-1)!.replace('.vue', '') }
    }, exports, ...Object.values(globals))
    return exports
  }
  const retryableSource = readFileSync(new URL('../app/composables/useRetryableLoad.ts', import.meta.url), 'utf8')
  globals.useRetryableLoad = evaluate(retryableSource).useRetryableLoad
  const filename = new URL('../app/pages/customers/painel.vue', import.meta.url)
  const { descriptor } = parse(readFileSync(filename, 'utf8'))
  const script = compileScript(descriptor, { id: 'client-portfolio-panel' })
  const template = compileTemplate({ source: descriptor.template!.content, filename: filename.pathname, id: 'client-portfolio-panel', compilerOptions: { bindingMetadata: script.bindings } })
  assert.deepEqual(template.errors, [])
  const component = evaluate(script.content).default as { setup: (props: object, context: object) => Record<string, unknown> }
  const scope = Vue.effectScope()
  const state = scope.run(() => component.setup({}, { expose() {} }))!
  const render = evaluate(template.code).render as (...args: unknown[]) => Vue.VNode
  function nodes(vnode: Vue.VNode): Vue.VNode[] {
    const result = [vnode]
    if (Array.isArray(vnode.children)) {
      for (const child of vnode.children) if (Vue.isVNode(child)) result.push(...nodes(child))
    }
    return result
  }
  return {
    reads, toasts, close: () => scope.stop(),
    start: () => mounted.forEach(callback => void callback()),
    nodes: () => nodes(render({}, [], {}, Vue.proxyRefs(state), {}, {}))
  }
}

const name = (node: Vue.VNode) => typeof node.type === 'object' ? (node.type as { name?: string }).name : node.type
const businessComponents = (nodes: Vue.VNode[]) => nodes.filter(node => ['PortfolioKpis', 'DeadlineAlertPanels', 'PortfolioDistribution'].includes(String(name(node))))
const flush = async () => {
  await Promise.resolve()
  await Promise.resolve()
  await Vue.nextTick()
}

describe('client portfolio panel truthful load states', () => {
  for (const failing of ['summary', 'analytics'] as const) {
    it(`hides regularity and zero indicators when ${failing} fails, then recovers both reads on retry`, async (t) => {
      const view = page()
      t.after(view.close)
      view.start()
      assert.ok(businessComponents(view.nodes()).every(node => node.props?.loading === true))
      const first = view.reads[0]!
      first[failing].reject({ statusCode: 503 })
      if (failing === 'summary') first.analytics.resolve(analytics)
      else first.summary.resolve(summary)
      await flush()
      const failed = view.nodes()
      const alert = failed.find(node => name(node) === 'ErrorRetryAlert')
      assert.ok(alert, 'A failed aggregate must render a persistent retryable error')
      assert.equal(businessComponents(failed).length, 0, 'No empty arrays or null summary may render as confirmed regularity/zero')

      const retrying = alert.props!.onRetry()
      assert.equal(view.reads.length, 2, 'Retry must refetch both aggregates')
      assert.ok(businessComponents(view.nodes()).every(node => node.props?.loading === true), 'Retry must not expose confirmed placeholder indicators')
      view.reads[1]!.summary.resolve(summary)
      view.reads[1]!.analytics.resolve(analytics)
      await retrying
      await flush()
      const recovered = view.nodes()
      assert.equal(recovered.some(node => name(node) === 'ErrorRetryAlert'), false)
      assert.deepEqual(recovered.find(node => name(node) === 'PortfolioKpis')?.props?.summary, summary)
      const deadlines = recovered.find(node => name(node) === 'DeadlineAlertPanels')
      assert.deepEqual(deadlines?.props?.certificate, analytics.attention.certificate)
      assert.deepEqual(deadlines?.props?.poa, analytics.attention.poa)
      assert.ok(businessComponents(recovered).every(node => node.props?.loading === false))
    })
  }

  it('keeps a failed retry in the error state instead of converting it to an empty portfolio', async (t) => {
    const view = page()
    t.after(view.close)
    view.start()
    view.reads[0]!.summary.reject({ statusCode: 503 })
    view.reads[0]!.analytics.resolve(analytics)
    await flush()
    const alert = view.nodes().find(node => name(node) === 'ErrorRetryAlert')
    assert.ok(alert)
    const retrying = alert.props!.onRetry()
    view.reads[1]!.summary.resolve(summary)
    view.reads[1]!.analytics.reject({ statusCode: 503 })
    await retrying
    await flush()
    assert.ok(view.nodes().some(node => name(node) === 'ErrorRetryAlert'))
    assert.equal(businessComponents(view.nodes()).length, 0)
  })
})
