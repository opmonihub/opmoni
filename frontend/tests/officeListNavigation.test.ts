import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

test('office table navigation retains only supported flat criteria and reconstructs filter models', () => {
  const remembered = new Map<string, Vue.Ref>()
  const bindings = {
    computed: Vue.computed,
    useState(key: string, initial: () => unknown) {
      if (!remembered.has(key)) remembered.set(key, Vue.ref(initial()))
      return remembered.get(key)
    }
  }
  const foundation = loadAppComposable<{ useListNavigationState: (...args: unknown[]) => Vue.Ref }>('composables/useListNavigationState.ts', bindings)
  const { useOfficeTableNavigation } = loadAppComposable<{ useOfficeTableNavigation: (key: string, columns: string[], sortIds?: string[], options?: { page?: boolean, pageSize?: boolean }) => { search: Vue.Ref<string>, filterModels: Vue.Ref<unknown[]>, sorting: Vue.Ref<unknown[]>, page: Vue.Ref<number>, pageSize: Vue.Ref<number> } }>('composables/useOfficeTableNavigation.ts', {
    ...bindings, useListNavigationState: foundation.useListNavigationState
  })
  const first = useOfficeTableNavigation('work-tasks', ['status', 'due_on'], ['title'], { page: true, pageSize: true })
  first.search.value = 'Fechamento'
  first.filterModels.value = [
    { columnId: 'status', operator: 'is not', values: ['done'] },
    { columnId: 'due_on', type: 'date', operator: 'is between', values: ['2027-02-01', '2027-02-28'] },
    { columnId: 'password', operator: 'is', values: ['secret'] }
  ]
  first.sorting.value = [{ id: 'title', desc: true }, { id: 'record', desc: false }]
  first.page.value = 4
  first.pageSize.value = 50
  const next = useOfficeTableNavigation('work-tasks', ['status', 'due_on'], ['title'], { page: true, pageSize: true })
  assert.equal(next.search.value, 'Fechamento')
  assert.equal(next.page.value, 4)
  assert.equal(next.pageSize.value, 50)
  assert.deepEqual(next.filterModels.value, [
    { columnId: 'status', operator: 'is not', values: ['done'] },
    { columnId: 'due_on', type: 'date', operator: 'is between', values: ['2027-02-01', '2027-02-28'] }
  ])
  assert.deepEqual(next.sorting.value, [{ id: 'title', desc: true }])
  const raw = remembered.get('list-navigation-state')!.value.lists['work-tasks'].value
  assert.equal(Object.hasOwn(raw, 'password'), false)
  assert.equal(Object.hasOwn(raw, 'rowSelection'), false)
  for (const value of Object.values(raw)) {
    assert.ok(value === null || ['string', 'number', 'boolean'].includes(typeof value) || (Array.isArray(value) && value.every(item => ['string', 'number', 'boolean'].includes(typeof item))))
  }
  next.page.value = -3
  next.pageSize.value = 999
  assert.equal(next.page.value, 1)
  assert.equal(next.pageSize.value, 25)
  assert.equal(useOfficeTableNavigation('fiscal-clients', ['modelo']).search.value, '')
})

test('unrelated page or sorting changes do not reset a restored table page', async () => {
  const states = new Map<string, Vue.Ref>()
  const bindings = {
    computed: Vue.computed,
    useListNavigationState(key: string, options: { defaults: () => unknown }) {
      if (!states.has(key)) states.set(key, Vue.ref(options.defaults()))
      return states.get(key)
    }
  }
  const { useOfficeTableNavigation } = loadAppComposable<{ useOfficeTableNavigation: (key: string, columns: string[], sortIds: string[], options?: { page?: boolean, pageSize?: boolean }) => { search: Vue.Ref<string>, filterModels: Vue.Ref<unknown[]>, sorting: Vue.Ref<unknown[]>, page: Vue.Ref<number> } }>('composables/useOfficeTableNavigation.ts', bindings)
  const navigation = useOfficeTableNavigation('work-tasks', ['status'], ['title'], { page: true })
  const scope = Vue.effectScope()
  scope.run(() => Vue.watch(navigation.filterModels, () => {
    navigation.page.value = 1
  }))
  try {
    navigation.page.value = 4
    await Vue.nextTick()
    assert.equal(navigation.page.value, 4)
    navigation.sorting.value = [{ id: 'title', desc: true }]
    await Vue.nextTick()
    assert.equal(navigation.page.value, 4)
    navigation.filterModels.value = [{ columnId: 'status', operator: 'is', values: ['done'] }]
    await Vue.nextTick()
    assert.equal(navigation.page.value, 1)
  } finally { scope.stop() }
})

