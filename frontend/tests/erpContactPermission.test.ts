import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as catalogs from '../shared/utils/erpCatalogs.ts'
import * as access from '../shared/utils/access.ts'
import type { CompanyMembership } from '../shared/types/access.ts'

function contacts(company: Partial<CompanyMembership> = {}, failStatus = 0, csrf?: () => Promise<void>, mutate?: () => Promise<unknown>) {
  const currentCompany = Vue.ref<CompanyMembership>({ id: 1, name: 'Empresa A', status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'], delegated_office_id: null, ...company })
  const currentAccount = Vue.ref<{ id: number } | null>(null)
  const writes: unknown[] = []
  let refreshes = 0
  const { descriptor } = parse(readFileSync(new URL('../app/pages/erp/cadastros/contatos.vue', import.meta.url), 'utf8'))
  const script = compileScript(descriptor, { id: 'contacts' })
  const template = compileTemplate({ source: descriptor.template!.content, filename: 'contatos.vue', id: 'contacts', compilerOptions: { bindingMetadata: script.bindings } })
  assert.deepEqual(template.errors, [])
  const globals = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose,
    definePageMeta() {}, useHead() {}, useAuth: () => ({ currentCompany, currentAccount }),
    useErpCatalogList: () => ({ search: Vue.ref(''), page: Vue.ref(1), pending: Vue.ref(false), state: Vue.ref({ data: [] }), error: Vue.ref(''), refresh: async () => { refreshes++ } }),
    useAccessApi: () => ({ refreshCsrf: csrf ?? (async () => {}), api: async (_path: string, options: unknown) => {
      writes.push(options)
      if (mutate) return await mutate()
      if (failStatus) throw { status: failStatus, data: { message: 'Falha de autorização ou validação', errors: { name: ['Nome inválido'] } } }
      return { data: { id: 1 } }
    } })
  }
  function evaluate(code: string) {
    const output = ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports = {} as { default: { setup: (props: object, context: object) => { form: { name: string, tax_id: string, is_customer: boolean, is_supplier: boolean }, formError: Vue.Ref<string>, creating: Vue.Ref<boolean>, createContact: () => Promise<void> } }, render: (...args: unknown[]) => Vue.VNode }
    new Function('require', 'exports', ...Object.keys(globals), output)((name: string) => {
      if (name === 'vue') return { ...Vue, resolveComponent: (name: string) => ({ name }) }
      if (name === '#shared/utils/erpCatalogs') return catalogs
      if (name === '#shared/utils/access') return access
      throw new Error(`Unexpected import: ${name}`)
    }, exports, ...Object.values(globals))
    return exports
  }
  const scope = Vue.effectScope()
  const state = scope.run(() => evaluate(script.content).default.setup({}, { expose() {} }))
  const render = evaluate(template.code).render
  return { state, writes, currentCompany, currentAccount, refreshes: () => refreshes, render: () => render({ $slots: {} }, [], {}, Vue.proxyRefs(state), {}, {}), close: () => scope.stop() }
}
function nodes(node: Vue.VNode): Vue.VNode[] {
  const children = Array.isArray(node.children)
    ? node.children
    : node.children && typeof node.children === 'object'
      ? Object.values(node.children).filter(value => typeof value === 'function').flatMap(slot => slot())
      : []
  return [node, ...children.filter(Vue.isVNode).flatMap(nodes)]
}
const createForm = (view: ReturnType<typeof contacts>) => nodes(view.render()).find(node => node.type === 'form')
function fill(view: ReturnType<typeof contacts>) {
  Object.assign(view.state.form, { name: 'Contato preenchido', tax_id: '12345678901', is_customer: false, is_supplier: true })
}

describe('Contact mutation permissions and input preservation', () => {
  for (const grants of [['*'], ['erp.contacts.store'], ['erp.contacts.*'], ['erp.contacts.write'], ['erp.write']]) {
    it(`allows a direct owner with actual server grants ${grants}`, async (t) => {
      const view = contacts({ permissions: grants })
      t.after(view.close)
      assert.ok(createForm(view))
      fill(view)
      await createForm(view)!.props!.onSubmit({ preventDefault() {} })
      assert.equal(view.writes.length, 1)
      assert.deepEqual(view.writes[0], { method: 'POST', body: { name: 'Contato preenchido', tax_id: '12345678901', is_customer: false, is_supplier: true } })
      assert.equal(view.state.form.name, '')
      assert.equal(view.refreshes(), 1)
    })
  }
  for (const company of [
    { role: 'viewer', permissions: ['*'] },
    { access_kind: 'delegated' as const, role: 'delegated', permissions: ['*'] },
    { role: 'operator', permissions: ['erp.read'] },
    { role: 'owner', permissions: ['erp.catalog.write'] },
    { role: 'owner', permissions: [] },
    { status: 'suspended' as const }
  ]) {
    it(`hides and guards mutation for ${JSON.stringify(company)}`, async (t) => {
      const view = contacts(company)
      t.after(view.close)
      assert.equal(createForm(view), undefined)
      fill(view)
      await view.state.createContact()
      assert.equal(view.writes.length, 0)
    })
  }
  for (const status of [403, 422]) {
    it(`retains all entered fields and actionable feedback after HTTP ${status}`, async (t) => {
      const view = contacts({}, status)
      t.after(view.close)
      fill(view)
      await view.state.createContact()
      assert.deepEqual({ ...view.state.form }, { name: 'Contato preenchido', tax_id: '12345678901', is_customer: false, is_supplier: true })
      assert.ok(view.state.formError.value)
      assert.equal(view.refreshes(), 0)
      assert.equal(view.state.creating.value, false)
    })
  }
  it('rechecks permission after CSRF and never submits after revocation', async (t) => {
    let release!: () => void
    const view = contacts({}, 0, () => new Promise((resolve) => {
      release = resolve
    }))
    t.after(view.close)
    fill(view)
    const pending = view.state.createContact()
    view.currentCompany.value = { ...view.currentCompany.value, role: 'viewer' }
    release()
    await pending
    assert.equal(view.writes.length, 0)
    assert.equal(createForm(view), undefined)
  })
  for (const rejected of [false, true]) {
    it(`ignores a late POST ${rejected ? 'failure' : 'success'} after Account switch`, async (t) => {
      let settle!: () => void
      const view = contacts({}, 0, undefined, () => new Promise((resolve, reject) => {
        settle = () => rejected ? reject({ status: 403, data: { message: 'Old context' } }) : resolve({ data: { id: 9 } })
      }))
      t.after(view.close)
      fill(view)
      const pending = view.state.createContact()
      await Vue.nextTick()
      assert.equal(view.writes.length, 1)
      view.currentAccount.value = { id: 2 }
      fill(view)
      settle()
      await pending
      assert.equal(view.state.form.name, 'Contato preenchido')
      assert.equal(view.state.formError.value, '')
      assert.equal(view.refreshes(), 0)
    })
  }
  it('clears private draft fields and ignores late mutation success across company context', async (t) => {
    let release!: () => void
    const view = contacts({}, 0, () => new Promise((resolve) => {
      release = resolve
    }))
    t.after(view.close)
    fill(view)
    const pending = view.state.createContact()
    view.currentCompany.value = { ...view.currentCompany.value, id: 2 }
    await Vue.nextTick()
    assert.equal(view.state.form.name, '')
    release()
    await pending
    assert.equal(view.writes.length, 0)
    assert.equal(view.refreshes(), 0)
  })
})
