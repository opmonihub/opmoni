import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { evaluateVueBindings, vueSource } from './helpers/compileVueComponent.ts'
import { officeAsyncDataEngine } from './helpers/officeAsyncData.ts'

test('Fiscal clients retain a remembered page through a failed read and retry, and bound a confirmed shrink', async () => {
  const engine = officeAsyncDataEngine(false, { serverRendered: false, data: {}, _errors: {} })
  engine.app.isHydrating = false
  let failing = true
  let count = 70
  const scope = Vue.effectScope()
  const result = await scope.run(() => (engine.useAsyncData as (...args: unknown[]) => Promise<{ data: Vue.Ref<unknown[]>, status: Vue.Ref<string>, refresh: () => Promise<void> }>)('fiscal-clients-recovery', async () => {
    if (failing) throw { statusCode: 503 }
    return Array.from({ length: count }, (_, id) => ({ id }))
  }))!
  const currentPage = Vue.ref(3)
  const bindings = {
    currentPage, pageSize: Vue.ref(25), filteredRows: Vue.computed(() => result.data.value ?? []), status: result.status,
    computed: Vue.computed, watch: Vue.watch
  }
  const source = vueSource('pages/fiscal/clientes.vue')
  const { pageCount } = evaluateVueBindings(source, ['pageCount'], bindings)
  const { descriptor } = parse(source)
  const script = ts.createSourceFile('fiscal-clients.ts', descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
  const watcher = script.statements.find(statement => ts.isExpressionStatement(statement) && statement.getText(script).includes('currentPage.value > count'))!
  assert.ok(watcher)
  const code = ts.transpileModule(watcher.getText(script), { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
  scope.run(() => new Function('watch', 'pageCount', 'currentPage', 'status', code)(Vue.watch, pageCount, currentPage, result.status))
  try {
    assert.equal(result.status.value, 'error')
    assert.equal(currentPage.value, 3, 'A failed read cannot confirm a smaller result set')
    failing = false
    await result.refresh()
    await Vue.nextTick()
    assert.equal(currentPage.value, 3)
    count = 5
    await result.refresh()
    await Vue.nextTick()
    assert.equal(currentPage.value, 1, 'A successful response confirms the smaller page count')
  } finally { scope.stop() }
})
