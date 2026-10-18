import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { readFileSync } from 'node:fs'
import { erpCompanyHasPermission } from '../app/utils/erpModulesNav.ts'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '../app/composables/useApiError.ts'

const routeFile = new URL('../app/pages/erp/configuracoes.vue', import.meta.url)
const companyAdministrationFile = new URL('../app/components/erp/ErpCompanyAdministration.vue', import.meta.url)
const mappingApprovalFile = new URL('../app/components/erp/ErpAccountingMappingApproval.vue', import.meta.url)
const administrationContextFile = new URL('../app/composables/useErpAdministrationContext.ts', import.meta.url)

type TestIdentity = {
  id: number
  current_account: { id: number, name: string, status: 'active' | 'suspended', role: 'admin' | 'operador' | 'user' } | null
  current_company: {
    id: number
    name: string
    status: 'active' | 'suspended'
    access_kind: 'direct' | 'delegated'
    role: string
    permissions: string[]
    delegated_office_id: number | null
  } | null
  companies: Array<{
    id: number
    name: string
    status: 'active' | 'suspended'
    access_kind: 'direct' | 'delegated'
    role: string
    permissions: string[]
    delegated_office_id: number | null
  }>
}

function defaultOwnerIdentity(): TestIdentity {
  const company = { id: 10, name: 'Empresa teste', status: 'active' as const, access_kind: 'direct' as const, role: 'owner', permissions: ['*'], delegated_office_id: null }
  return { id: 1, current_account: null, current_company: company, companies: [company] }
}

type AdministrationContext = {
  key: Vue.ComputedRef<string>
  capture: () => { key: string, generation: number }
  isCurrent: (token: { key: string, generation: number }) => boolean
  isCompanyOwner: Vue.ComputedRef<boolean>
  isAccountAdmin: Vue.ComputedRef<boolean>
}

function loadRealAdministrationContext(identity: Vue.Ref<TestIdentity>, session: Vue.Ref<number>) {
  const source = readFileSync(administrationContextFile, 'utf8')
  const output = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  }).outputText
  const globals = {
    computed: Vue.computed,
    ref: Vue.ref,
    watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose,
    useAuth: () => ({
      user: identity,
      currentCompany: Vue.computed(() => identity.value.current_company),
      currentAccount: Vue.computed(() => identity.value.current_account)
    }),
    useState: (key: string) => {
      assert.equal(key, 'access-session-generation')
      return session
    }
  }
  const exports = {} as { useErpAdministrationContext: (extra?: () => string | number | null) => AdministrationContext }
  new Function('require', 'exports', ...Object.keys(globals), output)(
    (name: string) => { throw new Error(`Unexpected context composable import: ${name}`) },
    exports,
    ...Object.values(globals)
  )
  return exports.useErpAdministrationContext
}

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  textContent: string
  value: string
  options: HostNode[]
  selectedIndex: number
  multiple: boolean
  selected: boolean
  _value?: unknown
  checked: boolean
  listeners: Record<string, Array<(event: { target: HostNode, preventDefault?: () => void }) => void>>
  addEventListener: (name: string, fn: (event: { target: HostNode, preventDefault?: () => void }) => void) => void
  removeEventListener: (name: string, fn: (event: { target: HostNode }) => void) => void
}

function createHostRenderer() {
  type Node = HostNode
  const createNode = (tag: string): Node => ({
    tag,
    props: {},
    children: [],
    parent: null,
    textContent: '',
    value: '',
    options: [],
    selectedIndex: -1,
    multiple: false,
    selected: false,
    checked: false,
    listeners: {},
    addEventListener(name, fn) { (this.listeners[name] ??= []).push(fn) },
    removeEventListener(name, fn) { this.listeners[name] = (this.listeners[name] ?? []).filter(listener => listener !== fn) }
  })
  const renderer = Vue.createRenderer({
    patchProp(node, key, _old, value) {
      node.props[key] = value
      if (key === 'value') {
        node._value = value
        node.value = String(value ?? '')
      }
      if (key === 'checked' && typeof value === 'boolean') node.checked = value
      if (key === 'selected' && typeof value === 'boolean') node.selected = value
      if (key === 'multiple' && typeof value === 'boolean') node.multiple = value
    },
    insert(node, parent, anchor = null) {
      if (node.parent) {
        const oldIndex = node.parent.children.indexOf(node)
        if (oldIndex >= 0) node.parent.children.splice(oldIndex, 1)
        if (node.parent.tag === 'select' && node.tag === 'option') node.parent.options = node.parent.options.filter(option => option !== node)
      }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
      if (parent.tag === 'select' && node.tag === 'option' && !parent.options.includes(node)) parent.options.push(node)
    },
    remove(node) {
      if (!node.parent) return
      const index = node.parent.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
      if (node.parent.tag === 'select' && node.tag === 'option') node.parent.options = node.parent.options.filter(option => option !== node)
      node.parent = null
    },
    createElement: createNode,
    createText(text) {
      const node = createNode('#text')
      node.textContent = text
      return node
    },
    createComment(text) {
      const node = createNode('#comment')
      node.textContent = text
      return node
    },
    setText(node, text) { node.textContent = text },
    setElementText(node, text) {
      node.textContent = text
      node.children = []
    },
    parentNode(node) { return node.parent },
    nextSibling(node) {
      if (!node.parent) return null
      return node.parent.children[node.parent.children.indexOf(node) + 1] ?? null
    }
  })
  return { renderer, createNode }
}

function hostText(node: HostNode): string {
  return node.textContent + node.children.map(child => hostText(child)).join('')
}

