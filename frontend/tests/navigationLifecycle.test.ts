import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { compileVueComponent, vueSource } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { createLatestRequestRunner } from '../app/utils/adminListFilters.ts'

type Node = { tag?: string, text?: string, props: Record<string, unknown>, children: Node[], parent?: Node | null }

function mount(component: Vue.Component, components: Record<string, Vue.Component> = {}) {
  const root: Node = { props: {}, children: [] }
  const renderer = Vue.createRenderer<Node, Node>({
    createElement: tag => ({ tag, props: {}, children: [] }),
    createText: text => ({ text, props: {}, children: [] }),
    createComment: text => ({ text, props: {}, children: [] }),
    setText: (node, text) => { node.text = text },
    setElementText: (node, text) => {
      node.text = text
      node.children = []
    },
    patchProp: (node, key, _previous, next) => { node.props[key] = next },
    insert(node, parent, anchor) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
    },
    remove(node) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    parentNode: node => node.parent ?? null,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const app = renderer.createApp(component)
  for (const [name, implementation] of Object.entries(components)) app.component(name, implementation)
  app.mount(root)
  const walk = (node: Node): Node[] => [node, ...node.children.flatMap(walk)]
  return { root, nodes: () => walk(root), unmount: () => app.unmount() }
}

function passthrough(tag: string, slotNames = ['default']) {
  return Vue.defineComponent({ setup: (_props, { attrs, slots }) => () => Vue.h(tag, attrs, slotNames.flatMap(name => slots[name]?.())) })
}

test('one mounted commercial shell follows tab changes and history', async () => {
  const route = Vue.reactive({ path: '/gestao-erp/faturas' })
  const component = compileVueComponent('pages/gestao-erp.vue', { useRoute: () => route, definePageMeta() {} })
  const mounted = mount(component, {
    UDashboardPanel: passthrough('panel', ['header', 'body']),
    UDashboardNavbar: passthrough('header'),
    UDashboardSidebarCollapse: passthrough('button'),
    UDashboardToolbar: passthrough('toolbar', ['left']),
    UNavigationMenu: Vue.defineComponent({
      props: ['items'],
      setup: props => () => Vue.h('nav', { current: (props.items as { active: boolean, to: string }[][]).flat().filter(item => item.active).map(item => item.to) })
    }),
    NuxtPage: passthrough('content')
  })
  try {
    const header = mounted.nodes().find(node => node.tag === 'header')
    for (const path of ['/gestao-erp/assinaturas', '/gestao-erp/planos', '/gestao-erp/clientes', '/gestao-erp/recebimentos', '/gestao-erp/configuracoes', '/gestao-erp', '/gestao-erp/faturas/12', '/gestao-erp/assinaturas/31', '/gestao-erp/planos']) {
      route.path = path
      await Vue.nextTick()
      const owner = path.replace(/\/(?:12|31)$/, '')
      assert.deepEqual(mounted.nodes().find(node => node.tag === 'nav')?.props.current, [owner], path)
      assert.equal(mounted.nodes().find(node => node.tag === 'header'), header)
    }
  } finally { mounted.unmount() }
})

test('settings query and anchor changes retain the mounted editor', async () => {
  const route = Vue.reactive({ path: '/erp/configuracoes/empresa', fullPath: '/erp/configuracoes/empresa' })
  let mounts = 0
  let unmounts = 0
  const component = compileVueComponent('pages/erp/configuracoes.vue', {
    useRoute: () => route, useAuth: () => ({ currentCompany: Vue.ref(null) }), definePageMeta() {}
  })
  const mounted = mount(component, {
    UDashboardPanel: passthrough('panel', ['header', 'body']),
    UDashboardNavbar: passthrough('header'),
    UDashboardSidebarCollapse: passthrough('button'),
    ErpWorkspaceDestinationToolbar: passthrough('toolbar'),
    NuxtPage: Vue.defineComponent({
      setup() {
        mounts++
        Vue.onUnmounted(() => {
          unmounts++
        })
        const draft = Vue.ref('Unsaved company')
        return () => Vue.h('editor', { draft: draft.value })
      }
    })
  })
  try {
    const editor = mounted.nodes().find(node => node.tag === 'editor')
    for (const suffix of ['?review=1', '?review=1#address', '', '#fiscal']) {
      route.fullPath = `${route.path}${suffix}`
      await Vue.nextTick()
      assert.equal(mounts, 1)
      assert.equal(unmounts, 0)
      assert.equal(mounted.nodes().find(node => node.tag === 'editor'), editor)
    }
  } finally { mounted.unmount() }
})

// Execute the actual page's cache callback against Nuxt's documented payload contract.
function cachePolicy(path: string) {
  const { descriptor } = parse(vueSource(path))
  const source = ts.createSourceFile(path, descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
  let options: ts.Expression | undefined
  function visit(node: ts.Node) {
    if (ts.isCallExpression(node) && node.expression.getText(source) === 'useAsyncData') options = node.arguments[2]
    ts.forEachChild(node, visit)
  }
  visit(source)
  assert.ok(options)
  const code = ts.transpileModule(`return (${options.getText(source)})`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
  return new Function(code)() as { getCachedData: (key: string, app: { isHydrating: boolean, payload: { data: Record<string, unknown> } }) => unknown }
}

for (const path of ['pages/monitoring/index.vue', 'pages/settings/certificado.vue', 'pages/settings/whatsapp.vue']) {
  test(`${path} retains populated, zero and absent hydration payload but revalidates visits`, () => {
    const { getCachedData } = cachePolicy(path)
    for (const value of [{ portfolio_total: 16, attention: { dctf: 7 } }, { portfolio_total: 0, attention: {} }, null]) {
      const app = { isHydrating: true, payload: { data: { resource: value } } }
      assert.equal(getCachedData('resource', app), value)
      app.isHydrating = false
      assert.equal(getCachedData('resource', app), undefined)
    }
  })
}

test('Work shell owns normalization while child month consumers remain read-only', async () => {
  const route = Vue.reactive({ path: '/work/tarefas', query: { search: 'preserved' } as Record<string, unknown> })
  const state = new Map<string, Vue.Ref>()
  const replacements: unknown[] = []
  const useState = (key: string, initial: () => unknown) => {
    if (!state.has(key)) state.set(key, Vue.ref(initial()))
    return state.get(key)
  }
  const foundation = loadAppComposable<{ useListNavigationState: (...args: unknown[]) => Vue.Ref }>('composables/useListNavigationState.ts', { useState })
  const { useWorkReferenceMonth } = loadAppComposable<{ useWorkReferenceMonth: (options?: { syncRoute?: boolean }) => { referenceMonth: Vue.Ref<string> } }>('composables/useWorkReferenceMonth.ts', {
    ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, useListNavigationState: foundation.useListNavigationState,
    useRoute: () => route,
    useRouter: () => ({ replace: (target: { query: Record<string, unknown> }) => {
      replacements.push(target)
      route.query = target.query
    } }),
    useState
  })
  const scope = Vue.effectScope()
  let selectedMonth = ''
  try {
    scope.run(() => {
      const shell = useWorkReferenceMonth({ syncRoute: true })
      selectedMonth = shell.referenceMonth.value
      const child = useWorkReferenceMonth()
      assert.equal(shell.referenceMonth.value, child.referenceMonth.value)
    })
    await Vue.nextTick()
    assert.equal(replacements.length, 1)
    assert.deepEqual(replacements[0], { query: { search: 'preserved', reference_month: selectedMonth } })
  } finally { scope.stop() }
})

test('invalidating an obsolete safe read aborts its transport signal without failure feedback', async () => {
  const run = createLatestRequestRunner()
  let signal: AbortSignal | undefined
  let finish: (() => void) | undefined
  const feedback: unknown[] = []
  const pending = run((received?: AbortSignal) => {
    signal = received
    return new Promise<void>((resolve) => {
      finish = resolve
    })
  }, { onSuccess: () => feedback.push('success'), onError: error => feedback.push(error), onSettled: () => feedback.push('settled') })
  run.invalidate()
  finish?.()
  await pending
  assert.equal(signal?.aborted, true)
  assert.deepEqual(feedback, [])
})

test('adjacent settings updates preserve a dirty company field and its named validation feedback', async () => {
  const state = Vue.ref<Record<string, unknown> | null>({ razao_social: 'Original', cst_pis_padrao: '01' })
  const settings = {
    state, meta: Vue.ref(null), pending: Vue.ref(false), canRead: Vue.ref(true), canWrite: Vue.ref(true), authorityKey: Vue.ref('company-41'),
    refresh: async () => {},
    save: async () => { throw { status: 422, data: { message: 'Revise os campos.', errors: { razao_social: ['Nome inválido.'] } } } }
  }
  const component = compileVueComponent('components/erp/ErpSettingsSectionForm.vue', { onMounted: Vue.onMounted, onScopeDispose: Vue.onScopeDispose, ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, reactive: Vue.reactive, useErpCompanySettings: () => settings, useToast: () => ({ add() {} }) })
  const wrapper = Vue.defineComponent({ setup: () => () => Vue.h(component, { title: 'Empresa', fields: [{ key: 'razao_social', label: 'Razão social' }, { key: 'cst_pis_padrao', label: 'PIS', type: 'code' }] }) })
  const mounted = mount(wrapper, {
    UForm: passthrough('form'), UPageCard: passthrough('card'), UButton: passthrough('button'),
    UFormField: passthrough('field'), UInput: passthrough('input'), USeparator: passthrough('hr'), UAlert: passthrough('alert')
  })
  try {
    const nameInput = () => mounted.nodes().filter(node => node.tag === 'input')[0]!
    ;(nameInput().props['onUpdate:modelValue'] as (value: string) => void)('Unsaved company')
    await Vue.nextTick()
    await (mounted.nodes().find(node => node.tag === 'form')!.props.onSubmit as () => Promise<void>)()
    await Vue.nextTick()
    state.value = { ...state.value, default_product_tax_profile_id: 24, cst_pis_padrao: '02' }
    await Vue.nextTick()
    assert.equal(nameInput().props['model-value'] ?? nameInput().props.modelValue, 'Unsaved company')
    assert.equal(mounted.nodes().find(node => node.tag === 'field')?.props.error, 'Nome inválido.')
    const codeInput = mounted.nodes().filter(node => node.tag === 'input')[1]!
    assert.equal(codeInput.props['model-value'] ?? codeInput.props.modelValue, '02')
  } finally { mounted.unmount() }
})
