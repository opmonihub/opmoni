import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

function harness(query: Record<string, unknown> = {}) {
  const route = Vue.reactive({ query })
  const states = new Map<string, Vue.Ref>()
  const navigations: { method: string, query: Record<string, unknown> }[] = []
  const useState = (key: string, initial: () => unknown) => {
    if (!states.has(key)) states.set(key, Vue.ref(initial()))
    return states.get(key)
  }
  const foundation = loadAppComposable<Record<string, (...args: unknown[]) => unknown>>('composables/useListNavigationState.ts', { useState })
  const bindings = {
    ...foundation, computed: Vue.computed, watch: Vue.watch, useState,
    useRoute: () => route,
    useRouter: () => Object.fromEntries(['push', 'replace'].map(method => [method, ({ query }: { query: Record<string, unknown> }) => {
      navigations.push({ method, query })
      route.query = query
      return Promise.resolve()
    }]))
  }
  return { route, states, navigations, load: <T extends Record<string, unknown>>(path: string) => loadAppComposable<T>(path, bindings) }
}

test('Fiscal URL and history override memory with one final normalized criteria read', async () => {
  const context = harness({ q: 'Cliente', page: '4', campaign: 'retained' })
  const { useFiscalDocumentNavigation } = context.load<{ useFiscalDocumentNavigation: () => { filters: Vue.Ref<Record<string, unknown>>, update: (value: Record<string, unknown>) => Promise<void> } }>('composables/useFiscalDocumentNavigation.ts')
  const scope = Vue.effectScope()
  try {
    let page = scope.run(useFiscalDocumentNavigation)!
    const reads: string[] = []
    scope.run(() => Vue.watch(() => JSON.stringify(page.filters.value), value => reads.push(value), { immediate: true }))
    assert.equal(page.filters.value.page, 4)
    await page.update({ ...page.filters.value, q: 'Novo', page: 1 })
    await Vue.nextTick()
    assert.equal(reads.length, 2, 'No query for the new filter on the old page')
    assert.deepEqual(context.route.query, { q: 'Novo', campaign: 'retained' })

    context.route.query = { q: 'History', page: '2', campaign: 'retained' }
    await Vue.nextTick()
    assert.equal(page.filters.value.q, 'History')
    assert.equal(page.filters.value.page, 2)
    context.route.query = { campaign: 'retained' }
    await Vue.nextTick()
    assert.equal(page.filters.value.q, undefined, 'Back to an empty query restores defaults')
    assert.equal(page.filters.value.page, 1)

    await page.update({ ...page.filters.value, q: 'Remembered', page: 3 })
    scope.stop()
    context.route.query = { campaign: 'return' }
    const returnScope = Vue.effectScope()
    try {
      page = returnScope.run(useFiscalDocumentNavigation)!
      assert.equal(page.filters.value.q, 'Remembered')
      assert.equal(page.filters.value.page, 3)
      assert.deepEqual(context.route.query, { campaign: 'return', q: 'Remembered', page: '3' })
    } finally { returnScope.stop() }
  } finally { scope.stop() }
})

test('Fiscal sort direction follows valid URL criteria, rejects invalid history and omits backend defaults', async () => {
  const context = harness({ sort: 'valor_total', direction: 'asc', page: '3', campaign: 'retained' })
  const { useFiscalDocumentNavigation } = context.load<{ useFiscalDocumentNavigation: () => { filters: Vue.Ref<Record<string, unknown>>, update: (value: Record<string, unknown>) => Promise<void> } }>('composables/useFiscalDocumentNavigation.ts')
  const scope = Vue.effectScope()
  try {
    const page = scope.run(useFiscalDocumentNavigation)!
    assert.equal(page.filters.value.sort, 'valor_total')
    assert.equal(page.filters.value.direction, 'asc')
    assert.equal(page.filters.value.page, 3)

    context.route.query = { sort: 'captured_at', direction: 'asc', campaign: 'retained' }
    await Vue.nextTick()
    assert.equal(page.filters.value.sort, 'captured_at')
    assert.equal(page.filters.value.direction, 'asc')
    assert.equal(page.filters.value.page, 1)

    context.route.query = { sort: 'unsupported', direction: 'sideways', page: '0', campaign: 'retained' }
    await Vue.nextTick()
    assert.equal(page.filters.value.sort, 'emissao_at')
    assert.equal(page.filters.value.direction, 'desc')
    assert.equal(page.filters.value.page, 1)

    await page.update({ ...page.filters.value, sort: 'emissao_at', direction: 'desc', page: 1 })
    assert.deepEqual(context.route.query, { campaign: 'retained' }, 'Reset leaves the legacy endpoint order implicit')
  } finally { scope.stop() }
})

test('Work calendar restores its date and supported filters, with URL/history date priority', async () => {
  const context = harness({ view: 'week', date: '2027-02-08', extra: 'preserved' })
  const { useWorkCalendarNavigation } = context.load<{ useWorkCalendarNavigation: () => { view: Vue.Ref<string>, focusDate: Vue.Ref<string>, processId: Vue.Ref<number | null>, priority: Vue.Ref<string>, statusVisible: Vue.Ref<Record<string, boolean>> } }>('composables/useWorkCalendarNavigation.ts')
  const first = Vue.effectScope()
  const page = first.run(useWorkCalendarNavigation)!
  assert.equal(page.focusDate.value, '2027-02-08')
  page.processId.value = 12
  page.priority.value = 'medium'
  page.statusVisible.value = { todo: true, doing: false, done: false, dismissed: false }
  first.stop()
  context.route.query = { extra: 'preserved' }
  const nextScope = Vue.effectScope()
  try {
    const next = nextScope.run(useWorkCalendarNavigation)!
    assert.equal(next.focusDate.value, '2027-02-08')
    assert.equal(next.view.value, 'week')
    assert.equal(next.processId.value, 12)
    assert.equal(next.priority.value, 'medium')
    assert.equal(next.statusVisible.value.done, false)
    context.route.query = { date: '2027-03-01', view: 'day', extra: 'preserved' }
    await Vue.nextTick()
    assert.equal(next.focusDate.value, '2027-03-01')
    assert.equal(next.view.value, 'day')
    assert.equal(next.processId.value, 12)
    assert.equal(context.navigations.length, 0)
    context.states.get('access-session-generation')!.value++
    assert.equal(next.processId.value, null)
    assert.equal(next.priority.value, '')
    assert.equal(next.statusVisible.value.done, true)
    assert.equal(next.focusDate.value, '2027-03-01', 'Authority reset retains the explicit URL date')
    assert.equal(next.view.value, 'day', 'Authority reset retains the explicit URL view')
  } finally { nextScope.stop() }
})