function findHostNodes(root: HostNode, predicate: (node: HostNode) => boolean): HostNode[] {
  const found: HostNode[] = []
  function visit(node: HostNode) {
    if (predicate(node)) found.push(node)
    for (const child of node.children) visit(child)
  }
  for (const child of root.children) visit(child)
  return found
}

async function flushVue() {
  await Vue.nextTick()
  await new Promise(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

function compileAndMount(file: URL, props: Record<string, unknown>, identityValue: TestIdentity, apiHandler: (path: string, options?: Record<string, unknown>) => Promise<unknown> | unknown) {
  const source = readFileSync(file, 'utf8')
  const { descriptor } = parse(source, { filename: file.pathname })
  const script = compileScript(descriptor, { id: `test-${file.pathname.split('/').pop()}` })
  const compiledScript = ts.transpileModule(script.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  })
  const identity = Vue.ref(identityValue)
  const session = Vue.ref(0)
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const api = async (path: string, options?: Record<string, unknown>) => {
    calls.push({ path, options })
    return await apiHandler(path, options)
  }
  const globals: Record<string, unknown> = {
    computed: Vue.computed,
    ref: Vue.ref,
    reactive: Vue.reactive,
    watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose,
    useAuth: () => ({
      user: identity,
      currentCompany: Vue.computed(() => identity.value.current_company),
      currentAccount: Vue.computed(() => identity.value.current_account)
    }),
    useNuxtApp: () => ({ $api: api }),
    useErpAdministrationContext: loadRealAdministrationContext(identity, session),
    apiErrorMessage,
    apiFieldErrors,
    apiStatus
  }
  const exports = {} as { default: Record<string, unknown> }
  new Function('require', 'exports', ...Object.keys(globals), compiledScript.outputText)(
    (name: string) => {
      if (name === 'vue') return Vue
      if (name === '~/utils/erpModulesNav') return { erpCompanyHasPermission }
      throw new Error(`Unexpected SFC import: ${name}`)
    },
    exports,
    ...Object.values(globals)
  )
  const template = compileTemplate({
    source: descriptor.template?.content ?? '',
    filename: file.pathname,
    id: `template-${file.pathname.split('/').pop()}`,
    compilerOptions: { mode: 'function', hoistStatic: false, bindingMetadata: script.bindings, expressionPlugins: ['typescript'] }
  })
  const compiledTemplate = ts.transpileModule(template.code, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  })
  const testVModelText = {
    created(element: HostNode, _binding: unknown, vnode: { props?: Record<string, unknown> }) {
      const assign = vnode.props?.['onUpdate:modelValue']
      element.addEventListener('input', (event) => {
        if (typeof assign === 'function') (assign as (value: string) => void)((event.target as HostNode).value)
      })
    },
    mounted(element: HostNode, binding: { value: unknown }) { element.value = String(binding.value ?? '') },
    beforeUpdate(element: HostNode, binding: { value: unknown }) { element.value = String(binding.value ?? '') }
  }
  const testTemplateRuntime = {
    ...Vue,
    vModelText: testVModelText,
    vModelSelect: {},
    vModelCheckbox: {},
    vModelRadio: {}
  }
  const render = new Function('Vue', compiledTemplate.outputText)(testTemplateRuntime) as (ctx: unknown, cache: unknown) => unknown
  const component = exports.default as { render?: typeof render }
  component.render = render

  const { renderer, createNode } = createHostRenderer()
  const button = Vue.defineComponent({
    props: ['type', 'disabled', 'loading', 'color', 'variant', 'size'],
    emits: ['click'],
    setup(buttonProps, { attrs, slots, emit }) {
      return () => Vue.h('button', {
        ...attrs,
        type: buttonProps.type ?? 'button',
        disabled: buttonProps.disabled ?? false,
        onClick: (event: unknown) => emit('click', event)
      }, slots.default?.())
    }
  })
  const surface = Vue.defineComponent({
    setup(_surfaceProps, { attrs, slots }) {
      return () => Vue.h('div', attrs, slots.default?.())
    }
  })
  const app = renderer.createApp(component as never, props)
  for (const name of ['UButton']) app.component(name, button)
  for (const name of ['UAlert', 'UCard', 'UBadge']) app.component(name, surface)
  const root = createNode('root')
  const instance = app.mount(root) as Record<string, unknown>
  return { app, root, instance, calls, identity, session, unmount: () => app.unmount() }
}

function clickButton(root: HostNode, label: string) {
  const button = findHostNodes(root, node => node.tag === 'button' && hostText(node).includes(label))[0]
  assert.ok(button, `button “${label}” should be rendered`)
  const event = { target: button, preventDefault() {} }
  const click = button.props.onClick
  assert.equal(typeof click, 'function')
  ;(click as (event: typeof event) => void)(event)
  if (button.props.type === 'submit') {
    let parent = button.parent as { tag: string, props: Record<string, unknown>, parent: unknown } | null
    while (parent && parent.tag !== 'form') parent = parent.parent as typeof parent
    const submit = parent?.props.onSubmit
    if (typeof submit === 'function') (submit as (event: typeof event) => void)(event)
  }
}

function changeInput(root: HostNode, selector: (node: HostNode) => boolean, value: string) {
  const input = findHostNodes(root, node => node.tag === 'input' && selector(node))[0]
  assert.ok(input, 'the requested form input should be rendered')
  input.value = value
  for (const listener of input.listeners.input ?? []) listener({ target: input as never })
}

