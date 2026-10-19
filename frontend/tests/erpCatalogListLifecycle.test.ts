import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { apiFailure } from '../shared/utils/access.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

interface Company {
  id: number
  status: 'active' | 'suspended'
  access_kind: 'direct' | 'delegated'
  role: string
  permissions: string[]
  delegated_office_id: number | null
}

interface Account {
  id: number
  status: 'active' | 'suspended'
  role: 'admin' | 'operador' | 'user'
}

interface CatalogList {
  search: Vue.Ref<string>
  page: Vue.Ref<number>
  pending: Vue.Ref<boolean>
  searchPending: Vue.Ref<boolean>
  state: Vue.Ref<{ data: Array<{ id: number, name: string }>, meta: Record<string, number> } | null>
  error: Vue.Ref<string>
  hasLoaded: Vue.ComputedRef<boolean>
  stale: Vue.ComputedRef<boolean>
  authorityBlocked: Vue.ComputedRef<boolean>
  refresh(): Promise<void>
  retry(): Promise<void>
}

interface CatalogRequest {
  url: string
  options: { signal?: AbortSignal }
  resolve(value: unknown): void
  reject(reason: unknown): void
}

function company(overrides: Partial<Company> = {}): Company {
  return {
    id: 41,
    status: 'active',
    access_kind: 'direct',
    role: 'owner',
    permissions: ['erp.read'],
    delegated_office_id: null,
    ...overrides
  }
}

function response(name: string, page = 1) {
  return {
    data: [{ id: page, name }],
    meta: { current_page: page, last_page: 3, per_page: 15, total: 45 }
  }
}

function setupList(path = '/erp/products', initial: {
  company?: Company | null
  account?: Account | null
  actorId?: number | null
  sessionGeneration?: number
} = {}) {
  const user = Vue.ref<{ id: number } | null>(initial.actorId === null ? null : { id: initial.actorId ?? 5 })
  const currentCompany = Vue.ref<Company | null>(initial.company === undefined ? company() : initial.company)
  const currentAccount = Vue.ref<Account | null>(initial.account ?? null)
  const sessionGeneration = Vue.ref(initial.sessionGeneration ?? 7)
  const requests: CatalogRequest[] = []
  let refreshAuthCalls = 0
  let authRefresh: () => Promise<unknown> = async () => user.value
  const api = (url: string, options: { signal?: AbortSignal } = {}) => new Promise<unknown>((resolve, reject) => {
    requests.push({ url, options, resolve, reject })
  })
  const useAuth = () => ({
    user,
    currentCompany,
    currentAccount,
    refresh: async () => {
      refreshAuthCalls++
      return authRefresh()
    }
  })
  const useState = <T>(key: string, init: () => T) => key === 'access-session-generation'
    ? sessionGeneration as Vue.Ref<T>
    : Vue.ref(init())
  const { useErpCatalogList } = loadAppComposable<{ useErpCatalogList(route: string): CatalogList }>(
    'composables/useErpCatalogList.ts',
    {
      useAuth,
      useState,
      useAccessApi: () => ({ api }),
      apiFailure,
      ref: Vue.ref,
      computed: Vue.computed,
      watch: Vue.watch,
      onScopeDispose: Vue.onScopeDispose
    }
  )
  const scope = Vue.effectScope()
  const list = scope.run(() => useErpCatalogList(path))!

  return {
    list,
    requests,
    user,
    currentCompany,
    currentAccount,
    sessionGeneration,
    stop: () => scope.stop(),
    refreshAuthCalls: () => refreshAuthCalls,
    setAuthRefresh: (refresh: () => Promise<unknown>) => { authRefresh = refresh },
    settle: async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Vue.nextTick()
    }
  }
}

