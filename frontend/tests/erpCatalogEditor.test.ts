import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { erpCompanyHasPermission } from '../app/utils/erpModulesNav.ts'
import { erpCatalogRoutes } from '../shared/utils/erpCatalogs.ts'
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

interface Request {
  url: string
  options: Record<string, unknown> & { signal?: AbortSignal }
  resolve(value: unknown): void
  reject(reason: unknown): void
}

interface Editor {
  permissions: {
    show: Vue.ComputedRef<boolean>
    store: Vue.ComputedRef<boolean>
    update: Vue.ComputedRef<boolean>
    archive: Vue.ComputedRef<boolean>
    edit: Vue.ComputedRef<boolean>
  }
  authorityBlocked: Vue.ComputedRef<boolean>
  refreshingAuthority: Vue.Ref<boolean>
  canSave: Vue.ComputedRef<boolean>
  mutationPending: Vue.ComputedRef<boolean>
  record: Vue.Ref<{ id: number } | null>
  mode: Vue.Ref<'create' | 'detail' | 'edit'>
  open: Vue.Ref<boolean>
  loadingDetail: Vue.Ref<boolean>
  saving: Vue.Ref<boolean>
  archiving: Vue.Ref<boolean>
  error: Vue.Ref<string>
  fieldErrors: Vue.Ref<Record<string, string[]>>
  create(): void
  view(id: number): Promise<void>
  edit(id: number): Promise<void>
  save(payload: Record<string, unknown>): Promise<void>
  archive(id: number): Promise<void>
  refreshAuthority(): Promise<boolean>
  cancel(): void
}

function company(overrides: Partial<Company> = {}): Company {
  return {
    id: 41,
    status: 'active',
    access_kind: 'direct',
    role: 'owner',
    permissions: [
      'erp.products.show',
      'erp.products.store',
      'erp.products.update',
      'erp.products.archive'
    ],
    delegated_office_id: null,
    ...overrides
  }
}

