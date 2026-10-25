import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type Node = { tag?: string, text?: string, props: Record<string, unknown>, children: Node[], parent?: Node | null }
const testAccount = Vue.ref({ id: 8, status: 'active', role: 'admin' })
const testUser = Vue.ref({ id: 5 })
const sessionGeneration = Vue.ref(2)
const capability = Vue.ref({ capability_key: 'office-capability-1', can_read: true, can_configure: true })

function renderText(node: Node): string {
  return `${node.text ?? ''}${node.children.map(renderText).join('')}`
}

function find(node: Node, predicate: (candidate: Node) => boolean): Node[] {
  return [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => find(child, predicate))]
}

function mount(component: Vue.Component) {
  const root: Node = { props: {}, children: [] }
  const renderer = Vue.createRenderer({
    patchProp(node: Node, key, _previous, next) { node.props[key] = next },
    insert(node: Node, parent: Node, anchor?: Node) {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node: Node) {
      if (!node.parent) return
      const index = node.parent.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
    },
    createElement(tag) { return { tag, props: {}, children: [], parent: null } },
    createText(text) { return { text, props: {}, children: [], parent: null } },
    createComment(text) { return { text, props: {}, children: [], parent: null } },
    setText(node: Node, text) { node.text = text },
    setElementText(node: Node, text) {
      node.children = []
      node.text = text
    },
    parentNode(node: Node) { return node.parent ?? null },
    nextSibling(node: Node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp(component)
  const components: Record<string, Vue.Component> = {
    UButton: { props: ['disabled', 'loading', 'type'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, 'disabled': props.disabled, 'data-loading': props.loading }, context.slots.default?.()) },
    UAlert: { props: ['title', 'description'], setup: (props, context) => () => Vue.h('aside', {}, [String(props.title ?? ''), String(props.description ?? ''), context.slots.default?.(), context.slots.description?.()]) },
    UCard: { setup: (_props, context) => () => Vue.h('section', {}, context.slots.default?.()) },
    UModal: { props: ['open'], setup: (props, context) => () => props.open ? Vue.h('section', { role: 'dialog' }, [context.slots.body?.(), context.slots.footer?.()]) : null },
    UBadge: { setup: (_props, context) => () => Vue.h('span', {}, context.slots.default?.()) },
    UFormField: { props: ['name', 'label', 'error'], setup: (props, context) => () => Vue.h('div', { 'data-field': props.name, 'aria-label': props.label }, [context.slots.default?.(), props.error ? String(props.error) : '']) },
    UInput: { props: ['modelValue', 'type', 'disabled'], setup: (props, context) => () => Vue.h('input', { ...context.attrs, ...props, 'onUpdate:modelValue': context.emit.bind(null, 'update:modelValue') }) },
    USelect: { props: ['modelValue', 'items', 'disabled'], setup: (props, context) => () => Vue.h('select', { ...context.attrs, 'modelValue': props.modelValue, 'disabled': props.disabled, 'onUpdate:modelValue': context.emit.bind(null, 'update:modelValue') }) },
    USwitch: { props: ['modelValue', 'disabled'], setup: (props, context) => () => Vue.h('button', { ...context.attrs, 'role': 'switch', 'aria-checked': String(props.modelValue), 'disabled': props.disabled, 'onUpdate:modelValue': context.emit.bind(null, 'update:modelValue') }) },
    USkeleton: { setup: () => () => Vue.h('div', { 'aria-label': 'Carregando' }) },
    UTable: { props: ['data', 'columns'], setup: props => () => Vue.h('table', {}, (props.data as Array<Record<string, unknown>>).map((row, index) => Vue.h('tr', { key: index }, Object.values(row).map(value => Vue.h('td', {}, String(value)))))) },
    UPagination: { props: ['page', 'total'], setup: (props, context) => () => Vue.h('nav', { 'data-total': props.total, 'data-page': props.page, 'onUpdate:page': context.emit.bind(null, 'update:page') }) }
  }
  for (const [name, component] of Object.entries(components)) app.component(name, component)
  app.mount(root)
  return { app, root }
}

function flush() {
  return Vue.nextTick().then(() => new Promise<void>(resolve => setImmediate(resolve))).then(() => Vue.nextTick())
}

function fixture(options: { read?: () => Promise<unknown>, preview?: (body: Record<string, unknown>) => Promise<unknown>, execute?: (...args: unknown[]) => Promise<unknown>, recover?: (key: string) => Promise<unknown> } = {}) {
  testAccount.value = { id: 8, status: 'active', role: 'admin' }
  testUser.value = { id: 5 }
  sessionGeneration.value = 2
  capability.value = { capability_key: 'office-capability-1', can_read: true, can_configure: true }
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const executeCalls: unknown[][] = []
  const recoverCalls: string[] = []
  const toasts: Array<Record<string, unknown>> = []
  const context = {
    capabilities: capability,
    capture: () => ({ key: JSON.stringify([testUser.value.id, sessionGeneration.value, testAccount.value.id, testAccount.value.status, testAccount.value.role, capability.value.capability_key]), generation: sessionGeneration.value }),
    isCurrent(token: { key: string, generation: number }) {
      return token.key === JSON.stringify([testUser.value.id, sessionGeneration.value, testAccount.value.id, testAccount.value.status, testAccount.value.role, capability.value.capability_key]) && token.generation === sessionGeneration.value
    },
    refresh: async () => {}
  }
  const command = {
    pending: Vue.ref(false),
    recoveryKey: Vue.ref<string | null>(null),
    async execute(...args: unknown[]) {
      executeCalls.push(args)
      const result = options.execute ? await options.execute(...args) : { status: 'confirmed', data: {} }
      if ((result as { status?: string }).status === 'unknown') {
        command.recoveryKey.value = (result as { key: string }).key
        command.pending.value = true
      } else if ((result as { status?: string }).status === 'confirmed') {
        command.pending.value = false
      }
      return result
    },
    async recover(key: string) {
      recoverCalls.push(key)
      const result = options.recover ? await options.recover(key) : { status: 'confirmed', data: {} }
      if ((result as { status?: string }).status === 'confirmed') {
        command.recoveryKey.value = null
        command.pending.value = false
      } else if ((result as { status?: string }).status === 'unknown') {
        command.pending.value = true
      }
      return result
    }
  }
  const component = compileVueComponent('pages/gestao-erp/configuracoes.vue', {
    onMounted: (callback: () => void) => { void callback() },
    onScopeDispose: () => {},
    useAuth: () => ({ user: testUser, currentAccount: testAccount }),
    useState: () => sessionGeneration,
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => command,
    useAccessApi: () => ({ api: async (path: string, request?: Record<string, unknown>) => {
      calls.push({ path, options: request })
      if (path === '/erp-commercial/settings/preview' && options.preview) return options.preview(request?.body as Record<string, unknown>)
      if (path === '/erp-commercial/settings' && options.read) return options.read()
      return { data: { mode: 'manual', grace_days: 0, billing_timezone: 'America/Sao_Paulo' } }
    } }),
    useToast: () => ({ add(value: Record<string, unknown>) { toasts.push(value) } }),
    useHead() {},
    definePageMeta() {}
  })
  return { ...mount(component), calls, executeCalls, recoverCalls, toasts, command, context }
}

describe('Office ERP policy settings page', () => {
  it('does not invent zero impact when the initial settings request fails', async () => {
    const view = fixture({ read: async () => {
      throw new Error('Leitura indisponível')
    } })
    await flush()
    assert.match(renderText(view.root), /Leitura indisponível/)
    assert.doesNotMatch(renderText(view.root), /0 contratos afetados/)
    view.app.unmount()
  })

  it('keeps the automatic switch as a draft and previews real affected contracts without writing', async () => {
    const view = fixture({ preview: async _body => ({ data: [{ id: 12, company_name: 'Comercial A', status: 'effective' }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 }, affected_count: 2 }) })
    await flush()
    const toggle = find(view.root, node => node.props.role === 'switch')[0]
    assert.ok(toggle)
    ;(toggle.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
    await flush()
    assert.equal(view.executeCalls.length, 0)
    assert.equal(view.calls.filter(call => call.path === '/erp-commercial/settings/preview').length, 0)
    const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]
    assert.ok(preview)
    ;(preview.props.onClick as () => void)()
    await flush()
    const previewCall = view.calls.find(call => call.path === '/erp-commercial/settings/preview')
    assert.deepEqual(previewCall?.options?.body, { mode: 'automatic', grace_days: 0, billing_timezone: 'America/Sao_Paulo', page: 1 })
    assert.match(renderText(view.root), /2 contratos afetados/)
    assert.match(renderText(view.root), /Comercial A/)
    assert.equal(view.executeCalls.length, 0)
    view.app.unmount()
  })

  it('requires explicit confirmation to save automatic policy and keeps a 422 draft visible', async () => {
    const view = fixture({
      preview: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 }, affected_count: 0 }),
      execute: async () => ({ status: 'failed', failure: { code: 'validation_failed', message: 'Revise os campos.', http_status: 422, errors: { grace_days: ['Informe uma tolerância válida.'] } } })
    })
    await flush()
    const toggle = find(view.root, node => node.props.role === 'switch')[0]!
    ;(toggle.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
    await flush()
    const save = find(view.root, node => node.tag === 'button' && renderText(node).includes('Salvar configuração'))[0]
    assert.ok(save)
    ;(save.props.onClick as () => void)()
    await flush()
    assert.equal(view.executeCalls.length, 0)
    const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]!
    ;(preview.props.onClick as () => void)()
    await flush()
    const confirm = find(view.root, node => node.tag === 'button' && renderText(node).includes('Confirmar ativação automática'))[0]
    assert.ok(confirm)
    ;(confirm.props.onClick as () => void)()
    await flush()
    assert.equal(view.executeCalls.length, 1)
    assert.deepEqual(view.executeCalls[0], ['policy.configure', '/erp-commercial/settings', { mode: 'automatic', grace_days: 0, billing_timezone: 'America/Sao_Paulo', confirm_automatic: true }, 'PATCH'])
    assert.match(renderText(view.root), /Revise os campos/)
    assert.match(renderText(view.root), /tolerância válida/)
    view.app.unmount()
  })

  it('retains an unknown save key for one explicit recovery lookup without resending', async () => {
    const key = '7cbbdbb6-7f88-4479-a78a-471e4cb16c78'
    const view = fixture({ execute: async () => ({ status: 'unknown', key }), recover: async () => ({ status: 'unknown', key }) })
    await flush()
    const save = find(view.root, node => node.tag === 'button' && renderText(node).includes('Salvar configuração'))[0]!
    ;(save.props.onClick as () => void)()
    await flush()
    assert.equal(view.executeCalls.length, 1)
    assert.equal(view.command.recoveryKey.value, key)
    const duplicateSave = find(view.root, node => node.tag === 'button' && renderText(node).includes('Salvar configuração'))[0]!
    assert.equal(duplicateSave.props.disabled, true)
    ;(duplicateSave.props.onClick as () => void)()
    await flush()
    assert.equal(view.executeCalls.length, 1)
    const recover = find(view.root, node => node.tag === 'button' && renderText(node).includes('Verificar resultado'))[0]
    assert.ok(recover)
    assert.equal(recover.props.disabled, false)
    ;(recover.props.onClick as () => void)()
    await flush()
    assert.deepEqual(view.recoverCalls, [key])
    assert.equal(view.executeCalls.length, 1)
    assert.equal(view.command.recoveryKey.value, key)
    view.app.unmount()
  })

  it('discards a delayed preview after Account authority changes', async () => {
    let finish!: (value: unknown) => void
    const view = fixture({ preview: () => new Promise((resolve) => {
      finish = resolve
    }) })
    await flush()
    const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]
    assert.ok(preview)
    ;(preview.props.onClick as () => void)()
    await flush()
    testAccount.value = { id: 9, status: 'active', role: 'admin' }
    await flush()
    finish({ data: [{ id: 12, company_name: 'Conta anterior', status: 'effective' }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 }, affected_count: 1 })
    await flush()
    assert.doesNotMatch(renderText(view.root), /Conta anterior/)
    view.app.unmount()
  })

  it('invalidates the counted rows whenever the draft changes', async () => {
    const view = fixture({ preview: async () => ({ data: [{ contract_id: 12, client_name: 'Cliente A', company_name: 'Comercial A', current_status: 'active', resulting_status: 'past_due', outstanding: '100.00', overdue: true }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 }, affected_count: 1 }) })
    await flush()
    const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]!
    ;(preview.props.onClick as () => void)()
    await flush()
    assert.match(renderText(view.root), /Cliente A/)
    const toggle = find(view.root, node => node.props.role === 'switch')[0]!
    ;(toggle.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
    await flush()
    assert.doesNotMatch(renderText(view.root), /Cliente A/)
    assert.doesNotMatch(renderText(view.root), /1 contratos afetados/)
    assert.equal(view.executeCalls.length, 0)
    view.app.unmount()
  })

  it('discards delayed preview callbacks after session or capability identity changes', async () => {
    for (const change of [
      () => { sessionGeneration.value++ },
      () => { capability.value = { ...capability.value, capability_key: 'office-capability-2' } }
    ]) {
      let finish!: (value: unknown) => void
      const view = fixture({ preview: () => new Promise((resolve) => {
        finish = resolve
      }) })
      await flush()
      const preview = find(view.root, node => node.tag === 'button' && renderText(node).includes('Pré-visualizar consequências'))[0]!
      ;(preview.props.onClick as () => void)()
      await flush()
      change()
      await flush()
      finish({ data: [{ contract_id: 2, client_name: 'Conta anterior', company_name: 'Empresa antiga', current_status: 'active', resulting_status: 'past_due', outstanding: '1.00', overdue: true }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 }, affected_count: 1 })
      await flush()
      assert.doesNotMatch(renderText(view.root), /Conta anterior/)
      view.app.unmount()
    }
  })
})
