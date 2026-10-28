import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import type { FiscalProcessingPage } from '../app/types/fiscal.ts'
import { queryOf } from '../app/composables/useApiQuery.ts'

const require = createRequire(import.meta.url)
function compileVueComponent(path: string, context: Record<string, unknown>) {
  const { descriptor } = parse(readFileSync(new URL(`../app/${path}`, import.meta.url), 'utf8'), { filename: path })
  const script = compileScript(descriptor, { id: path, inlineTemplate: true })
  const code = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext } }).outputText
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', ...Object.keys(context), code)((name: string) => {
    if (name === 'vue')
      return { ...Vue, resolveComponent: (name: string) => ({ name }) }
    return require(name.startsWith('~/') ? fileURLToPath(new URL(`../app/${name.slice(2)}.ts`, import.meta.url)) : name)
  }, exports, ...Object.values(context))
  return exports.default
}
function deferred() {
  let resolve!: (value: unknown) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}
const response = (client = 'Cliente A', total = 60): FiscalProcessingPage => ({
  data: total ? [{ client_id: 2, source: 'nfe_distribuicao', stage: 'summary', emissao_at: '2022-04-04T12:00:00Z', processing_attempts: 1, id: 1, client_name: client, chave_acesso: '123', model: 'nfe', authorized_at: '2026-10-01T12:00:00Z', conclusive_deadline_at: '2026-12-30T12:00:00Z', next_attempt_at: '2026-10-18T12:00:00Z', processing_state: 'deferred', processing_reason: 'budget_cooldown', storage_state: 'unverified' }] : [],
  summary: { active: total ? 1 : 0, deferred: total ? 1 : 0, attention: total ? 1 : 0 },
  meta: { current_page: 1, last_page: total ? 3 : 1, per_page: 25, total }
})
function nodes(vnode: Vue.VNode): Vue.VNode[] {
  const result = [vnode]
  if (Array.isArray(vnode.children)) {
    for (const child of vnode.children)
      if (Vue.isVNode(child))
        result.push(...nodes(child))
  } else if (vnode.children && typeof vnode.children === 'object') {
    for (const slot of Object.values(vnode.children))
      if (typeof slot === 'function') {
        for (const child of [slot()].flat())
          if (Vue.isVNode(child))
            result.push(...nodes(child))
      }
  }
  return result
}
function page() {
  const mounted: (() => void)[] = [], unmounted: (() => void)[] = []
  const session = Vue.ref(1)
  const account = Vue.ref<{
    id: number
  } | null>({ id: 7 })
  const reads: (ReturnType<typeof deferred> & {
    filters: unknown
    signal?: AbortSignal
  })[] = []
  let tick: (() => void) | undefined, cleared = false
  const document = { visibilityState: 'visible' }
  const component = compileVueComponent('pages/fiscal/processamento.vue', {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch,
    useState: () => session,
    useOfficeReadLifecycle: () => ({ own() {} }),
    useListNavigationState: (_key: string, options: { defaults: () => unknown }) => Vue.ref(options.defaults()),
    listNavigationField: (state: Vue.Ref<Record<string, unknown>>, key: string) => Vue.computed({
      get: () => state.value[key], set: (value) => { state.value = { ...state.value, [key]: value } }
    }),
    definePageMeta() { }, useAuth: () => ({ currentAccount: account }),
    onMounted: (callback: () => void) => mounted.push(callback),
    onBeforeUnmount: (callback: () => void) => unmounted.push(callback),
    setInterval: (callback: () => void, delay: number) => {
      assert.equal(delay, 30000)
      tick = callback
      return 1
    },
    clearInterval: () => { cleared = true }, document,
    useFiscal: () => ({ processingList: (filters: unknown, options?: {
      signal: AbortSignal
    }) => {
      const read = { ...deferred(), filters, signal: options?.signal }
      reads.push(read)
      return read.promise
    } })
  }) as {
    setup: (props: object, context: object) => (context: object, cache: unknown[]) => Vue.VNode
  }
  const scope = Vue.effectScope()
  const render = scope.run(() => component.setup({}, { expose() { } }))!
  return {
    account, session, reads, document, start: () => mounted.forEach(fn => fn()),
    poll: () => tick?.(), cleared: () => cleared, nodes: () => nodes(render({}, [])),
    text: () => nodes(render({}, [])).map(node => node.type !== Vue.Comment && typeof node.children === 'string' ? node.children : '').join(' '),
    stop: () => {
      unmounted.forEach(fn => fn())
      scope.stop()
    }
  }
}
const flush = async () => {
  await Promise.resolve()
  await Promise.resolve()
  await Vue.nextTick()
}
const button = (view: ReturnType<typeof page>, label: string) => view.nodes().find(node => node.props?.label === label)
describe('Fiscal processing actual page lifecycle', () => {
  it('initial failure is unknown, offers retry, and only successful empty responses show zero', async (t) => {
    const view = page()
    t.after(view.stop)
    view.start()
    view.reads[0]!.reject(new Error('offline'))
    await flush()
    assert.doesNotMatch(view.text(), /Ativos: 0|Nenhum item em processamento/)
    assert.match(view.text(), /Contagens indisponíveis/)
    assert.ok(button(view, 'Tentar novamente'))
    button(view, 'Tentar novamente')!.props!.onClick()
    await flush()
    assert.equal(view.reads.length, 2)
    view.reads[1]!.resolve(response('', 0))
    await flush()
    assert.match(view.text(), /Ativos: 0/)
    assert.match(view.text(), /Nenhum item em processamento/)
  })
  it('keeps confirmed rows/counts on refresh failure and renders readable dates and reasons', async (t) => {
    const view = page()
    t.after(view.stop)
    view.start()
    view.reads[0]!.resolve(response())
    await flush()
    assert.match(view.text(), /Aguardando janela de consulta/)
    assert.match(view.text(), /01\/10\/2026/)
    assert.match(view.text(), /18\/10\/2026/)
    assert.match(view.text(), /90 dias/)
    assert.match(view.text(), /10 dias/)
    assert.match(view.text(), /Prazo conclusivo: 30\/12\/2026/)
    view.poll()
    view.reads[1]!.reject(new Error('offline'))
    await flush()
    assert.match(view.text(), /Cliente A/)
    assert.match(view.text(), /Ativos: 1/)
    assert.match(view.text(), /Última atualização confirmada/)
  })
  for (const failure of [false, true])
    it(`ignores late ${failure ? 'failure' : 'success'} after Account change and aborts prior read`, async (t) => {
      const view = page()
      t.after(view.stop)
      view.start()
      view.account.value = { id: 8 }
      await flush()
      assert.equal(view.reads.length, 2)
      assert.equal(view.reads[0]!.signal?.aborted, true)
      view.reads[1]!.resolve(response('Cliente B'))
      await flush()
      if (failure)
        view.reads[0]!.reject(new Error('old failure'))
      else
        view.reads[0]!.resolve(response('PRIVATE OLD CLIENT'))
      await flush()
      assert.match(view.text(), /Cliente B/)
      assert.doesNotMatch(view.text(), /PRIVATE OLD CLIENT|Não foi possível/)
    })
  for (const failing of [false, true])
    it(`invalidates same-Account prior-session processing ${failing ? 'failure' : 'success'}`, async (t) => {
      const view = page()
      t.after(view.stop)
      view.start()
      view.session.value++
      assert.equal(view.reads[0]!.signal?.aborted, true)
      assert.equal(view.reads.length, 2)
      view.reads[1]!.resolve(response('Current session'))
      await flush()
      if (failing) view.reads[0]!.reject(new Error('old session failure'))
      else view.reads[0]!.resolve(response('OLD SESSION PRIVATE ROW'))
      await flush()
      assert.match(view.text(), /Current session/)
      assert.doesNotMatch(view.text(), /OLD SESSION PRIVATE ROW|Não foi possível/)
    })
  it('resets page and invalidates delayed failures on filter changes; page changes keep filter', async (t) => {
    const view = page()
    t.after(view.stop)
    view.start()
    view.reads[0]!.resolve(response())
    await flush()
    const pagination = () => view.nodes().find(node => node.props && 'onUpdate:page' in node.props)!
    pagination().props!['onUpdate:page'](3)
    await flush()
    assert.deepEqual(view.reads[1]!.filters, { page: 3, per_page: 25, state: undefined })
    const select = view.nodes().find(node => node.props?.['aria-label'] === 'Estado do processamento')!
    select.props!['onUpdate:modelValue']('attention')
    await flush()
    assert.equal(view.reads[1]!.signal?.aborted, true)
    assert.deepEqual(view.reads[2]!.filters, { page: 1, per_page: 25, state: 'attention' })
    view.reads[1]!.reject(new Error('stale'))
    await flush()
    assert.doesNotMatch(view.text(), /Não foi possível/)
    view.reads[2]!.resolve(response())
    await flush()
    pagination().props!['onUpdate:page'](2)
    await flush()
    assert.deepEqual(view.reads[3]!.filters, { page: 2, per_page: 25, state: 'attention' })
  })
  for (const date of [null, '2099-01-01T12:00:00Z'])
    it('does not invent processing authorization or conclusive dates', async (t) => {
      const view = page()
      t.after(view.stop)
      view.start()
      const data = response()
      data.data[0]!.authorized_at = date
      data.data[0]!.conclusive_deadline_at = null
      view.reads[0]!.resolve(data)
      await flush()
      assert.match(view.text(), /Autorização pelo fisco: não verificável/)
      assert.match(view.text(), /Prazo conclusivo: não verificável/)
    })
  it('labels unavailable completed XML as attention while retaining its completion audit', async (t) => {
    const view = page()
    t.after(view.stop)
    view.start()
    const data = response()
    Object.assign(data.data[0]!, { stage: 'document', processing_state: 'complete', processing_reason: 'ready', storage_state: 'missing' })
    view.reads[0]!.resolve(data)
    await flush()
    assert.match(view.text(), /Requer atenção — Arquivo XML indisponível/)
    assert.doesNotMatch(view.text(), /Concluído — XML pronto/)
  })
  for (const storageState of ['available', 'unverified', 'missing', 'corrupt'])
    it(`explains artifact conflict requiring reconciliation with ${storageState} storage without starting recovery`, async (t) => {
      const view = page()
      t.after(view.stop)
      view.start()
      const data = response()
      Object.assign(data.data[0]!, { stage: 'document', processing_state: 'attention', processing_reason: 'artifact_conflict', storage_state: storageState })
      view.reads[0]!.resolve(data)
      await flush()
      assert.match(view.text(), /Requer atenção — Conflito entre arquivos XML: reconciliação necessária/)
      assert.doesNotMatch(view.text(), /Motivo ainda não informado|XML pronto/)
      assert.deepEqual(view.nodes().filter(node => node.props?.label && !node.props.to).map(node => node.props!.label), ['Atualizar'])
      assert.equal(button(view, 'Documentos prontos')?.props?.to, '/fiscal/documentos')
      assert.equal(view.reads.length, 1, 'Receiving a conflict must not start another request')
      button(view, 'Atualizar')!.props!.onClick()
      assert.equal(view.reads.length, 2, 'Only the explicit refresh performs another processing GET')
      view.reads[1]!.resolve(data)
      await flush()
      assert.match(view.text(), /Conflito entre arquivos XML: reconciliação necessária/)
    })
  it('bounds polling, blocks overlapping refreshes, and invalidates on unmount', async () => {
    const view = page()
    view.start()
    view.poll()
    assert.equal(view.reads.length, 1)
    view.reads[0]!.resolve(response())
    await flush()
    view.document.visibilityState = 'hidden'
    view.poll()
    assert.equal(view.reads.length, 1)
    view.document.visibilityState = 'visible'
    view.poll()
    assert.equal(view.reads.length, 2)
    view.stop()
    assert.equal(view.cleared(), true)
    assert.equal(view.reads[1]!.signal?.aborted, true)
    const before = view.text()
    view.reads[1]!.resolve(response('OLD'))
    await flush()
    assert.equal(view.text(), before)
  })
})

