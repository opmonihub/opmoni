import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type UnitDefault = { status: 'available' | 'unset' | 'unresolved' | 'unavailable', id: number | null, label: string | null }
type PendingRequest = { url: string, options: { signal?: AbortSignal }, resolve(value: unknown): void, reject(reason: unknown): void }
type DefaultConsumer = {
  pending: Vue.Ref<boolean>
  error: Vue.Ref<string>
  status: Vue.Ref<string>
  retry(): Promise<unknown>
  markExplicit(): void
}

function harness(initial: { mode?: 'create' | 'edit', unitId?: number | null, permissions?: string[] } = {}) {
  const user = Vue.ref({ id: 5 })
  const currentCompany = Vue.ref({ id: 41, status: 'active', access_kind: 'direct', role: 'operator', delegated_office_id: null, permissions: initial.permissions ?? ['erp.products.store', 'erp.units.index', 'erp.units.show'] })
  const currentAccount = Vue.ref(null)
  const session = Vue.ref(1)
  const mode = Vue.ref(initial.mode ?? 'create')
  const unitId = Vue.ref<number | null>(initial.unitId ?? null)
  const requests: PendingRequest[] = []
  const api = (url: string, options: PendingRequest['options'] = {}) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }))
  const scope = Vue.effectScope()
  const bindings = {
    computed: Vue.computed,
    ref: Vue.ref,
    watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose,
    useAuth: () => ({ user, currentCompany, currentAccount }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? session : Vue.ref(init()),
    useAccessApi: () => ({ api })
  }
  const loaded = loadAppComposable<{ useErpNativeUnitDefault(options: { mode: Vue.Ref<string>, unitId: Vue.Ref<number | null> }): DefaultConsumer }>('composables/useErpNativeUnitDefault.ts', bindings)
  const consumer = scope.run(() => loaded.useErpNativeUnitDefault({ mode, unitId }))!
  return { consumer, mode, unitId, requests, currentCompany, session, dispose: () => scope.stop() }
}

const available: UnitDefault = { status: 'available', id: 81, label: 'UN · Unidade' }
const response = (unit: UnitDefault) => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 1, total: 0, native_default_unit: unit } })
const flush = async () => {
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

describe('native unit default consumer', () => {
  it('initializes the first product from a verified native pointer outside the first catalog page', async () => {
    const h = harness()
    try {
      assert.equal(h.requests.length, 1)
      assert.match(h.requests[0]!.url, /^\/erp\/units(?:\?|$)/)
      assert.ok(h.requests[0]!.options.signal)
      h.requests[0]!.resolve(response(available))
      await flush()
      assert.equal(h.unitId.value, 81)
      assert.equal(h.consumer.status.value, 'available')
      assert.equal(h.consumer.error.value, '')
    } finally { h.dispose() }
  })

  it('never requests settings or defaults when editing or when an explicit unit already exists', async () => {
    for (const initial of [{ mode: 'edit' as const, unitId: 77 }, { unitId: 77 }]) {
      const h = harness(initial)
      try {
        await flush()
        assert.equal(h.requests.length, 0)
        assert.equal(h.unitId.value, 77)
      } finally { h.dispose() }
    }
  })

  it('preserves a manual choice or explicit clearing while a default lookup is pending', async () => {
    for (const choice of [null, 93]) {
      const h = harness()
      try {
        h.consumer.markExplicit()
        h.unitId.value = choice
        h.requests[0]!.resolve(response(available))
        await flush()
        assert.equal(h.unitId.value, choice)
        assert.equal(h.consumer.pending.value, false)
      } finally { h.dispose() }
    }
  })

  it('reports an unavailable native pointer and supports a fresh explicit retry', async () => {
    const h = harness()
    try {
      h.requests[0]!.resolve(response({ status: 'unavailable', id: 81, label: null }))
      await flush()
      assert.equal(h.unitId.value, null)
      assert.equal(h.consumer.status.value, 'unavailable')
      assert.notEqual(h.consumer.error.value, '')
      const retry = h.consumer.retry()
      assert.equal(h.requests.length, 2)
      h.requests[1]!.resolve(response({ ...available, id: 82 }))
      await retry
      assert.equal(h.unitId.value, 82)
      assert.equal(h.consumer.error.value, '')
    } finally { h.dispose() }
  })

  it('leaves unresolved legacy identifiers unselected and preserves a failed lookup for retry', async () => {
    const h = harness()
    try {
      h.requests[0]!.resolve(response({ status: 'unresolved', id: null, label: null }))
      await flush()
      assert.equal(h.unitId.value, null)
      assert.notEqual(h.consumer.error.value, '')
      const retry = h.consumer.retry()
      h.requests[1]!.reject({ status: 500, data: { message: 'Consulta indisponível' } })
      await retry
      assert.equal(h.unitId.value, null)
      assert.notEqual(h.consumer.error.value, '')
    } finally { h.dispose() }
  })

  it('aborts on Company or session changes and discards an old response even if abort is ignored', async () => {
    for (const change of ['company', 'session']) {
      const h = harness()
      try {
        const old = h.requests[0]!
        if (change === 'company') h.currentCompany.value.id = 42
        else h.session.value++
        assert.equal(old.options.signal?.aborted, true)
        old.resolve(response(available))
        await flush()
        assert.equal(h.unitId.value, null)
      } finally { h.dispose() }
    }
  })

  it('does not fetch without unit authority and clears pending state on revocation or scope disposal', async () => {
    const denied = harness({ permissions: ['erp.products.store'] })
    try {
      assert.equal(denied.requests.length, 0)
      assert.equal(denied.unitId.value, null)
    } finally { denied.dispose() }
    const h = harness()
    const request = h.requests[0]!
    h.currentCompany.value.permissions = ['erp.products.store']
    assert.equal(request.options.signal?.aborted, true)
    request.resolve(response(available))
    await flush()
    assert.equal(h.unitId.value, null)
    assert.equal(h.consumer.pending.value, false)
    h.dispose()
  })
})
