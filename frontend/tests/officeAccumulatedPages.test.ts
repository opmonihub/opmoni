import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { vueSource } from './helpers/compileVueComponent.ts'

function handler(path: string, bindings: Record<string, unknown>) {
  const { descriptor } = parse(vueSource(path))
  const script = ts.createSourceFile(path, descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
  let read: ts.Expression | undefined
  function visit(node: ts.Node) {
    if (ts.isCallExpression(node) && node.expression.getText(script) === 'useAsyncData' && node.arguments[0]?.getText(script) === 'listKey') read = node.arguments[1]
    ts.forEachChild(node, visit)
  }
  visit(script)
  assert.ok(read)
  const code = ts.transpileModule(`return ${read.getText(script)}`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
  return new Function(...Object.keys(bindings), code)(...Object.values(bindings)) as (app: unknown, options: { signal: AbortSignal }) => Promise<{ data: unknown, meta?: unknown, data_rows?: unknown[] }>
}

for (const lastPage of [1, 2, 3]) {
  test(`portfolio rebuilds a remembered page from current records bounded by server last page ${lastPage}`, async () => {
    const pages: number[] = []
    const signal = new AbortController().signal
    const load = handler('pages/customers/[documento]/[[situacao]].vue', {
      page: Vue.ref(3), params: Vue.ref({ q: 'Current filter' }),
      list: async (query: { page?: number, q: string }, options: { signal: AbortSignal }) => {
        assert.equal(query.q, 'Current filter')
        assert.equal(options.signal, signal)
        const page = query.page ?? 1
        pages.push(page)
        return { data: [{ id: page, name: `Current ${page}` }], meta: { mode: 'paged', total: lastPage, last_page: lastPage, current_page: page } }
      }
    })
    const result = await load({}, { signal })
    assert.deepEqual(pages, Array.from({ length: lastPage }, (_, index) => index + 1))
    assert.equal((result.data as unknown[]).length, lastPage)
    assert.equal((result.meta as { current_page: number }).current_page, lastPage)
  })
  test(`Monitoring rebuilds its remembered pages bounded by current total ${lastPage}`, async () => {
    const pages: number[] = []
    const signal = new AbortController().signal
    const load = handler('components/monitoring/MonitoringSheet.vue', {
      page: Vue.ref(3), params: Vue.ref({ q: 'Current filter' }), props: { obligation: { slug: 'declaracoes/dctf' } }, isUnserved: Vue.ref(false),
      apiStatus: () => 0,
      listObligation: async (slug: string, query: { page?: number, q: string }, options: { signal: AbortSignal }) => {
        assert.equal(slug, 'declaracoes/dctf')
        assert.equal(query.q, 'Current filter')
        assert.equal(options.signal, signal)
        const page = query.page ?? 1
        pages.push(page)
        return { data_rows: [{ client_id: page, name: `Current ${page}` }], data: { total: lastPage, current_page: page } }
      }
    })
    const result = await load({}, { signal })
    assert.deepEqual(pages, Array.from({ length: lastPage }, (_, index) => index + 1))
    assert.equal(result.data_rows!.length, lastPage)
    assert.equal((result.data as { current_page: number }).current_page, lastPage)
  })
}