it('ignores a delayed processing failure after unmount without publishing an error', async () => {
  const view = page()
  view.start()
  const before = view.text()
  view.stop()
  view.reads[0]!.reject(new Error('late network failure'))
  await flush()
  assert.equal(view.text(), before)
  assert.doesNotMatch(view.text(), /Não foi possível/)
})

it('forwards processing filters and cancellation through the actual authenticated fiscal adapter', async () => {
  const source = readFileSync(new URL('../app/composables/useFiscal.ts', import.meta.url), 'utf8')
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext } }).outputText
  const requests: { path: string, options: { signal?: AbortSignal, query?: object } }[] = []
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', 'useNuxtApp', code)(() => ({ queryOf }), exports, () => ({
    $api: async (path: string, options: { signal?: AbortSignal, query?: object }) => {
      requests.push({ path, options })
      return { data: [] }
    }
  }))
  const client = (exports.useFiscal as () => {
    processingList: (params: object, options: { signal: AbortSignal }) => Promise<unknown>
    show: (id: number, options: { signal: AbortSignal }) => Promise<unknown>
  })()
  const controller = new AbortController()
  await client.processingList({ page: 2, per_page: 25, state: 'attention', client_id: null }, { signal: controller.signal })
  await client.show(7, { signal: controller.signal })
  assert.equal(requests[0]!.path, '/fiscal/processing')
  assert.deepEqual(requests[0]!.options.query, { page: 2, per_page: 25, state: 'attention' })
  assert.equal(requests[1]!.path, '/fiscal/documents/7')
  controller.abort()
  assert.equal(requests[0]!.options.signal?.aborted, true)
  assert.equal(requests[1]!.options.signal?.aborted, true)
})
