import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'

const require = createRequire(import.meta.url)
const path = new URL('../app/pages/settings/certificado.vue', import.meta.url)
const { descriptor } = parse(readFileSync(path, 'utf8'), { filename: path.pathname })
const script = compileScript(descriptor, { id: 'serpro-settings' })
const template = compileTemplate({ source: descriptor.template!.content, filename: path.pathname, id: 'serpro-settings', compilerOptions: { bindingMetadata: script.bindings } })

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

async function page(options: { schedules?: () => Promise<Record<string, number>>, superAdmin?: boolean, role?: string | null, enabled?: boolean, failEnablement?: boolean } = {}) {
  const mounted: (() => unknown)[] = []
  const writes: unknown[] = []
  const toggles: boolean[] = []
  const globals = {
    ref: Vue.ref, shallowRef: Vue.shallowRef, computed: Vue.computed, reactive: Vue.reactive,
    definePageMeta() {}, useToast: () => ({ add() {} }),
    useAuth: () => ({ isSuperAdmin: Vue.ref(options.superAdmin ?? true), currentRole: Vue.ref(options.role === undefined ? 'admin' : options.role), currentAccount: Vue.ref({ id: 7, name: 'Escritório', status: 'active' }) }),
    useSerpro: () => ({
      accountCertificate: async () => { throw { statusCode: 404 } },
      schedules: options.schedules ?? (async () => ({ 'declaracoes/pgdas': 10 })),
      setSchedules: async (map: unknown) => {
        writes.push(map)
        return map
      },
      enablement: async () => {
        if (options.failEnablement) throw { statusCode: 503 }
        return { enabled: options.enabled ?? false }
      },
      setEnablement: async (enabled: boolean) => {
        toggles.push(enabled)
        return { enabled }
      }
    }),
    onMounted: (fn: () => unknown) => mounted.push(fn),
    useAsyncData: async (_key: string, load: () => Promise<unknown>) => ({ data: Vue.ref(await load()), status: Vue.ref('success'), error: Vue.ref(null), refresh: async () => {} }),
    useRetryableLoad: () => ({ isLoading: Vue.ref(false), showError: Vue.ref(false), retry() {} })
  }
  function evaluate(code: string) {
    const compiled = ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext } }).outputText
    const exports: Record<string, unknown> = {}
    new Function('require', 'exports', ...Object.keys(globals), compiled)((name: string) => {
      if (name === 'vue') return { ...Vue, withAsyncContext: (run: () => unknown) => [run(), () => {}], resolveComponent: (name: string) => ({ name }) }
      return require(name.startsWith('~/') ? fileURLToPath(new URL(`../app/${name.slice(2)}.ts`, import.meta.url)) : name)
    }, exports, ...Object.values(globals))
    return exports
  }
  const component = evaluate(script.content).default as { setup: (props: object, context: object) => Promise<Record<string, unknown>> }
  const state = await component.setup({}, { expose() {} }) as {
    submitSchedules: () => Promise<void>
    loadSchedules: () => Promise<void>
    scheduleInputs: Record<string, string>
    toggleEnablement?: () => Promise<void>
  }
  const render = evaluate(template.code).render as (...args: unknown[]) => Vue.VNode
  function nodes(vnode: Vue.VNode): Vue.VNode[] {
    const result = [vnode]
    if (Array.isArray(vnode.children)) {
      for (const child of vnode.children) if (Vue.isVNode(child)) result.push(...nodes(child))
    } else if (vnode.children && typeof vnode.children === 'object') {
      for (const slot of Object.values(vnode.children)) if (typeof slot === 'function') {
        const children = slot()
        for (const child of Array.isArray(children) ? children : [children]) if (Vue.isVNode(child)) result.push(...nodes(child))
      }
    }
    return result
  }
  return { state, writes, toggles, start: () => mounted.forEach(fn => void fn()), nodes: () => nodes(render({}, [], {}, Vue.proxyRefs(state), {}, {})) }
}
const button = (view: Vue.VNode[], label: string) => view.find(node => node.props?.label === label)
const flush = async () => {
  await Promise.resolve()
  await Vue.nextTick()
  await Promise.resolve()
}

