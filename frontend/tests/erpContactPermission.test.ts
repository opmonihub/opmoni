import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import type { Membership, CompanyMembership } from '../shared/types/access.ts'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
  focus(): void
}
function nodes(root: HostNode): HostNode[] {
  return [root, ...root.children.flatMap(nodes)]
}
async function settle() {
  for (let turn = 0; turn < 8; turn++) {
    await Vue.nextTick()
    await Promise.resolve()
  }
}

function contacts(company: Partial<CompanyMembership> = {}, failStatus = 0, csrf?: () => Promise<void>, mutate?: () => Promise<unknown>, office: Partial<Membership> = {}) {
  const currentCompany = Vue.ref<CompanyMembership>({ id: 1, name: 'Empresa A', status: 'active', access_kind: 'direct', role: 'owner', permissions: ['*'], delegated_office_id: null, ...company })
  const currentAccount = Vue.ref<Membership | null>(company.access_kind === 'delegated'
    ? { id: 77, name: 'Escritório delegado', status: 'active', role: 'operador', ...office }
    : null)
  const user = Vue.ref<{ id: number } | null>({ id: 5 })
  const session = Vue.ref(1)
  const writes: Array<{ method: string, body: unknown, signal?: AbortSignal }> = []
  let reads = 0
  let authConfirmed = true
  const states = new Map<string, Vue.Ref>()
  const bindings = {
    computed: Vue.computed, ref: Vue.ref, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose,
    definePageMeta() {}, useHead() {},
    useAuth: () => ({ user, currentCompany, currentAccount, refresh: async () => authConfirmed ? user.value : null }),
    useState: (key: string, init: () => unknown) => {
      if (key === 'access-session-generation') return session
      if (!states.has(key)) states.set(key, Vue.ref(init()))
      return states.get(key)!
    },
    useAccessApi: () => ({
      refreshCsrf: csrf ?? (async () => {}),
      api: async (path: string, options: { method?: string, body?: unknown, signal?: AbortSignal } = {}) => {
        if (options.method === 'POST') {
          assert.equal(path, '/erp/contacts')
          writes.push({ method: options.method, body: options.body, signal: options.signal })
          if (mutate) return await mutate()
          if (failStatus) throw {
            status: failStatus,
            data: {
              code: failStatus === 409 ? 'company_context_changed' : '',
              message: 'Falha de autorização ou validação',
              errors: { name: ['Nome inválido'] }
            }
          }
          return { data: { id: 1 } }
        }
        assert.match(path, /^\/erp\/contacts\?/)
        reads++
        return { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
      }
    })
  }
  const listModule = loadAppComposable<{ useErpCatalogList: typeof import('../app/composables/useErpCatalogList.ts').useErpCatalogList }>('composables/useErpCatalogList.ts', bindings)
  const editorModule = loadAppComposable<{ useErpCatalogEditor: typeof import('../app/composables/useErpCatalogEditor.ts').useErpCatalogEditor }>('composables/useErpCatalogEditor.ts', bindings)
  let editor!: ReturnType<typeof editorModule.useErpCatalogEditor>
  let list!: ReturnType<typeof listModule.useErpCatalogList>
  const referenceSelect = Vue.defineComponent({
    props: ['modelValue', 'name', 'label', 'disabled'],
    setup: props => () => Vue.h('select', { 'name': props.name, 'value': props.modelValue, 'disabled': props.disabled, 'aria-label': props.label })
  })
  const page = compileVueComponent('pages/erp/cadastros/contatos.vue', bindings, {
    '~/composables/useErpCatalogList': { useErpCatalogList: (path: string) => (list = listModule.useErpCatalogList(path)) },
    '~/composables/useErpCatalogEditor': { useErpCatalogEditor: (path: string, refresh: () => Promise<void>) => (editor = editorModule.useErpCatalogEditor(path, refresh)) },
    '~/composables/useErpContactNotes': loadAppComposable('composables/useErpContactNotes.ts', bindings),
    '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': referenceSelect
  })
  const makeNode = (tag: string, text = ''): HostNode => ({ tag, props: {}, children: [], parent: null, text, focus() {} })
  const host = Vue.createRenderer<HostNode, HostNode>({
    patchProp(node, key, _previous, next) { node.props[key] = next },
    insert(node, parent, anchor = null) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    createElement: tag => makeNode(tag),
    createText: text => makeNode('#text', text),
    createComment: text => makeNode('#comment', text),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insertStaticContent(content, parent, anchor) {
      const node = makeNode('#static', content.replace(/<[^>]*>/g, ''))
      this.insert(node, parent, anchor)
      return [node, node]
    }
  })
  const app = host.createApp(page)
  app.component('ErpListFooter', compileVueComponent('components/erp/ErpListFooter.vue', bindings))
  const surface = Vue.defineComponent({
    setup: (_props, { attrs, slots }) => () => Vue.h('div', attrs, Object.values(slots).flatMap(slot => slot?.()))
  })
  for (const name of ['UDashboardPanel', 'UDashboardNavbar', 'UDashboardToolbar', 'UCard', 'UDropdownMenu', 'UBadge', 'UTabs'])
    app.component(name, surface)
  for (const name of ['CompanyMenu', 'UserMenu', 'UDashboardSidebarCollapse', 'UDashboardSidebarToggle', 'UTable', 'UPagination', 'UIcon'])
    app.component(name, Vue.defineComponent({ setup: () => () => Vue.h('div') }))
  app.component('UButton', Vue.defineComponent({
    inheritAttrs: false,
    props: ['label', 'type', 'disabled', 'loading'],
    setup: (props, { attrs, slots }) => () => Vue.h('button', { ...attrs, type: props.type ?? 'button', disabled: props.disabled }, slots.default?.() ?? String(props.label ?? ''))
  }))
  app.component('UForm', Vue.defineComponent({
    props: ['state', 'disabled'],
    emits: ['submit'],
    setup: (props, { emit, slots }) => () => Vue.h('form', {
      'data-state': props.state,
      'onSubmit': (event: { preventDefault(): void }) => {
        event.preventDefault()
        emit('submit', { data: props.state })
      }
    }, slots.default?.())
  }))
  app.component('UFormField', Vue.defineComponent({
    props: ['label', 'name', 'error'],
    setup: (props, { slots }) => () => Vue.h('label', { 'data-field': props.name }, [
      String(props.label ?? ''), slots.default?.(), props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null
    ])
  }))
  for (const [name, tag] of [['UInput', 'input'], ['UTextarea', 'textarea']]) {
    app.component(name!, Vue.defineComponent({
      inheritAttrs: false,
      props: ['modelValue'],
      emits: ['update:modelValue'],
      setup: (props, { attrs, emit }) => () => Vue.h(tag!, { ...attrs, value: props.modelValue, onInput: (event: { target: { value: string } }) => emit('update:modelValue', event.target.value) })
    }))
  }
  app.component('UCheckbox', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'label'],
    emits: ['update:modelValue'],
    setup: (props, { attrs, emit }) => () => Vue.h('input', { ...attrs, type: 'checkbox', checked: props.modelValue, onChange: (event: { target: { checked: boolean } }) => emit('update:modelValue', event.target.checked) })
  }))
  for (const name of ['USlideover', 'UModal']) {
    app.component(name, Vue.defineComponent({
      props: ['open', 'unmountOnHide'],
      setup: (props, { slots }) => () => Vue.h('div', { 'data-overlay-open': props.open }, props.open || (name === 'USlideover' && props.unmountOnHide !== true) ? [slots.body?.(), slots.footer?.()] : [])
    }))
  }
  app.component('UAlert', Vue.defineComponent({
    props: ['title'],
    setup: (props, { slots }) => () => Vue.h('section', { role: 'alert' }, [String(props.title ?? ''), slots.actions?.()])
  }))
  const root = makeNode('root')
  app.mount(root)
  const input = (name: string) => nodes(root).find(node => node.tag === 'input' && node.props.name === name)
  return {
    root, input, writes, currentCompany, currentAccount, user, session,
    editor, list,
    refreshes: () => reads - 1,
    setAuthConfirmed: (value: boolean) => { authConfirmed = value },
    open: async () => {
      await settle()
      const action = nodes(root).find(node => node.tag === 'button' && node.props['aria-label'] === 'Adicionar contato')
      if (action) (action.props.onClick as () => void)()
      await settle()
    },
    submit: async () => {
      const form = createForm({ root })
      assert.ok(form, 'the real Contact form is mounted')
      ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
      await settle()
    },
    close: () => app.unmount()
  }
}
const createForm = (view: { root: HostNode }) => nodes(view.root).find(node => node.tag === 'form')
async function fill(view: ReturnType<typeof contacts>, name = 'Contato preenchido') {
  for (const [key, value] of [['name', name], ['tax_id', '12345678901']]) {
    const input = view.input(key!)
    assert.ok(input, `the real form renders ${key}`)
    ;(input.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: value! } })
  }
  for (const [key, checked] of [['is_customer', false], ['is_supplier', true]] as const) {
    const input = view.input(key)
    assert.ok(input)
    ;(input.props.onChange as (event: { target: { checked: boolean } }) => void)({ target: { checked } })
  }
  await settle()
}

