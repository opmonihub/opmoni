import assert from 'node:assert/strict'
import { test } from 'node:test'
import { effectScope, ref, watch, type Ref } from 'vue'
import { useListNavigationRegistry, useListNavigationState, listNavigationField } from '../app/composables/useListNavigationState.ts'

function application() {
  const values = new Map<string, Ref>()
  const useState = (key: string, init: () => unknown) => {
    if (!values.has(key)) values.set(key, ref(init()))
    return values.get(key)!
  }
  Object.assign(globalThis, { useState })
  const identity = useState('access-user', () => ({
    id: 1, is_super_admin: true,
    current_account: { id: 10, status: 'active', role: 'admin' },
    current_company: { id: 20, status: 'active', role: 'admin', access_kind: 'direct', permissions: ['sales.read'], delegated_office_id: null }
  }))
  const session = useState('access-session-generation', () => 1)
  const scope = effectScope()
  scope.run(useListNavigationRegistry)
  return { identity, session, values, scope }
}
const options = {
  defaults: () => ({ q: '', page: 1, status: 'all' }),
  normalize(value: unknown) {
    const input = value as Record<string, unknown> | undefined
    return { q: typeof input?.q === 'string' ? input.q : '', page: Number.isSafeInteger(input?.page) && Number(input?.page) > 0 ? Number(input?.page) : 1, status: ['all', 'active'].includes(String(input?.status)) ? String(input?.status) : 'all' }
  }
}

test('real registry restores validated criteria across disposal while excluding unsupported state', () => {
  const app = application()
  const first = effectScope()
  const state = first.run(() => useListNavigationState('users', options))!
  state.value = { q: 'Needle', page: 3, status: 'active', records: [{ id: 1 }] } as typeof state.value
  first.stop()
  const second = effectScope()
  const restored = second.run(() => useListNavigationState('users', options))!
  assert.deepEqual(restored.value, { q: 'Needle', page: 3, status: 'active' })
  restored.value = { q: 'safe', page: -2, status: 'unsupported' }
  assert.deepEqual(restored.value, { q: 'safe', page: 1, status: 'all' })
  second.stop()
  app.scope.stop()
})

test('explicit reactive query wins and absent canonical query restores memory without stranding field lenses', () => {
  const app = application()
  const query = ref<ReturnType<typeof options.defaults> | null>(null)
  const scope = effectScope()
  const state = scope.run(() => useListNavigationState('users', { ...options, fromQuery: () => query.value }))!
  const q = listNavigationField(state, 'q')
  state.value = { q: 'remembered', page: 3, status: 'active' }
  query.value = { q: 'history', page: 2, status: 'all' }
  assert.equal(q.value, 'history')
  query.value = null
  assert.equal(q.value, 'history')
  app.session.value++
  assert.equal(q.value, '')
  scope.stop()
  app.scope.stop()
})

for (const field of ['actor', 'session', 'account', 'company', 'grant', 'super-admin'])
  test(`clears synchronously for ${field} even after every list unmounts; former authority has no bucket`, () => {
    const app = application()
    const scope = effectScope()
    const state = scope.run(() => useListNavigationState('users', options))!
    state.value.q = 'private'
    scope.stop()
    if (field === 'actor') app.identity.value.id++
    if (field === 'session') app.session.value++
    if (field === 'account') app.identity.value.current_account.id++
    if (field === 'company') app.identity.value.current_company.id++
    if (field === 'grant') app.identity.value.current_company.permissions = []
    if (field === 'super-admin') app.identity.value.is_super_admin = false
    const registry = app.values.get('list-navigation-state')!
    assert.deepEqual(registry.value.lists, {})
    assert.ok(!JSON.stringify(registry.value).includes('private'))
    app.scope.stop()
  })

