import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type Request = { path: string, options?: { method?: string, body?: Record<string, unknown> } }
function setup(api: (path: string, options?: Request['options']) => Promise<unknown>, permissions: string[] = ['*']) {
  const natureId = Vue.ref<number | null>(32)
  const movement = Vue.ref<'in' | 'out'>('out')
  const canRead = Vue.ref(true)
  const canWrite = Vue.ref(true)
  const rootDirty = Vue.ref(false)
  const user = Vue.ref({ id: 42 })
  const currentCompany = Vue.ref<Record<string, unknown>>({ id: 7, permissions, status: 'active', access_kind: 'direct', role: 'owner' })
  const currentAccount = Vue.ref(null)
  const sessionGeneration = Vue.ref(1)
  let refreshes = 0
  const loaded = loadAppComposable<Record<string, unknown>>('composables/useErpOperationNatureRules.ts', {
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api }),
    useAuth: () => ({
      user,
      currentCompany,
      currentAccount,
      refresh: async () => {
        refreshes++
        return { id: 42 }
      }
    }),
    useState: () => sessionGeneration
  })
  const scope = Vue.effectScope()
  const rules = scope.run(() => (loaded.useErpOperationNatureRules as (options: Record<string, unknown>) => Record<string, unknown>)({
    natureId,
    movement,
    canRead,
    canWrite,
    rootDirty
  }))!

  return { rules, natureId, canRead, canWrite, rootDirty, currentCompany, refreshCount: () => refreshes, stop: () => scope.stop() }
}

