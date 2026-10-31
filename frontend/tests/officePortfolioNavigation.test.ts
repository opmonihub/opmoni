import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

test('portfolio restores search and column filters and keeps pinned document state and selections separate', async () => {
  const states = new Map<string, Vue.Ref>()
  const useState = (key: string, initial: () => unknown) => {
    if (!states.has(key)) states.set(key, Vue.ref(initial()))
    return states.get(key)
  }
  const foundation = loadAppComposable<Record<string, (...args: unknown[]) => unknown>>('composables/useListNavigationState.ts', { useState })
  const office = loadAppComposable<Record<string, (...args: unknown[]) => unknown>>('composables/useOfficeTableNavigation.ts', { ...foundation, computed: Vue.computed })
  const { useClientListFilters } = loadAppComposable<{ useClientListFilters: (options: Record<string, unknown>) => { search: Vue.Ref<string>, filterModels: Vue.Ref<unknown[]>, certificateFilter: Vue.Ref<string[]>, poaFilter: Vue.Ref<string[]>, onFilters: (value: unknown[]) => void } }>('composables/useClientListFilters.ts', { ...foundation, ...office, ref: Vue.ref, computed: Vue.computed, watch: Vue.watch })
  const listing = Vue.ref({ document: 'certificate', status: 'all' })
  const open = () => useClientListFilters({
    navigation: office.useOfficeTableNavigation!('portfolio', ['tag', 'regime', 'status', 'certificate', 'poa']), listing,
    rows: Vue.ref([]), matchingTotal: Vue.ref(0), listMode: Vue.ref('sheet'), listStatus: Vue.ref('success'), tagCatalog: Vue.ref({ data: [] }), view: Vue.ref('all')
  })
  const scope = Vue.effectScope()
  const filters = scope.run(open)!
  filters.search.value = 'Escrituração'
  filters.onFilters([
    { columnId: 'tag', operator: 'is any of', values: ['2', '3'] },
    { columnId: 'regime', operator: 'is not', values: ['simples_nacional'] },
    { columnId: 'poa', operator: 'is', values: ['expired'] }
  ])
  scope.stop()
  const nextScope = Vue.effectScope()
  try {
    const restored = nextScope.run(open)!
    assert.equal(restored.search.value, 'Escrituração')
    assert.deepEqual(restored.filterModels.value, [
      { columnId: 'tag', operator: 'is any of', values: ['2', '3'] },
      { columnId: 'regime', operator: 'is not', values: ['simples_nacional'] },
      { columnId: 'poa', operator: 'is', values: ['expired'] }
    ])
    listing.value = { document: 'poa', status: 'expired' }
    await Vue.nextTick()
    assert.deepEqual(restored.poaFilter.value, [], 'Pinned route takes precedence over remembered document criteria')
    assert.equal(restored.search.value, 'Escrituração')
    states.get('access-session-generation')!.value++
    assert.equal(restored.search.value, '')
    assert.deepEqual(restored.filterModels.value, [])
  } finally { nextScope.stop() }
})
