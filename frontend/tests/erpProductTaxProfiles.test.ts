import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { apiFailure } from '../shared/utils/access.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type Company = {
  id: number
  status: 'active' | 'suspended'
  access_kind: 'direct' | 'delegated'
  role: string
  delegated_office_id: number | null
  permissions: string[]
}

type Account = { id: number, status: 'active' | 'suspended', role: 'admin' | 'operador' | 'user' }
type Profile = { id: number, name: string, status: 'active' | 'archived' }
type Request = { url: string, options: { signal?: AbortSignal }, resolve(value: unknown): void, reject(reason: unknown): void }
type CatalogList = {
  page: Vue.Ref<number>
  pending: Vue.Ref<boolean>
  state: Vue.Ref<{ data: Profile[], meta: { current_page: number, last_page: number, per_page: number, total: number } } | null>
  error: Vue.Ref<string>
  canRead: Vue.ComputedRef<boolean>
  authorityBlocked: Vue.ComputedRef<boolean>
}

function runtime(
  permissions: string[] = ['erp.product-tax-profiles.index'],
  access: { kind?: 'direct' | 'delegated', officeId?: number, account?: Account | null } = {},
  path = '/erp/tax-settings/product-tax-profiles'
) {
  const requests: Request[] = []
  const user = Vue.ref<{ id: number } | null>({ id: 5 })
  const company = Vue.ref<Company>({
    id: 41,
    status: 'active',
    access_kind: access.kind ?? 'direct',
    role: access.kind === 'delegated' ? 'member' : 'owner',
    delegated_office_id: access.officeId ?? null,
    permissions
  })
  const account = Vue.ref<Account | null>(access.account ?? null)
  const sessionGeneration = Vue.ref(1)
  const module = loadAppComposable<{ useErpCatalogList(path: string): CatalogList }>(
    'composables/useErpCatalogList.ts',
    {
      useAuth: () => ({ user, currentCompany: company, currentAccount: account }),
      useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? sessionGeneration : Vue.ref(init()),
      useAccessApi: () => ({
        api: (url: string, options: Request['options'] = {}) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }))
      }),
      apiFailure,
      computed: Vue.computed,
      onScopeDispose: Vue.onScopeDispose,
      ref: Vue.ref,
      watch: Vue.watch
    }
  )
  const scope = Vue.effectScope()
  const list = scope.run(() => module.useErpCatalogList(path))!
  return { list, requests, company, account, scope }
}

function page(data: Profile[], currentPage = 1, lastPage = 1) {
  return { data, meta: { current_page: currentPage, last_page: lastPage, per_page: 15, total: data.length } }
}

describe('ERP product tax profile catalog boundary', () => {
  it('loads only through the shared bounded catalog list and exact profile index grant', async (t) => {
    const allowed = runtime()
    t.after(() => allowed.scope.stop())
    assert.equal(allowed.list.canRead.value, true)
    assert.equal(allowed.requests.length, 1)
    assert.equal(allowed.requests[0]?.url, '/erp/tax-settings/product-tax-profiles?page=1')

    allowed.requests[0]!.resolve(page([{ id: 12, name: 'Rural', status: 'active' }], 1, 3))
    await Vue.nextTick()
    assert.equal(allowed.list.state.value?.data[0]?.id, 12)
    assert.equal(allowed.list.state.value?.meta.last_page, 3)

    const denied = runtime(['erp.products.update'])
    t.after(() => denied.scope.stop())
    assert.equal(denied.requests.length, 0)
    assert.equal(denied.list.canRead.value, false)
    assert.match(denied.list.error.value, /permiss|autoriz/i)
  })

  it('requires exact profile index authority and an active matching Account for delegated lookup', (t) => {
    const broadDelegation = runtime(['erp.read'], {
      kind: 'delegated',
      officeId: 30,
      account: { id: 30, status: 'active', role: 'operador' }
    })
    t.after(() => broadDelegation.scope.stop())
    assert.equal(broadDelegation.requests.length, 0, 'legacy broad read must not grant product profile access')
    assert.equal(broadDelegation.list.canRead.value, false)

    const mismatchedOffice = runtime(['erp.product-tax-profiles.index'], {
      kind: 'delegated',
      officeId: 30,
      account: { id: 31, status: 'active', role: 'operador' }
    })
    t.after(() => mismatchedOffice.scope.stop())
    assert.equal(mismatchedOffice.requests.length, 0)
    assert.equal(mismatchedOffice.list.canRead.value, false)

    const exactDelegation = runtime(['erp.product-tax-profiles.index'], {
      kind: 'delegated',
      officeId: 30,
      account: { id: 30, status: 'active', role: 'operador' }
    })
    t.after(() => exactDelegation.scope.stop())
    assert.equal(exactDelegation.requests.length, 1)
    assert.equal(exactDelegation.requests[0]?.url, '/erp/tax-settings/product-tax-profiles?page=1')
    assert.equal(exactDelegation.list.canRead.value, true)
  })

  it('aborts a pending profile page when Company authority changes', async (t) => {
    const app = runtime(['erp.products.index'], {}, '/erp/products')
    t.after(() => app.scope.stop())
    const oldRequest = app.requests[0]!
    app.company.value = { ...app.company.value, id: 42 }
    assert.equal(oldRequest.options.signal?.aborted, true)
    assert.equal(app.list.state.value, null)
    assert.equal(app.requests.length, 2)

    oldRequest.resolve(page([{ id: 12, name: 'Perfil antigo', status: 'active' }]))
    await Vue.nextTick()
    assert.equal(app.list.state.value, null)
  })
})