describe('operation-nature CFOP rules', () => {
  it('submits child changes against the currently reviewed configuration revision', async (t) => {
    const requests: Request[] = []
    const harness = setup(async (path, options) => {
      requests.push({ path, options })
      if (path.includes('/cfops?'))
        return { data: [{ code: '5102', description: 'Venda de mercadoria', reference_version: 'reference-v1', source_metadata: {}, applicability: { direction: 'outbound' } }], meta: { last_page: 1 } }
      if (!options?.method)
        return { data: [], meta: { configuration_revision: 6 } }
      return { data: { id: 71 }, meta: { configuration_revision: 7 } }
    })
    t.after(harness.stop)

    await (harness.rules.load as () => Promise<void>)()
    await (harness.rules.searchCfops as () => Promise<void>)()
    ;(harness.rules.selectCfop as (value: { code: string, reference_version: string }) => void)({ code: '5102', reference_version: 'reference-v1' })
    await (harness.rules.addChoice as (code: string) => Promise<boolean>)('5102')

    assert.deepEqual(requests.find(request => request.options?.method), {
      path: '/erp/tax-settings/operation-natures/32/choices',
      options: { method: 'POST', body: { cfop: '5102', expected_revision: 6 } }
    })
  })

  it('blocks child writes while the root catalog form has unsaved changes', async (t) => {
    const requests: Request[] = []
    const harness = setup(async (path, options) => {
      requests.push({ path, options })
      return { data: [], meta: { configuration_revision: 4 } }
    })
    t.after(harness.stop)
    await (harness.rules.load as () => Promise<void>)()
    harness.rootDirty.value = true

    const added = await (harness.rules.addChoice as (code: string) => Promise<boolean>)('6102')

    assert.equal(added, false)
    assert.equal(requests.filter(request => request.options?.method).length, 0)
  })

  it('keeps the reviewed revision and blocks retry after a stale configuration conflict', async (t) => {
    let rejectWrite = true
    const requests: Request[] = []
    const harness = setup(async (path, options) => {
      requests.push({ path, options })
      if (path.includes('/cfops?'))
        return { data: [{ code: '5102', description: 'Venda de mercadoria', reference_version: 'reference-v1', source_metadata: {}, applicability: { direction: 'outbound' } }], meta: { last_page: 1 } }
      if (options?.method && rejectWrite) {
        rejectWrite = false
        throw { statusCode: 409, data: { code: 'operation_nature_changed', message: 'The configuration changed.' } }
      }
      return { data: [], meta: { configuration_revision: 8 } }
    })
    t.after(harness.stop)
    await (harness.rules.load as () => Promise<void>)()
    await (harness.rules.searchCfops as () => Promise<void>)()
    ;(harness.rules.selectCfop as (value: { code: string, reference_version: string }) => void)({ code: '5102', reference_version: 'reference-v1' })

    const result = await (harness.rules.addChoice as (code: string) => Promise<boolean>)('5102')
    const retry = await (harness.rules.addChoice as (code: string) => Promise<boolean>)('5102')

    assert.equal(result, false)
    assert.equal(retry, false)
    assert.equal((harness.rules.revision as Vue.Ref<number | null>).value, 8)
    assert.equal((harness.rules.reviewRequired as Vue.Ref<boolean>).value, true)
    assert.equal(requests.filter(request => request.options?.method).length, 1)
  })

  it('keeps writes blocked when an explicit review fails after a stale conflict', async (t) => {
    let failReview = false
    let rejectWrite = false
    const requests: Request[] = []
    const harness = setup(async (path, options) => {
      requests.push({ path, options })
      if (path.includes('/cfops?'))
        return { data: [{ code: '6102', description: 'Venda', reference_version: 'reference-v1', source_metadata: {}, applicability: { direction: 'outbound' } }], meta: { last_page: 1 } }
      if (options?.method && rejectWrite) {
        rejectWrite = false
        throw { statusCode: 409, data: { code: 'operation_nature_changed', message: 'The configuration changed.' } }
      }
      if (!options?.method && failReview && path.includes('/choices?'))
        throw { statusCode: 503, data: { message: 'Temporarily unavailable.' } }
      return { data: [], meta: { configuration_revision: options?.method ? 9 : 8 } }
    })
    t.after(harness.stop)
    await (harness.rules.load as () => Promise<void>)()
    await (harness.rules.searchCfops as () => Promise<void>)()
    ;(harness.rules.selectCfop as (value: { code: string, reference_version: string }) => void)({ code: '6102', reference_version: 'reference-v1' })
    rejectWrite = true

    await (harness.rules.addChoice as (code: string) => Promise<boolean>)('6102')
    failReview = true
    const reviewed = await (harness.rules.reviewCurrent as () => Promise<boolean>)()
    const retry = await (harness.rules.addChoice as (code: string) => Promise<boolean>)('6102')

    assert.equal(reviewed, false)
    assert.equal(retry, false)
    assert.equal((harness.rules.reviewRequired as Vue.Ref<boolean>).value, true)
    assert.equal(requests.filter(request => request.options?.method).length, 1)
  })

  it('requires a successful explicit review and a separate user retry after a stale conflict', async (t) => {
    let rejectWrite = true
    let stale = false
    const requests: Request[] = []
    const harness = setup(async (path, options) => {
      requests.push({ path, options })
      if (path.includes('/cfops?'))
        return { data: [{ code: '6102', description: 'Venda', reference_version: 'reference-v1', source_metadata: {}, applicability: { direction: 'outbound' } }], meta: { last_page: 1 } }
      if (options?.method === 'POST' && rejectWrite) {
        rejectWrite = false
        stale = true
        throw { statusCode: 409, data: { code: 'operation_nature_changed', message: 'The configuration changed.' } }
      }
      return { data: [], meta: { configuration_revision: options?.method ? 10 : stale ? 8 : 6 } }
    })
    t.after(harness.stop)
    await (harness.rules.load as () => Promise<void>)()
    await (harness.rules.searchCfops as () => Promise<void>)()
    ;(harness.rules.selectCfop as (value: { code: string, reference_version: string }) => void)({ code: '6102', reference_version: 'reference-v1' })

    assert.equal(await (harness.rules.addChoice as (code: string) => Promise<boolean>)('6102'), false)
    assert.equal(await (harness.rules.addChoice as (code: string) => Promise<boolean>)('6102'), false)
    assert.equal(await (harness.rules.reviewCurrent as () => Promise<boolean>)(), true)
    assert.equal((harness.rules.reviewRequired as Vue.Ref<boolean>).value, false)
    assert.equal(await (harness.rules.addChoice as (code: string) => Promise<boolean>)('6102'), true)

    const writes = requests.filter(request => request.options?.method === 'POST')
    assert.deepEqual(writes.map(request => request.options?.body?.expected_revision), [6, 8])
  })

  it('ignores late responses after the active company or session context changes', async (t) => {
    const resolvers: Array<(value: unknown) => void> = []
    const harness = setup(() => new Promise((resolve) => {
      resolvers.push(resolve)
    }))
    t.after(harness.stop)

    const loading = (harness.rules.load as () => Promise<void>)()
    harness.currentCompany.value = { ...harness.currentCompany.value, id: 8 }
    for (const resolve of resolvers)
      resolve({ data: [{ cfop: '5102' }], meta: { last_page: 1, configuration_revision: 9 } })
    await loading

    assert.deepEqual((harness.rules.choices as Vue.Ref<unknown[]>).value, [])
    assert.equal((harness.rules.revision as Vue.Ref<number | null>).value, null)
  })

  it('does not search products without the existing product-read grant', async (t) => {
    const requests: Request[] = []
    const harness = setup(async (path, options) => {
      requests.push({ path, options })
      return { data: [], meta: { configuration_revision: 1 } }
    }, ['erp.operation-natures.index', 'erp.operation-natures.update'])
    t.after(harness.stop)

    await (harness.rules.searchProducts as () => Promise<void>)()

    assert.equal(requests.length, 0)
    assert.equal((harness.rules.canSearchProducts as Vue.Ref<boolean>).value, false)
    assert.match((harness.rules.error as Vue.Ref<string>).value, /permissão para consultar produtos/i)
  })

  it('uses bounded native product search and writes an exception against its saved choice and revision', async (t) => {
    const requests: Request[] = []
    const harness = setup(async (path, options) => {
      requests.push({ path, options })
      if (path.includes('/choices?'))
        return { data: [{ id: 18, cfop: '5102', reference_version: 'reference-v1', status: 'active', implicit: false }], meta: { last_page: 1, configuration_revision: 12 } }
      if (path.includes('/product-exceptions?'))
        return { data: [], meta: { last_page: 1, configuration_revision: 12 } }
      if (path.includes('/cfops?'))
        return { data: [], meta: { last_page: 1 } }
      if (path.startsWith('/erp/products?'))
        return { data: [{ id: 91, name: 'Café', sku: 'CAF-1' }], meta: { last_page: 1 } }
      if (options?.method === 'POST')
        return { data: { id: 44 }, configuration_revision: 13 }
      return { data: [] }
    })
    t.after(harness.stop)
    await (harness.rules.load as () => Promise<void>)()
    ;(harness.rules.productSearch as Vue.Ref<string>).value = 'CAF-1'
    await (harness.rules.searchProducts as () => Promise<void>)()
    ;(harness.rules.selectProduct as (value: { id: number, name: string, sku: string }) => void)({ id: 91, name: 'Café', sku: 'CAF-1' })
    ;(harness.rules.selectExceptionChoice as (code: string) => void)('5102')

    const added = await (harness.rules.addException as () => Promise<boolean>)()

    assert.equal(added, true)
    assert.match(requests.find(request => request.path.startsWith('/erp/products?'))?.path ?? '', /search=CAF-1.*per_page=15/)
    assert.deepEqual(requests.find(request => request.options?.method === 'POST'), {
      path: '/erp/tax-settings/operation-natures/32/product-exceptions',
      options: { method: 'POST', body: { erp_product_id: 91, erp_operation_nature_cfop_choice_id: 18, expected_revision: 12 } }
    })
  })

  it('preserves the selected product and CFOP after an exception write fails', async (t) => {
    const harness = setup(async (path, options) => {
      if (path.includes('/choices?'))
        return { data: [{ id: 18, cfop: '5102', reference_version: 'reference-v1', status: 'active', implicit: false }], meta: { last_page: 1, configuration_revision: 12 } }
      if (path.includes('/product-exceptions?'))
        return { data: [], meta: { last_page: 1, configuration_revision: 12 } }
      if (path.includes('/cfops?'))
        return { data: [], meta: { last_page: 1 } }
      if (path.startsWith('/erp/products?'))
        return { data: [{ id: 91, name: 'Café', sku: 'CAF-1' }], meta: { last_page: 1 } }
      if (options?.method === 'POST')
        throw { statusCode: 422, data: { message: 'Produto fora da empresa.' } }
      return { data: [] }
    })
    t.after(harness.stop)
    await (harness.rules.load as () => Promise<void>)()
    await (harness.rules.searchProducts as () => Promise<void>)()
    const product = { id: 91, name: 'Café', sku: 'CAF-1' }
    ;(harness.rules.selectProduct as (value: typeof product) => void)(product)
    ;(harness.rules.selectExceptionChoice as (code: string) => void)('5102')

    const added = await (harness.rules.addException as () => Promise<boolean>)()

    assert.equal(added, false)
    assert.deepEqual((harness.rules.selectedProduct as Vue.Ref<unknown>).value, product)
    assert.equal((harness.rules.selectedExceptionChoiceCode as Vue.Ref<string>).value, '5102')
    assert.match((harness.rules.error as Vue.Ref<string>).value, /Produto fora da empresa/)
  })

  it('stages a requested default by code and revision instead of choosing the first allowed option', async (t) => {
    const requests: Request[] = []
    const harness = setup(async (path, options) => {
      requests.push({ path, options })
      if (path.includes('/choices?'))
        return { data: [
          { id: 21, cfop: '6102', reference_version: 'reference-v1', status: 'active', implicit: false },
          { id: 22, cfop: '5102', reference_version: 'reference-v1', status: 'active', implicit: false }
        ], meta: { last_page: 1, configuration_revision: 15 } }
      return { data: [], meta: { last_page: 1, configuration_revision: 15 } }
    })
    t.after(harness.stop)
    await (harness.rules.load as () => Promise<void>)()

    const staged = await (harness.rules.setDefault as (code: string) => Promise<{ code: string, expected_revision: number }>)('6102')

    assert.deepEqual(staged, { code: '6102', expected_revision: 15 })
    assert.equal(requests.filter(request => request.options?.method).length, 0)
  })

  it('keeps a selected reference visible after lookup failure and blocks its use as new authority', async (t) => {
    let failed = false
    const harness = setup(async (path, _options) => {
      if (path.includes('/choices?'))
        return { data: [], meta: { last_page: 1, configuration_revision: 4 } }
      if (path.includes('/product-exceptions?'))
        return { data: [], meta: { last_page: 1, configuration_revision: 4 } }
      if (path.includes('/cfops?')) {
        if (failed)
          throw { statusCode: 503, data: { message: 'A referência de CFOP está indisponível.' } }
        return { data: [{ code: '5102', description: 'Venda de mercadoria', reference_version: 'reference-v1', source_metadata: {}, applicability: { direction: 'outbound' } }], meta: { last_page: 1 } }
      }
      return { data: [], meta: { configuration_revision: 5 } }
    })
    t.after(harness.stop)
    await (harness.rules.load as () => Promise<void>)()
    await (harness.rules.searchCfops as () => Promise<void>)()
    const selected = { code: '5102', description: 'Venda de mercadoria', reference_version: 'reference-v1', source_metadata: {}, applicability: { direction: 'outbound' } }
    ;(harness.rules.selectCfop as (value: typeof selected) => void)(selected)
    failed = true
    await (harness.rules.searchCfops as () => Promise<void>)()

    const added = await (harness.rules.addChoice as (code: string) => Promise<boolean>)('5102')

    assert.deepEqual((harness.rules.selectedCfop as Vue.Ref<unknown>).value, selected)
    assert.equal((harness.rules.cfopResults as Vue.Ref<unknown[]>).value.length, 1)
    assert.equal((harness.rules.cfopResultsCurrent as Vue.Ref<boolean>).value, false)
    assert.equal(added, false)
    assert.match((harness.rules.error as Vue.Ref<string>).value, /Serviço temporariamente indisponível/i)
  })

  it('ignores an older CFOP search response when a newer query has started', async (t) => {
    const pending: Array<{ path: string, resolve: (value: unknown) => void }> = []
    const harness = setup(path => new Promise((resolve) => {
      pending.push({ path, resolve })
    }))
    t.after(harness.stop)

    ;(harness.rules.cfopSearch as Vue.Ref<string>).value = '5102'
    const older = (harness.rules.searchCfops as () => Promise<void>)()
    ;(harness.rules.cfopSearch as Vue.Ref<string>).value = '6102'
    const newer = (harness.rules.searchCfops as () => Promise<void>)()
    pending[1]?.resolve({ data: [{ code: '6102', description: 'Atual', reference_version: 'v1', source_metadata: {}, applicability: {} }], meta: { last_page: 1 } })
    await newer
    pending[0]?.resolve({ data: [{ code: '5102', description: 'Antiga', reference_version: 'v1', source_metadata: {}, applicability: {} }], meta: { last_page: 1 } })
    await older

    assert.match(pending[0]?.path ?? '', /q=5102/)
    assert.match(pending[1]?.path ?? '', /q=6102/)
    assert.deepEqual((harness.rules.cfopResults as Vue.Ref<Array<{ code: string }>>).value.map(item => item.code), ['6102'])
  })

  it('hydrates visible exception product and choice identities without loading either catalog', async (t) => {
    const requests: Request[] = []
    const harness = setup(async (path, options) => {
      requests.push({ path, options })
      if (path.includes('/choices?'))
        return { data: [], meta: { last_page: 1, configuration_revision: 18 } }
      if (path.includes('/product-exceptions?'))
        return { data: [{ id: 70, erp_product_id: 90, erp_operation_nature_cfop_choice_id: 19, status: 'active' }], meta: { last_page: 1, configuration_revision: 18 } }
      if (path.endsWith('/choices/19'))
        return { data: { id: 19, cfop: '6102', reference_version: 'reference-v1', status: 'active', implicit: false } }
      if (path === '/erp/products/90')
        return { data: { id: 90, name: 'Mercadoria', sku: 'SKU-90' } }
      return { data: [] }
    })
    t.after(harness.stop)

    await (harness.rules.load as () => Promise<void>)()

    assert.deepEqual((harness.rules.exceptionChoiceRefs as Vue.Ref<Record<number, { cfop: string }>>).value, {
      19: { id: 19, cfop: '6102', reference_version: 'reference-v1', status: 'active', implicit: false }
    })
    assert.ok(requests.some(request => request.path === '/erp/products/90'), JSON.stringify(requests))
    assert.equal((harness.rules.exceptions as Vue.Ref<Array<{ product?: { name: string, sku: string } }>>).value[0]?.product?.sku, 'SKU-90')
    assert.equal(requests.filter(request => request.path.startsWith('/erp/products?')).length, 0)
  })

  it('blocks retries after authority loss until the existing session refresh succeeds', async (t) => {
    let denied = true
    let requests = 0
    const harness = setup(async (_path) => {
      requests++
      if (denied)
        throw { statusCode: 403, data: { message: 'Acesso negado.' } }
      return { data: [], meta: { configuration_revision: 20, last_page: 1 } }
    })
    t.after(harness.stop)

    await (harness.rules.load as () => Promise<void>)()
    const deniedRequests = requests
    await (harness.rules.load as () => Promise<void>)()
    denied = false
    const recovered = await (harness.rules.retry as () => Promise<boolean>)()

    assert.equal(deniedRequests, 2)
    assert.equal(harness.refreshCount(), 1)
    assert.equal(recovered, true)
    assert.equal((harness.rules.authorityBlocked as Vue.Ref<boolean>).value, false)
    assert.equal((harness.rules.revision as Vue.Ref<number | null>).value, 20)
  })
})