test('remembered sort directions stay paired with supported sort ids and reset to no ordering', () => {
  const remembered = new Map<string, Vue.Ref>()
  const bindings = {
    computed: Vue.computed,
    useState(key: string, initial: () => unknown) {
      if (!remembered.has(key)) remembered.set(key, Vue.ref(initial()))
      return remembered.get(key)
    }
  }
  const foundation = loadAppComposable<{ useListNavigationState: (...args: unknown[]) => Vue.Ref }>('composables/useListNavigationState.ts', bindings)
  const { useOfficeTableNavigation } = loadAppComposable<{ useOfficeTableNavigation: (key: string, columns: string[], sortIds?: string[]) => { sorting: Vue.Ref<unknown[]> } }>('composables/useOfficeTableNavigation.ts', {
    ...bindings, useListNavigationState: foundation.useListNavigationState
  })
  const first = useOfficeTableNavigation('history', ['status'], ['title', 'due_on'])
  const stored = remembered.get('list-navigation-state')!.value.lists.history.value
  stored.sortIds = ['retired-column', 'title', 'due_on', 'title']
  stored.sortDesc = [true, false, 'invalid', true]

  const restored = useOfficeTableNavigation('history', ['status'], ['title', 'due_on'])
  assert.deepEqual(restored.sorting.value, [
    { id: 'title', desc: false },
    { id: 'due_on', desc: false }
  ])

  restored.sorting.value = []
  assert.deepEqual(first.sorting.value, [])
  const cleared = remembered.get('list-navigation-state')!.value.lists.history.value
  assert.deepEqual(cleared.sortIds, [])
  assert.deepEqual(cleared.sortDesc, [])
})

test('sort criteria are discarded when the shared navigation authority changes', () => {
  const remembered = new Map<string, Vue.Ref>()
  const bindings = {
    computed: Vue.computed,
    useState(key: string, initial: () => unknown) {
      if (!remembered.has(key)) remembered.set(key, Vue.ref(initial()))
      return remembered.get(key)
    }
  }
  const foundation = loadAppComposable<{ useListNavigationState: (...args: unknown[]) => Vue.Ref }>('composables/useListNavigationState.ts', bindings)
  const { useOfficeTableNavigation } = loadAppComposable<{ useOfficeTableNavigation: (key: string, columns: string[], sortIds?: string[]) => { sorting: Vue.Ref<unknown[]> } }>('composables/useOfficeTableNavigation.ts', {
    ...bindings, useListNavigationState: foundation.useListNavigationState
  })
  const scope = Vue.effectScope()
  try {
    const navigation = scope.run(() => useOfficeTableNavigation('private-sort', [], ['name']))!
    navigation.sorting.value = [{ id: 'name', desc: true }]
    assert.deepEqual(navigation.sorting.value, [{ id: 'name', desc: true }])

    const identity = remembered.get('access-user')!
    identity.value = { id: 2, is_super_admin: false, current_account: null, current_company: null }
    assert.deepEqual(navigation.sorting.value, [])
    assert.ok(!JSON.stringify(remembered.get('list-navigation-state')!.value).includes('name'))
  } finally { scope.stop() }
})
