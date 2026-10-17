import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import ts from 'typescript'
import * as selectionUtils from '../app/utils/clientSelection.ts'
import type { ClientListParams, ClientSheet } from '../app/types/client.ts'

type Params = Pick<ClientListParams, 'q' | 'view'> & { tag_id: number[] }
type Snapshot = { data: { id: string, ids: number[], count: number } }

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

function harness() {
  const params = Vue.ref<Params>({ q: 'Alfa', tag_id: [1] })
  const rows = Vue.ref([{ id: 1 }, { id: 2 }] as ClientSheet[])
  const requests: { body: Pick<ClientListParams, 'q' | 'tag_id' | 'view'>, response: ReturnType<typeof deferred<Snapshot>> }[] = []
  const toasts: unknown[] = []
  const globals = {
    ref: Vue.ref, shallowRef: Vue.shallowRef, computed: Vue.computed,
    watch: Vue.watch, onScopeDispose: Vue.onScopeDispose,
    useToast: () => ({ add: (toast: unknown) => toasts.push(toast) })
  }
  const source = readFileSync(new URL('../app/composables/useClientListSelection.ts', import.meta.url), 'utf8')
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext } }).outputText
  const exports: { useClientListSelection?: typeof import('../app/composables/useClientListSelection.ts').useClientListSelection } = {}
  new Function('require', 'exports', ...Object.keys(globals), code)(
    () => selectionUtils, exports, ...Object.values(globals)
  )
  const scope = Vue.effectScope()
  const state = scope.run(() => exports.useClientListSelection!({
    matchingTotal: Vue.ref(2), rows, selectionParams: params,
    createSelection: (body) => {
      const response = deferred<Snapshot>()
      requests.push({ body: structuredClone(Vue.toRaw(body)), response })
      return response.promise
    }
  }))!
  return { state, params, requests, rows, toasts, close: () => scope.stop() }
}

const snapshot = (id: string, ids = [1, 2]): Snapshot => ({ data: { id, ids, count: ids.length } })

describe('filtered client selection request ownership', () => {
  it('ignores a snapshot after filters change while the selection count is still zero', async (t) => {
    const view = harness()
    t.after(view.close)
    const pending = view.state.selectAllMatching()
    assert.equal(view.state.selectedCount.value, 0)
    view.params.value.q = 'Beta'
    view.requests[0]!.response.resolve(snapshot('old'))
    await pending
    assert.equal(view.state.selectedCount.value, 0)
    assert.deepEqual(view.state.tagAssignment.value, { ids: [] })
  })

  it('ignores a snapshot after explicit clear and preserves a subsequent manual selection', async (t) => {
    const view = harness()
    t.after(view.close)
    const pending = view.state.selectAllMatching()
    view.state.clearSelection()
    view.state.setClientSelected(2, true)
    view.requests[0]!.response.resolve(snapshot('old'))
    await pending
    assert.deepEqual(view.state.tagAssignment.value, { ids: [2] })
    assert.equal(view.state.isClientSelected(1), false)
  })

  it('lets a new selection start after clear without letting an old finally end its loading', async (t) => {
    const view = harness()
    t.after(view.close)
    const first = view.state.selectAllMatching()
    view.state.clearSelection()
    const second = view.state.selectAllMatching()
    assert.equal(view.requests.length, 2)
    view.requests[0]!.response.resolve(snapshot('old'))
    await first
    assert.equal(view.state.selectingAll.value, true)
    assert.equal(view.state.selectedCount.value, 0)
    view.requests[1]!.response.resolve(snapshot('new', [2]))
    await second
    assert.equal(view.state.selectingAll.value, false)
    assert.equal(view.state.tagAssignment.value.selection_id, 'new')
    assert.equal(view.state.isClientSelected(2), true)
  })

  it('ignores stale failures without ending a newer filtered selection or showing a toast', async (t) => {
    const view = harness()
    t.after(view.close)
    const first = view.state.selectAllMatching()
    view.params.value = { q: 'Beta', tag_id: [2] }
    const second = view.state.selectAllMatching()
    assert.equal(view.requests.length, 2)
    view.requests[0]!.response.reject(new Error('Old request failed'))
    await first
    assert.equal(view.state.selectingAll.value, true)
    assert.deepEqual(view.toasts, [])
    view.requests[1]!.response.resolve(snapshot('beta', [2]))
    await second
    assert.equal(view.state.tagAssignment.value.selection_id, 'beta')
  })

  it('invalidates in-place filter arrays and completed explicit selections without a page watcher', async (t) => {
    const view = harness()
    t.after(view.close)
    view.state.setClientSelected(1, true)
    view.params.value.tag_id = [2]
    assert.equal(view.state.selectedCount.value, 0)
    const pending = view.state.selectAllMatching()
    view.params.value.tag_id.push(3)
    view.requests[0]!.response.resolve(snapshot('old'))
    await pending
    assert.equal(view.state.selectedCount.value, 0)
  })

  it('keeps snapshots, exclusions and explicit additions when the filter values do not change', async (t) => {
    const view = harness()
    t.after(view.close)
    const pending = view.state.selectAllMatching()
    view.requests[0]!.response.resolve(snapshot('current'))
    await pending
    view.params.value = { q: 'Alfa', tag_id: [1] }
    view.rows.value = [{ id: 2 }, { id: 3 }] as ClientSheet[]
    view.state.setClientSelected(1, false)
    view.state.setClientSelected(3, true)
    assert.deepEqual(view.state.tagAssignment.value, { selection_id: 'current', excluded_ids: [1], ids: [3] })
    assert.equal(view.state.selectedCount.value, 2)
    assert.equal(view.state.isClientSelected(3), true)
  })

  it('ignores pending responses after the owning scope is disposed', async () => {
    const view = harness()
    const pending = view.state.selectAllMatching()
    view.close()
    view.requests[0]!.response.resolve(snapshot('unmounted'))
    await pending
    assert.equal(view.state.selectedCount.value, 0)
    assert.equal(view.state.selectingAll.value, false)
  })

  it('still reports a current failure and permits retry', async (t) => {
    const view = harness()
    t.after(view.close)
    const pending = view.state.selectAllMatching()
    view.requests[0]!.response.reject(new Error('Current request failed'))
    await pending
    assert.equal(view.toasts.length, 1)
    assert.equal(view.state.selectingAll.value, false)
    const retry = view.state.selectAllMatching()
    view.requests[1]!.response.resolve(snapshot('retried'))
    await retry
    assert.equal(view.state.selectedCount.value, 2)
  })
})
