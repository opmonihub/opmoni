import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import * as Vue from 'vue'
import { parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { apiStatus } from '../app/composables/useApiError.ts'

type DataResult = { data: Vue.Ref<unknown>, error: Vue.Ref<unknown>, status: Vue.Ref<string>, refresh: () => Promise<unknown> }
type Payload = { serverRendered: boolean, data: Record<string, unknown>, _errors: Record<string, unknown> }
const compiledEngines = new Map<boolean, string>()

// Run the installed Nuxt engine, replacing only its Nuxt-app/build environment.
// This covers useAsyncData's null/error cache and unmount/reentry semantics;
// actual document hydration remains a separate browser acceptance gate.
function asyncDataEngine(server: boolean, payload: Payload) {
  const app = {
    payload: Vue.reactive(payload),
    isHydrating: !server,
    static: { data: {} },
    _asyncData: {},
    _asyncDataPromises: {},
    hook: () => () => {},
    runWithContext: (run: () => unknown) => run()
  }
  if (!compiledEngines.has(server)) {
    const source = readFileSync(new URL('../node_modules/nuxt/dist/app/composables/asyncData.js', import.meta.url), 'utf8')
      .replaceAll('import.meta.server', String(server))
      .replaceAll('import.meta.client', String(!server))
      .replaceAll('import.meta.dev', 'false')
      .replaceAll('import.meta.prerender', 'false')
    compiledEngines.set(server, ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText)
  }
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', compiledEngines.get(server)!)((path: string) => {
    if (path === 'vue') return Vue
    if (path.endsWith('/nuxt.js')) return { useNuxtApp: () => app }
    if (path.endsWith('/error.js')) return { createError: (error: unknown) => error }
    if (path.endsWith('/debounce-tick.js')) return { debounceTick: (run: unknown) => run }
    if (path.endsWith('/compiler/runtime/index.js')) return { defineKeyedFunctionFactory: (config: { factory: unknown }) => config.factory }
    if (path === '#build/nuxt.config.mjs') return { asyncDataDefaults: { deep: false }, granularCachedData: true, pendingWhenIdle: false, purgeCachedData: true, stripNeverHydratedData: false, vapor: false }
    return {}
  }, exports)
  return { app, useAsyncData: exports.useAsyncData }
}

function pageReader(path: string, useAsyncData: unknown, read: () => Promise<unknown>) {
  const sourcePath = path === 'monitoring' ? 'monitoring/index' : `settings/${path}`
  const { descriptor } = parse(readFileSync(new URL(`../app/pages/${sourcePath}.vue`, import.meta.url), 'utf8'))
  const source = ts.createSourceFile(path, descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
  let call: ts.CallExpression | undefined
  function visit(node: ts.Node) {
    if (ts.isCallExpression(node) && node.expression.getText(source) === 'useAsyncData') call = node
    ts.forEachChild(node, visit)
  }
  visit(source)
  assert.ok(call)
  const readChannel = source.statements.find(statement => ts.isFunctionDeclaration(statement) && statement.name?.text === 'readChannel')
  const code = ts.transpileModule(`${readChannel?.getText(source) ?? ''}\nreturn ${call.getText(source)}`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
  return () => new Function('useAsyncData', 'accountCertificate', 'channel', 'overview', 'apiStatus', code)(useAsyncData, read, read, read, apiStatus) as Promise<DataResult>
}

for (const page of ['certificado', 'whatsapp', 'monitoring']) {
  test(`${page} performs one safe read when the hydration payload is missing`, async () => {
    let reads = 0
    const client = asyncDataEngine(false, { serverRendered: true, data: {}, _errors: {} })
    const scope = Vue.effectScope()
    const value = page === 'monitoring' ? { portfolio_total: 0, attention: {} } : null
    const result = await scope.run(pageReader(page, client.useAsyncData, async () => {
      reads++
      return value
    }))!
    assert.equal(reads, 1)
    assert.deepEqual(result.data.value, value)
    assert.equal(result.status.value, 'success')
    scope.stop()
  })

  const values = page === 'monitoring'
    ? [{ portfolio_total: 16, attention: { dctf: 7 } }, { portfolio_total: 0, attention: {} }]
    : [{ status: 'connected', document: 'sample', subject: 'Escritório' }, { status: 'disconnected', portfolio_total: 0 }, null]
  for (const value of values) {
    test(`${page} reuses its SSR ${value === null ? '404/null' : JSON.stringify(value)} payload and rereads changed visits`, async () => {
      let reads = 0
      const read = async () => {
        reads++
        if (value === null) throw { statusCode: 404 }
        return value
      }
      const payload: Payload = { serverRendered: true, data: {}, _errors: {} }
      const server = asyncDataEngine(true, payload)
      const ssr = await pageReader(page, server.useAsyncData, read)()
      assert.equal(reads, 1)
      assert.equal(ssr.status.value, 'success')
      assert.equal(ssr.data.value, value)
      const client = asyncDataEngine(false, structuredClone(payload))
      const scope = Vue.effectScope()
      const hydrated = await scope.run(pageReader(page, client.useAsyncData, read))!
      assert.deepEqual(hydrated.data.value, value)
      assert.equal(hydrated.status.value, 'success')
      assert.equal(reads, 1, 'hydration must not repeat the SSR read, including null')
      client.app.isHydrating = false
      scope.stop()
      const changed = { status: 'changed-between-visits' }
      const reentryScope = Vue.effectScope()
      const reentered = await reentryScope.run(pageReader(page, client.useAsyncData, async () => {
        reads++
        return changed
      }))!
      assert.equal(reads, 2)
      assert.deepEqual(reentered.data.value, changed)
      reentryScope.stop()
    })
  }

  test(`${page} preserves an SSR read error through hydration and permits an explicit retry`, async () => {
    let reads = 0
    let fail = true
    const read = async () => {
      reads++
      if (fail) throw { statusCode: 503, message: 'Unavailable' }
      return { status: 'retried' }
    }
    const payload: Payload = { serverRendered: true, data: {}, _errors: {} }
    const server = asyncDataEngine(true, payload)
    const ssr = await pageReader(page, server.useAsyncData, read)()
    assert.equal(ssr.status.value, 'error')
    const client = asyncDataEngine(false, structuredClone(payload))
    const scope = Vue.effectScope()
    const hydrated = await scope.run(pageReader(page, client.useAsyncData, read))!
    assert.equal(hydrated.status.value, 'error')
    assert.deepEqual(hydrated.error.value, { statusCode: 503, message: 'Unavailable' })
    assert.equal(reads, 1)
    client.app.isHydrating = false
    fail = false
    await hydrated.refresh()
    assert.equal(reads, 2)
    assert.deepEqual(hydrated.data.value, { status: 'retried' })
    assert.equal(hydrated.error.value, undefined)
    assert.equal(hydrated.status.value, 'success')
    scope.stop()
  })
}