describe('Contact mutation permissions and input preservation', () => {
  it('allows delegated creation only with the effective exact action from the server', async (t) => {
    const view = contacts({ access_kind: 'delegated', role: 'delegated', delegated_office_id: 77, permissions: ['erp.contacts.index', 'erp.contacts.store'] })
    t.after(view.close)
    await view.open()
    assert.ok(createForm(view))
    await fill(view)
    await view.submit()
    assert.equal(view.writes.length, 1)
  })
  for (const grants of [['*'], ['erp.contacts.store'], ['erp.contacts.*'], ['erp.contacts.write'], ['erp.write']]) {
    it(`allows a direct owner with actual server grants ${grants}`, async (t) => {
      const view = contacts({ permissions: ['erp.contacts.index', ...grants] })
      t.after(view.close)
      await view.open()
      assert.ok(createForm(view))
      await fill(view)
      await view.submit()
      assert.equal(view.writes.length, 1)
      assert.equal(view.writes[0]!.method, 'POST')
      assert.ok(view.writes[0]!.signal instanceof AbortSignal)
      assert.deepEqual(view.writes[0]!.body, {
        name: 'Contato preenchido', trade_name: null, tax_id: '12345678901', email: null, phone: null, notes: null,
        is_customer: false, is_supplier: true, erp_customer_group_id: null, address: null
      })
      assert.equal(createForm(view), undefined, 'successful creation unmounts the editor')
      assert.equal(view.refreshes(), 1)
      await view.open()
      assert.equal(view.input('name')?.props.value, '', 'the next create session starts blank')
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
      await view.open()
      assert.equal(createForm(view), undefined)
      view.editor.create()
      await view.editor.save({ name: 'Não permitido' })
      assert.equal(view.writes.length, 0)
      assert.equal(view.editor.open.value, false)
    })
  }
  for (const permissions of [['*'], ['erp.contacts.*'], ['erp.contacts.write'], ['erp.write']]) {
    it(`does not infer a delegated store action from ${permissions}`, async (t) => {
      const view = contacts({ access_kind: 'delegated', role: 'delegated', delegated_office_id: 77, permissions })
      t.after(view.close)
      await view.open()
      assert.equal(createForm(view), undefined)
      view.editor.create()
      await view.editor.save({ name: 'Sem ação exata' })
      assert.equal(view.writes.length, 0)
    })
  }
  for (const office of [
    { role: 'user' as const }, { status: 'suspended' as const }, { id: 88 }
  ]) {
    it(`requires the real active delegated office writer ${JSON.stringify(office)}`, async (t) => {
      const view = contacts({ access_kind: 'delegated', role: 'delegated', delegated_office_id: 77, permissions: ['erp.contacts.index', 'erp.contacts.store'] }, 0, undefined, undefined, office)
      t.after(view.close)
      await view.open()
      assert.equal(createForm(view), undefined)
      await view.editor.save({ name: 'Sem autoridade do escritório' })
      assert.equal(view.writes.length, 0)
    })
  }
  for (const status of [422, 503]) {
    it(`retains all entered fields and actionable feedback after HTTP ${status}`, async (t) => {
      const view = contacts({}, status)
      t.after(view.close)
      await view.open()
      await fill(view)
      const form = createForm(view)
      await view.submit()
      assert.equal(createForm(view), form, 'validation/retryable failure retains the mounted form')
      assert.equal(view.input('name')?.props.value, 'Contato preenchido')
      assert.equal(view.input('tax_id')?.props.value, '12345678901')
      assert.equal(view.input('is_customer')?.props.checked, false)
      assert.equal(view.input('is_supplier')?.props.checked, true)
      assert.ok(view.editor.error.value)
      assert.ok(nodes(view.root).some(node => node.props.role === 'alert'))
      if (status === 422) assert.ok(nodes(view.root).some(node => node.text === 'Nome inválido'))
      assert.equal(view.refreshes(), 0)
      assert.equal(view.editor.saving.value, false)
    })
  }
  for (const status of [401, 403, 409]) {
    it(`unmounts protected fields after authority loss HTTP ${status} until real recovery`, async (t) => {
      const view = contacts({}, status)
      t.after(view.close)
      await view.open()
      await fill(view)
      await view.submit()
      assert.equal(createForm(view), undefined)
      assert.equal(view.input('name'), undefined)
      assert.equal(view.editor.record.value, null)
      assert.equal(view.editor.authorityBlocked.value, true)
      assert.equal(view.list.authorityBlocked.value, true)
      assert.equal(view.list.state.value, null)
      assert.ok(view.editor.error.value)
      await view.open()
      view.editor.create()
      await view.editor.save({ name: 'Não pode reabrir' })
      assert.equal(view.editor.open.value, false)
      assert.equal(view.writes.length, 1)
      assert.equal(view.refreshes(), 0)

      const recover = async () => {
        const button = nodes(view.root).find(node => node.tag === 'button' && node.text === 'Revalidar acesso')
        assert.ok(button, 'the real resource exposes authority recovery')
        await (button.props.onClick as () => Promise<void>)()
        await settle()
      }
      view.setAuthConfirmed(false)
      await recover()
      await view.open()
      assert.equal(createForm(view), undefined, 'failed auth revalidation cannot reopen private fields')
      view.setAuthConfirmed(true)
      await recover()
      assert.equal(view.editor.authorityBlocked.value, false)
      assert.equal(view.list.authorityBlocked.value, false)
      await view.open()
      assert.equal(view.input('name')?.props.value, '', 'authority recovery never restores the discarded protected draft')
    })
  }
  it('rechecks permission after CSRF and never submits after revocation', async (t) => {
    let release!: () => void
    const view = contacts({}, 0, () => new Promise((resolve) => {
      release = resolve
    }))
    t.after(view.close)
    await view.open()
    await fill(view)
    await view.submit()
    view.currentCompany.value = { ...view.currentCompany.value, role: 'viewer' }
    release()
    await settle()
    assert.equal(view.writes.length, 0)
    assert.equal(createForm(view), undefined)
  })
  for (const boundary of ['Account', 'actor', 'Company', 'office role', 'session'] as const) {
    for (const rejected of [false, true]) {
      it(`ignores a late POST ${rejected ? 'failure' : 'success'} after ${boundary} switch`, async (t) => {
        let releaseMutation!: () => void
        const view = contacts({
          access_kind: 'delegated', role: 'delegated', delegated_office_id: 77,
          permissions: ['erp.contacts.index', 'erp.contacts.store']
        }, 0, undefined, () => new Promise((resolve, reject) => {
          releaseMutation = () => rejected ? reject({ status: 403, data: { message: 'Old context' } }) : resolve({ data: { id: 9 } })
        }))
        t.after(view.close)
        await view.open()
        await fill(view)
        await view.submit()
        assert.equal(view.writes.length, 1)
        if (boundary === 'Account') view.currentAccount.value = { ...view.currentAccount.value!, id: 88 }
        if (boundary === 'actor') view.user.value = { id: 6 }
        if (boundary === 'Company') view.currentCompany.value = { ...view.currentCompany.value, id: 2 }
        if (boundary === 'office role') view.currentAccount.value = { ...view.currentAccount.value!, role: 'admin' }
        if (boundary === 'session') view.session.value++
        await Vue.nextTick()
        assert.equal(createForm(view), undefined, 'the old protected form is unmounted at the authority boundary')
        assert.equal(view.writes[0]!.signal?.aborted, true)
        const refreshes = view.refreshes()
        if (boundary !== 'Account') {
          await view.open()
          await fill(view, 'Rascunho do contexto atual')
        }
        releaseMutation()
        await settle()
        assert.equal(view.input('name')?.props.value, boundary === 'Account' ? undefined : 'Rascunho do contexto atual')
        assert.equal(view.editor.error.value, '')
        assert.equal(view.editor.authorityBlocked.value, false)
        assert.equal(view.refreshes(), refreshes, 'obsolete completion cannot refresh the current list')
        assert.equal(view.writes.length, 1)
      })
    }
  }
  it('clears private draft fields and ignores late mutation success across company context', async (t) => {
    let release!: () => void
    const view = contacts({}, 0, () => new Promise((resolve) => {
      release = resolve
    }))
    t.after(view.close)
    await view.open()
    await fill(view)
    await view.submit()
    view.currentCompany.value = { ...view.currentCompany.value, id: 2 }
    await Vue.nextTick()
    assert.equal(createForm(view), undefined)
    const refreshes = view.refreshes()
    release()
    await settle()
    assert.equal(view.writes.length, 0)
    assert.equal(view.refreshes(), refreshes)
    await view.open()
    assert.equal(view.input('name')?.props.value, '')
  })
})
