import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { describe, it } from 'node:test'
import { compileScript, parse } from 'vue/compiler-sfc'
import { computed, proxyRefs, ref } from 'vue'
import ts from 'typescript'
import { vueSource } from './helpers/compileVueComponent.ts'

const require = createRequire(import.meta.url)
const { descriptor } = parse(vueSource('components/WorkspaceContextMenu.vue'))
const script = compileScript(descriptor, { id: 'workspace-context-menu' })
const compiled = ts.transpileModule(script.content, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
}).outputText

function menu(options: {
  context?: 'account' | 'company'
  collapsed?: boolean
  switchContext?: (kind: string, id: number) => Promise<void>
} = {}) {
  const user = ref({
    current_account: { id: 1, name: 'Escritório atual' } as { id: number, name: string } | null,
    current_company: { id: 3, name: 'Empresa atual' } as { id: number, name: string } | null,
    accounts: [
      { id: 1, name: 'Escritório atual', status: 'active' },
      { id: 2, name: 'Outro escritório', status: 'active' },
      { id: 9, name: 'Escritório suspenso', status: 'suspended' }
    ],
    companies: [
      { id: 3, name: 'Empresa atual', status: 'active', access_kind: 'direct' },
      { id: 4, name: 'Empresa delegada', status: 'active', access_kind: 'delegated' },
      { id: 8, name: 'Empresa suspensa', status: 'suspended', access_kind: 'direct' }
    ]
  })
  const calls: [string, number][] = []
  const routes: string[] = []
  const notices: { title: string, color: string }[] = []
  async function change(kind: string, id: number) {
    calls.push([kind, id])
    await options.switchContext?.(kind, id)
  }
  const bindings = {
    ref,
    computed,
    navigateTo: async (path: string) => { routes.push(path) },
    useAuth: () => ({ user, switchAccount: (id: number) => change('account', id), switchCompany: (id: number) => change('company', id) }),
    useToast: () => ({ add: (notice: { title: string, color: string }) => notices.push(notice) })
  }
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', ...Object.keys(bindings), compiled)(
    (path: string) => path === '#shared/utils/access' ? { apiFailure: () => ({ message: 'Não foi possível trocar o contexto.' }) } : require(path),
    exports,
    ...Object.values(bindings)
  )
  const component = exports.default as { setup: (props: object, context: object) => Record<string, unknown> }
  const state = proxyRefs(component.setup({ context: options.context ?? 'account', collapsed: options.collapsed ?? false }, { expose() {} })) as {
    selected: { label: string, icon: string }
    busy: boolean
    items: { label: string, type?: string, active?: boolean, disabled?: boolean, onSelect?: () => Promise<void> }[][]
  }
  return { state, user, calls, routes, notices, item: (name: string) => state.items.flat().find(item => item.label.startsWith(name))! }
}

describe('Workspace context menu', () => {
  it('keeps offices and companies in separate sections and identifies the current workspace', () => {
    const office = menu()
    assert.equal(office.state.selected.label, 'Escritório atual')
    assert.deepEqual(office.state.items.map(group => group[0]?.label), ['Escritórios', 'Empresas'])
    assert.deepEqual(office.state.items.flat().filter(item => item.active).map(item => item.label), ['Escritório atual'])
    assert.ok(office.state.items.flat().every(item => item.type !== 'checkbox'))
    const company = menu({ context: 'company', collapsed: true })
    assert.equal(company.state.selected.label, 'Empresa atual')
    assert.equal(company.state.selected.icon, 'i-lucide-factory')
    assert.deepEqual(company.state.items.flat().filter(item => item.active).map(item => item.label), ['Empresa atual'])
    assert.ok(company.item('Outro escritório').onSelect)
    assert.ok(company.item('Empresa delegada').onSelect)
  })

  it('keeps the active workspace unique when office and company identifiers overlap', () => {
    const view = menu({ context: 'company' })
    view.user.value.current_company!.id = 1
    view.user.value.companies[0]!.id = 1
    assert.deepEqual(view.state.items.flat().filter(item => item.active).map(item => item.label), ['Empresa atual'])
  })

  it('uses the company context for company-only users and leaves missing contexts unselected', () => {
    const view = menu()
    view.user.value.current_account = null
    view.user.value.accounts = []
    assert.equal(view.state.selected.label, 'Empresa atual')
    assert.deepEqual(view.state.items.flat().filter(item => item.active).map(item => item.label), ['Empresa atual'])
    view.user.value.current_company = null
    assert.equal(view.state.selected.label, 'Selecionar contexto')
    assert.equal(view.state.items.flat().filter(item => item.active).length, 0)
  })

  it('uses existing context switches without repeating writes for current or suspended identities', async () => {
    const view = menu()
    await view.item('Escritório atual').onSelect?.()
    await view.item('Empresa atual').onSelect?.()
    assert.equal(view.item('Escritório suspenso').disabled, true)
    assert.equal(view.item('Empresa suspensa').disabled, true)
    await view.item('Escritório suspenso').onSelect?.()
    await view.item('Empresa suspensa').onSelect?.()
    assert.deepEqual(view.calls, [])
    await view.item('Outro escritório').onSelect?.()
    await view.item('Empresa delegada').onSelect?.()
    assert.deepEqual(view.calls, [['account', 2], ['company', 4]])
    assert.match(view.item('Empresa delegada').label, /Delegada/)
  })

  it('opens the already selected office or company workspace without changing session context again', async () => {
    const erp = menu({ context: 'company' })
    await erp.item('Escritório atual').onSelect?.()
    assert.deepEqual(erp.routes, ['/'])
    assert.deepEqual(erp.calls, [])
    const office = menu()
    await office.item('Empresa atual').onSelect?.()
    assert.deepEqual(office.routes, ['/erp'])
    assert.deepEqual(office.calls, [])
  })

  it('prevents concurrent switches and restores the menu with an error notification after failure', async () => {
    let fail!: () => void
    const pending = new Promise<void>((_resolve, reject) => {
      fail = () => reject(new Error('offline'))
    })
    const view = menu({ switchContext: () => pending })
    const first = view.item('Outro escritório').onSelect!()
    assert.equal(view.state.busy, true)
    assert.ok(view.state.items.flat().filter(item => item.type !== 'label').every(item => item.disabled))
    await view.item('Empresa delegada').onSelect?.()
    assert.deepEqual(view.calls, [['account', 2]])
    fail()
    await first
    assert.equal(view.state.busy, false)
    assert.equal(view.item('Empresa delegada').disabled, false)
    assert.deepEqual(view.notices, [{ title: 'Não foi possível trocar o contexto.', color: 'error' }])
  })

  it('omits empty context sections without inventing an available office', () => {
    const view = menu({ context: 'company' })
    view.user.value.accounts = []
    assert.deepEqual(view.state.items.map(group => group[0]?.label), ['Empresas'])
  })
})
