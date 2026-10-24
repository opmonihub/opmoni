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
type TestIdentity = { id: number, current_account: null, current_company: TestCompany | null }

function ownerIdentity(): TestIdentity {
  return {
    id: 1,
    current_account: null,
    current_company: { id: 10, status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'], delegated_office_id: null }
  }
}

function mountAdministration(kind: 'members' | 'establishments', identityValue: TestIdentity, handler: (path: string, options?: Record<string, unknown>) => Promise<unknown> | unknown) {
  const identity = Vue.ref(identityValue)
  const session = Vue.ref(0)
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
    computed: Vue.computed,
    ref: Vue.ref,
    reactive: Vue.reactive,
    watch: Vue.watch,
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
  return { identity, calls, exposed, bindings, stop: () => scope.stop() }
}

async function flushVue() {
  await Vue.nextTick()
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

describe('ERP company administration editor', () => {
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
