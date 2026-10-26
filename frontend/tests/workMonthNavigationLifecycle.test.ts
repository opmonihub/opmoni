import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

test('one Work owner normalizes entry and history while children admit one read per valid month', async () => {
  const route = Vue.reactive({ path: '/work/tarefas', query: { q: 'preserved' } as Record<string, unknown> })
  const state = new Map<string, Vue.Ref>()
  const replacements: Record<string, unknown>[] = []
  const useState = (key: string, initial: () => unknown) => {
    if (!state.has(key)) state.set(key, Vue.ref(initial()))
    return state.get(key)
  }
  const foundation = loadAppComposable<{ useListNavigationState: (...args: unknown[]) => Vue.Ref }>('composables/useListNavigationState.ts', { useState })
  const { useWorkReferenceMonth } = loadAppComposable<{ useWorkReferenceMonth: (options?: { syncRoute?: boolean }) => { referenceMonth: Vue.Ref<string>, nextMonth: () => void } }>('composables/useWorkReferenceMonth.ts', {
    computed: Vue.computed, watch: Vue.watch, useListNavigationState: foundation.useListNavigationState,
    useRoute: () => route,
    useRouter: () => ({ replace: ({ query }: { query: Record<string, unknown> }) => {
      replacements.push(query)
      route.query = query
    } }),
    useState
  })
  const reads: string[] = []
  const scope = Vue.effectScope()
  try {
    const shell = scope.run(() => useWorkReferenceMonth({ syncRoute: true }))!
    scope.run(() => {
      const child = useWorkReferenceMonth()
      Vue.watch(child.referenceMonth, value => reads.push(value), { immediate: true })
    })
    await Vue.nextTick()
    assert.equal(replacements.length, 1)
    assert.equal(reads.length, 1)
    const month = shell.referenceMonth.value
    assert.deepEqual(replacements[0], { q: 'preserved', reference_month: month })

    route.query = { q: 'preserved', reference_month: '2027-02' }
    await Vue.nextTick()
    assert.equal(shell.referenceMonth.value, '2027-02')
    assert.equal(replacements.length, 1, 'Valid history does not write the URL')
    assert.deepEqual(reads, [month, '2027-02'])

    route.query = { q: 'preserved', reference_month: '2027-13' }
    await Vue.nextTick()
    assert.equal(replacements.length, 2, 'Invalid history normalizes once')
    assert.deepEqual(replacements.at(-1), { q: 'preserved', reference_month: '2027-02' })
    assert.equal(reads.length, 2, 'Invalid history cannot admit a dataset')

    route.path = '/work/clientes'
    route.query = { q: 'preserved' }
    await Vue.nextTick()
    assert.equal(replacements.length, 3, 'A canonical child link restores the month once')
    assert.equal(reads.length, 2)

    shell.nextMonth()
    await Vue.nextTick()
    assert.equal(replacements.length, 4)
    assert.deepEqual(reads, [month, '2027-02', '2027-03'])
    assert.equal(route.query.q, 'preserved')

    route.path = '/work/modelos'
    route.query = { q: 'preserved' }
    await Vue.nextTick()
    assert.equal(replacements.length, 4, 'Unscoped editors do not receive list month parameters')
  } finally { scope.stop() }
})