test('SSR applications never share criteria and cleared Nuxt state recreates safe defaults', () => {
  const firstApp = application()
  const firstScope = effectScope()
  firstScope.run(() => useListNavigationState('users', options))!.value.q = 'private'
  firstScope.stop()
  firstApp.scope.stop()
  const secondApp = application()
  const secondScope = effectScope()
  const state = secondScope.run(() => useListNavigationState('users', options))!
  assert.equal(state.value.q, '')
  secondApp.values.get('list-navigation-state')!.value = undefined
  assert.equal(state.value.q, '')
  secondScope.stop()
  secondApp.scope.stop()
})

test('first capability confirmation keeps criteria, later revocation discards every list', () => {
  const app = application()
  const scope = effectScope()
  const grant = ref<string | null>(null)
  const state = scope.run(() => useListNavigationState('users', { ...options, authority: () => grant.value }))!
  state.value.q = 'remembered'
  grant.value = 'allowed'
  assert.equal(state.value.q, 'remembered')
  grant.value = 'denied'
  assert.equal(state.value.q, '')
  assert.ok(!JSON.stringify(app.values.get('list-navigation-state')!.value).includes('remembered'))
  scope.stop()
  app.scope.stop()
})

test('a valid URL remains authoritative when an identity change discards remembered criteria', () => {
  const app = application()
  const scope = effectScope()
  const query = ref({ q: 'from-url', page: 2, status: 'active' })
  const state = scope.run(() => useListNavigationState('url-list', { ...options, fromQuery: () => query.value }))!
  const privateState = scope.run(() => useListNavigationState('private-list', options))!
  privateState.value.q = 'remembered-only'

  app.session.value++

  assert.deepEqual(state.value, { q: 'from-url', page: 2, status: 'active' })
  assert.equal(privateState.value.q, '')
  assert.ok(!JSON.stringify(app.values.get('list-navigation-state')!.value).includes('remembered-only'))
  scope.stop()
  app.scope.stop()
})

test('effective membership permissions invalidate independently of selected identity metadata', () => {
  const app = application()
  app.identity.value.accounts = [{ ...app.identity.value.current_account }]
  app.identity.value.companies = [{ ...app.identity.value.current_company }]
  const scope = effectScope()
  const state = scope.run(() => useListNavigationState('users', options))!
  state.value.q = 'private'
  app.identity.value.accounts[0].role = 'user'
  assert.equal(state.value.q, '')
  state.value.q = 'private'
  app.identity.value.companies[0].permissions = []
  assert.equal(state.value.q, '')
  scope.stop()
  app.scope.stop()
})

test('supports codec-approved primitive multiselect arrays but stores no nested records', () => {
  const app = application()
  const scope = effectScope()
  const state = scope.run(() => useListNavigationState('filters', {
    defaults: () => ({ status: [] as string[] }),
    normalize: value => ({ status: Array.isArray((value as { status?: unknown })?.status) ? (value as { status: unknown[] }).status.filter((item): item is string => ['active', 'pending'].includes(String(item))) : [] })
  }))!
  state.value.status = ['active', 'pending']
  assert.deepEqual(state.value.status, ['active', 'pending'])
  scope.stop()
  app.scope.stop()
})

test('scalar field-lens edits preserve equivalent multiselect identity and never reset the selected page', () => {
  const app = application()
  const scope = effectScope()
  scope.run(() => {
    const state = useListNavigationState('filters', {
      defaults: () => ({ q: '', page: 1, tags: ['active'] }),
      normalize: (value) => {
        const input = value as { q: string, page: number, tags: string[] }
        return { q: input.q, page: input.page, tags: [...input.tags] }
      }
    })
    const page = listNavigationField(state, 'page')
    const tags = listNavigationField(state, 'tags')
    const q = listNavigationField(state, 'q')
    let filterChanges = 0
    watch(tags, () => {
      filterChanges++
      page.value = 1
    }, { flush: 'sync' })
    page.value = 3
    q.value = 'needle'
    assert.equal(page.value, 3)
    assert.equal(filterChanges, 0)
    tags.value = ['pending']
    assert.equal(filterChanges, 1)
    assert.equal(page.value, 1)
  })
  scope.stop()
  app.scope.stop()
})
