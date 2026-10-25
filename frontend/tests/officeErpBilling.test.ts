import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent, vueSource } from './helpers/compileVueComponent.ts'
import { formatOfficeErpMoney, parseOfficeErpMoney } from '../shared/utils/officeErpCommercial.ts'

interface HostNode {
  tag: string
  text?: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
}

function mountComponent(name: 'OfficeErpAdjustmentForm' | 'OfficeErpReceiptModal' | 'OfficeErpReversalModal', props: Record<string, unknown> = {}) {
  const root: HostNode = { tag: 'root', props: {}, children: [], parent: null }
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    createElement: tag => ({ tag, props: {}, children: [], parent: null }),
    createText: text => ({ tag: '#text', text, props: {}, children: [], parent: null }),
    createComment: text => ({ tag: '#comment', text, props: {}, children: [], parent: null }),
    setText: (node, text) => { node.text = text },
    setElementText: (node, text) => {
      node.text = text
      node.children = []
    },
    patchProp: (node, key, _previous, next) => { node.props[key] = next },
    insert: (node, parent, anchor) => {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
    },
    remove: (node) => {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const component = compileVueComponent(`components/office-erp/${name}.vue`, {}, {
    '#shared/utils/officeErpCommercial': { parseOfficeErpMoney, formatOfficeErpMoney }
  })
  const componentProps = Vue.reactive(props)
  const app = renderer.createApp({ render: () => Vue.h(component, componentProps) })
  app.component('UApp', { setup: (_props, context) => () => Vue.h('div', context.slots.default?.()) })
  app.component('UForm', { emits: ['submit'], setup: (_props, context) => () => Vue.h('form', { onSubmit: (event: unknown) => context.emit('submit', event) }, context.slots.default?.()) })
  app.component('UFormField', { props: ['label', 'error', 'description', 'name'], setup: (field, context) => () => Vue.h('label', { 'data-field': field.name }, [String(field.label ?? ''), ...(context.slots.default?.() ?? []), field.error ? Vue.h('span', { role: 'alert' }, String(field.error)) : null]) })
  app.component('UInput', { props: ['modelValue', 'label', 'type', 'accept'], emits: ['update:modelValue', 'change'], setup: (field, context) => () => Vue.h('input', { ...context.attrs, 'type': field.type ?? 'text', 'accept': field.accept, 'aria-label': field.label, 'value': field.modelValue, 'onInput': (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value), 'onChange': (event: unknown) => context.emit('change', event) }) })
  app.component('UTextarea', { props: ['modelValue', 'label'], emits: ['update:modelValue'], setup: (field, context) => () => Vue.h('textarea', { ...context.attrs, 'aria-label': field.label, 'value': field.modelValue, 'onInput': (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value) }) })
  app.component('USelect', { props: ['modelValue', 'label', 'items'], emits: ['update:modelValue'], setup: (field, context) => () => Vue.h('select', { 'aria-label': field.label, 'value': field.modelValue, 'onChange': (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value) }, (field.items as Array<{ label: string, value: string }> ?? []).map(item => Vue.h('option', { value: item.value }, item.label))) })
  app.component('UCheckboxGroup', { props: ['modelValue', 'items'], emits: ['update:modelValue'], setup: (field, context) => () => Vue.h('fieldset', (field.items as Array<{ label: string, value: string }> ?? []).map(item => Vue.h('button', { 'type': 'button', 'data-choice': item.value, 'onClick': () => {
    const selected = field.modelValue as string[]
    context.emit('update:modelValue', selected.includes(item.value) ? selected.filter(value => value !== item.value) : [...selected, item.value])
  } }, item.label))) })
  app.component('UModal', { props: ['open', 'title', 'description'], emits: ['update:open'], setup: (modal, context) => () => modal.open ? Vue.h('section', { 'role': 'dialog', 'aria-label': modal.title }, [Vue.h('h2', String(modal.title ?? '')), Vue.h('p', String(modal.description ?? '')), ...(context.slots.body?.() ?? []), ...(context.slots.footer?.() ?? []), Vue.h('button', { 'type': 'button', 'aria-label': 'Fechar modal', 'onClick': () => context.emit('update:open', false) }, 'Fechar modal')]) : null })
  app.component('UAlert', { props: ['title', 'description'], setup: (alert, context) => () => Vue.h('div', { role: 'alert' }, [String(alert.title ?? ''), String(alert.description ?? ''), ...(context.slots.default?.() ?? [])]) })
  app.component('UButton', { props: ['label', 'type', 'disabled', 'loading'], setup: (button, context) => () => Vue.h('button', { ...context.attrs, 'type': button.type ?? 'button', 'disabled': button.disabled, 'aria-busy': button.loading ? 'true' : undefined }, context.slots.default?.() ?? String(button.label ?? '')) })
  app.mount(root)
  const find = (predicate: (node: HostNode) => boolean): HostNode | undefined => {
    const visit = (node: HostNode): HostNode | undefined => predicate(node) ? node : node.children.map(visit).find(Boolean)
    return visit(root)
  }
  const all = (predicate: (node: HostNode) => boolean): HostNode[] => {
    const result: HostNode[] = []
    const visit = (node: HostNode) => {
      if (predicate(node)) result.push(node)
      node.children.forEach(visit)
    }
    visit(root)
    return result
  }
  const text = (node: HostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`
  return { app, root, props: componentProps, find, all, text }
}

function input(mounted: ReturnType<typeof mountComponent>, label: string, value: string) {
  const node = mounted.find(candidate => candidate.tag === 'input' && candidate.props['aria-label'] === label)
  assert.ok(node, `Missing input ${label}`)
  ;(node.props.onInput as (event: { target: { value: string } }) => void)({ target: { value } })
  return node
}

function mountPage(path: string, options: {
  params?: Record<string, string>
  routePath?: string
  api?: (path: string, options?: Record<string, unknown>) => Promise<unknown>
  execute?: (...args: unknown[]) => Promise<unknown>
  recover?: (...args: unknown[]) => Promise<unknown>
  recoveryKey?: string | null
  pending?: boolean
} = {}) {
  const root: HostNode = { tag: 'root', props: {}, children: [], parent: null }
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    createElement: tag => ({ tag, props: {}, children: [], parent: null }),
    createText: text => ({ tag: '#text', text, props: {}, children: [], parent: null }),
    createComment: text => ({ tag: '#comment', text, props: {}, children: [], parent: null }),
    setText: (node, text) => { node.text = text },
    setElementText: (node, text) => {
      node.text = text
      node.children = []
    },
    patchProp: (node, key, _previous, next) => { node.props[key] = next },
    insert: (node, parent, anchor) => {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
    },
    remove: (node) => {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const route = Vue.reactive({ path: options.routePath ?? '', params: { id: 'nova', ...(options.params ?? {}) } })
  const account = Vue.ref({ id: 1, status: 'active', role: 'admin' })
  const user = Vue.ref({ id: 9 })
  const sessionGeneration = Vue.ref(0)
  const token = () => ({ key: String(account.value.id), generation: sessionGeneration.value })
  const capabilities = Vue.ref({ can_read: true, can_maintain: true, capability_key: 'cap-' + account.value.id })
  const context = { capabilities, capture: token, isCurrent: (captured: { key: string, generation: number }) => captured.key === String(account.value.id) && captured.generation === sessionGeneration.value, refresh: async () => {}, invalidate: () => {} }
  const recoveryKey = Vue.ref(options.recoveryKey ?? null)
  const pending = Vue.ref(options.pending ?? !!options.recoveryKey)
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const commands: unknown[][] = []
  const api = async (url: string, requestOptions?: Record<string, unknown>) => {
    calls.push({ path: url, options: requestOptions })
    return options.api ? options.api(url, requestOptions) : { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
  }
  const execute = async (...args: unknown[]) => {
    commands.push(args)
    const result = options.execute ? await options.execute(...args) : { status: 'confirmed', data: { id: 21 } }
    if (result && typeof result === 'object' && (result as { status?: string }).status === 'unknown') {
      recoveryKey.value = String((result as { key?: string }).key ?? '')
      pending.value = true
    }
    return result
  }
  const recover = async (...args: unknown[]) => {
    commands.push(['recover', ...args])
    const result = options.recover ? await options.recover(...args) : { status: 'confirmed', data: {} }
    if (result && typeof result === 'object' && (result as { status?: string }).status === 'confirmed') {
      recoveryKey.value = null
      pending.value = false
    }
    return result
  }
  const metadata: Record<string, unknown>[] = []
  const bindings = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, nextTick: Vue.nextTick,
    onMounted: Vue.onMounted, onBeforeUnmount: Vue.onBeforeUnmount,
    useRoute: () => route,
    useRouter: () => ({ replace: async (url: string) => {
      const match = /faturas\/(\d+)/.exec(url)
      if (match) route.params.id = match[1]!
    } }),
    useAccessApi: () => ({ api }), useAuth: () => ({ user, currentAccount: account }),
    useState: (_key: string, init: () => unknown) => Vue.ref(init()),
    useOfficeErpContext: () => context, useOfficeErpCommand: () => ({ pending, recoveryKey, execute, recover }),
    definePageMeta: (value: Record<string, unknown>) => { metadata.push(value) },
    useToast: () => ({ add: () => {} }), useHead: () => {}
  }
  const shared = { parseOfficeErpMoney, formatOfficeErpMoney }
  const modules = {
    '#shared/utils/officeErpCommercial': shared,
    '~/components/data-table/panel': { panelBodyClass: '', panelFooterClass: '', panelFooterCountClass: '', panelPaginationClass: '', panelTableUi: {} },
    '~/composables/useApiError': { apiErrorMessage: (error: unknown) => error instanceof Error ? error.message : undefined }
  }
  const component = compileVueComponent(path, bindings, modules)
  const app = renderer.createApp(component)
  const basic = (props: string[] = []) => ({ props, setup: (_p: unknown, ctx: { slots: Record<string, () => HostNode[]> }) => () => Vue.h('div', {}, Object.values(ctx.slots).flatMap(slot => slot?.() ?? [])) })
  app.component('UApp', basic())
  for (const [name, marker] of [['UDashboardPanel', 'dashboard-panel'], ['UDashboardNavbar', 'dashboard-navbar'], ['UDashboardToolbar', 'dashboard-toolbar'], ['UDashboardSidebarCollapse', 'dashboard-sidebar-collapse']] as const) {
    app.component(name, { setup: (_props, context) => () => Vue.h('div', { 'data-shell': marker }, Object.values(context.slots).flatMap(slot => slot?.() ?? [])) })
  }
  app.component('UNavigationMenu', { props: ['items'], setup: (props, context) => () => Vue.h('nav', { 'data-shell': 'navigation-menu', 'data-items': JSON.stringify(props.items) }, Object.values(context.slots).flatMap(slot => slot?.() ?? [])) })
  app.component('NuxtPage', { setup: () => () => Vue.h('section', { 'data-route-page': 'true' }, 'Conteúdo da rota filha') })
  app.component('UCard', basic())
  app.component('UFormField', {
    props: ['label', 'error', 'name'],
    setup: (p: Record<string, unknown>, c: { slots: Record<string, () => HostNode[]> }) => () => Vue.h('label', { 'data-field': p.name }, [String(p.label ?? ''), ...Object.values(c.slots).flatMap(slot => slot?.() ?? []), p.error ? Vue.h('span', { role: 'alert' }, String(p.error)) : null])
  })
  app.component('DataTablePanelList', basic(['title', 'description']))
  app.component('UTable', { props: ['data'], setup: (p: { data: Array<unknown> }, c: { slots: Record<string, (scope: { row: { original: unknown } }) => HostNode[]> }) => () => Vue.h('div', { 'data-test-table': true }, (p.data ?? []).flatMap(row => Object.values(c.slots).flatMap(slot => slot({ row: { original: row } })))) })
  app.component('UPagination', { props: ['page', 'total', 'itemsPerPage', 'disabled'], emits: ['update:page'], setup: (p: { page: number }, c: { emit: (name: string, value: number) => void }) => () => Vue.h('button', { 'aria-label': 'Próxima página', 'disabled': p.page >= 2, 'onClick': () => c.emit('update:page', p.page + 1) }, 'Próxima página') })
  app.component('UInput', { props: ['modelValue', 'label', 'type', 'disabled'], emits: ['update:modelValue'], setup: (p: Record<string, unknown>, c: { attrs: Record<string, unknown>, emit: (name: string, value: string) => void }) => () => Vue.h('input', { ...c.attrs, 'aria-label': c.attrs['aria-label'] ?? p.label, 'type': p.type ?? 'text', 'disabled': p.disabled, 'value': p.modelValue, 'onInput': (event: { target: { value: string } }) => c.emit('update:modelValue', event.target.value) }) })
  app.component('UTextarea', { props: ['modelValue', 'label'], emits: ['update:modelValue'], setup: (p: Record<string, unknown>, c: { attrs: Record<string, unknown>, emit: (name: string, value: string) => void }) => () => Vue.h('textarea', { ...c.attrs, 'aria-label': p.label, 'value': p.modelValue, 'onInput': (event: { target: { value: string } }) => c.emit('update:modelValue', event.target.value) }) })
  app.component('USelect', { props: ['modelValue', 'items', 'label'], emits: ['update:modelValue'], setup: (p: { modelValue: string, items: Array<{ label: string, value: string }> }, c: { emit: (name: string, value: string) => void }) => () => Vue.h('select', { value: p.modelValue, onChange: (event: { target: { value: string } }) => c.emit('update:modelValue', event.target.value) }, p.items?.map(item => Vue.h('option', { value: item.value }, item.label))) })
  app.component('UButton', { props: ['label', 'disabled', 'loading', 'type'], setup: (p: Record<string, unknown>, c: { attrs: Record<string, unknown>, slots: { default?: () => HostNode[] } }) => () => Vue.h('button', { ...c.attrs, 'disabled': p.disabled, 'type': p.type ?? 'button', 'aria-busy': p.loading ? 'true' : undefined }, c.slots.default?.() ?? String(p.label ?? '')) })
  app.component('UAlert', { props: ['title', 'description'], setup: (p: Record<string, unknown>, c: { slots: Record<string, () => HostNode[]> }) => () => Vue.h('div', { role: 'alert' }, [String(p.title ?? ''), String(p.description ?? ''), ...Object.values(c.slots).flatMap(slot => slot?.() ?? [])]) })
  app.component('UBadge', basic(['color', 'variant']))
  app.component('UModal', { props: ['open', 'title', 'description'], emits: ['update:open'], setup: (p: { open: boolean, title: string }, c: { slots: Record<string, () => HostNode[]>, emit: (name: string, value: boolean) => void }) => () => p.open ? Vue.h('section', { 'role': 'dialog', 'aria-label': p.title }, [...Object.values(c.slots).flatMap(slot => slot?.() ?? []), Vue.h('button', { 'aria-label': 'Fechar modal', 'onClick': () => c.emit('update:open', false) }, 'Fechar modal')]) : null })
  app.component('UForm', { emits: ['submit'], setup: (_p: unknown, c: { slots: { default?: () => HostNode[] }, emit: (name: string, event: unknown) => void }) => () => Vue.h('form', { onSubmit: (event: unknown) => c.emit('submit', event) }, c.slots.default?.()) })
  app.component('UCheckboxGroup', { props: ['modelValue', 'items'], emits: ['update:modelValue'], setup: (p: { modelValue: string[], items: Array<{ label: string, value: string }> }, c: { emit: (name: string, value: string[]) => void }) => () => Vue.h('fieldset', p.items?.map(item => Vue.h('button', { 'type': 'button', 'data-choice': item.value, 'onClick': () => c.emit('update:modelValue', p.modelValue.includes(item.value) ? p.modelValue.filter(value => value !== item.value) : [...p.modelValue, item.value]) }, item.label))) })
  app.component('OfficeErpAdjustmentForm', compileVueComponent('components/office-erp/OfficeErpAdjustmentForm.vue', bindings, modules))
  app.component('OfficeErpReceiptModal', compileVueComponent('components/office-erp/OfficeErpReceiptModal.vue', bindings, modules))
  app.component('OfficeErpReversalModal', compileVueComponent('components/office-erp/OfficeErpReversalModal.vue', bindings, modules))
  app.component('NuxtLink', { props: ['to'], setup: (_p: unknown, c: { slots: { default?: () => HostNode[] } }) => () => Vue.h('a', {}, c.slots.default?.()) })
  app.mount(root)
  const find = (predicate: (node: HostNode) => boolean) => {
    const visit = (node: HostNode): HostNode | undefined => predicate(node) ? node : node.children.map(visit).find(Boolean)
    return visit(root)
  }
  const all = (predicate: (node: HostNode) => boolean) => {
    const result: HostNode[] = []
    const visit = (node: HostNode) => {
      if (predicate(node)) result.push(node)
      node.children.forEach(visit)
    }
    visit(root)
    return result
  }
  const text = (node: HostNode): string => (node.text ?? '') + node.children.map(text).join('')
  return { app, root, route, account, sessionGeneration, calls, commands, metadata, find, all, text }
}

async function settlePage() {
  await Vue.nextTick()
  await new Promise(resolve => setTimeout(resolve, 0))
  await Vue.nextTick()
}

function setNodeInput(node: HostNode, value: string) {
  const handler = node.props.onInput as ((event: { target: { value: string } }) => void) | undefined
  assert.ok(handler, 'Missing input handler on ' + node.tag)
  handler({ target: { value } })
}

describe('office ERP billing form components', () => {
  it('renders all billing routes inside the office shell metadata', async (t) => {
    const paths = [
      'pages/gestao-erp/faturas/index.vue',
      'pages/gestao-erp/faturas/[id]/index.vue',
      'pages/gestao-erp/faturas/[id]/imprimir.vue',
      'pages/gestao-erp/recebimentos.vue'
    ]
    const pages = paths.map(path => mountPage(path))
    t.after(() => pages.forEach(page => page.app.unmount()))
    await Vue.nextTick()
    assert.equal(pages.length, 4)
    for (const path of paths) {
      const source = vueSource(path)
      assert.match(source, /definePageMeta\(\{\s*middleware:\s*'auth'/)
      assert.doesNotMatch(source, /layout:\s*'erp'/)
    }
  })

  it('retains every draft line and allows zero amount and total in the PATCH payload', async (t) => {
    const invoice = {
      id: 42, contract_id: 3, kind: 'manual', document_status: 'draft', number: null, due_on: '2026-11-01',
      period_start: '2026-10-01', period_end: '2026-10-31',
      items_snapshot: [{ description: 'Linha zero', amount: '0.00', discount: '0.00' }, { description: 'Serviço', amount: '100.00', discount: '0.00' }],
      adjustments: [], balance: { original_total: '100.00', adjusted_charge: '0.00', effective_received: '0.00', outstanding: '0.00' },
      seller_snapshot: { account: { name: 'Escritório' } }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null }
    }
    let submitted: unknown
    const mounted = mountPage('pages/gestao-erp/faturas/[id]/index.vue', {
      params: { id: '42' },
      api: async () => ({ data: invoice }),
      execute: async (...args) => {
        submitted = args
        return { status: 'confirmed', data: invoice }
      }
    })
    t.after(() => mounted.app.unmount())
    await new Promise(resolve => setTimeout(resolve, 0))
    const save = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Salvar rascunho'))
    assert.ok(save, mounted.text(mounted.root))
    ;(save.props.onClick as () => void)()
    await new Promise(resolve => setTimeout(resolve, 0))
    assert.equal((submitted as unknown[])[1], '/erp-commercial/invoices/42')
    assert.equal((submitted as unknown[])[3], 'PATCH')
    assert.deepEqual(((submitted as unknown[])[2] as { items: unknown[] }).items, [
      { description: 'Linha zero', amount: '0.00', discount: '0.00' },
      { description: 'Serviço', amount: '100.00', discount: '0.00' }
    ])
  })

  it('creates a draft with POST using an exact zero-value amount', async (t) => {
    let submitted: unknown
    const mounted = mountPage('pages/gestao-erp/faturas/[id]/index.vue', {
      api: async path => path.endsWith('/contracts')
        ? { data: [{ id: 3, client: { name: 'Cliente' }, agreement_status: 'effective' }] }
        : { data: {
            id: 21, contract_id: 3, kind: 'manual', document_status: 'draft', number: null, due_on: '2026-10-01',
            period_start: '2026-10-01', period_end: '2026-10-31', items_snapshot: [{ description: 'Serviço gratuito', amount: '0.00', discount: '0.00' }],
            adjustments: [], balance: { original_total: '0.00', adjusted_charge: '0.00', effective_received: '0.00', outstanding: '0.00' },
            seller_snapshot: { account: { name: 'Escritório' } }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null }
          } },
      execute: async (...args) => {
        submitted = args
        return { status: 'confirmed', data: { id: 21 } }
      }
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const contract = mounted.find(node => node.tag === 'select')
    assert.ok(contract)
    ;(contract.props.onChange as (event: { target: { value: string } }) => void)({ target: { value: '3' } })
    const textInputs = mounted.all(node => node.tag === 'input' && node.props.type === 'text')
    assert.equal(textInputs.length, 3)
    setNodeInput(textInputs[0]!, 'Serviço gratuito')
    setNodeInput(textInputs[1]!, '0,00')
    setNodeInput(textInputs[2]!, '0,00')
    for (const node of mounted.all(candidate => candidate.tag === 'input' && candidate.props.type === 'date')) setNodeInput(node, '2026-10-01')
    const save = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Salvar rascunho'))
    assert.ok(save)
    ;(save.props.onClick as () => void)()
    await settlePage()
    assert.equal((submitted as unknown[])[1], '/erp-commercial/invoices')
    assert.equal((submitted as unknown[])[3], 'POST')
    assert.deepEqual(((submitted as unknown[])[2] as { items: unknown[] }).items, [{ description: 'Serviço gratuito', amount: '0.00', discount: '0.00' }])
  })

  it('sends separate explicit issue and reasoned cancel commands from the rendered invoice page', async (t) => {
    const invoice = {
      id: 42, contract_id: 3, kind: 'manual', document_status: 'draft', number: null, due_on: '2026-11-01',
      period_start: '2026-10-01', period_end: '2026-10-31', items_snapshot: [{ description: 'Serviço', amount: '100.00', discount: '0.00' }],
      adjustments: [], balance: { original_total: '100.00', adjusted_charge: '0.00', effective_received: '0.00', outstanding: '0.00' },
      seller_snapshot: { account: { name: 'Escritório' } }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null }
    }
    const submitted: unknown[][] = []
    const mounted = mountPage('pages/gestao-erp/faturas/[id]/index.vue', {
      params: { id: '42' }, api: async () => ({ data: invoice }),
      execute: async (...args) => {
        submitted.push(args)
        return { status: 'confirmed', data: invoice }
      }
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const issue = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Emitir fatura'))
    assert.ok(issue)
    ;(issue.props.onClick as () => void)()
    await settlePage()
    assert.deepEqual(submitted[0]?.slice(0, 4), ['invoice.issue', '/erp-commercial/invoices/42/issue', {}, 'POST'])
    const cancelTrigger = mounted.find(node => node.tag === 'button' && mounted.text(node).trim() === 'Cancelar')
    assert.ok(cancelTrigger)
    ;(cancelTrigger.props.onClick as () => void)()
    await settlePage()
    const reason = mounted.find(node => node.tag === 'textarea')
    assert.ok(reason)
    setNodeInput(reason, 'Correção solicitada')
    const confirm = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Confirmar cancelamento'))
    assert.ok(confirm)
    ;(confirm.props.onClick as () => void)()
    await settlePage()
    assert.deepEqual(submitted[1]?.slice(0, 3), ['invoice.correct', '/erp-commercial/invoices/42/cancel', { reason: 'Correção solicitada' }])
    assert.equal(mounted.find(node => node.props.role === 'dialog'), undefined)
    assert.ok(mounted.find(node => node.tag === 'button' && mounted.text(node).trim() === 'Cancelar'), 'the dialog trigger remains mounted after dismissal')
  })

  it('preserves adjustment inputs and server errors after a rendered 422 response', async (t) => {
    const invoice = {
      id: 42, contract_id: 3, kind: 'manual', document_status: 'issued', number: 'F-42', due_on: '2026-11-01',
      period_start: '2026-10-01', period_end: '2026-10-31', items_snapshot: [{ description: 'Serviço', amount: '100.00', discount: '0.00' }],
      adjustments: [], balance: { original_total: '100.00', adjusted_charge: '100.00', effective_received: '0.00', outstanding: '100.00' },
      seller_snapshot: { account: { name: 'Escritório' } }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null }
    }
    let failure = { message: 'Falha de validação', errors: { amount: ['Valor indisponível'] } }
    const mounted = mountPage('pages/gestao-erp/faturas/[id]/index.vue', {
      params: { id: '42' }, api: async () => ({ data: invoice }),
      execute: async () => ({ status: 'failed', failure })
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const forms = mounted.all(node => node.props['data-office-erp-adjustment-form'] !== undefined)
    assert.equal(forms.length, 2)
    const amount = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Valor do ajuste')
    assert.ok(amount)
    setNodeInput(amount, '25,00')
    const reason = mounted.find(node => node.tag === 'textarea' && node.props['aria-label'] === 'Motivo')
    assert.ok(reason)
    setNodeInput(reason, 'Ajuste')
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    assert.equal(mounted.commands.length, 1)
    const retained = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Valor do ajuste')
    assert.equal(retained?.props.value, '25,00')
    assert.match(mounted.text(mounted.root), /Valor indisponível/)
    failure = { message: 'A fatura foi atualizada em outra sessão.', errors: {} }
    const retry = mounted.find(node => node.tag === 'form')
    assert.ok(retry)
    ;(retry.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    assert.equal(mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Valor do ajuste')?.props.value, '25,00')
    assert.match(mounted.text(mounted.root), /atualizada em outra sessão/)
  })

  it('waits for proof upload, then lets the next receipt omit proof after the first succeeds', async (t) => {
    const invoice = { id: 7, number: 'F-7', document_status: 'issued', balance: { outstanding: '100.00' }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null } }
    const receiptCalls: unknown[][] = []
    let finishUpload!: (value: unknown) => void
    const upload = new Promise((resolve) => {
      finishUpload = resolve
    })
    const mounted = mountPage('pages/gestao-erp/recebimentos.vue', {
      api: async (path, options) => {
        if (path === '/erp-commercial/receipts') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
        if (path === '/erp-commercial/invoices') return { data: [invoice], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
        if (path === '/erp-commercial/receipt-proofs') {
          assert.equal(options?.method, 'POST')
          assert.ok(options?.body instanceof FormData)
          return upload
        }
        throw new Error(`Unexpected API request: ${path}`)
      },
      execute: async (...args) => {
        receiptCalls.push(args)
        return { status: 'confirmed', data: { id: 12 } }
      }
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const open = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Registrar recebimento'))
    assert.ok(open)
    ;(open.props.onClick as () => void)()
    await settlePage()
    const choice = mounted.find(node => node.props['data-choice'] === '7')
    assert.ok(choice)
    ;(choice.props.onClick as () => void)()
    await settlePage()
    const allocation = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Alocação para fatura 7')
    assert.ok(allocation, mounted.text(mounted.root))
    setNodeInput(allocation, '40,00')
    const date = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Data do recebimento')
    assert.ok(date)
    setNodeInput(date, '2026-10-25')
    const fileInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')
    assert.ok(fileInput)
    const proof = new File(['%PDF-1.7\nfixture'], 'comprovante.pdf', { type: 'application/pdf' })
    ;(fileInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [proof] } })
    await settlePage()
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    assert.equal(receiptCalls.length, 0, 'no receipt command can race ahead of its proof upload')
    finishUpload({ data: { id: 'proof-123' } })
    await settlePage()
    const nextReceiptForm = mounted.find(node => node.tag === 'form')
    assert.ok(nextReceiptForm)
    ;(nextReceiptForm.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    assert.deepEqual(receiptCalls[0]?.slice(0, 3), [
      'receipt.record', '/erp-commercial/receipts',
      { amount: '40.00', received_on: '2026-10-25', allocations: [{ invoice_id: 7, amount: '40.00' }], proof_id: 'proof-123' }
    ])
    const reopen = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Registrar recebimento'))
    assert.ok(reopen)
    ;(reopen.props.onClick as () => void)()
    await settlePage()
    const refreshedForm = mounted.find(node => node.tag === 'form')
    assert.ok(refreshedForm)
    ;(refreshedForm.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    assert.equal(receiptCalls.length, 2, 'a confirmed proof receipt does not make proof mandatory for the next receipt')
    assert.deepEqual(receiptCalls[1]?.[2], { amount: '40.00', received_on: '2026-10-25', allocations: [{ invoice_id: 7, amount: '40.00' }] })
  })

  it('keeps generic restored command recovery outside the receipt modal and disables duplicate lookups while busy', async (t) => {
    let finishRecovery!: (value: unknown) => void
    const recovery = new Promise((resolve) => {
      finishRecovery = resolve
    })
    const mounted = mountPage('pages/gestao-erp/recebimentos.vue', {
      recoveryKey: '11111111-2222-4333-8444-555555555555', pending: true,
      api: async path => path === '/erp-commercial/invoices'
        ? { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
        : { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } },
      recover: async () => recovery
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    assert.equal(mounted.find(node => node.tag === 'section' && node.props.role === 'dialog'), undefined, 'an untyped global command key must not open the receipt modal')
    const lookup = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Consultar operação'))
    assert.ok(lookup)
    assert.equal(lookup.props.disabled, false)
    ;(lookup.props.onClick as () => void)()
    await settlePage()
    assert.equal(lookup.props.disabled, true, 'busy state prevents a duplicate recovery request')
    finishRecovery({ status: 'failed', failure: { message: 'Ainda não confirmado', errors: {} } })
    await settlePage()
    assert.equal(lookup.props.disabled, false)
  })

  it('clears an earlier staged proof when a replacement upload fails', async (t) => {
    const invoice = { id: 7, number: 'F-7', document_status: 'issued', balance: { outstanding: '100.00' }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null } }
    let uploadCount = 0
    const receipts: unknown[][] = []
    const mounted = mountPage('pages/gestao-erp/recebimentos.vue', {
      api: async (path) => {
        if (path === '/erp-commercial/invoices') return { data: [invoice], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
        if (path === '/erp-commercial/receipt-proofs') {
          uploadCount++
          if (uploadCount === 1) return { data: { id: 'first-proof' } }
          throw new Error('Falha no comprovante substituto')
        }
        return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
      },
      execute: async (...args) => {
        receipts.push(args)
        return { status: 'confirmed', data: { id: 12 } }
      }
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const open = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Registrar recebimento'))
    assert.ok(open)
    ;(open.props.onClick as () => void)()
    await settlePage()
    const choice = mounted.find(node => node.props['data-choice'] === '7')
    assert.ok(choice)
    ;(choice.props.onClick as () => void)()
    await settlePage()
    const amount = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Alocação para fatura 7')
    assert.ok(amount)
    setNodeInput(amount, '10,00')
    const fileInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')
    assert.ok(fileInput)
    const first = new File(['one'], 'first.pdf', { type: 'application/pdf' })
    ;(fileInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [first] } })
    await settlePage()
    const replacement = new File(['two'], 'replacement.pdf', { type: 'application/pdf' })
    ;(fileInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [replacement] } })
    await settlePage()
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    assert.equal(receipts.length, 0, 'the old private proof cannot be reused after its replacement fails')
    assert.match(mounted.text(mounted.root), /Envie novamente o comprovante/)
  })

  it('clears an earlier staged proof when the replacement file is rejected locally', async (t) => {
    const invoice = { id: 7, number: 'F-7', document_status: 'issued', balance: { outstanding: '100.00' }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null } }
    const receipts: unknown[][] = []
    const mounted = mountPage('pages/gestao-erp/recebimentos.vue', {
      api: async (path) => {
        if (path === '/erp-commercial/invoices') return { data: [invoice], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
        if (path === '/erp-commercial/receipt-proofs') return { data: { id: 'first-proof' } }
        return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
      },
      execute: async (...args) => {
        receipts.push(args)
        return { status: 'confirmed', data: { id: 12 } }
      }
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const open = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Registrar recebimento'))
    assert.ok(open)
    ;(open.props.onClick as () => void)()
    await settlePage()
    const choice = mounted.find(node => node.props['data-choice'] === '7')
    assert.ok(choice)
    ;(choice.props.onClick as () => void)()
    await settlePage()
    const amount = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Alocação para fatura 7')
    assert.ok(amount)
    setNodeInput(amount, '10,00')
    const fileInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')
    assert.ok(fileInput)
    const first = new File(['one'], 'first.pdf', { type: 'application/pdf' })
    ;(fileInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [first] } })
    await settlePage()
    const invalid = new File(['invalid'], 'replacement.txt', { type: 'text/plain' })
    ;(fileInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [invalid] } })
    await settlePage()
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    assert.equal(receipts.length, 0, 'a locally rejected file cannot leave the previously staged proof attached')
    assert.match(mounted.text(mounted.root), /Envie novamente o comprovante/)
  })

  it('shows only the reversal recovery modal for an unknown receipt reversal', async (t) => {
    const receipt = { id: 31, amount: '25.00', received_on: '2026-10-20', reference: null, proof_id: null, allocations: [{ invoice_id: 7, amount: '25.00' }], reversal: null }
    const mounted = mountPage('pages/gestao-erp/recebimentos.vue', {
      api: async path => path === '/erp-commercial/invoices'
        ? { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
        : { data: [receipt], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } },
      execute: async () => ({ status: 'unknown', key: '11111111-2222-4333-8444-555555555555' })
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const reverse = mounted.find(node => node.tag === 'button' && mounted.text(node).trim() === 'Reverter')
    assert.ok(reverse)
    ;(reverse.props.onClick as () => void)()
    await settlePage()
    const reason = mounted.find(node => node.tag === 'textarea' && node.props['aria-label'] === 'Motivo da reversão')
    assert.ok(reason)
    setNodeInput(reason, 'Correção interna')
    const submit = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Confirmar reversão'))
    assert.ok(submit)
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    const dialogs = mounted.all(node => node.tag === 'section' && node.props.role === 'dialog')
    assert.equal(dialogs.length, 1)
    assert.equal(dialogs[0]?.props['aria-label'], 'Reverter recebimento')
    assert.equal(mounted.all(node => node.tag === 'section' && node.props.role === 'dialog' && node.props['aria-label'] === 'Registrar recebimento').length, 0)
    assert.match(mounted.text(dialogs[0]!), /Consultar operação/)
  })

  it('routes a recovered new-draft operation to its confirmed invoice without a second create', async (t) => {
    let finishRecovery!: (value: unknown) => void
    const recovery = new Promise((resolve) => {
      finishRecovery = resolve
    })
    const createCalls: unknown[][] = []
    const mounted = mountPage('pages/gestao-erp/faturas/[id]/index.vue', {
      recoveryKey: '11111111-2222-4333-8444-555555555555', pending: true,
      api: async path => path === '/erp-commercial/contracts'
        ? { data: [{ id: 3, client: { name: 'Cliente' }, agreement_status: 'effective' }] }
        : { data: {
            id: 77, contract_id: 3, kind: 'manual', document_status: 'draft', number: null, due_on: '2026-11-01', period_start: '2026-10-01', period_end: '2026-10-31',
            items_snapshot: [{ description: 'Serviço', amount: '100.00', discount: '0.00' }], adjustments: [], balance: { original_total: '100.00', adjusted_charge: '0.00', effective_received: '0.00', outstanding: '0.00' },
            seller_snapshot: { account: { name: 'Escritório' } }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null }
          } },
      execute: async (...args) => {
        createCalls.push(args)
        return { status: 'confirmed', data: { id: 88 } }
      },
      recover: async () => recovery
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const lookup = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Consultar operação'))
    assert.ok(lookup)
    ;(lookup.props.onClick as () => void)()
    await settlePage()
    const saveWhileResolving = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Salvar rascunho'))
    assert.ok(saveWhileResolving)
    assert.equal(saveWhileResolving.props.disabled, true)
    finishRecovery({ status: 'confirmed', data: { id: 77 } })
    await settlePage()
    assert.equal(mounted.route.params.id, '77')
    assert.ok(mounted.calls.some(call => call.path === '/erp-commercial/invoices/77'))
    assert.equal(createCalls.length, 0)
    const issue = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Emitir fatura'))
    assert.ok(issue)
    ;(issue.props.onClick as () => void)()
    await settlePage()
    assert.deepEqual(createCalls[0]?.slice(0, 2), ['invoice.issue', '/erp-commercial/invoices/77/issue'])
  })

  it('clears the used proof after recovering a confirmed receipt before the next receipt', async (t) => {
    const invoice = { id: 7, number: 'F-7', document_status: 'issued', balance: { outstanding: '100.00' }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null } }
    const commands: unknown[][] = []
    const mounted = mountPage('pages/gestao-erp/recebimentos.vue', {
      api: async (path) => {
        if (path === '/erp-commercial/invoices') return { data: [invoice], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
        if (path === '/erp-commercial/receipt-proofs') return { data: { id: 'proof-used-by-confirmed-receipt' } }
        return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
      },
      execute: async (...args) => {
        commands.push(args)
        return commands.length === 1
          ? { status: 'unknown', key: '11111111-2222-4333-8444-555555555555' }
          : { status: 'confirmed', data: { id: 18 } }
      },
      recover: async () => ({ status: 'confirmed', data: { id: 18, proof_id: 'proof-used-by-confirmed-receipt' } })
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const open = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Registrar recebimento'))
    assert.ok(open)
    ;(open.props.onClick as () => void)()
    await settlePage()
    const choice = mounted.find(node => node.props['data-choice'] === '7')
    assert.ok(choice)
    ;(choice.props.onClick as () => void)()
    await settlePage()
    const allocation = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Alocação para fatura 7')
    assert.ok(allocation)
    setNodeInput(allocation, '25,00')
    const fileInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')
    assert.ok(fileInput)
    const proof = new File(['proof'], 'recibo-confirmado.pdf', { type: 'application/pdf' })
    ;(fileInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [proof] } })
    await settlePage()
    const firstForm = mounted.find(node => node.tag === 'form')
    assert.ok(firstForm)
    ;(firstForm.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    assert.equal((commands[0]?.[2] as { proof_id?: string }).proof_id, 'proof-used-by-confirmed-receipt')
    const lookup = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Consultar operação'))
    assert.ok(lookup)
    ;(lookup.props.onClick as () => void)()
    await settlePage()
    const reopen = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Registrar recebimento'))
    assert.ok(reopen)
    ;(reopen.props.onClick as () => void)()
    await settlePage()
    assert.doesNotMatch(mounted.text(mounted.root), /recibo-confirmado\.pdf|proof-used-by-confirmed-receipt/)
    const nextForm = mounted.find(node => node.tag === 'form')
    assert.ok(nextForm)
    ;(nextForm.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await settlePage()
    assert.equal(commands.length, 2)
    const nextPayload = commands[1]?.[2] as { amount: string, received_on: string, allocations: unknown[], proof_id?: string }
    assert.equal(nextPayload.amount, '25.00')
    assert.match(nextPayload.received_on, /^\d{4}-\d{2}-\d{2}$/)
    assert.deepEqual(nextPayload.allocations, [{ invoice_id: 7, amount: '25.00' }])
    assert.equal(nextPayload.proof_id, undefined)
  })

  it('fences a late invoice command completion after the selected account changes', async (t) => {
    const invoice = {
      id: 42, contract_id: 3, kind: 'manual', document_status: 'draft', number: null, due_on: '2026-11-01',
      period_start: '2026-10-01', period_end: '2026-10-31', items_snapshot: [{ description: 'Serviço', amount: '100.00', discount: '0.00' }],
      adjustments: [], balance: { original_total: '100.00', adjusted_charge: '0.00', effective_received: '0.00', outstanding: '0.00' },
      seller_snapshot: { account: { name: 'Escritório' } }, bill_to_snapshot: { client: { name: 'Cliente anterior' }, company: null }
    }
    let finishCommand!: (value: unknown) => void
    let invoiceLoads = 0
    const commandResult = new Promise((resolve) => {
      finishCommand = resolve
    })
    const mounted = mountPage('pages/gestao-erp/faturas/[id]/index.vue', {
      params: { id: '42' }, api: async () => {
        invoiceLoads++
        if (invoiceLoads > 1) throw new Error('Outra conta')
        return { data: invoice }
      },
      execute: async () => commandResult
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const save = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Salvar rascunho'))
    assert.ok(save)
    ;(save.props.onClick as () => void)()
    await settlePage()
    mounted.account.value = { id: 2, status: 'active', role: 'admin' }
    await settlePage()
    finishCommand({ status: 'failed', failure: { message: 'Resultado antigo', errors: {} } })
    await settlePage()
    assert.doesNotMatch(mounted.text(mounted.root), /Cliente anterior|Resultado antigo/)
    assert.match(mounted.text(mounted.root), /Outra conta/)
  })

  it('discards a private proof upload callback when the active account changes', async (t) => {
    let finishUpload!: (value: unknown) => void
    const upload = new Promise((resolve) => {
      finishUpload = resolve
    })
    const invoice = { id: 7, number: 'F-7', document_status: 'issued', balance: { outstanding: '100.00' }, bill_to_snapshot: { client: { name: 'Cliente' }, company: null } }
    const mounted = mountPage('pages/gestao-erp/recebimentos.vue', {
      api: async (path) => {
        if (path === '/erp-commercial/receipt-proofs') return upload
        if (path === '/erp-commercial/invoices') return { data: [invoice], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } }
        return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
      }
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const open = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Registrar recebimento'))
    assert.ok(open)
    ;(open.props.onClick as () => void)()
    await settlePage()
    const fileInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')
    assert.ok(fileInput)
    const proof = new File(['%PDF-1.7\nfixture'], 'conta-anterior.pdf', { type: 'application/pdf' })
    ;(fileInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [proof] } })
    await settlePage()
    mounted.account.value = { id: 2, status: 'active', role: 'admin' }
    await settlePage()
    finishUpload({ data: { id: 'old-proof' } })
    await settlePage()
    const reopened = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Registrar recebimento'))
    assert.ok(reopened)
    ;(reopened.props.onClick as () => void)()
    await settlePage()
    assert.doesNotMatch(mounted.text(mounted.root), /conta-anterior\.pdf|old-proof/)
  })

  it('loads the next receipt page through the rendered pagination control', async (t) => {
    const pageRequests: number[] = []
    const mounted = mountPage('pages/gestao-erp/recebimentos.vue', {
      api: async (path, options) => {
        if (path === '/erp-commercial/receipts') {
          pageRequests.push(Number((options?.params as Record<string, unknown>)?.page))
          return { data: [], meta: { current_page: Number((options?.params as Record<string, unknown>)?.page), last_page: 2, per_page: 25, total: 30 } }
        }
        return { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }
      }
    })
    t.after(() => mounted.app.unmount())
    await settlePage()
    const next = mounted.find(node => node.tag === 'button' && node.props['aria-label'] === 'Próxima página')
    assert.ok(next)
    ;(next.props.onClick as () => void)()
    await settlePage()
    assert.deepEqual(pageRequests, [1, 2])
  })

  it('prints only a loaded issued invoice and keeps protected print content absent on errors', async (t) => {
    const originalWindow = (globalThis as { window?: unknown }).window
    let printCalls = 0
    ;(globalThis as { window?: unknown }).window = { print: () => {
      printCalls++
    } }
    t.after(() => {
      if (originalWindow === undefined) delete (globalThis as { window?: unknown }).window
      else (globalThis as { window?: unknown }).window = originalWindow
    })
    const invoice = {
      id: 42, number: 'F-42', document_status: 'issued', issued_at: '2026-10-01T12:00:00Z', period_start: '2026-10-01', period_end: '2026-10-31', due_on: '2026-11-01',
      items_snapshot: [{ description: 'Serviço', amount: '100.00', discount: '0.00' }], seller_snapshot: { account: { name: 'Escritório' } },
      bill_to_snapshot: { client: { name: 'Cliente', tax_id: '12345678901' }, company: null }, balance: { original_total: '100.00', adjusted_charge: '100.00', effective_received: '0.00', outstanding: '100.00' }, adjustments: []
    }
    const success = mountPage('pages/gestao-erp/faturas/[id]/imprimir.vue', { params: { id: '42' }, api: async () => ({ data: invoice }) })
    t.after(() => success.app.unmount())
    await settlePage()
    const print = success.find(node => node.tag === 'button' && success.text(node).includes('Imprimir ou salvar em PDF'))
    assert.ok(print)
    assert.equal(print.props.disabled, false)
    ;(print.props.onClick as () => void)()
    await settlePage()
    assert.equal(printCalls, 1)
    const failed = mountPage('pages/gestao-erp/faturas/[id]/imprimir.vue', {
      params: { id: '42' }, api: async () => {
        throw new Error('Acesso indisponível')
      }
    })
    t.after(() => failed.app.unmount())
    await settlePage()
    assert.equal(failed.find(node => node.tag === 'article'), undefined)
    const unavailablePrint = failed.find(node => node.tag === 'button' && failed.text(node).includes('Imprimir ou salvar em PDF'))
    assert.ok(unavailablePrint)
    assert.equal(unavailablePrint.props.disabled, true)
    assert.equal(printCalls, 1)
  })

  it('compiles the invoice list, detail, protected print and receipt pages as real Vue SFCs', () => {
    const mocks = {
      '#shared/utils/officeErpCommercial': { parseOfficeErpMoney, formatOfficeErpMoney },
      '~/components/data-table/panel': {
        panelBodyClass: '', panelFooterClass: '', panelFooterCountClass: '',
        panelPaginationClass: '', panelTableUi: {}
      },
      '~/composables/useApiError': { apiErrorMessage: () => undefined }
    }
    for (const path of [
      'pages/gestao-erp/faturas/index.vue',
      'pages/gestao-erp/faturas/[id]/index.vue',
      'pages/gestao-erp/faturas/[id]/imprimir.vue',
      'pages/gestao-erp/recebimentos.vue'
    ]) assert.ok(compileVueComponent(path, {}, mocks), path)
  })

  it('queries invoices by issue and due dates and renders the print route in a shell-free layout', async (t) => {
    const mounted = mountPage('pages/gestao-erp/faturas/index.vue', {
      api: async (_path, request) => ({ data: [], meta: { current_page: Number((request?.params as Record<string, unknown>).page), last_page: 2, per_page: 25, total: 50 } })
    })
    t.after(() => mounted.app.unmount())
    await settlePage()

    const nextPage = mounted.find(node => node.tag === 'button' && node.props['aria-label'] === 'Próxima página')
    assert.ok(nextPage)
    ;(nextPage.props.onClick as () => void)()
    await settlePage()
    const pageTwoRequest = mounted.calls.filter(call => call.path === '/erp-commercial/invoices').at(-1)
    assert.equal((pageTwoRequest?.options?.params as Record<string, unknown> | undefined)?.page, 2)

    input(mounted, 'Emissão de', '2026-10-01')
    input(mounted, 'Emissão até', '2026-10-31')
    input(mounted, 'Vencimento de', '2026-11-01')
    input(mounted, 'Vencimento até', '2026-11-30')
    await settlePage()

    const request = mounted.calls.filter(call => call.path === '/erp-commercial/invoices').at(-1)
    assert.deepEqual(request?.options?.params, {
      page: 1,
      per_page: 25,
      from_on: '2026-10-01',
      to_on: '2026-10-31',
      due_from: '2026-11-01',
      due_to: '2026-11-30'
    })
    assert.equal(mounted.metadata.at(-1)?.layout, undefined)

    const print = mountPage('pages/gestao-erp/faturas/[id]/imprimir.vue', { params: { id: '42' } })
    t.after(() => print.app.unmount())
    await settlePage()
    assert.equal(print.metadata.at(-1)?.layout, 'office-erp-print')
    assert.ok(compileVueComponent('layouts/office-erp-print.vue'))

    const printShell = mountPage('pages/gestao-erp.vue', { routePath: '/gestao-erp/faturas/42/imprimir' })
    t.after(() => printShell.app.unmount())
    assert.ok(printShell.find(node => node.props['data-route-page'] === 'true'))
    assert.equal(printShell.find(node => node.props['data-shell'] === 'dashboard-panel'), undefined)
    assert.equal(printShell.find(node => node.props['data-shell'] === 'dashboard-navbar'), undefined)
    assert.equal(printShell.find(node => node.props['data-shell'] === 'dashboard-toolbar'), undefined)
    assert.equal(printShell.find(node => node.props['data-shell'] === 'navigation-menu'), undefined)

    const officeShell = mountPage('pages/gestao-erp.vue', { routePath: '/gestao-erp/faturas' })
    t.after(() => officeShell.app.unmount())
    assert.ok(officeShell.find(node => node.props['data-shell'] === 'dashboard-panel'))
    assert.ok(officeShell.find(node => node.props['data-shell'] === 'dashboard-navbar'))
    assert.ok(officeShell.find(node => node.props['data-shell'] === 'dashboard-toolbar'))
    const navigation = officeShell.find(node => node.props['data-shell'] === 'navigation-menu')
    assert.ok(navigation)
    assert.match(String(navigation.props['data-items']), /"label":"Faturas"/)
    assert.match(String(navigation.props['data-items']), /"label":"Recebimentos"/)
    assert.match(String(navigation.props['data-items']), /"label":"Configurações"/)
  })

  it('emits a positive credit amount and preserves it with a server validation error', async (t) => {
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpAdjustmentForm', { direction: 'credit', errors: {}, onSubmit: (payload: unknown) => submitted.push(payload) })
    t.after(() => mounted.app.unmount())
    const amount = input(mounted, 'Valor do ajuste', '60,00')
    const reason = mounted.find(node => node.tag === 'textarea' && node.props['aria-label'] === 'Motivo')
    assert.ok(reason)
    ;(reason.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Correção de cobrança' } })
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(submitted[0], { direction: 'credit', amount: '60.00', reason: 'Correção de cobrança' })
    mounted.props.errors = { amount: ['O valor excede o saldo disponível.'] }
    await Vue.nextTick()
    assert.equal(amount.props.value, '60,00', 'a server validation error keeps the visible amount draft')
    assert.match(mounted.text(mounted.root), /O valor excede o saldo disponível/)
    assert.equal(parseOfficeErpMoney('9.007.199.254.740.993,01'), '9007199254740993.01')
  })

  it('emits partial multi-invoice allocations as canonical string amounts', async (t) => {
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', {
      open: true,
      invoices: [{ id: 7, label: 'Fatura 7', outstanding: '100.00' }, { id: 8, label: 'Fatura 8', outstanding: '12.50' }],
      onSubmit: (payload: unknown) => submitted.push(payload)
    })
    t.after(() => mounted.app.unmount())
    const choice = mounted.find(node => node.props['data-choice'] === '7')
    assert.ok(choice)
    ;(choice.props.onClick as () => void)()
    await Vue.nextTick()
    input(mounted, 'Alocação para fatura 7', '40,00')
    const receivedOn = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Data do recebimento')
    assert.ok(receivedOn)
    ;(receivedOn.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '2026-10-25' } })
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(submitted[0], { amount: '40.00', received_on: '2026-10-25', allocations: [{ invoice_id: 7, amount: '40.00' }] })
    assert.match(mounted.text(mounted.root), /manual|manualmente/i)
  })

  it('sums allocations across selected invoices with integer cents and enforces each outstanding balance', async (t) => {
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', {
      open: true,
      invoices: [{ id: 7, label: 'Fatura 7', outstanding: '40.00' }, { id: 8, label: 'Fatura 8', outstanding: '12.50' }],
      onSubmit: (payload: unknown) => submitted.push(payload)
    })
    t.after(() => mounted.app.unmount())
    for (const id of ['7', '8']) {
      const choice = mounted.find(node => node.props['data-choice'] === id)
      assert.ok(choice)
      ;(choice.props.onClick as () => void)()
      await Vue.nextTick()
    }
    input(mounted, 'Alocação para fatura 7', '40.00')
    input(mounted, 'Alocação para fatura 8', '2,50')
    const date = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Data do recebimento')
    assert.ok(date)
    ;(date.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '2026-10-25' } })
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(submitted[0], {
      amount: '42.50',
      received_on: '2026-10-25',
      allocations: [{ invoice_id: 7, amount: '40.00' }, { invoice_id: 8, amount: '2.50' }]
    })
    input(mounted, 'Alocação para fatura 7', '40.01')
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await Vue.nextTick()
    assert.equal(submitted.length, 1, 'allocation above the selected invoice balance is rejected locally')
    assert.match(mounted.text(mounted.root), /excede o saldo em aberto/i)
  })

  it('attaches indexed server allocation errors to the invoice in payload order after reverse selection', async (t) => {
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', {
      open: true,
      invoices: [{ id: 7, label: 'Fatura 7', outstanding: '100.00' }, { id: 8, label: 'Fatura 8', outstanding: '100.00' }],
      onSubmit: (payload: unknown) => submitted.push(payload)
    })
    t.after(() => mounted.app.unmount())
    for (const id of ['8', '7']) {
      const choice = mounted.find(node => node.props['data-choice'] === id)
      assert.ok(choice)
      ;(choice.props.onClick as () => void)()
      await Vue.nextTick()
    }
    input(mounted, 'Alocação para fatura 7', '40.00')
    input(mounted, 'Alocação para fatura 8', '30.00')
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual((submitted[0] as { allocations: unknown[] }).allocations, [
      { invoice_id: 7, amount: '40.00' }, { invoice_id: 8, amount: '30.00' }
    ])

    mounted.props.errors = { 'allocations.0.amount': ['Saldo atualizado da fatura 7.'] }
    await Vue.nextTick()
    const invoice7 = mounted.find(node => node.tag === 'label' && node.props['data-field'] === 'allocations.7.amount')
    const invoice8 = mounted.find(node => node.tag === 'label' && node.props['data-field'] === 'allocations.8.amount')
    assert.ok(invoice7)
    assert.ok(invoice8)
    assert.match(mounted.text(invoice7), /Saldo atualizado da fatura 7/)
    assert.doesNotMatch(mounted.text(invoice8), /Saldo atualizado da fatura 7/)
  })

  it('emits a grouped amount above the safe integer range unchanged from the real receipt SFC', async (t) => {
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', {
      open: true,
      invoices: [{ id: 81, label: 'Fatura 81', outstanding: '9007199254740994.00' }],
      onSubmit: (payload: unknown) => submitted.push(payload)
    })
    t.after(() => mounted.app.unmount())
    const choice = mounted.find(node => node.props['data-choice'] === '81')
    assert.ok(choice)
    ;(choice.props.onClick as () => void)()
    await Vue.nextTick()
    const amount = input(mounted, 'Alocação para fatura 81', '9.007.199.254.740.993,01')
    await Vue.nextTick()
    assert.equal(amount.props.value, '9.007.199.254.740.993,01')
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.equal((submitted[0] as { amount: string }).amount, '9007199254740993.01')
    assert.deepEqual((submitted[0] as { allocations: unknown[] }).allocations, [{ invoice_id: 81, amount: '9007199254740993.01' }])
  })

  it('keeps recovery available while a receipt command is unknown and blocks resubmission', async (t) => {
    const recovered: string[] = []
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', {
      open: true, busy: false, pending: true, unknown: true, recoveryKey: '11111111-2222-4333-8444-555555555555', invoices: [],
      onRecover: (key: string) => recovered.push(key), onSubmit: (payload: unknown) => submitted.push(payload)
    })
    t.after(() => mounted.app.unmount())
    const recover = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Consultar operação'))
    assert.ok(recover)
    assert.equal(recover.props.disabled, false)
    mounted.props.busy = true
    await Vue.nextTick()
    assert.equal(recover.props.disabled, true, 'a recovery already in progress prevents duplicate clicks')
    mounted.props.busy = false
    await Vue.nextTick()
    assert.equal(recover.props.disabled, false, 'pending and unknown state do not disable recovery')
    ;(recover.props.onClick as () => void)()
    assert.deepEqual(recovered, ['11111111-2222-4333-8444-555555555555'])
    const submit = mounted.find(node => node.tag === 'form')
    assert.equal(submit, undefined, 'unknown command must not render an editable submit form')
    assert.deepEqual(submitted, [])
  })

  it('disables adjustment and reversal recovery only while a recovery request is busy', async (t) => {
    const recoveryKey = '11111111-2222-4333-8444-555555555555'
    const scenarios = [
      { name: 'OfficeErpAdjustmentForm' as const, props: { direction: 'credit' as const, unknown: true, recoveryKey } },
      { name: 'OfficeErpReversalModal' as const, props: { open: true, unknown: true, recoveryKey } }
    ]
    const mounts: ReturnType<typeof mountComponent>[] = []
    t.after(() => mounts.forEach(mounted => mounted.app.unmount()))

    for (const scenario of scenarios) {
      const recovered: string[] = []
      const mounted = mountComponent(scenario.name, { ...scenario.props, onRecover: (key: string) => recovered.push(key) })
      mounts.push(mounted)
      const recover = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Consultar operação'))
      assert.ok(recover)
      assert.equal(recover.props.disabled, false, `${scenario.name} remains recoverable while unknown`)
      mounted.props.busy = true
      await Vue.nextTick()
      assert.equal(recover.props.disabled, true, `${scenario.name} blocks duplicate recovery while busy`)
      mounted.props.busy = false
      await Vue.nextTick()
      ;(recover.props.onClick as () => void)()
      assert.deepEqual(recovered, [recoveryKey])
    }
  })

  it('validates private proof MIME and 5 MiB size before emitting a separate upload intent', async (t) => {
    const uploads: File[] = []
    const selections: File[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', { open: true, invoices: [], onProofSelection: (file: File) => selections.push(file), onUploadProof: (file: File) => uploads.push(file) })
    t.after(() => mounted.app.unmount())
    const fileInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')
    assert.ok(fileInput)
    assert.equal(fileInput.props.accept, '.pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg')
    const tooLarge = new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'grande.pdf', { type: 'application/pdf' })
    ;(fileInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [tooLarge] } })
    await Vue.nextTick()
    assert.match(mounted.text(mounted.root), /no máximo 5 MiB/i)
    const unsupported = new File(['text'], 'comprovante.txt', { type: 'text/plain' })
    const retryInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')!
    ;(retryInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [unsupported] } })
    await Vue.nextTick()
    assert.match(mounted.text(mounted.root), /Envie um arquivo PDF, PNG ou JPEG/i)
    const atLimit = new File([new Uint8Array(5 * 1024 * 1024)], 'limite.pdf', { type: 'application/pdf' })
    const boundaryInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')!
    ;(boundaryInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [atLimit] } })
    await Vue.nextTick()
    assert.deepEqual(uploads, [atLimit], 'exactly 5 MiB remains eligible for private upload')
    const valid = new File(['%PDF-1.7\nfixture'], 'comprovante.pdf', { type: 'application/pdf' })
    const refreshedInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')!
    ;(refreshedInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [valid] } })
    await Vue.nextTick()
    assert.deepEqual(uploads, [atLimit, valid])
    assert.deepEqual(selections, [tooLarge, unsupported, atLimit, valid], 'every selection attempt is reported before local validation')
  })

  it('requires a reversal reason and keeps modal dismissal parent-controlled', async (t) => {
    const reversed: unknown[] = []
    const dismissed: boolean[] = []
    const openUpdates: boolean[] = []
    const mounted = mountComponent('OfficeErpReversalModal', { 'open': true, 'onSubmit': (payload: unknown) => reversed.push(payload), 'onCancel': () => dismissed.push(true), 'onUpdate:open': (open: boolean) => openUpdates.push(open) })
    t.after(() => mounted.app.unmount())
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(reversed, [])
    const reason = mounted.find(node => node.tag === 'textarea' && node.props['aria-label'] === 'Motivo da reversão')
    assert.ok(reason)
    ;(reason.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Registro lançado em duplicidade' } })
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(reversed, [{ reason: 'Registro lançado em duplicidade' }])
    const close = mounted.find(node => node.props['aria-label'] === 'Fechar modal')
    assert.ok(close)
    ;(close.props.onClick as () => void)()
    assert.deepEqual(dismissed, [true])
    assert.deepEqual(openUpdates, [false])
  })
})