function settingsLinks(company: {
  id: number
  status: 'active' | 'suspended'
  access_kind: 'direct' | 'delegated'
  role: string
  permissions: string[]
} | null) {
  const { descriptor } = parse(readFileSync(routeFile, 'utf8'), { filename: routeFile.pathname })
  const script = compileScript(descriptor, { id: 'erp-settings-admin-links' })
  const compiled = ts.transpileModule(script.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  })
  const exports = {} as { default: { setup: (props: object, context: object) => { links: Vue.ComputedRef<Array<Array<{ to: string, label: string }>>> } } }
  const globals = {
    computed: Vue.computed,
    definePageMeta() {},
    useRoute: () => ({ path: '/erp/configuracoes' }),
    useAuth: () => ({ currentCompany: Vue.ref(company) })
  }
  new Function('require', 'exports', ...Object.keys(globals), compiled.outputText)(
    (name: string) => name === 'vue' ? Vue : name === '~/utils/erpModulesNav' ? { erpCompanyHasPermission } : (() => { throw new Error(`Unexpected import: ${name}`) })(),
    exports,
    ...Object.values(globals)
  )
  const scope = Vue.effectScope()
  const state = scope.run(() => exports.default.setup({}, { expose() {} }))!
  return { links: state.links.value[0]!, close: () => scope.stop() }
}

describe('ERP company administration navigation', () => {
  it('shows owner controls only for a direct owner company context', (t) => {
    const owner = settingsLinks({ id: 10, status: 'active', access_kind: 'direct', role: 'owner', permissions: ['erp.read'] })
    t.after(owner.close)
    assert.deepEqual(
      owner.links.filter(link => ['/erp/configuracoes/estabelecimentos', '/erp/configuracoes/membros', '/erp/configuracoes/escritorio'].includes(link.to)).map(link => link.to),
      ['/erp/configuracoes/estabelecimentos', '/erp/configuracoes/membros', '/erp/configuracoes/escritorio']
    )

    const delegated = settingsLinks({ id: 10, status: 'active', access_kind: 'delegated', role: 'delegated', permissions: ['*'] })
    t.after(delegated.close)
    assert.ok(delegated.links.some(link => link.to === '/erp/configuracoes/estabelecimentos'))
    assert.ok(delegated.links.every(link => !['/erp/configuracoes/membros', '/erp/configuracoes/escritorio'].includes(link.to)))

    const readOnly = settingsLinks({ id: 10, status: 'active', access_kind: 'direct', role: 'operator', permissions: ['erp.read'] })
    t.after(readOnly.close)
    assert.ok(readOnly.links.some(link => link.to === '/erp/configuracoes/estabelecimentos'))
  })

  it('does not infer owner-only controls from a wildcard grant or a delegated office admin', (t) => {
    for (const company of [
      { id: 10, status: 'active' as const, access_kind: 'direct' as const, role: 'manager', permissions: ['*'] },
      { id: 10, status: 'active' as const, access_kind: 'delegated' as const, role: 'delegated', permissions: ['*'] },
      { id: 10, status: 'suspended' as const, access_kind: 'direct' as const, role: 'owner', permissions: ['*'] },
      null
    ]) {
      const view = settingsLinks(company)
      t.after(view.close)
      assert.ok(view.links.every(link => !['/erp/configuracoes/membros', '/erp/configuracoes/escritorio'].includes(link.to)))
    }
  })
})

describe('ERP administration context request fence', () => {
  it('invalidates the real composable token on session, actor, Account, Company authority, grants, and disposal', () => {
    const identity = Vue.ref(defaultOwnerIdentity())
    const session = Vue.ref(4)
    const scope = Vue.effectScope()
    const useContext = loadRealAdministrationContext(identity, session)
    const context = scope.run(() => useContext(() => 88))!

    let token = context.capture()
    assert.equal(context.isCurrent(token), true)
    session.value++
    assert.equal(context.isCurrent(token), false)

    token = context.capture()
    identity.value.id++
    assert.equal(context.isCurrent(token), false)

    token = context.capture()
    identity.value.current_account = { id: 88, name: 'Escritório', status: 'active', role: 'admin' }
    assert.equal(context.isCurrent(token), false)

    token = context.capture()
    identity.value.current_company = { id: 11, name: 'Outra empresa', status: 'active', access_kind: 'delegated', role: 'delegated', permissions: ['erp.read'], delegated_office_id: 88 }
    assert.equal(context.isCurrent(token), false)

    token = context.capture()
    identity.value.current_company.permissions = ['erp.read', 'erp.establishments.index']
    assert.equal(context.isCurrent(token), false)
    assert.equal(context.key.value.includes('88'), true)
    scope.stop()
    assert.equal(context.isCurrent(token), false)
  })
})

