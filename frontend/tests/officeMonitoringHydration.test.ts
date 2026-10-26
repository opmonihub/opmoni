import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { vueSource } from './helpers/compileVueComponent.ts'
import { officeAsyncDataEngine, type OfficePayload } from './helpers/officeAsyncData.ts'

test('Monitoring uses the installed Nuxt payload for populated, zero, null and failed reads and refreshes later visits', async () => {
  const { descriptor } = parse(vueSource('pages/monitoring/index.vue'))
  const source = ts.createSourceFile('monitoring.ts', descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
  let call: ts.CallExpression | undefined
  function visit(node: ts.Node) {
    if (ts.isCallExpression(node) && node.expression.getText(source) === 'useAsyncData') call = node
    ts.forEachChild(node, visit)
  }
  visit(source)
  assert.ok(call)
  const code = ts.transpileModule(`return ${call.getText(source)}`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
  for (const value of [{ portfolio_total: 16, attention: { dctf: 7 } }, { portfolio_total: 0, attention: {} }, null, 'failure']) {
    let reads = 0
    let failed = value === 'failure'
    let current: unknown = value
    const overview = async (options: { signal: AbortSignal }) => {
      assert.ok(options.signal instanceof AbortSignal)
      reads++
      if (failed) throw { statusCode: 503, message: 'Unavailable' }
      return current
    }
    const execute = (useAsyncData: unknown) => new Function('useAsyncData', 'overview', code)(useAsyncData, overview) as Promise<{ data: Vue.Ref<unknown>, error: Vue.Ref<unknown>, status: Vue.Ref<string>, refresh: () => Promise<void> }>
    const payload: OfficePayload = { serverRendered: true, data: {}, _errors: {} }
    await execute(officeAsyncDataEngine(true, payload).useAsyncData)
    const client = officeAsyncDataEngine(false, structuredClone(payload))
    const scope = Vue.effectScope()
    const hydrated = await scope.run(() => execute(client.useAsyncData))!
    assert.equal(reads, 1)
    assert.equal(hydrated.status.value, failed ? 'error' : 'success')
    if (!failed) assert.deepEqual(hydrated.data.value, value)
    client.app.isHydrating = false
    failed = false
    current = { portfolio_total: 17, attention: { dctf: 8 } }
    await hydrated.refresh()
    assert.equal(reads, 2)
    assert.deepEqual(hydrated.data.value, current)
    scope.stop()
    const returnScope = Vue.effectScope()
    try {
      await returnScope.run(() => execute(client.useAsyncData))!
      assert.equal(reads, 3, 'Returning confirms current stored state')
    } finally { returnScope.stop() }
  }
})