function setupEditor(initial: {
  company?: Company | null
  account?: Account | null
  actorId?: number | null
  sessionGeneration?: number
} = {}) {
  const user = Vue.ref<{ id: number } | null>(initial.actorId === null ? null : { id: initial.actorId ?? 5 })
  const currentCompany = Vue.ref<Company | null>(initial.company === undefined ? company() : initial.company)
  const currentAccount = Vue.ref<Account | null>(initial.account ?? null)
  const sessionGeneration = Vue.ref(initial.sessionGeneration ?? 7)
  const requests: Request[] = []
  let refreshAuthCalls = 0
  let authRefresh: () => Promise<unknown> = async () => user.value
  let refreshListCalls = 0
  const api = (url: string, options: Record<string, unknown> = {}) => new Promise<unknown>((resolve, reject) => {
    requests.push({ url, options: options as Request['options'], resolve, reject })
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
  const { useErpCatalogEditor } = loadAppComposable<{
    useErpCatalogEditor(path: string, refresh: () => Promise<void>): Editor
  }>('composables/useErpCatalogEditor.ts', {
    computed: Vue.computed,
    onScopeDispose: Vue.onScopeDispose,
    ref: Vue.ref,
    watch: Vue.watch,
    useAuth,
    useState,
    useAccessApi: () => ({ api, refreshCsrf: async () => undefined }),
    erpCompanyHasPermission
  })
  const scope = Vue.effectScope()
  const editor = scope.run(() => useErpCatalogEditor(erpCatalogRoutes.products, async () => {
    refreshListCalls++
  }))!

  return {
    editor,
    requests,
    user,
    currentCompany,
    currentAccount,
    sessionGeneration,
    setAuthRefresh: (refresh: () => Promise<unknown>) => { authRefresh = refresh },
    refreshAuthCalls: () => refreshAuthCalls,
    refreshListCalls: () => refreshListCalls,
    stop: () => scope.stop(),
    settle: async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Promise.resolve()
      await Vue.nextTick()
    }
  }
}

describe('ERP catalog editor authority and request lifecycle', () => {
  it('provides read-only detail for show-only members without exposing edit or archive', async () => {
    const app = setupEditor({
      company: company({ role: 'viewer', permissions: ['erp.products.show'] })
    })
    const { editor } = app

    assert.equal(editor.permissions.show.value, true)
    assert.equal(editor.permissions.edit.value, false)
    assert.equal(editor.permissions.archive.value, false)
    const viewing = editor.view(17)
    assert.equal(app.requests[0]?.url, '/erp/products/17')
    app.requests[0]!.resolve({ data: { id: 17, name: 'Consulta' } })
    await viewing
    await app.settle()

    assert.equal(editor.mode.value, 'detail')
    assert.equal(editor.open.value, true)
    assert.equal(editor.record.value?.id, 17)
    assert.equal(editor.canSave.value, false)
    await editor.save({ name: 'Tentativa de edição' })
    assert.match(editor.error.value, /consulta/i)
    await editor.archive(17)
    assert.equal(app.requests.length, 1)
    assert.match(editor.error.value, /permissão/i)
    app.stop()
  })

  it('requires show and update to edit, and fetches the real record before opening edit mode', async () => {
    const app = setupEditor({ company: company({ permissions: ['erp.products.update'] }) })
    assert.equal(app.editor.permissions.update.value, true)
    assert.equal(app.editor.permissions.show.value, false)
    assert.equal(app.editor.permissions.edit.value, false)

    await app.editor.edit(4)
    assert.equal(app.requests.length, 0)
    assert.match(app.editor.error.value, /consultar e editar/i)
    app.stop()

    const permitted = setupEditor()
    const editing = permitted.editor.edit(4)
    assert.equal(permitted.editor.mode.value, 'edit')
    assert.equal(permitted.editor.open.value, true)
    assert.equal(permitted.editor.loadingDetail.value, true)
    assert.equal(permitted.requests[0]?.url, '/erp/products/4')
    permitted.requests[0]!.resolve({ data: { id: 4, name: 'Produto' } })
    await editing
    await permitted.settle()
    assert.equal(permitted.editor.record.value?.id, 4)
    assert.equal(permitted.editor.canSave.value, true)
    permitted.stop()
  })

  it('aborts a delayed detail response and clears form state synchronously when authority changes', async () => {
    const app = setupEditor()
    const loading = app.editor.edit(8)
    const oldRequest = app.requests[0]!
    app.currentCompany.value = company({ id: 42 })

    assert.equal(oldRequest.options.signal?.aborted, true)
    assert.equal(app.editor.open.value, false)
    assert.equal(app.editor.record.value, null)
    assert.equal(app.editor.loadingDetail.value, false)

    oldRequest.resolve({ data: { id: 8, name: 'Resposta da empresa anterior' } })
    await loading
    await app.settle()
    assert.equal(app.editor.record.value, null)
    assert.equal(app.editor.open.value, false)
    assert.equal(app.editor.error.value, '')
    app.stop()
  })

  it('clears protected form state and blocks writes after 401, 403, or company-context 409', async (t) => {
    const failures = [
      { statusCode: 401, data: { message: 'Sessão expirada' } },
      { statusCode: 403, data: { message: 'Permissão revogada' } },
      { statusCode: 409, data: { code: 'company_context_changed', message: 'Empresa alterada' } }
    ]

    for (const failure of failures) {
      await t.test(`${failure.statusCode}:${failure.data.code ?? ''}`, async () => {
        const app = setupEditor()
        const loading = app.editor.edit(12)
        app.requests[0]!.resolve({ data: { id: 12, name: 'Protegido' } })
        await loading
        await app.settle()

        const saving = app.editor.save({ name: 'Alteração local' })
        await app.settle()
        const mutation = app.requests[1]!
        mutation.reject(failure)
        await saving
        await app.settle()

        assert.equal(app.editor.authorityBlocked.value, true)
        assert.equal(app.editor.open.value, false)
        assert.equal(app.editor.record.value, null)
        assert.deepEqual(app.editor.fieldErrors.value, {})
        const requestCount = app.requests.length
        await app.editor.save({ name: 'Não enviar' })
        await app.editor.archive(12)
        assert.equal(app.requests.length, requestCount)

        app.setAuthRefresh(async () => ({ id: 5 }))
        assert.equal(await app.editor.refreshAuthority(), true)
        assert.equal(app.refreshAuthCalls(), 1)
        assert.equal(app.editor.authorityBlocked.value, false)
        assert.equal(app.editor.error.value, '')
        app.stop()
      })
    }
  })

  it('resets an old authority refresh on context change without clearing a newer refresh busy flag', async () => {
    const app = setupEditor()
    const firstRead = app.editor.view(1)
    app.requests[0]!.reject({ statusCode: 403, data: { message: 'Acesso revogado' } })
    await firstRead
    await app.settle()
    assert.equal(app.editor.authorityBlocked.value, true)

    let resolveOldAuth!: (identity: unknown) => void
    const oldAuthRefresh = new Promise<unknown>((resolve) => {
      resolveOldAuth = resolve
    })
    app.setAuthRefresh(() => oldAuthRefresh)
    const oldRefresh = app.editor.refreshAuthority()
    assert.equal(app.editor.refreshingAuthority.value, true)

    app.currentCompany.value = company({ id: 42 })
    assert.equal(app.editor.authorityBlocked.value, false)
    assert.equal(app.editor.refreshingAuthority.value, false)

    const secondRead = app.editor.view(2)
    app.requests[1]!.reject({ statusCode: 403, data: { message: 'Segundo acesso revogado' } })
    await secondRead
    await app.settle()
    assert.equal(app.editor.authorityBlocked.value, true)

    let resolveNewAuth!: (identity: unknown) => void
    const newAuthRefresh = new Promise<unknown>((resolve) => {
      resolveNewAuth = resolve
    })
    app.setAuthRefresh(() => newAuthRefresh)
    const newRefresh = app.editor.refreshAuthority()
    assert.equal(app.editor.refreshingAuthority.value, true)

    resolveOldAuth({ id: 5 })
    assert.equal(await oldRefresh, false)
    assert.equal(app.editor.refreshingAuthority.value, true, 'an old finally must not clear the newer refresh flag')

    resolveNewAuth({ id: 5 })
    assert.equal(await newRefresh, true)
    assert.equal(app.editor.refreshingAuthority.value, false)
    assert.equal(app.editor.authorityBlocked.value, false)
    app.stop()
  })

  it('retains form values for validation, conflict, and service errors', async (t) => {
    const failures = [
      { statusCode: 422, data: { message: 'Revise os campos.', errors: { name: ['Nome obrigatório.'] } } },
      { statusCode: 409, data: { message: 'Conflito de catálogo.' } },
      { statusCode: 503, data: { message: 'Temporariamente indisponível.' } }
    ]
    for (const failure of failures) {
      await t.test(String(failure.statusCode), async () => {
        const app = setupEditor()
        app.editor.create()
        const saving = app.editor.save({ name: 'Manter digitado', sale_price: '15.2500' })
        await app.settle()
        app.requests[0]!.reject(failure)
        await saving
        await app.settle()

        assert.equal(app.editor.open.value, true)
        assert.equal(app.editor.record.value, null)
        assert.equal(app.editor.authorityBlocked.value, false)
        assert.match(app.editor.error.value, /revise os campos|conflito|temporariamente/i)
        if (failure.statusCode === 422)
          assert.deepEqual(app.editor.fieldErrors.value.name, ['Nome obrigatório.'])
        app.stop()
      })
    }
  })

  it('prevents archive and save from running concurrently in either order', async () => {
    const app = setupEditor()
    app.editor.create()
    const savePromise = app.editor.save({ name: 'Novo' })
    await app.settle()
    assert.equal(app.editor.saving.value, true)
    assert.equal(app.requests.length, 1)
    await app.editor.archive(22)
    assert.equal(app.requests.length, 1)
    app.requests[0]!.resolve({ data: { id: 22 } })
    await savePromise
    await app.settle()

    const archivePromise = app.editor.archive(22)
    await app.settle()
    assert.equal(app.editor.archiving.value, true)
    const archiveCount = app.requests.length
    await app.editor.save({ name: 'Não concorrer' })
    assert.equal(app.requests.length, archiveCount)
    app.requests.at(-1)!.resolve({ data: { id: 22 } })
    await archivePromise
    await app.settle()
    app.stop()
  })
})