describe('ERP administration rendered SFC interactions', () => {
  it('retains establishment fields and server validation feedback after a 422 submit', async (t) => {
    let submissions = 0
    const mount = compileAndMount(companyAdministrationFile, { kind: 'establishments' }, defaultOwnerIdentity(), async (path, options) => {
      if (path.startsWith('/erp/establishments?')) return { data: [], meta: { current_page: 1, last_page: 1, all_establishments: true } }
      if (path === '/erp/establishments' && options?.method === 'POST') {
        submissions++
        throw { statusCode: 422, data: { errors: { name: ['Este nome já está em uso.'] } } }
      }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()

    assert.equal(mount.instance.canCreate, true)
    clickButton(mount.root, 'Adicionar estabelecimento')
    await Vue.nextTick()
    assert.equal(mount.instance.formOpen, true)
    changeInput(mount.root, node => node.props.maxlength === '255', 'Filial Centro')
    clickButton(mount.root, 'Salvar')
    await flushVue()

    assert.equal(submissions, 1)
    assert.equal(mount.calls.find(call => call.path === '/erp/establishments' && call.options?.method === 'POST')?.options?.body && (mount.calls.find(call => call.path === '/erp/establishments' && call.options?.method === 'POST')?.options?.body as { name: string }).name, 'Filial Centro')
    assert.equal(hostText(mount.root as never).includes('Este nome já está em uso.'), true)
    assert.equal((mount.instance.form as { name: string }).name, 'Filial Centro')
    assert.equal((mount.instance.formOpen as boolean), true)
  })

  it('does not issue establishment writes for a read-only delegated member', async (t) => {
    const identity = defaultOwnerIdentity()
    identity.current_company = { ...identity.current_company!, access_kind: 'delegated', role: 'delegated', permissions: ['erp.establishments.index'], delegated_office_id: 9 }
    const mount = compileAndMount(companyAdministrationFile, { kind: 'establishments' }, identity, async (path) => {
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 5, name: 'Unidade', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1, all_establishments: true } }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()

    assert.equal((mount.instance.canView as boolean), true)
    assert.equal((mount.instance.canWrite as boolean), false)
    assert.equal(hostText(mount.root as never).includes('Unidade'), true)
    assert.equal(findHostNodes(mount.root as never, node => node.tag === 'button' && hostText(node as never).includes('Adicionar estabelecimento')).length, 0)
    assert.equal(mount.calls.some(call => call.options?.method === 'POST' || call.options?.method === 'PATCH'), false)
  })

  it('separates establishment store and update grants and respects the real scope', async (t) => {
    const storeIdentity = defaultOwnerIdentity()
    storeIdentity.current_company = { ...storeIdentity.current_company!, role: 'manager', permissions: ['erp.establishments.index', 'erp.establishments.store'] }
    const storeOnly = compileAndMount(companyAdministrationFile, { kind: 'establishments' }, storeIdentity, async (path) => {
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 5, name: 'Unidade', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1, all_establishments: true } }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(storeOnly.unmount)
    await flushVue()
    assert.equal(storeOnly.instance.canCreate, true)
    assert.equal(storeOnly.instance.canUpdate, false)
    assert.equal(findHostNodes(storeOnly.root as never, node => node.tag === 'button' && ['Editar', 'Inativar'].includes(hostText(node as never).trim())).length, 0)

    const selectedScope = compileAndMount(companyAdministrationFile, { kind: 'establishments' }, storeIdentity, async (path) => {
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 7, name: 'Filial selecionada', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1, all_establishments: false } }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(selectedScope.unmount)
    await flushVue()
    assert.equal(selectedScope.instance.canCreate, false)
    assert.equal(findHostNodes(selectedScope.root as never, node => node.tag === 'button' && hostText(node as never).includes('Adicionar estabelecimento')).length, 0)

    const updateIdentity = defaultOwnerIdentity()
    updateIdentity.current_company = { ...updateIdentity.current_company!, role: 'operator', permissions: ['erp.establishments.index', 'erp.establishments.update'] }
    const updateOnly = compileAndMount(companyAdministrationFile, { kind: 'establishments' }, updateIdentity, async (path) => {
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 6, name: 'Filial', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1, all_establishments: true } }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(updateOnly.unmount)
    await flushVue()
    assert.equal(updateOnly.instance.canCreate, false)
    assert.equal(updateOnly.instance.canUpdate, true)
    assert.equal(findHostNodes(updateOnly.root as never, node => node.tag === 'button' && hostText(node as never).includes('Adicionar estabelecimento')).length, 0)
    assert.deepEqual(findHostNodes(updateOnly.root as never, node => node.tag === 'button' && ['Editar', 'Inativar'].includes(hostText(node as never).trim())).map(node => hostText(node as never).trim()).sort(), ['Editar', 'Inativar'])
  })

  it('keeps selected establishment IDs while moving through the member picker pages', async (t) => {
    const mount = compileAndMount(companyAdministrationFile, { kind: 'members' }, defaultOwnerIdentity(), async (path) => {
      if (path.startsWith('/erp/members?')) return { data: [], meta: { current_page: 1, last_page: 1 } }
      if (path.startsWith('/erp/establishments?')) {
        const page = new URL(`http://erp.test${path}`).searchParams.get('page')
        return page === '2'
          ? { data: [{ id: 8, name: 'Filial B', cnpj: null, status: 'active' }], meta: { current_page: 2, last_page: 2 } }
          : { data: [{ id: 7, name: 'Filial A', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 2 } }
      }
      if (path === '/erp/member-permissions') return { data: [] }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()
    clickButton(mount.root, 'Adicionar membro')
    await Vue.nextTick()
    mount.instance.form.establishmentScope = 'selected'
    await Vue.nextTick()
    const checkbox = findHostNodes(mount.root as never, node => node.tag === 'input' && node.props.type === 'checkbox')[0]
    assert.ok(checkbox)
    checkbox.checked = true
    const change = checkbox.props.onChange as (event: { target: HostNode }) => void
    change({ target: checkbox })
    await Vue.nextTick()
    assert.deepEqual(mount.instance.form.establishmentIds, [7])

    clickButton(mount.root, 'Mais estabelecimentos')
    await flushVue()
    assert.equal(mount.instance.establishmentPage, 2)
    assert.deepEqual(mount.instance.establishments.map((item: { id: number }) => item.id), [8])
    assert.deepEqual(mount.instance.form.establishmentIds, [7])
    assert.equal(hostText(mount.root as never).includes('Seleção preservada: #7'), true)
  })

  it('ignores an older same-context list response after a newer request completes', async (t) => {
    const pending: Array<(value: unknown) => void> = []
    const mount = compileAndMount(companyAdministrationFile, { kind: 'establishments' }, defaultOwnerIdentity(), async (path) => {
      if (path.startsWith('/erp/establishments?')) {
        return await new Promise((resolve) => {
          pending.push(resolve)
        })
      }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()
    assert.equal(pending.length, 1)

    const newerLoad = mount.instance.load()
    await flushVue()
    assert.equal(pending.length, 2)
    pending[1]!({ data: [{ id: 2, name: 'Atual', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1, all_establishments: true } })
    await newerLoad
    await flushVue()
    pending[0]!({ data: [{ id: 1, name: 'Antigo', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1, all_establishments: true } })
    await flushVue()
    assert.deepEqual(mount.instance.rows.map((item: { name: string }) => item.name), ['Atual'])
  })

  it('keeps the owner row immutable and submits selected empty scope for a newly looked-up member', async (t) => {
    const submissions: Array<{ body: Record<string, unknown> }> = []
    const mount = compileAndMount(companyAdministrationFile, { kind: 'members' }, defaultOwnerIdentity(), async (path, options) => {
      if (path.startsWith('/erp/members?')) return { data: [{ id: 1, user: { id: 1, name: 'Owner', email: 'owner@example.test' }, role: 'owner', action_permissions: null, effective_permissions: ['*'], establishment_scope: 'all', establishment_ids: [] }], meta: { current_page: 1, last_page: 1 } }
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 4, name: 'Matriz', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1 } }
      if (path === '/erp/member-permissions') return { data: [{ permission: 'erp.read', label: 'erp.read', write: false }], meta: { roles: [{ role: 'manager', default_permissions: ['*'] }] } }
      if (path === '/erp/members/identity-lookup') return { data: { id: 6, name: 'New Member', email: 'new@example.test' } }
      if (path === '/erp/members' && options?.method === 'POST') {
        submissions.push({ body: options.body as Record<string, unknown> })
        return { data: {} }
      }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()

    const ownerArticle = findHostNodes(mount.root as never, node => node.tag === 'article').find(article => hostText(article as never).includes('Owner'))
    assert.ok(ownerArticle)
    assert.equal(findHostNodes(ownerArticle as never, node => node.tag === 'button' && ['Editar', 'Remover'].includes(hostText(node as never).trim())).length, 0)

    clickButton(mount.root, 'Adicionar membro')
    await Vue.nextTick()
    changeInput(mount.root, node => node.props.type === 'email', 'new@example.test')
    clickButton(mount.root, 'Localizar identidade')
    await flushVue()
    assert.equal(hostText(mount.root as never).includes('New Member'), true)

    mount.instance.form.establishmentScope = 'selected'
    mount.instance.form.permissionsMode = 'defaults'
    clickButton(mount.root, 'Salvar')
    await flushVue()
    assert.equal(submissions.length, 1)
    assert.deepEqual(submissions[0]?.body, {
      user_id: 6,
      role: 'manager',
      action_permissions: null,
      establishment_scope: 'selected',
      establishment_ids: []
    })
  })

  it('prevents duplicate owner delegation writes and requires the confirmation button', async (t) => {
    let releaseGrant: ((value: unknown) => void) | null = null
    const grants: Array<Record<string, unknown>> = []
    const mount = compileAndMount(mappingApprovalFile, { mode: 'owner' }, defaultOwnerIdentity(), async (path, options) => {
      if (path === '/erp/accounting-mappings') return { data: { delegation_id: null, status: 'not_delegated', delegation: null, mappings: [] } }
      if (path === '/erp/member-permissions') return { data: [{ permission: 'erp.read', label: 'erp.read', write: false }] }
      if (path === '/erp/delegations' && options?.method === 'POST') {
        grants.push(options.body as Record<string, unknown>)
        return await new Promise((resolve) => {
          releaseGrant = resolve
        })
      }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()

    mount.instance.accountId = '55'
    clickButton(mount.root, 'Revisar concessão')
    await Vue.nextTick()
    assert.equal(grants.length, 0)
    const confirm = findHostNodes(mount.root as never, node => node.tag === 'button' && hostText(node as never).includes('Confirmar concessão'))[0]
    assert.ok(confirm)
    const click = confirm.props.onClick as (event: unknown) => void
    click({ target: confirm })
    click({ target: confirm })
    await Vue.nextTick()
    assert.equal(grants.length, 1)
    assert.deepEqual(grants[0], { company_id: 10, account_id: 55, permissions: ['erp.read'] })
    releaseGrant?.({ data: { id: 8, account_id: 55, permissions: ['erp.read'], status: 'active' } })
    await flushVue()
  })

  it('approves a selected establishment against an active Account-owned client without switching ERP Company', async (t) => {
    const identity = defaultOwnerIdentity()
    identity.current_account = { id: 55, name: 'Escritório', status: 'active', role: 'admin' }
    identity.current_company = { ...identity.current_company!, role: 'viewer', permissions: ['erp.read'] }
    identity.companies = [{ ...identity.current_company!, id: 10, status: 'active', access_kind: 'direct', delegated_office_id: null }]
    const approvalPayloads: Array<Record<string, unknown>> = []
    const mount = compileAndMount(mappingApprovalFile, { mode: 'office' }, identity, async (path, options) => {
      if (path.startsWith('/account/erp-accounting-mappings/context?')) {
        const page = new URL(`http://erp.test${path}`).searchParams.get('page')
        return page === '2'
          ? { data: { delegation_id: 31, status: 'active', establishments: [{ id: 43, name: 'Filial' }] }, meta: { current_page: 2, last_page: 2, per_page: 100, total: 2 } }
          : { data: { delegation_id: 31, status: 'active', establishments: [{ id: 42, name: 'Matriz' }] }, meta: { current_page: 1, last_page: 2, per_page: 100, total: 2 } }
      }
      if (path.startsWith('/account/erp-accounting-mappings?company_id=')) return { data: [] }
      if (path.startsWith('/clients?')) {
        const query = new URL(`http://erp.test${path}`).searchParams
        return query.get('page') === '2'
          ? { data: [{ id: 74, name: 'Cliente repetido', tax_id: null, status: 'active' }], meta: { current_page: 2, last_page: 2 } }
          : { data: [{ id: 73, name: 'Cliente repetido', tax_id: null, status: 'active' }], meta: { current_page: 1, last_page: 2 } }
      }
      if (path === '/account/erp-accounting-mappings' && options?.method === 'POST') {
        approvalPayloads.push(options.body as Record<string, unknown>)
        return { data: { id: 90 } }
      }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()

    const companySelect = findHostNodes(mount.root as never, node => node.tag === 'select')[0]
    assert.ok(companySelect, hostText(mount.root as never))
    const change = companySelect.props.onChange as (event: { target: { value: string } }) => void
    change({ target: { value: '10' } })
    await flushVue()
    assert.equal(mount.instance.officeContext.delegation_id, 31)
    assert.equal(mount.instance.selectedEstablishmentId, 42)
    clickButton(mount.root, 'Carregar próxima página')
    await flushVue()
    assert.deepEqual(mount.instance.officeContext.establishments.map((item: { id: number }) => item.id), [42, 43])
    assert.equal(mount.instance.selectedEstablishmentId, 42)

    changeInput(mount.root, node => node.props.placeholder === 'Nome ou documento', 'Cliente')
    const searchButton = findHostNodes(mount.root as never, node => node.tag === 'button' && hostText(node as never).includes('Pesquisar clientes'))[0]
    assert.ok(searchButton)
    assert.equal(searchButton.props.type, 'submit')
    assert.equal(searchButton.parent?.tag, 'form')
    assert.equal(typeof searchButton.parent?.props.onSubmit, 'function')
    clickButton(mount.root, 'Pesquisar clientes')
    await flushVue()
    assert.equal(mount.instance.clients[0]?.id, 73, JSON.stringify({ selected: mount.instance.selectedCompanyId, calls: mount.calls.map(call => call.path), listError: mount.instance.listError, errorMessage: mount.instance.errorMessage }))
    clickButton(mount.root, 'Carregar mais clientes')
    await flushVue()
    assert.deepEqual(mount.instance.clients.map((client: { id: number }) => client.id), [73, 74])
    assert.equal(mount.instance.selectedClientId, null)
    mount.instance.selectedEstablishmentId = 42
    mount.instance.selectedClientId = 73
    await Vue.nextTick()
    changeInput(mount.root, node => node.props.placeholder === 'Nome ou documento', 'Outro cliente')
    await Vue.nextTick()
    assert.equal(mount.instance.clientResultsStale, true)
    assert.equal(findHostNodes(mount.root as never, node => node.tag === 'button' && hostText(node as never).includes('Revisar aprovação')).length, 0)
    changeInput(mount.root, node => node.props.placeholder === 'Nome ou documento', 'Cliente')
    await Vue.nextTick()
    mount.instance.selectedClientId = 73
    await Vue.nextTick()
    clickButton(mount.root, 'Revisar aprovação')
    await Vue.nextTick()
    clickButton(mount.root, 'Confirmar aprovação')
    await flushVue()

    assert.deepEqual(approvalPayloads[0], { delegation_id: 31, erp_establishment_id: 42, client_id: 73 })
    assert.equal(mount.calls.some(call => call.path.includes('/erp/company/switch')), false)
    assert.equal(mount.calls.some(call => call.path.startsWith('/clients?') && call.path.includes('status=active')), true)
  })

  it('marks same-office client results stale on transient errors and clears protected results on authorization loss', async (t) => {
    const identity = defaultOwnerIdentity()
    identity.current_account = { id: 55, name: 'Escritório', status: 'active', role: 'admin' }
    identity.current_company = { ...identity.current_company!, role: 'viewer', permissions: ['erp.read'] }
    identity.companies = [{ ...identity.current_company!, id: 10, status: 'active', access_kind: 'direct', delegated_office_id: null }]
    let clientFailure: number | null = null
    const mount = compileAndMount(mappingApprovalFile, { mode: 'office' }, identity, async (path) => {
      if (path.startsWith('/account/erp-accounting-mappings/context?')) return { data: { delegation_id: 31, status: 'active', establishments: [{ id: 42, name: 'Matriz' }] }, meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/account/erp-accounting-mappings?company_id=')) return { data: [] }
      if (path.startsWith('/clients?')) {
        if (clientFailure) throw { statusCode: clientFailure, data: { message: 'Access denied.' } }
        return { data: [{ id: 73, name: 'Cliente da conta', tax_id: null, status: 'active' }], meta: { current_page: 1, last_page: 1 } }
      }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()
    const companySelect = findHostNodes(mount.root as never, node => node.tag === 'select')[0]
    assert.ok(companySelect)
    ;(companySelect.props.onChange as (event: { target: { value: string } }) => void)({ target: { value: '10' } })
    await flushVue()
    changeInput(mount.root, node => node.props.placeholder === 'Nome ou documento', 'Cliente')
    clickButton(mount.root, 'Pesquisar clientes')
    await flushVue()
    assert.deepEqual(mount.instance.clients.map((client: { id: number }) => client.id), [73])

    clientFailure = 503
    mount.instance.clientQuery = 'Cliente'
    clickButton(mount.root, 'Pesquisar clientes')
    await flushVue()
    assert.deepEqual(mount.instance.clients.map((client: { id: number }) => client.id), [73])
    assert.equal(mount.instance.clientResultsStale, true)
    assert.equal(hostText(mount.root as never).includes('Resultados desatualizados'), true)

    clientFailure = 403
    clickButton(mount.root, 'Pesquisar clientes')
    await flushVue()
    assert.deepEqual(mount.instance.clients, [])
    assert.equal(mount.instance.officeContext, null)
    assert.equal(mount.instance.canApproveForOffice, false)
    assert.equal(hostText(mount.root as never).includes('Cliente da conta'), false)
  })

  it('clears establishment rows and blocks controls after a forbidden list refresh', async (t) => {
    let listFailure: number | null = null
    const mount = compileAndMount(companyAdministrationFile, { kind: 'establishments' }, defaultOwnerIdentity(), async (path) => {
      if (path.startsWith('/erp/establishments?')) {
        if (listFailure) throw { statusCode: listFailure, data: { message: 'List unavailable.' } }
        return { data: [{ id: 5, name: 'Unidade protegida', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1, all_establishments: true } }
      }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()
    assert.equal(hostText(mount.root as never).includes('Unidade protegida'), true)

    listFailure = 503
    await mount.instance.load()
    await flushVue()
    assert.equal(mount.instance.rows[0]?.name, 'Unidade protegida')
    assert.equal(mount.instance.listStale, true)
    assert.equal(mount.instance.canUpdate, false)

    listFailure = 403
    await mount.instance.load()
    await flushVue()
    assert.deepEqual(mount.instance.rows, [])
    assert.equal(mount.instance.canView, false)
    assert.equal(mount.instance.authorityBlocked, true)
    assert.equal(hostText(mount.root as never).includes('Unidade protegida'), false)
  })

  it('clears protected member rows and identity results after a forbidden exact-email lookup', async (t) => {
    const member = { id: 2, user: { id: 8, name: 'Member', email: 'member@example.test' }, role: 'manager', action_permissions: null, effective_permissions: ['erp.read'], establishment_scope: 'all', establishment_ids: [] }
    const mount = compileAndMount(companyAdministrationFile, { kind: 'members' }, defaultOwnerIdentity(), async (path) => {
      if (path.startsWith('/erp/members?')) return { data: [member], meta: { current_page: 1, last_page: 1 } }
      if (path.startsWith('/erp/establishments?')) return { data: [], meta: { current_page: 1, last_page: 1 } }
      if (path === '/erp/member-permissions') return { data: [] }
      if (path === '/erp/members/identity-lookup') throw { statusCode: 403, data: { message: 'Forbidden.' } }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()
    clickButton(mount.root, 'Adicionar membro')
    await Vue.nextTick()
    changeInput(mount.root, node => node.props.type === 'email', 'new@example.test')
    clickButton(mount.root, 'Localizar identidade')
    await flushVue()

    assert.deepEqual(mount.instance.rows, [])
    assert.equal(mount.instance.formOpen, false)
    assert.equal(mount.instance.canView, false)
    assert.equal(hostText(mount.root as never).includes('Member'), false)
  })

  it('clears protected establishment state after a forbidden write response', async (t) => {
    const mount = compileAndMount(companyAdministrationFile, { kind: 'establishments' }, defaultOwnerIdentity(), async (path, options) => {
      if (path.startsWith('/erp/establishments?')) return { data: [{ id: 5, name: 'Unidade protegida', cnpj: null, status: 'active' }], meta: { current_page: 1, last_page: 1, all_establishments: true } }
      if (path === '/erp/establishments' && options?.method === 'POST') throw { statusCode: 403, data: { message: 'Forbidden.' } }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()
    clickButton(mount.root, 'Adicionar estabelecimento')
    await Vue.nextTick()
    changeInput(mount.root, node => node.props.maxlength === '255', 'Filial protegida')
    clickButton(mount.root, 'Salvar')
    await flushVue()

    assert.deepEqual(mount.instance.rows, [])
    assert.equal(mount.instance.authorityBlocked, true)
    assert.equal(mount.instance.formOpen, false)
    assert.equal(hostText(mount.root as never).includes('Unidade protegida'), false)
  })

  it('clears office establishment and mapping context after a forbidden explicit context read', async (t) => {
    const identity = defaultOwnerIdentity()
    identity.current_account = { id: 55, name: 'Escritório', status: 'active', role: 'admin' }
    identity.current_company = { ...identity.current_company!, role: 'viewer', permissions: ['erp.read'] }
    identity.companies = [{ ...identity.current_company!, id: 10, status: 'active', access_kind: 'direct', delegated_office_id: null }]
    let contextFailure: number | null = null
    const mount = compileAndMount(mappingApprovalFile, { mode: 'office' }, identity, async (path) => {
      if (path.startsWith('/account/erp-accounting-mappings/context?')) {
        if (contextFailure) throw { statusCode: contextFailure, data: { message: 'Context unavailable.' } }
        return { data: { delegation_id: 31, status: 'active', establishments: [{ id: 42, name: 'Unidade protegida' }] }, meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      }
      if (path.startsWith('/account/erp-accounting-mappings?company_id=')) return { data: [{ id: 90, erp_company_id: 10, erp_establishment_id: 42, delegation_id: 31, client_id: 73, revision: 1, status: 'active' }] }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()
    const companySelect = findHostNodes(mount.root as never, node => node.tag === 'select')[0]
    assert.ok(companySelect)
    ;(companySelect.props.onChange as (event: { target: { value: string } }) => void)({ target: { value: '10' } })
    await flushVue()
    assert.equal(mount.instance.officeContext.establishments[0]?.name, 'Unidade protegida')
    assert.equal(mount.instance.mappings.length, 1)

    contextFailure = 503
    await mount.instance.loadOfficeContext(10)
    await flushVue()
    assert.equal(mount.instance.officeContext.establishments[0]?.name, 'Unidade protegida')
    assert.equal(mount.instance.officeDataStale, true)
    assert.equal(findHostNodes(mount.root as never, node => node.tag === 'div' && node.props.title === 'Contexto desatualizado').length, 1)

    contextFailure = 403
    await mount.instance.loadOfficeContext(10)
    await flushVue()
    assert.equal(mount.instance.officeContext, null)
    assert.deepEqual(mount.instance.mappings, [])
    assert.equal(mount.instance.canApproveForOffice, false)
    assert.equal(hostText(mount.root as never).includes('Unidade protegida'), false)
  })

  it('clears office candidates after a forbidden accounting approval write', async (t) => {
    const identity = defaultOwnerIdentity()
    identity.current_account = { id: 55, name: 'Escritório', status: 'active', role: 'admin' }
    identity.current_company = { ...identity.current_company!, role: 'viewer', permissions: ['erp.read'] }
    identity.companies = [{ ...identity.current_company!, id: 10, status: 'active', access_kind: 'direct', delegated_office_id: null }]
    const mount = compileAndMount(mappingApprovalFile, { mode: 'office' }, identity, async (path, options) => {
      if (path.startsWith('/account/erp-accounting-mappings/context?')) return { data: { delegation_id: 31, status: 'active', establishments: [{ id: 42, name: 'Matriz protegida' }] }, meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
      if (path.startsWith('/account/erp-accounting-mappings?company_id=')) return { data: [] }
      if (path.startsWith('/clients?')) return { data: [{ id: 73, name: 'Cliente protegido', tax_id: null, status: 'active' }], meta: { current_page: 1, last_page: 1 } }
      if (path === '/account/erp-accounting-mappings' && options?.method === 'POST') throw { statusCode: 403, data: { message: 'Forbidden.' } }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()
    const companySelect = findHostNodes(mount.root as never, node => node.tag === 'select')[0]
    assert.ok(companySelect)
    ;(companySelect.props.onChange as (event: { target: { value: string } }) => void)({ target: { value: '10' } })
    await flushVue()
    changeInput(mount.root, node => node.props.placeholder === 'Nome ou documento', 'Cliente')
    clickButton(mount.root, 'Pesquisar clientes')
    await flushVue()
    mount.instance.selectedEstablishmentId = 42
    mount.instance.selectedClientId = 73
    await Vue.nextTick()
    clickButton(mount.root, 'Revisar aprovação')
    await Vue.nextTick()
    clickButton(mount.root, 'Confirmar aprovação')
    await flushVue()

    assert.equal(mount.instance.officeContext, null)
    assert.deepEqual(mount.instance.clients, [])
    assert.deepEqual(mount.instance.mappings, [])
    assert.equal(mount.instance.canApproveForOffice, false)
    assert.equal(hostText(mount.root as never).includes('Cliente protegido'), false)
  })

  it('clears owner delegation state after a forbidden grant response', async (t) => {
    let readFailure: number | null = null
    const mount = compileAndMount(mappingApprovalFile, { mode: 'owner' }, defaultOwnerIdentity(), async (path, options) => {
      if (path === '/erp/accounting-mappings') {
        if (readFailure) throw { statusCode: readFailure, data: { message: 'Read unavailable.' } }
        return { data: { delegation_id: 31, status: 'active', delegation: { id: 31, account: { id: 55, name: 'Escritório protegido' }, status: 'active', effective_permissions: ['erp.read'] }, mappings: [] } }
      }
      if (path === '/erp/member-permissions') return { data: [{ permission: 'erp.read', label: 'erp.read', write: false }] }
      if (path === '/erp/delegations' && options?.method === 'POST') throw { statusCode: 403, data: { message: 'Forbidden.' } }
      throw new Error(`Unexpected ERP request: ${path}`)
    })
    t.after(mount.unmount)
    await flushVue()
    readFailure = 503
    await mount.instance.loadOwner()
    await flushVue()
    assert.equal(mount.instance.ownerState.delegation.account.name, 'Escritório protegido')
    assert.equal(mount.instance.ownerDataStale, true)
    const revoke = findHostNodes(mount.root as never, node => node.tag === 'button' && hostText(node as never).includes('Revogar delegação'))[0]
    assert.ok(revoke)
    assert.equal(revoke.props.disabled, true)

    readFailure = null
    await mount.instance.loadOwner()
    await flushVue()
    assert.equal(mount.instance.ownerDataStale, false)
    mount.instance.accountId = '55'
    clickButton(mount.root, 'Revisar substituição')
    await Vue.nextTick()
    clickButton(mount.root, 'Confirmar concessão')
    await flushVue()

    assert.equal(mount.instance.ownerState, null)
    assert.equal(findHostNodes(mount.root as never, node => node.tag === 'div' && node.props.title === 'Acesso restrito').length, 1)
    assert.equal(hostText(mount.root as never).includes('Escritório protegido'), false)
  })
})
