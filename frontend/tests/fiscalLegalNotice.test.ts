import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'

const require = createRequire(import.meta.url)
function compileVueComponent(path: string, context: Record<string, unknown>) {
  const { descriptor } = parse(readFileSync(new URL(`../app/${path}`, import.meta.url), 'utf8'), { filename: path })
  const script = compileScript(descriptor, { id: path, inlineTemplate: true })
  const code = ts.transpileModule(script.content.replaceAll('import.meta.client', 'true'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext } }).outputText
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', ...Object.keys(context), code)((name: string) => {
    if (name === 'vue')
      return { ...Vue, resolveComponent: (name: string) => ({ name }) }
    return require(name.startsWith('~/') ? fileURLToPath(new URL(`../app/${name.slice(2)}.ts`, import.meta.url)) : name)
  }, exports, ...Object.values(context))
  return exports.default
}
function nodes(node: Vue.VNode): Vue.VNode[] {
  const result = [node]
  if (Array.isArray(node.children)) {
    for (const child of node.children)
      if (Vue.isVNode(child))
        result.push(...nodes(child))
  } else if (node.children && typeof node.children === 'object') {
    for (const slot of Object.values(node.children))
      if (typeof slot === 'function') {
        for (const child of [slot({ close() { } })].flat())
          if (Vue.isVNode(child))
            result.push(...nodes(child))
      }
  }
  return result
}
function sheet(authorizedAt: string | null, deadline: string | null, load?: (options: {
  signal?: AbortSignal
}) => Promise<unknown>, download: () => Promise<Blob> = async () => new Blob(['<nfeProc/>'])) {
  let exposed!: {
    open: (id: number) => Promise<void>
  }
  const account = Vue.ref({ id: 7 })
  const session = Vue.ref(1)
  const downloads: string[] = []
  const unmounted: (() => void)[] = []
  const toasts: unknown[] = []
  const component = compileVueComponent('components/fiscal/FiscalDocumentSheet.vue', {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch,
    URL: {
      createObjectURL: () => {
        downloads.push('created')
        return 'blob:fiscal'
      },
      revokeObjectURL: () => downloads.push('revoked')
    },
    document: { createElement: () => ({ click: () => downloads.push('clicked'), remove() {} }), body: { append() {} } },
    onBeforeUnmount: (fn: () => void) => unmounted.push(fn),
    useFiscal: () => ({ download, show: async (_id: number, options: {
      signal?: AbortSignal
    }) => load ? load(options) : ({ id: 1, client: { id: 2, name: 'Cliente' }, model: 'nfe', kind: 'document', stage: 'document', events: [], source: 'nfe_distribuicao', authorized_at: authorizedAt, conclusive_deadline_at: deadline, emissao_at: '2022-04-04', captured_at: '2026-10-02', xml_bytes: 444, sha256: 'abc', xml_preview: '<nfeProc/>' }) }),
    useAuth: () => ({ canManageClients: Vue.ref(false), currentAccount: account }),
    useToast: () => ({ add: (value: unknown) => toasts.push(value) }), useState: (key: string) => key === 'access-session-generation' ? session : Vue.ref(0)
  }) as {
    setup: (props: object, context: object) => (context: object, cache: unknown[]) => Vue.VNode
  }
  const scope = Vue.effectScope()
  const render = scope.run(() => component.setup({}, {
    expose: (value: typeof exposed) => {
      exposed = value
    }
  }))!
  return {
    account, session, downloads, toasts, open: () => exposed.open(1), nodes: () => nodes(render({}, [])),
    text: () => nodes(render({}, [])).map(node => node.type !== Vue.Comment && typeof node.children === 'string' ? node.children : '').join(' '),
    stop: () => {
      unmounted.forEach(fn => fn())
      scope.stop()
    }
  }
}
describe('Completed NF-e actual detail legal context', () => {
  it('retains non-conclusive notice after XML completion and shows independent authorization/deadline', async (t) => {
    const view = sheet('2026-10-01T12:00:00Z', '2026-12-30T12:00:00Z')
    t.after(view.stop)
    await view.open()
    assert.match(view.text(), /210210/)
    assert.match(view.text(), /XML não confirmam a operação/)
    assert.match(view.text(), /90 dias/)
    assert.match(view.text(), /01\/10\/2026/)
    assert.match(view.text(), /30\/12\/2026/)
    assert.equal(view.nodes().some(node => node.props?.label === 'Capturar agora'), false)
    assert.equal(view.nodes().find(node => node.props?.label === 'Baixar XML')?.props?.disabled, false)
  })
  for (const date of [null, '2099-01-01T12:00:00Z'])
    it(`labels ${date ? 'future' : 'missing'} authorization unverifiable instead of substituting issue date`, async (t) => {
      const view = sheet(date, null)
      t.after(view.stop)
      await view.open()
      assert.match(view.text(), /Prazo conclusivo: não verificável/)
      assert.match(view.text(), /Autorização pelo fisco: não verificável/)
      assert.doesNotMatch(view.text(), /04\/07\/2022|Prazo conclusivo: 01/)
    })
  for (const change of ['account', 'unmount'])
    for (const failing of [false, true])
      it(`ignores late detail ${failing ? 'failure' : 'success'} after ${change}`, async () => {
        let resolve!: (value: unknown) => void, reject!: (error: unknown) => void
        let signal: AbortSignal | undefined
        const deferred = new Promise((done, fail) => {
          resolve = done
          reject = fail
        })
        const view = sheet(null, null, (options) => {
          signal = options.signal
          return deferred
        })
        const opening = view.open()
        if (change === 'account')
          view.account.value = { id: 8 }
        else
          view.stop()
        assert.equal(signal?.aborted, true)
        if (failing)
          reject(new Error('old failure'))
        else
          resolve({ id: 1, client: { name: 'OLD' }, model: 'nfe', events: [], xml_preview: 'OLD PRIVATE XML' })
        await opening
        assert.doesNotMatch(view.text(), /OLD PRIVATE XML/)
        assert.deepEqual(view.toasts, [])
        if (change === 'account')
          view.stop()
      })
  it('discards same-Account prior-session detail success and closes the protected sheet', async (t) => {
    let resolve!: (value: unknown) => void
    let signal: AbortSignal | undefined
    const pending = new Promise((done) => {
      resolve = done
    })
    const view = sheet(null, null, (options) => {
      signal = options.signal
      return pending
    })
    t.after(view.stop)
    const opening = view.open()
    view.session.value++
    assert.equal(signal?.aborted, true)
    resolve({ id: 1, client: { name: 'OLD' }, model: 'nfe', events: [], xml_preview: 'OLD SESSION XML' })
    await opening
    assert.doesNotMatch(view.text(), /OLD SESSION XML/)
    assert.equal(view.nodes().find(node => node.props?.label === 'Baixar XML')?.props?.disabled, true)
  })
  it('never creates a Blob URL or clicks a stale same-Account prior-session XML download', async (t) => {
    let resolve!: (value: Blob) => void
    const pending = new Promise<Blob>((done) => {
      resolve = done
    })
    const view = sheet('2026-10-01T12:00:00Z', '2026-12-30T12:00:00Z', undefined, () => pending)
    t.after(view.stop)
    await view.open()
    const downloading = view.nodes().find(node => node.props?.label === 'Baixar XML')!.props!.onClick()
    view.session.value++
    resolve(new Blob(['OLD SESSION XML']))
    await downloading
    assert.deepEqual(view.downloads, [])
    assert.deepEqual(view.toasts, [])
    assert.doesNotMatch(view.text(), /<nfeProc/)
  })
  it('clears completed legal/document context when Account changes', async (t) => {
    const view = sheet('2026-10-01T12:00:00Z', '2026-12-30T12:00:00Z')
    t.after(view.stop)
    await view.open()
    view.account.value = { id: 8 }
    await Vue.nextTick()
    assert.doesNotMatch(view.text(), /30\/12\/2026|<nfeProc/)
    assert.equal(view.nodes().find(node => node.props?.label === 'Baixar XML')?.props?.disabled, true)
  })
})