describe('SERPRO settings request lifecycle', () => {
  it('cannot replace saved schedules while their initial read is pending', async () => {
    const pending = deferred<Record<string, number>>()
    const view = await page({ schedules: () => pending.promise })
    view.start()
    assert.equal(button(view.nodes(), 'Salvar agendamentos')?.props?.disabled, true)
    const dayInputs = view.nodes().filter(node => node.props?.['aria-label'] === 'Dia do mês')
    assert.ok(dayInputs.length)
    assert.ok(dayInputs.every(node => node.props?.disabled === true))
    await view.state.submitSchedules()
    assert.deepEqual(view.writes, [])
    pending.resolve({ 'declaracoes/pgdas': 10 })
    await flush()
    assert.equal(button(view.nodes(), 'Salvar agendamentos')?.props?.disabled, false)
  })
  it('keeps a failed read locked until retry restores saved values', async () => {
    let fail = true
    const view = await page({ schedules: async () => {
      if (fail) throw { statusCode: 503 }
      return { 'declaracoes/pgdas': 10 }
    } })
    view.start()
    await flush()
    await view.state.submitSchedules()
    assert.deepEqual(view.writes, [])
    assert.equal(button(view.nodes(), 'Salvar agendamentos')?.props?.disabled, true)
    fail = false
    await view.state.loadSchedules()
    assert.equal(view.state.scheduleInputs['declaracoes/pgdas'], '10')
    await view.state.submitSchedules()
    assert.deepEqual(view.writes, [{ 'declaracoes/pgdas': 10 }])
  })
  it('does not reread over edits once the initial schedule read succeeded', async () => {
    let calls = 0
    const view = await page({ schedules: async () => {
      calls++
      return { 'declaracoes/pgdas': 10 }
    } })
    await view.state.loadSchedules()
    view.state.scheduleInputs['declaracoes/pgdas'] = '15'
    await view.state.loadSchedules()
    assert.equal(view.state.scheduleInputs['declaracoes/pgdas'], '15')
    assert.equal(calls, 1)
  })
  it('exposes enablement independently from credentials to a Super Admin member administrator', async () => {
    const view = await page()
    view.start()
    await flush()
    const toggle = button(view.nodes(), 'Habilitar integração')
    assert.ok(toggle, 'The current office must have a reachable enablement action')
    await toggle.props!.onClick()
    assert.deepEqual(view.toggles, [true])
    const disable = button(view.nodes(), 'Desabilitar integração')
    assert.ok(disable)
    await disable.props!.onClick()
    assert.deepEqual(view.toggles, [true], 'Opening disable confirmation must not stop integration')
    await button(view.nodes(), 'Confirmar desabilitação')!.props!.onClick()
    assert.deepEqual(view.toggles, [true, false])
  })
  for (const access of [{ superAdmin: false, role: 'admin' }, { superAdmin: true, role: 'operador' }, { superAdmin: true, role: null }]) {
    it(`does not offer or perform enablement writes for ${JSON.stringify(access)}`, async () => {
      const view = await page(access)
      view.start()
      await flush()
      assert.equal(button(view.nodes(), 'Habilitar integração'), undefined)
      if (view.state.toggleEnablement) await view.state.toggleEnablement()
      assert.deepEqual(view.toggles, [])
    })
  }
  it('does not offer an enablement write when its initial state cannot be read', async () => {
    const options = { failEnablement: true }
    const view = await page(options)
    view.start()
    await flush()
    const toggle = button(view.nodes(), 'Habilitar integração')
    assert.ok(!toggle || toggle.props?.disabled)
    if (view.state.toggleEnablement) await view.state.toggleEnablement()
    assert.deepEqual(view.toggles, [])
    const failure = view.nodes().find(node => node.props?.title === 'Não foi possível carregar o estado da integração')
    assert.ok(failure)
    options.failEnablement = false
    await failure.props!.onRetry()
    assert.ok(button(view.nodes(), 'Habilitar integração'), 'Retry restores the authorized action')
  })
})
