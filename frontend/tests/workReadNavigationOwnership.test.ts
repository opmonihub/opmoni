import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { vueSource } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { officeAsyncDataEngine } from './helpers/officeAsyncData.ts'

const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor

test('Work month transitions do not read from still-mounted departing pages', async () => {
  const currentRoute = Vue.ref({ path: '/work/tarefas' })
  const referenceMonth = Vue.ref('2026-08')
  const registry = Vue.ref({ authority: 'office' })
  const engine = officeAsyncDataEngine(false, { serverRendered: false, data: {}, _errors: {} })
  engine.app.isHydrating = false
  const shared = { computed: Vue.computed, watch: Vue.watch, nextTick: Vue.nextTick, onScopeDispose: Vue.onScopeDispose, useListNavigationRegistry: () => ({ registry }) }
  const office = loadAppComposable<Record<string, unknown>>('composables/useOfficeReadLifecycle.ts', shared)
  const work = loadAppComposable<Record<string, unknown>>('composables/useWorkReadLifecycle.ts', { ...shared, ...office, useRouter: () => ({ currentRoute }) })
  const reads: string[] = []
  async function open(path: string, key: string, ownerPath: string) {
    const { descriptor } = parse(vueSource(path))
    const script = ts.createSourceFile(path, descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
    const owner = script.statements.find(statement => ts.isVariableStatement(statement) && statement.declarationList.declarations.some(declaration => declaration.name.getText(script) === 'readLifecycle'))!
    const read = script.statements.find(statement => ts.isVariableStatement(statement) && statement.getText(script).includes(`'${key}'`))!
    const registration = script.statements.find(statement => ts.isExpressionStatement(statement) && statement.getText(script).startsWith('readLifecycle.own({ clear: clearOffice0'))!
    const code = ts.transpileModule(`${owner.getText(script)}\n${read.getText(script)}\n${registration.getText(script)}\nreturn { data, clear: clearOffice0, refresh: reload }`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
    const bindings = {
      ...shared, ...office, ...work, referenceMonth, useAsyncData: engine.useAsyncData, rowSelection: Vue.ref({}),
      grouped: async (month: string, _options: { signal: AbortSignal }) => {
        reads.push(`${ownerPath}:grouped:${month}`)
        return []
      },
      unscopedTasks: async (month: string, _options: { signal: AbortSignal }) => {
        reads.push(`${ownerPath}:unscoped:${month}`)
        return []
      }
    }
    const scope = Vue.effectScope()
    const result = await scope.run(() => new AsyncFunction(...Object.keys(bindings), code)(...Object.values(bindings)))!
    return { scope, result }
  }
  const tasks = await open('pages/work/tarefas.vue', 'work-tarefas-by-month', 'tarefas')
  const scopes = [tasks.scope]
  try {
    currentRoute.value = { path: '/work/clientes' }
    referenceMonth.value = '2026-09'
    const clients = await open('pages/work/clientes.vue', 'work-clientes', 'clientes')
    scopes.push(clients.scope)
    await new Promise(resolve => setImmediate(resolve))
    assert.deepEqual(reads, ['tarefas:grouped:2026-08', 'tarefas:unscoped:2026-08', 'clientes:grouped:2026-09'])
    tasks.scope.stop()
    currentRoute.value = { path: '/work/tarefas' }
    referenceMonth.value = '2026-08'
    const returned = await open('pages/work/tarefas.vue', 'work-tarefas-by-month', 'tarefas')
    scopes.push(returned.scope)
    await new Promise(resolve => setImmediate(resolve))
    assert.deepEqual(reads, ['tarefas:grouped:2026-08', 'tarefas:unscoped:2026-08', 'clientes:grouped:2026-09', 'tarefas:grouped:2026-08', 'tarefas:unscoped:2026-08'])
    referenceMonth.value = '2026-10'
    await new Promise(resolve => setImmediate(resolve))
    assert.deepEqual(reads.slice(5), ['tarefas:grouped:2026-10', 'tarefas:unscoped:2026-10'], 'An active-page month change keeps one logical read')
  } finally { scopes.forEach(scope => scope.stop()) }
})

test('Work route loss aborts a queued safe read before Suspense disposal', async () => {
  const currentRoute = Vue.ref({ path: '/work/processos' })
  const registry = Vue.ref({ authority: 'office' })
  const shared = { computed: Vue.computed, watch: Vue.watch, nextTick: Vue.nextTick, onScopeDispose: Vue.onScopeDispose, useListNavigationRegistry: () => ({ registry }) }
  const office = loadAppComposable<Record<string, unknown>>('composables/useOfficeReadLifecycle.ts', shared)
  const { useWorkReadLifecycle } = loadAppComposable<{ useWorkReadLifecycle: (path: string) => { enabled: Vue.Ref<boolean>, own: (resource: { clear: () => void, refresh: () => Promise<unknown> }) => void } }>('composables/useWorkReadLifecycle.ts', { ...shared, ...office, useRouter: () => ({ currentRoute }) })
  const engine = officeAsyncDataEngine(false, { serverRendered: false, data: {}, _errors: {} })
  engine.app.isHydrating = false
  let signal: AbortSignal | undefined
  let complete!: (value: unknown[]) => void
  const scope = Vue.effectScope()
  const owner = scope.run(() => useWorkReadLifecycle('/work/processos'))!
  const resource = scope.run(() => (engine.useAsyncData as (...args: unknown[]) => unknown)('processes-route-owner', (_app: unknown, options: { signal: AbortSignal }) => {
    signal = options.signal
    return new Promise((resolve) => {
      complete = resolve
    })
  }, { enabled: owner.enabled, default: () => [] })) as { data: Vue.Ref<unknown[]>, error: Vue.Ref<unknown>, clear: () => void, refresh: () => Promise<unknown> }
  owner.own(resource)
  assert.equal(signal!.aborted, false)
  currentRoute.value = { path: '/work/tarefas' }
  assert.equal(scope.active, true, 'The outgoing page still exists inside Suspense')
  assert.equal(signal!.aborted, true, 'The departure cancels queued/running transport synchronously')
  complete(['obsolete'])
  await new Promise(resolve => setImmediate(resolve))
  assert.deepEqual(resource.data.value, [])
  assert.equal(resource.error.value, undefined)
  scope.stop()
})

test('Work month read remains enabled for the initial server page', async () => {
  const { descriptor } = parse(vueSource('pages/work/tarefas.vue'))
  const script = ts.createSourceFile('tasks.ts', descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
  const read = script.statements.find(statement => ts.isVariableStatement(statement) && statement.getText(script).includes('\'work-tarefas-by-month\''))!
  const code = ts.transpileModule(`${read.getText(script)}\nreturn data`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
  const engine = officeAsyncDataEngine(true, { serverRendered: true, data: {}, _errors: {} })
  const reads: string[] = []
  const bindings = {
    useAsyncData: engine.useAsyncData, referenceMonth: Vue.ref('2026-08'), readLifecycle: { enabled: Vue.computed(() => true) },
    grouped: async (month: string) => {
      reads.push(`grouped:${month}`)
      return []
    },
    unscopedTasks: async (month: string) => {
      reads.push(`unscoped:${month}`)
      return []
    }
  }
  await new AsyncFunction(...Object.keys(bindings), code)(...Object.values(bindings))
  assert.deepEqual(reads, ['grouped:2026-08', 'unscoped:2026-08'])
})

test('Nuxt cancels Work entry when navigation wins before async setup registers ownership', async () => {
  const currentRoute = Vue.ref({ path: '/work/tarefas' })
  const registry = Vue.ref({ authority: 'office' })
  const shared = { computed: Vue.computed, watch: Vue.watch, nextTick: Vue.nextTick, onScopeDispose: Vue.onScopeDispose, useListNavigationRegistry: () => ({ registry }) }
  const office = loadAppComposable<Record<string, unknown>>('composables/useOfficeReadLifecycle.ts', shared)
  const { useWorkReadLifecycle } = loadAppComposable<{ useWorkReadLifecycle: (path: string) => { enabled: Vue.Ref<boolean>, own: (resource: { clear: () => void, refresh: () => Promise<unknown> }, reset?: () => void) => void } }>('composables/useWorkReadLifecycle.ts', { ...shared, ...office, useRouter: () => ({ currentRoute }) })
  const engine = officeAsyncDataEngine(false, { serverRendered: false, data: {}, _errors: {} })
  engine.app.isHydrating = false
  let signal: AbortSignal | undefined
  const scope = Vue.effectScope()
  const owner = scope.run(() => useWorkReadLifecycle('/work/tarefas'))!
  const resource = scope.run(() => (engine.useAsyncData as (...args: unknown[]) => unknown)('pending-work-entry', (_app: unknown, options: { signal: AbortSignal }) => {
    signal = options.signal
    return new Promise(() => {})
  }, { enabled: owner.enabled, default: () => [] })) as { data: Vue.Ref<unknown[]>, error: Vue.Ref<unknown>, clear: () => void, refresh: () => Promise<unknown> }
  currentRoute.value = { path: '/work/clientes' }
  await Vue.nextTick()
  assert.equal(signal!.aborted, true, 'Native enabled cancellation applies before the awaited setup resumes')
  resource.data.value = ['old retained result']
  let resets = 0
  scope.run(() => owner.own(resource, () => {
    resets++
  }))
  assert.deepEqual(resource.data.value, [], 'Late ownership registration immediately clears the departed dataset')
  assert.equal(resets, 1)
  scope.stop()
})
