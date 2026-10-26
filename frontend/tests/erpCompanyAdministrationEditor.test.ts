import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { readFileSync } from 'node:fs'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { erpCompanyHasPermission } from '../app/utils/erpModulesNav.ts'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '../app/composables/useApiError.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

const componentFile = new URL('../app/components/erp/ErpCompanyAdministration.vue', import.meta.url)
const source = readFileSync(componentFile, 'utf8')
const { descriptor } = parse(source, { filename: componentFile.pathname })
const compiledScript = ts.transpileModule(compileScript(descriptor, { id: 'erp-company-administration-editor' }).content, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
}).outputText

type TestCompany = { id: number, status: 'active' | 'suspended', access_kind: 'direct' | 'delegated', role: string, permissions: string[], delegated_office_id: number | null }
type TestIdentity = { id: number, current_account: null, current_company: TestCompany | null, companies?: TestCompany[] }

function ownerIdentity(): TestIdentity {
  return {
    id: 1,
    current_account: null,
    current_company: { id: 10, status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'], delegated_office_id: null }
  }
}

function mountAdministration(kind: 'members' | 'establishments', identityValue: TestIdentity, handler: (path: string, options?: Record<string, unknown>) => Promise<unknown> | unknown, navigationCache = new Map<string, Vue.Ref>()) {
  const identity = Vue.ref(identityValue)
  const session = Vue.ref(0)
  const navigation = loadAppComposable('composables/useListNavigationState.ts', {
    useState: (key: string, initial: () => unknown) => {
      if (key === 'access-user') return identity
      if (key === 'access-session-generation') return session
      if (!navigationCache.has(key)) navigationCache.set(key, Vue.ref(initial()))
      return navigationCache.get(key)
    }
  })
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const api = async (path: string, options?: Record<string, unknown>) => {
    calls.push({ path, options })
    return await handler(path, options)
  }
  const useAuth = () => ({
    user: identity,
    currentCompany: Vue.computed(() => identity.value.current_company),
    currentAccount: Vue.computed(() => identity.value.current_account)
  })
  const useErpAdministrationContext = loadAppComposable('composables/useErpAdministrationContext.ts', {
    computed: Vue.computed,
    ref: Vue.ref,
    watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose,
    useAuth,
    useState: (key: string) => {
      assert.equal(key, 'access-session-generation')
      return session
    }
  }).useErpAdministrationContext as () => Record<string, unknown>
  const globals = {
    ...navigation,
    computed: Vue.computed,
    ref: Vue.ref,
    reactive: Vue.reactive,
    watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose,
    nextTick: Vue.nextTick,
    useId: () => 'company-administration-test',
    useAuth,
    useNuxtApp: () => ({ $api: api }),
    useErpAdministrationContext,
    apiErrorMessage,
    apiFieldErrors,
    apiStatus
  }
  const exports = {} as { default: { setup: (props: Record<string, unknown>, context: { expose: (value: Record<string, unknown>) => void }) => Record<string, unknown> } }
  new Function('require', 'exports', ...Object.keys(globals), compiledScript)(
    (name: string) => {
      if (name === 'vue') return { ...Vue, useId: () => 'company-administration-test' }
      if (name === '~/utils/erpModulesNav') return { erpCompanyHasPermission }
      throw new Error(`Unexpected component import: ${name}`)
    },
    exports,
    ...Object.values(globals)
  )
  const scope = Vue.effectScope()
  let exposed: Record<string, unknown> = {}
  let bindings: Record<string, unknown> = {}
  scope.run(() => {
    bindings = exports.default.setup({ kind }, {
      expose: (value) => {
        exposed = value
      }
    })
  })
  return { identity, session, calls, exposed, bindings, stop: () => scope.stop() }
}

async function flushVue() {
  await Vue.nextTick()
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

describe('ERP company administration editor', () => {
  it('clears protected rows and editor and aborts a pending read when matching effective grants change alone', async () => {
    const identity = ownerIdentity()
    identity.companies = [{ ...identity.current_company!, permissions: ['*'] }]
    const response = { data: [{ id: 11, name: 'Matriz protegida', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1, all_establishments: true } }
    let pending = false
    const reads: Array<{ signal: AbortSignal, finish: (value: unknown) => void }> = []
    const mounted = mountAdministration('establishments', identity, (_path, options) => {
      if (!pending) return response
      return new Promise((finish) => {
        reads.push({ signal: options?.signal as AbortSignal, finish })
      })
    })
    try {
      await flushVue()
      assert.equal((mounted.exposed.rows as Vue.Ref<unknown[]>).value.length, 1)
      mounted.exposed.beginEdit(response.data[0])
      assert.equal((mounted.exposed.formOpen as Vue.Ref<boolean>).value, true)
      ;(mounted.exposed.form as Record<string, unknown>).name = 'Rascunho privado'
      pending = true
      const read = mounted.exposed.load()
      assert.equal(reads.length, 1)
      const selectedCompany = mounted.identity.value.current_company
      mounted.identity.value.companies![0]!.permissions = []
      assert.equal(mounted.identity.value.current_company, selectedCompany, 'selected Company identity remains unchanged')
      assert.deepEqual(selectedCompany!.permissions, ['*'], 'only the matching effective membership loses grants')
      assert.deepEqual((mounted.exposed.rows as Vue.Ref<unknown[]>).value, [])
      assert.equal((mounted.exposed.formOpen as Vue.Ref<boolean>).value, false)
      assert.equal((mounted.exposed.form as Record<string, unknown>).name, '')
      assert.equal(reads[0]!.signal.aborted, true)
      reads[0]!.finish(response)
      await read
      assert.deepEqual((mounted.exposed.rows as Vue.Ref<unknown[]>).value, [], 'obsolete safe read cannot restore protected rows')
      assert.equal((mounted.exposed.formOpen as Vue.Ref<boolean>).value, false)
    } finally {
      mounted.stop()
      reads.forEach(attempt => attempt.finish(response))
    }
  })

  for (const kind of ['establishments', 'members'] as const) {
    it(`retains ${kind} navigation criteria across reentry without storing editor state`, async () => {
      const cache = new Map<string, Vue.Ref>()
      const handler = (path: string) => ({ data: [], meta: { current_page: Number(new URL(path, 'http://local').searchParams.get('page') ?? 1), last_page: 5, all_establishments: true } })
      const first = mountAdministration(kind, ownerIdentity(), handler, cache)
      await flushVue()
      ;(first.bindings.searchText as Vue.Ref<string>).value = '  Filial  '
      first.exposed.applySearch()
      await flushVue()
      ;(first.exposed.currentPage as Vue.Ref<number>).value = 3
      await flushVue()
      first.exposed.beginCreate()
      first.stop()
      const second = mountAdministration(kind, ownerIdentity(), (path) => {
        const page = Number(new URL(path, 'http://local').searchParams.get('page') ?? 1)
        return { data: [], meta: { current_page: page, last_page: 5, all_establishments: true } }
      }, cache)
      try {
        await flushVue()
        const main = second.calls.filter(call => call.path.startsWith(`/erp/${kind}?`))
        assert.equal(main.length, 1)
        assert.match(main[0]!.path, /page=3/)
        assert.match(main[0]!.path, /search=Filial/)
        assert.equal((second.bindings.searchText as Vue.Ref<string>).value, 'Filial')
        assert.equal((second.exposed.formOpen as Vue.Ref<boolean>).value, false)
        assert.equal((second.exposed.establishmentPage as Vue.Ref<number>).value, 1)
        assert.doesNotMatch(JSON.stringify(cache.get('list-navigation-state')?.value), /establishmentIds|permissionsMode|cnpj|formOpen/)
      } finally { second.stop() }
    })
  }

  it('aborts obsolete navigation reads on replacement and disposal', async () => {
    const signals: AbortSignal[] = []
    const mounted = mountAdministration('establishments', ownerIdentity(), (_path, options) => {
      signals.push(options?.signal as AbortSignal)
      return new Promise(() => {})
    })
    await flushVue()
    void mounted.exposed.load()
    assert.equal(signals[0]?.aborted, true)
    assert.equal(signals[1]?.aborted, false)
    mounted.stop()
    assert.equal(signals[1]?.aborted, true)
  })

  it('submits one normalized page-one navigation read after changing a search on a later page', async () => {
    const mounted = mountAdministration('establishments', ownerIdentity(), path => ({ data: [], meta: { current_page: Number(new URL(path, 'http://local').searchParams.get('page')), last_page: 5, all_establishments: true } }))
    try {
      await flushVue()
      ;(mounted.exposed.currentPage as Vue.Ref<number>).value = 3
      await flushVue()
      const before = mounted.calls.length
      ;(mounted.bindings.searchText as Vue.Ref<string>).value = '  Atualizada  '
      mounted.exposed.applySearch()
      await flushVue()
      assert.equal(mounted.calls.length, before + 1)
      assert.match(mounted.calls.at(-1)!.path, /page=1.*search=Atualizada/)
    } finally { mounted.stop() }
  })

  it('bounds remembered navigation pages using confirmed server totals', async () => {
    const cache = new Map<string, Vue.Ref>()
    const first = mountAdministration('establishments', ownerIdentity(), path => ({ data: [], meta: { current_page: Number(new URL(path, 'http://local').searchParams.get('page')), last_page: 5, all_establishments: true } }), cache)
    await flushVue()
    ;(first.exposed.currentPage as Vue.Ref<number>).value = 5
    await flushVue()
    first.stop()
    const second = mountAdministration('establishments', ownerIdentity(), path => ({ data: [], meta: { current_page: Number(new URL(path, 'http://local').searchParams.get('page')), last_page: 2, all_establishments: true } }), cache)
    try {
      await flushVue()
      assert.deepEqual(second.calls.map(call => new URL(call.path, 'http://local').searchParams.get('page')), ['5', '2'])
      assert.equal((second.exposed.currentPage as Vue.Ref<number>).value, 2)
    } finally { second.stop() }
  })

  for (const boundary of ['company', 'session', 'grants']) {
    it(`clears private navigation criteria synchronously on ${boundary} changes`, async () => {
      const mounted = mountAdministration('establishments', ownerIdentity(), path => ({ data: [], meta: { current_page: Number(new URL(path, 'http://local').searchParams.get('page')), last_page: 5, all_establishments: true } }))
      try {
        await flushVue()
        ;(mounted.bindings.searchText as Vue.Ref<string>).value = 'Private search'
        mounted.exposed.applySearch()
        await flushVue()
        if (boundary === 'company') mounted.identity.value.current_company!.id++
        else if (boundary === 'session') mounted.session.value++
        else mounted.identity.value.current_company!.permissions = ['erp.establishments.index']
        assert.equal((mounted.bindings.searchText as Vue.Ref<string>).value, '')
        assert.equal((mounted.exposed.currentPage as Vue.Ref<number>).value, 1)
        const before = mounted.calls.length
        await flushVue()
        assert.equal(mounted.calls.length, before + 1, 'an authority change has one final criteria read owner')
      } finally { mounted.stop() }
    })
  }

  it('clears navigation and private search input after a definitive same-context refusal', async () => {
    let denied = false
    const cache = new Map<string, Vue.Ref>()
    const mounted = mountAdministration('establishments', ownerIdentity(), () => {
      if (denied) throw { statusCode: 403 }
      return { data: [], meta: { current_page: 1, last_page: 1, all_establishments: true } }
    }, cache)
    try {
      await flushVue()
      ;(mounted.bindings.searchText as Vue.Ref<string>).value = 'Private filter'
      mounted.exposed.applySearch()
      await flushVue()
      denied = true
      await mounted.exposed.load()
      assert.equal((mounted.bindings.searchText as Vue.Ref<string>).value, '')
      assert.equal((mounted.exposed.canView as Vue.Ref<boolean>).value, false)
      assert.doesNotMatch(JSON.stringify(cache.get('list-navigation-state')?.value), /Private filter/)
    } finally { mounted.stop() }
  })
  it('uses the native form and field components for administration controls', () => {
    const template = descriptor.template?.content ?? ''

    assert.match(template, /<UModal\b[^>]*:open="formOpen"/)
    assert.match(template, /<UForm\b/)
    assert.match(template, /<UFormField\b/)
    assert.match(template, /<UInput\b/)
    assert.match(template, /<USelect\b/)
    assert.match(template, /<UCheckbox\b/)
    assert.match(template, /<URadioGroup\b/)
    assert.match(template, /<UAccordion\b/)
    assert.doesNotMatch(template, /<input\b|<select\b/)
  })

  it('keeps detailed grants behind an explicit disclosure and preserves default/custom payload intent', () => {
    const template = descriptor.template?.content ?? ''

    assert.match(template, /<UAccordion\b|<UCollapsible\b/)
    assert.match(template, /Permissões de ação/)
    assert.match(source, /form\.permissionsMode === 'defaults' \? null : \[\.\.\.form\.actionPermissions\]/)
    assert.match(source, /establishment_scope: form\.establishmentScope/)
    assert.match(source, /establishment_ids: form\.establishmentScope === 'all' \? \[\] : \[\.\.\.form\.establishmentIds\]/)
  })

  it('keeps owner protection, direct-owner authorization, identity lookup, and stale mutation fences', () => {
    assert.match(source, /value\.role === 'owner'/)
    assert.match(source, /isCompanyOwner\.value/)
    assert.match(source, /'\/erp\/members\/identity-lookup'/)
    assert.match(source, /if \(!isCurrent\(token\)\) return/)
    assert.match(source, /form\.establishmentScope === 'selected'/)
  })

  it('does not load or expose member administration to a non-owner with broad grants', async () => {
    const identity = ownerIdentity()
    identity.current_company = { ...identity.current_company!, role: 'manager', permissions: ['*'] }
    const mount = mountAdministration('members', identity, () => {
      throw new Error('Members API must not be called for a non-owner.')
    })
    try {
      await flushVue()
      assert.equal((mount.exposed.canView as Vue.Ref<boolean>).value, false)
      assert.equal(mount.calls.length, 0)
      mount.exposed.beginCreate()
      assert.equal((mount.exposed.formOpen as Vue.Ref<boolean>).value, false)
    } finally {
      mount.stop()
    }
  })

  it('provides a stable form target for validation and restores focus after the editor closes', () => {
    const template = descriptor.template?.content ?? ''

    assert.match(template, /:id="[^"]*FormId|\bid="[^"]*FormId/)
    assert.match(source, /nextTick/)
    assert.match(source, /\.focus\(\)/)
  })

  it('keeps lookup, selected establishment scope, stale blocking, protected owner, and focus return in the component setup', async () => {
    const member = { id: 2, user: { id: 8, name: 'Pessoa', email: 'pessoa@example.test' }, role: 'manager', action_permissions: [], effective_permissions: ['erp.read'], establishment_scope: 'selected', establishment_ids: [11, 17] }
    const requests: Array<{ path: string, options?: Record<string, unknown> }> = []
    const mount = mountAdministration('members', ownerIdentity(), async (path, options) => {
      requests.push({ path, options })
      if (path.startsWith('/erp/members?')) return { data: [member], meta: { current_page: 1, last_page: 1 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 11, name: 'Matriz', cnpj: null, status: 'active' }, { id: 17, name: 'Filial', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1 } }
      if (path === '/erp/member-permissions') return { data: [{ permission: 'erp.read', label: 'Consultar', write: false }] }
      if (path === '/erp/members/identity-lookup') return { data: { id: 22, name: 'Pessoa nova', email: 'nova@example.test' } }
      if (path === '/erp/members' && options?.method === 'POST') return { data: { id: 23 } }
      if (path === '/erp/members/2' && options?.method === 'PATCH') throw { statusCode: 409, data: { code: 'company_context_changed', message: 'Contexto alterado.' } }
      throw new Error(`Unexpected administration request: ${path}`)
    })
    try {
      await flushVue()
      assert.equal((mount.exposed.canView as Vue.Ref<boolean>).value, true)
      mount.exposed.beginEdit(member)
      await Vue.nextTick()
      assert.equal((mount.exposed.formOpen as Vue.Ref<boolean>).value, true)

      const focusTarget = {
        focusCalls: 0,
        focus() {
          this.focusCalls++
        }
      }
      mount.exposed.beginCreate({ currentTarget: focusTarget } as unknown as MouseEvent)
      const lookupEmail = mount.bindings.lookupEmail as Vue.Ref<string>
      lookupEmail.value = 'nova@example.test'
      await (mount.exposed.lookupIdentity as () => Promise<void>)()
      assert.deepEqual(requests.find(request => request.path === '/erp/members/identity-lookup')?.options?.body, { email: 'nova@example.test' })

      const form = mount.exposed.form as { role: string, permissionsMode: string, actionPermissions: string[], establishmentScope: string, establishmentIds: number[] }
      form.role = 'operator'
      form.permissionsMode = 'custom'
      form.actionPermissions = ['erp.read']
      form.establishmentScope = 'selected'
      form.establishmentIds = [11, 17]
      await (mount.exposed.submitForm as () => Promise<void>)()
      const payload = requests.find(request => request.path === '/erp/members' && request.options?.method === 'POST')?.options?.body
      assert.deepEqual(payload, { user_id: 22, role: 'operator', action_permissions: ['erp.read'], establishment_scope: 'selected', establishment_ids: [11, 17] })
      assert.equal(focusTarget.focusCalls, 1, 'successful save returns focus to the triggering control')

      mount.exposed.beginCreate({ currentTarget: focusTarget } as unknown as MouseEvent)
      ;(mount.bindings.cancelForm as () => void)()
      await Vue.nextTick()
      assert.equal(focusTarget.focusCalls, 2, 'cancel returns focus to the triggering control')

      mount.exposed.beginEdit({ ...member, role: 'owner' })
      assert.equal((mount.exposed.formOpen as Vue.Ref<boolean>).value, false, 'the protected owner cannot be edited')
      mount.exposed.beginEdit(member)
      await (mount.exposed.submitForm as () => Promise<void>)()
      assert.equal((mount.exposed.formOpen as Vue.Ref<boolean>).value, false, 'a changed Company context closes the editor')
      assert.equal((mount.exposed.rows as Vue.Ref<unknown[]>).value.length, 0, 'protected rows are cleared after context loss')
    } finally {
      mount.stop()
    }
  })
})
