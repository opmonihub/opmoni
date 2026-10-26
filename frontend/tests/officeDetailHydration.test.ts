import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { vueSource } from './helpers/compileVueComponent.ts'
import { officeAsyncDataEngine, type OfficePayload } from './helpers/officeAsyncData.ts'

const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor

// Run the real detail declaration; only Nuxt's payload boundary and transport
// are injected. Browser SSR/hydration counts are a separate acceptance gate.
for (const [path, keyName, resultName, reader, entity] of [
  ['pages/work/modelos/[id].vue', 'templateReadKey', 'templateData', 'showTemplate', 'templateId'],
  ['pages/customers/empresa/[id].vue', 'clientReadKey', 'client', 'show', 'activeId']
]) {
  test(`${path} reuses its authority/entity payload, revalidates visits and forwards cancellation`, async () => {
    const { descriptor } = parse(vueSource(path))
    const script = ts.createSourceFile(path, descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
    const key = script.statements.find(statement => ts.isVariableStatement(statement) && statement.declarationList.declarations.some(declaration => ts.isIdentifier(declaration.name) && declaration.name.text === keyName))
    const read = script.statements.find(statement => ts.isVariableStatement(statement) && statement.getText(script).includes(`data: ${resultName}`) && statement.getText(script).includes('useAsyncData'))
    assert.ok(key, 'The detail key contains its authority and entity')
    assert.ok(read, 'The proven duplicate raw loader has one Nuxt payload owner')
    const compiled = ts.transpileModule(`${key.getText(script)}\n${read.getText(script)}\nreturn { ${keyName}, ${resultName} }`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
    const payload = new Map<string, unknown>()
    let reads = 0
    const signal = new AbortController().signal
    let hydrating = false
    const execute = async (id: number, authority = 'office-a') => {
      const context = {
        computed: Vue.computed,
        readAuthority: Vue.ref(authority), confirmedClient: Vue.ref(null),
        [entity]: Vue.ref(id),
        isNew: Vue.ref(false),
        [reader]: async (requested: number, options: { signal: AbortSignal }) => {
          assert.equal(options.signal, signal)
          reads++
          return { id: requested, name: `Record ${requested}` }
        },
        useAsyncData: async (key: Vue.Ref<string>, handler: (app: unknown, options: { signal: AbortSignal }) => Promise<unknown>, options: { getCachedData: (key: string, app: unknown) => unknown }) => {
          const app = { isHydrating: hydrating, payload: { data: Object.fromEntries(payload) } }
          let result = options.getCachedData(key.value, app)
          if (result === undefined) result = await handler(app, { signal })
          payload.set(key.value, result)
          return { data: Vue.ref(result), error: Vue.ref(null), status: Vue.ref('success'), refresh: async () => {} }
        }
      }
      return new AsyncFunction(...Object.keys(context), compiled)(...Object.values(context))
    }
    const first = await execute(1)
    assert.equal(first[resultName].value.id, 1)
    assert.equal(reads, 1)
    hydrating = true
    await execute(1)
    assert.equal(reads, 1, 'Hydration consumes the SSR payload')
    hydrating = false
    await execute(1)
    assert.equal(reads, 2, 'A later visit confirms current data')
    await execute(2)
    assert.equal(reads, 3)
    const other = await execute(1, 'office-b')
    assert.notEqual(other[keyName].value, first[keyName].value)
  })
}

for (const [path, resultName, reader, entity] of [
  ['pages/work/modelos/[id].vue', 'templateData', 'showTemplate', 'templateId'],
  ['pages/customers/empresa/[id].vue', 'client', 'show', 'activeId']
]) {
  test(`${path} preserves SSR data and failures with the installed Nuxt engine`, async () => {
    const { descriptor } = parse(vueSource(path))
    const source = ts.createSourceFile(path, descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
    const statement = source.statements.find(statement => ts.isVariableStatement(statement) && statement.getText(source).includes(`data: ${resultName}`) && statement.getText(source).includes('useAsyncData'))!
    let call: ts.CallExpression | undefined
    function find(node: ts.Node) {
      if (ts.isCallExpression(node) && node.expression.getText(source) === 'useAsyncData') call = node
      ts.forEachChild(node, find)
    }
    find(statement)
    assert.ok(call)
    const code = ts.transpileModule(`return ${call.getText(source)}`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
    for (const outcome of ['populated', 'empty', 'failure']) {
      let reads = 0
      let fail = outcome === 'failure'
      const read = async (_id: number, options: { signal: AbortSignal }) => {
        assert.ok(options.signal instanceof AbortSignal)
        reads++
        if (fail) throw { statusCode: 503, message: 'Unavailable' }
        return outcome === 'empty' ? null : { id: 1, name: 'Authorized record' }
      }
      const execute = (useAsyncData: unknown) => {
        const context = { confirmedClient: Vue.ref(null), useAsyncData, templateReadKey: Vue.ref('detail-key'), clientReadKey: Vue.ref('detail-key'), [entity]: Vue.ref(1), [reader]: read, isNew: Vue.ref(false) }
        return new Function(...Object.keys(context), code)(...Object.values(context)) as Promise<{ data: Vue.Ref<unknown>, error: Vue.Ref<unknown>, status: Vue.Ref<string>, refresh: () => Promise<void> }>
      }
      const payload: OfficePayload = { serverRendered: true, data: {}, _errors: {} }
      const server = officeAsyncDataEngine(true, payload)
      const initial = await execute(server.useAsyncData)
      const client = officeAsyncDataEngine(false, structuredClone(payload))
      const scope = Vue.effectScope()
      try {
        const hydrated = await scope.run(() => execute(client.useAsyncData))!
        assert.equal(reads, 1, outcome)
        assert.deepEqual(hydrated.data.value, initial.data.value)
        assert.equal(hydrated.status.value, outcome === 'failure' ? 'error' : 'success')
        client.app.isHydrating = false
        fail = false
        await hydrated.refresh()
        assert.equal(reads, 2)
        assert.equal(hydrated.status.value, 'success')
        assert.equal(hydrated.error.value, undefined)
      } finally { scope.stop() }
    }
  })
}

test('installed Nuxt cancels an unmounted detail owner without publishing a departed error', async () => {
  const { descriptor } = parse(vueSource('pages/work/modelos/[id].vue'))
  const source = ts.createSourceFile('detail.ts', descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
  let call: ts.CallExpression | undefined
  function find(node: ts.Node) {
    if (ts.isCallExpression(node) && node.expression.getText(source) === 'useAsyncData' && node.arguments[0]?.getText(source) === 'templateReadKey') call = node
    ts.forEachChild(node, find)
  }
  find(source)
  assert.ok(call)
  const code = ts.transpileModule(`return ${call.getText(source)}`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
  const client = officeAsyncDataEngine(false, { serverRendered: false, data: {}, _errors: {} })
  client.app.isHydrating = false
  let signal: AbortSignal | undefined
  const scope = Vue.effectScope()
  const result = scope.run(() => new Function('useAsyncData', 'templateReadKey', 'templateId', 'isNew', 'showTemplate', code)(client.useAsyncData, Vue.ref('template-owner'), Vue.ref(1), Vue.ref(false), (_id: number, options: { signal: AbortSignal }) => {
    signal = options.signal
    return new Promise(() => {})
  })) as { data: Vue.Ref<unknown>, error: Vue.Ref<unknown>, status: Vue.Ref<string> }
  assert.ok(signal)
  assert.equal(signal.aborted, false)
  scope.stop()
  await Vue.nextTick()
  assert.equal(signal.aborted, true)
  assert.equal(result.error.value, undefined)
  assert.equal(result.status.value, 'idle')
})
