import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'

const require = createRequire(import.meta.url)
const path = new URL('../app/components/monitoring/MessageDetail.vue', import.meta.url)
const { descriptor } = parse(readFileSync(path, 'utf8'), { filename: path.pathname })
const script = compileScript(descriptor, { id: 'mailbox' })
const template = compileTemplate({ source: descriptor.template!.content, filename: path.pathname, id: 'mailbox', compilerOptions: { bindingMetadata: script.bindings } })

function deferred() {
  let resolve!: (value: unknown) => void
  const promise = new Promise((done) => {
    resolve = done
  })
  return { promise, resolve }
}

function page(read: () => Promise<unknown> = async () => {
  throw { data: { code: 'operation_uncertain' } }
}) {
  const props = Vue.reactive({ open: true, obligation: { slug: 'caixas-postais/e-cac', label: 'e-CAC' }, clientName: 'Cliente', clientId: 9, stub: { id: 42, assunto: 'Intimação', ciencia_em: null, prazo_limite: null } })
  const account = Vue.ref({ id: 7 })
  let reads = 0
  let recoveries = 0
  const events: unknown[] = []
  const globals = {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, onBeforeUnmount() {},
    useToast: () => ({ add() {} }),
    useAuth: () => ({ canManageClients: Vue.ref(true), currentAccount: account }),
    useSerpro: () => ({ readMessage: () => {
      reads++
      return read()
    }, reconcileMessage: async () => {
      recoveries++
      return { id: 42, effect_status: 'confirmed', body_available: false, ciencia_em: '2026-10-15', prazo_limite: null, codigo: null, notice: 'O corpo da mensagem não foi recuperado.' }
    } })
  }
  function evaluate(code: string) {
    const compiled = ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext } }).outputText
    const exports: Record<string, unknown> = {}
    new Function('require', 'exports', ...Object.keys(globals), compiled)((name: string) => {
      if (name === 'vue') return { ...Vue, resolveComponent: (name: string) => ({ name }) }
      return require(name.startsWith('~/') ? fileURLToPath(new URL(`../app/${name.slice(2)}.ts`, import.meta.url)) : name)
    }, exports, ...Object.values(globals))
    return exports
  }
  const component = evaluate(script.content).default as { setup: (props: object, context: object) => Record<string, unknown> }
  const scope = Vue.effectScope()
  const state = scope.run(() => component.setup(props, { expose() {}, emit: (...args: unknown[]) => events.push(args) }))!
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
  return { props, account, state: state as { confirm: () => Promise<void>, reconcile?: () => Promise<void> }, events, reads: () => reads, recoveries: () => recoveries, nodes: () => nodes(render(props, [], props, Vue.proxyRefs(state), {}, {})), stop: () => scope.stop() }
}

const button = (view: Vue.VNode[], label: string) => view.find(node => node.props?.label === label)

describe('Mailbox explicit effects lifecycle', () => {
  it('opening never reads or reconciles, uncertain blocks retry without claiming nothing happened', async () => {
    const view = page()
    assert.equal(view.reads(), 0)
    assert.equal(view.recoveries(), 0)
    await view.state.confirm()
    assert.ok(view.nodes().some(node => node.props?.title === 'Resultado da ciência não confirmado'))
    assert.equal(button(view.nodes(), 'Abrir e registrar ciência'), undefined)
    await view.state.confirm()
    assert.equal(view.reads(), 1)
    assert.deepEqual(view.events, [])
    view.stop()
  })
  it('confirmed conflict offers explicit local recovery and never claims the body was recovered', async () => {
    const view = page(async () => {
      throw { data: { code: 'operation_confirmed', effect_status: 'confirmed' } }
    })
    await view.state.confirm()
    assert.equal(view.recoveries(), 0)
    const recovery = button(view.nodes(), 'Reconciliar dados locais')
    assert.ok(recovery)
    await recovery.props!.onClick()
    assert.equal(view.reads(), 1)
    assert.equal(view.recoveries(), 1)
    assert.ok(view.nodes().some(node => node.props?.title === 'Corpo da mensagem indisponível'))
    assert.equal(view.nodes().some(node => node.props?.title === 'Esta mensagem não tem texto'), false)
    view.stop()
  })
  it('explicit before-send error permits a new consent', async () => {
    const view = page(async () => {
      throw { data: { code: 'operation_before_send', effect_status: 'pending' } }
    })
    await view.state.confirm()
    assert.ok(button(view.nodes(), 'Abrir e registrar ciência'))
    assert.ok(view.nodes().some(node => node.props?.title === 'Consulta não enviada'))
    view.stop()
  })
  it('treats an unclassified network failure as uncertain rather than asserting no effect', async () => {
    const view = page(async () => {
      throw new Error('network interrupted')
    })
    await view.state.confirm()
    assert.ok(view.nodes().some(node => node.props?.title === 'Resultado da ciência não confirmado'))
    assert.equal(button(view.nodes(), 'Abrir e registrar ciência'), undefined)
    view.stop()
  })
  for (const change of ['client', 'close', 'account']) {
    it(`ignores a late provider response after ${change} changes`, async () => {
      const pending = deferred()
      const view = page(() => pending.promise)
      const action = view.state.confirm()
      if (change === 'client') view.props.clientId = 10
      if (change === 'close') view.props.open = false
      if (change === 'account') view.account.value = { id: 8 }
      await Vue.nextTick()
      pending.resolve({ id: 42, corpo: 'PRIVATE OLD BODY', effect_status: 'confirmed', body_available: true })
      await action
      assert.deepEqual(view.events, [])
      assert.equal(JSON.stringify(view.nodes()).includes('PRIVATE OLD BODY'), false)
      view.stop()
    })
  }
})