describe('shared ERP catalog list lifecycle', () => {
  it('starts only for an active Company with the exact route read permission', () => {
    const denied = setupList('/erp/products', { company: company({ permissions: ['erp.products.write'] }) })
    assert.equal(denied.requests.length, 0)
    assert.equal(denied.list.state.value, null)
    assert.equal(denied.list.hasLoaded.value, false)
    assert.match(denied.list.error.value, /permiss|autoriz/i)
    denied.stop()

    const directWithoutOffice = setupList('/erp/products', {
      company: company({ permissions: ['erp.products.read'] }),
      account: { id: 9, status: 'suspended', role: 'user' }
    })
    assert.equal(directWithoutOffice.requests.length, 1)
    assert.equal(directWithoutOffice.list.pending.value, true)
    assert.match(directWithoutOffice.requests[0]!.url, /^\/erp\/products\?page=1$/)
    directWithoutOffice.stop()
  })

  it('maps every catalog URL to its backend route permission, including fiscal short names', () => {
    const routes = [
      ['/erp/contacts', 'erp.contacts.index'],
      ['/erp/products', 'erp.products.index'],
      ['/erp/units', 'erp.units.index'],
      ['/erp/categories', 'erp.categories.index'],
      ['/erp/brands', 'erp.brands.index'],
      ['/erp/price-groups', 'erp.price-groups.index'],
      ['/erp/customer-groups', 'erp.customer-groups.index'],
      ['/erp/discount-rules', 'erp.discount-rules.index'],
      ['/erp/tax-settings/tax-rates', 'erp.tax-rates.index'],
      ['/erp/tax-settings/operation-natures', 'erp.operation-natures.index'],
      ['/erp/carriers', 'erp.carriers.index'],
      ['/erp/vehicles', 'erp.vehicles.index'],
      ['/erp/drivers', 'erp.drivers.index']
    ] as const

    for (const [route, permission] of routes) {
      const app = setupList(route, { company: company({ permissions: [permission] }) })
      assert.equal(app.requests.length, 1, `${route} should accept ${permission}`)
      assert.equal(app.requests[0]!.url, `${route}?page=1`)
      app.stop()
    }
  })

  it('requires the selected active office to match a delegated Company', () => {
    const mismatch = setupList('/erp/products', {
      company: company({ access_kind: 'delegated', delegated_office_id: 12, permissions: ['erp.read'] }),
      account: { id: 13, status: 'active', role: 'admin' }
    })
    assert.equal(mismatch.requests.length, 0)
    assert.match(mismatch.list.error.value, /escrit[oó]rio|contexto/i)
    mismatch.stop()

    const matching = setupList('/erp/products', {
      company: company({ access_kind: 'delegated', delegated_office_id: 12, permissions: ['erp.read'] }),
      account: { id: 12, status: 'active', role: 'user' }
    })
    assert.equal(matching.requests.length, 1)
    matching.stop()
  })

  it('clears and aborts synchronously on context change, then ignores a late old success and finally', async () => {
    const app = setupList()
    const first = app.requests[0]!
    app.currentCompany.value = company({ id: 42 })
    assert.equal(first.options.signal?.aborted, true)
    assert.equal(app.list.state.value, null)
    assert.equal(app.list.search.value, '')
    assert.equal(app.list.page.value, 1)
    assert.equal(app.list.pending.value, true)
    assert.equal(app.requests.length, 2)

    first.resolve(response('Resposta antiga'))
    await app.settle()
    assert.equal(app.list.state.value, null)
    assert.equal(app.list.error.value, '')
    assert.equal(app.list.pending.value, true, 'old finally must not release the new request')

    app.requests[1]!.resolve(response('Empresa atual'))
    await app.settle()
    assert.equal(app.list.state.value?.data[0]?.name, 'Empresa atual')
    assert.equal(app.list.pending.value, false)
    app.stop()
  })

  it('ignores a late old-context authorization error after the new list succeeds', async () => {
    const app = setupList()
    const oldRequest = app.requests[0]!
    app.currentCompany.value = company({ id: 42 })
    const currentRequest = app.requests[1]!
    currentRequest.resolve(response('Empresa atual'))
    await app.settle()
    oldRequest.reject({ statusCode: 403, data: { message: 'Acesso antigo revogado' } })
    await app.settle()
    assert.equal(app.list.state.value?.data[0]?.name, 'Empresa atual')
    assert.equal(app.list.error.value, '')
    assert.equal(app.list.authorityBlocked.value, false)
    assert.equal(app.list.pending.value, false)
    app.stop()
  })

  it('invalidates captures for actor, Account, session, delegated office and authority changes', async (t) => {
    const delegated = company({ access_kind: 'delegated', delegated_office_id: 12 })
    const changes: Array<[
      string,
      Parameters<typeof setupList>[1],
      (app: ReturnType<typeof setupList>) => void
    ]> = [
      ['actor', {}, (app) => { app.user.value = { id: 6 } }],
      ['Account', {}, (app) => { app.currentAccount.value = { id: 20, status: 'active', role: 'admin' } }],
      ['session generation', {}, (app) => { app.sessionGeneration.value++ }],
      ['delegated office and access kind', {
        company: delegated,
        account: { id: 12, status: 'active', role: 'user' }
      }, (app) => { app.currentCompany.value = company({ access_kind: 'direct', permissions: ['erp.read'] }) }],
      ['Company authority', {}, (app) => { app.currentCompany.value = company({ permissions: ['erp.products.read'] }) }]
    ]

    for (const [label, initial, change] of changes) {
      await t.test(label, async () => {
        const app = setupList('/erp/products', initial)
        const oldRequest = app.requests[0]!
        change(app)
        assert.equal(app.list.state.value, null)
        assert.equal(app.list.search.value, '')
        assert.equal(app.list.page.value, 1)
        assert.equal(oldRequest.options.signal?.aborted, true)
        assert.equal(app.requests.length, 2)
        app.requests[1]!.resolve(response('Depois'))
        await app.settle()
        assert.equal(app.list.state.value?.data[0]?.name, 'Depois')
        app.stop()
      })
    }
  })

  it('keeps same-context last-good rows visibly stale on a transient refresh failure and retries', async () => {
    const app = setupList()
    app.requests[0]!.resolve(response('Última leitura'))
    await app.settle()
    const refresh = app.list.refresh()
    assert.equal(app.requests.length, 2)
    app.requests[1]!.reject({ statusCode: 503, data: { message: 'Falha transitória' } })
    await refresh
    await app.settle()
    assert.equal(app.list.state.value?.data[0]?.name, 'Última leitura')
    assert.equal(app.list.hasLoaded.value, true)
    assert.equal(app.list.stale.value, true)
    assert.match(app.list.error.value, /temporariamente indisponível/i)

    const retry = app.list.retry()
    assert.equal(app.requests.length, 3)
    app.requests[2]!.resolve(response('Leitura atual'))
    await retry
    assert.equal(app.list.state.value?.data[0]?.name, 'Leitura atual')
    assert.equal(app.list.stale.value, false)
    assert.equal(app.list.error.value, '')
    app.stop()
  })

  it('clears on 401, 403 or named context conflict and only retries after an auth refresh', async (t) => {
    const failures = [
      ['401', { statusCode: 401, data: { message: 'Sessão expirou' } }],
      ['403', { statusCode: 403, data: { message: 'Acesso negado' } }],
      ['named 409', { statusCode: 409, data: { code: 'company_context_changed', message: 'Contexto alterado' } }]
    ] as const

    for (const [label, failure] of failures) {
      await t.test(label, async () => {
        const app = setupList()
        app.requests[0]!.reject(failure)
        await app.settle()
        assert.equal(app.list.state.value, null)
        assert.equal(app.list.authorityBlocked.value, true)
        assert.equal(app.list.pending.value, false)
        assert.equal(app.requests.length, 1, 'the rejected authority must not trigger an automatic retry')

        await app.list.refresh()
        assert.equal(app.requests.length, 1, 'ordinary refresh stays blocked until auth is renewed')

        const retry = app.list.retry()
        assert.equal(app.refreshAuthCalls(), 1)
        await app.settle()
        assert.equal(app.requests.length, 2)
        app.requests[1]!.resolve(response('Após renovar'))
        await retry
        assert.equal(app.list.state.value?.data[0]?.name, 'Após renovar')
        assert.equal(app.list.authorityBlocked.value, false)
        app.stop()
      })
    }
  })

  it('does not let an auth refresh started for a rejected context replace a newer context request', async () => {
    const app = setupList()
    app.requests[0]!.reject({ statusCode: 403, data: { message: 'Acesso negado' } })
    await app.settle()

    let finishAuthRefresh!: (identity: unknown) => void
    app.setAuthRefresh(() => new Promise((resolve) => {
      finishAuthRefresh = resolve
    }))
    const retry = app.list.retry()
    assert.equal(app.refreshAuthCalls(), 1)

    app.currentCompany.value = company({ id: 42 })
    assert.equal(app.list.state.value, null)
    assert.equal(app.requests.length, 2)
    assert.equal(app.list.pending.value, true)

    finishAuthRefresh(app.user.value)
    await retry
    assert.equal(app.requests.length, 2)
    assert.equal(app.list.pending.value, true)

    app.requests[1]!.resolve(response('Empresa atual'))
    await app.settle()
    assert.equal(app.list.state.value?.data[0]?.name, 'Empresa atual')
    app.stop()
  })

  it('keeps ordinary same-context 409 data and does not treat it as lost authority', async () => {
    const app = setupList()
    app.requests[0]!.resolve(response('Confirmado'))
    await app.settle()
    const refresh = app.list.refresh()
    assert.equal(app.requests.length, 2)
    app.requests[1]!.reject({ statusCode: 409, data: { code: 'duplicate_reference', message: 'Conflito comum' } })
    await refresh
    await app.settle()
    assert.equal(app.list.state.value?.data[0]?.name, 'Confirmado')
    assert.equal(app.list.stale.value, true)
    assert.equal(app.list.authorityBlocked.value, false)
    app.stop()
  })

  it('debounces text, resets page, and honors an explicit page action during the debounce', async () => {
    const app = setupList()
    app.requests[0]!.resolve(response('Página 1'))
    await app.settle()
    app.list.page.value = 3
    assert.equal(app.requests[1]!.url, '/erp/products?page=3')
    app.requests[1]!.resolve(response('Página 3', 3))
    await app.settle()

    app.list.search.value = '  arroz  '
    assert.equal(app.list.page.value, 1)
    assert.equal(app.requests.length, 2, 'typing must wait for the debounce')
    assert.equal(app.list.searchPending.value, true)
    app.list.page.value = 2
    assert.equal(app.requests.length, 3, 'an explicit page action is fetched immediately')
    assert.equal(app.requests[2]!.url, '/erp/products?page=2&search=arroz')
    app.requests[2]!.resolve(response('Página 2', 2))
    await app.settle()
    await new Promise(resolve => setTimeout(resolve, 280))
    assert.equal(app.requests.length, 3, 'the cancelled debounce must not replace the explicit page request')
    assert.equal(app.list.state.value?.data[0]?.name, 'Página 2')
    app.stop()
  })

  it('keeps successful empty distinct from initial pending and initial failure', async (t) => {
    await t.test('initial pending', () => {
      const app = setupList()
      assert.equal(app.list.state.value, null)
      assert.equal(app.list.hasLoaded.value, false)
      assert.equal(app.list.pending.value, true)
      app.stop()
    })

    await t.test('initial failure', async () => {
      const app = setupList()
      app.requests[0]!.reject({ statusCode: 500 })
      await app.settle()
      assert.equal(app.list.state.value, null)
      assert.equal(app.list.hasLoaded.value, false)
      assert.equal(app.list.pending.value, false)
      assert.notEqual(app.list.error.value, '')
      app.stop()
    })

    await t.test('successful empty', async () => {
      const app = setupList()
      app.requests[0]!.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } })
      await app.settle()
      assert.deepEqual(app.list.state.value?.data, [])
      assert.equal(app.list.hasLoaded.value, true)
      assert.equal(app.list.error.value, '')
      app.stop()
    })
  })

  it('aborts on dispose and cannot restart or accept a late result', async () => {
    const app = setupList()
    const first = app.requests[0]!
    app.stop()
    assert.equal(first.options.signal?.aborted, true)
    app.currentCompany.value = company({ id: 99 })
    await app.list.refresh()
    await app.list.retry()
    assert.equal(app.requests.length, 1)
    first.resolve(response('Resposta após dispose'))
    await app.settle()
    assert.equal(app.list.state.value, null)
    assert.equal(app.list.pending.value, false)
  })
})
