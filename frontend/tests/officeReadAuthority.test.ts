import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { officeAsyncDataEngine } from './helpers/officeAsyncData.ts'

test('effective office membership changes clear protected reads synchronously and cancel old transport', async () => {
  const identity = Vue.ref({ id: 1, current_account: { id: 7, status: 'active', role: 'admin' }, accounts: [{ id: 7, status: 'active', role: 'admin' }] })
  const states = new Map<string, Vue.Ref>([['access-user', identity]])
  const useState = (key: string, initial: () => unknown) => {
    if (!states.has(key)) states.set(key, Vue.ref(initial()))
    return states.get(key)
  }
  const foundation = loadAppComposable<Record<string, (...args: unknown[]) => unknown>>('composables/useListNavigationState.ts', { useState })
  const { useOfficeReadLifecycle } = loadAppComposable<{ useOfficeReadLifecycle: () => { own: (resource: { clear: () => void, refresh: () => Promise<unknown> }) => void } }>('composables/useOfficeReadLifecycle.ts', { ...foundation, computed: Vue.computed, watch: Vue.watch, nextTick: Vue.nextTick, onScopeDispose: Vue.onScopeDispose })
  const engine = officeAsyncDataEngine(false, { serverRendered: false, data: {}, _errors: {} })
  engine.app.isHydrating = false
  let resolveOld!: (value: unknown) => void
  const signals: AbortSignal[] = []
  let requests = 0
  const scope = Vue.effectScope()
  const lifecycle = scope.run(useOfficeReadLifecycle)!
  const resource = scope.run(() => (engine.useAsyncData as (...args: unknown[]) => unknown)('office-protected-list', (_app: unknown, options: { signal: AbortSignal }) => {
    signals.push(options.signal)
    requests++
    return requests === 1
      ? new Promise((resolve) => {
          resolveOld = resolve
        })
      : Promise.resolve(['current-role'])
  }, { default: () => [] })) as { data: Vue.Ref<unknown[]>, status: Vue.Ref<string>, error: Vue.Ref<unknown>, clear: () => void, refresh: () => Promise<unknown> }
  lifecycle.own(resource)
  resource.data.value = ['prior-role']
  // tenantPermissions resolves the selected Account through this effective row.
  // current_account is deliberately unchanged during the grant-only refresh.
  identity.value.accounts[0]!.role = 'user'
  assert.deepEqual(resource.data.value, [])
  assert.equal(signals[0]!.aborted, true)
  resolveOld(['stale'])
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
  assert.equal(requests, 2)
  assert.deepEqual(resource.data.value, ['current-role'])
  assert.equal(resource.error.value, undefined)
  scope.stop()
})

test('a reactive authority key admits only one read for its new authority', async () => {
  const registry = Vue.ref({ authority: 'office-a' })
  const { useOfficeReadLifecycle } = loadAppComposable<{ useOfficeReadLifecycle: () => { authority: Vue.Ref<string>, own: (resource: { clear: () => void, refresh: () => Promise<unknown> }) => void } }>('composables/useOfficeReadLifecycle.ts', { useListNavigationRegistry: () => ({ registry }), computed: Vue.computed, watch: Vue.watch, nextTick: Vue.nextTick, onScopeDispose: Vue.onScopeDispose })
  const engine = officeAsyncDataEngine(false, { serverRendered: false, data: {}, _errors: {} })
  engine.app.isHydrating = false
  const reads: { authority: string, signal: AbortSignal }[] = []
  const scope = Vue.effectScope()
  const lifecycle = scope.run(useOfficeReadLifecycle)!
  const resource = scope.run(() => (engine.useAsyncData as (...args: unknown[]) => unknown)(Vue.computed(() => `detail-${lifecycle.authority.value}`), (_app: unknown, options: { signal: AbortSignal }) => {
    reads.push({ authority: lifecycle.authority.value, signal: options.signal })
    return new Promise(() => {})
  }, { default: () => null })) as { data: Vue.Ref<unknown>, clear: () => void, refresh: () => Promise<unknown> }
  // The reactive key is this read's owner; page cleanup clears only its local ref.
  Vue.watch(lifecycle.authority, () => {
    resource.data.value = null
  }, { flush: 'sync' })
  resource.data.value = { name: 'Prior authority' }
  registry.value.authority = 'office-b'
  assert.equal(resource.data.value, null)
  assert.equal(reads[0]!.signal.aborted, true)
  await Vue.nextTick()
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(reads.filter(read => read.authority === 'office-b').length, 1)
  assert.equal(reads[0]!.signal.aborted, true)
  scope.stop()
})
