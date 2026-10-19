import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { erpCompanyHasPermission } from '../app/utils/erpModulesNav.ts'
import { apiFailure } from '../shared/utils/access.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
  focusCalls: number
  focus(): void
}

interface Request {
  url: string
  options: Record<string, unknown> & { signal?: AbortSignal, method?: string }
  resolve(value: unknown): void
  reject(reason: unknown): void
}

function renderer() {
  const allNodes: HostNode[] = []
  const makeNode = (tag: string, text = '') => {
    const node: HostNode = {
      tag,
      props: {},
      children: [],
      parent: null,
      text,
      focusCalls: 0,
      focus() {
        this.focusCalls++
      }
    }
    allNodes.push(node)
    return node
  }
  return {
    makeNode,
    allNodes,
    renderer: Vue.createRenderer<HostNode, HostNode>({
      patchProp(node, key, _previous, next) { node.props[key] = next },
      insert(node, parent, anchor = null) {
        if (node.parent) {
          const previous = node.parent.children.indexOf(node)
          if (previous >= 0) node.parent.children.splice(previous, 1)
        }
        node.parent = parent
        const index = anchor ? parent.children.indexOf(anchor) : -1
        if (index < 0) parent.children.push(node)
        else parent.children.splice(index, 0, node)
      },
      remove(node) {
        if (!node.parent) return
        const index = node.parent.children.indexOf(node)
        if (index >= 0) node.parent.children.splice(index, 1)
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
  }
}

function descendants(root: HostNode): HostNode[] {
  return [root, ...root.children.flatMap(descendants)]
}

function isAttachedTo(root: HostNode, node: HostNode): boolean {
  let current: HostNode | null = node
  while (current) {
    if (current === root)
      return true
    current = current.parent
  }
  return false
}

function rowActionTrigger(root: HostNode, label: string): HostNode | undefined {
  return descendants(root).find(node => node.tag === 'button' && node.props['aria-label'] === label)
}

function setupResource(options: { accessKind?: 'direct' | 'delegated', permissions?: string[] } = {}) {
  const accessKind = options.accessKind ?? 'direct'
  const permissions = options.permissions ?? ['erp.units.index', 'erp.units.show', 'erp.units.store', 'erp.units.update', 'erp.units.archive']
  const user = Vue.ref<{ id: number } | null>({ id: 5 })
  const currentCompany = Vue.ref({
    id: 41,
    status: 'active',
    access_kind: accessKind,
    role: accessKind === 'direct' ? 'owner' : 'member',
    permissions,
    delegated_office_id: accessKind === 'delegated' ? 77 : null
  })
  const currentAccount = Vue.ref(accessKind === 'delegated' ? { id: 77, status: 'active', role: 'user' } : null)
  const sessionGeneration = Vue.ref(7)
  const requests: Request[] = []
  let authRefresh: () => Promise<unknown> = async () => user.value
  const api = (url: string, options: Request['options'] = {}) => new Promise<unknown>((resolve, reject) => {
    requests.push({ url, options, resolve, reject })
  })
  const useAuth = () => ({ user, currentCompany, currentAccount, refresh: () => authRefresh() })
  const useState = <T>(key: string, init: () => T) => key === 'access-session-generation'
    ? sessionGeneration as Vue.Ref<T>
    : Vue.ref(init())
  const bindings = {
    useAuth,
    useState,
    useAccessApi: () => ({ api, refreshCsrf: async () => undefined }),
    computed: Vue.computed,
    onScopeDispose: Vue.onScopeDispose,
    ref: Vue.ref,
    watch: Vue.watch
  }
  const listModule = loadAppComposable<{ useErpCatalogList(path: string): unknown }>(
    'composables/useErpCatalogList.ts',
    { ...bindings, apiFailure }
  )
  const editorModule = loadAppComposable<{ useErpCatalogEditor(path: string, refresh: () => Promise<void>): unknown }>(
    'composables/useErpCatalogEditor.ts',
    { ...bindings, apiFailure, erpCompanyHasPermission }
  )
  const scope = Vue.effectScope()
  let list!: ReturnType<typeof listModule.useErpCatalogList>
  let editor!: ReturnType<typeof editorModule.useErpCatalogEditor>
  scope.run(() => {
    list = listModule.useErpCatalogList('/erp/units')
    editor = editorModule.useErpCatalogEditor('/erp/units', list.refresh)
  })
  const table = Vue.defineComponent({
    props: ['data', 'columns', 'loading'],
    setup: props => () => Vue.h('div', { 'data-row-count': String(props.data.length) }, props.data.map((record: { id: number }) => {
      const row = { original: record }
      const columns = props.columns as Array<{ id?: string, accessorKey?: string, cell?: (args: { row: { original: { id: number } } }) => unknown }>
      return Vue.h('div', { 'data-row-id': record.id }, columns.flatMap((column) => {
        if (column.id !== 'actions' && column.accessorKey !== 'status')
          return []
        return column.cell ? [Vue.h(column.cell as Vue.Component, { row })] : []
      }))
    }))
  })
  const component = compileVueComponent(
    'components/erp/catalogs/ErpCatalogResource.vue',
    bindings,
    {
      '~/composables/useErpCatalogList': { useErpCatalogList: () => list },
      '~/composables/useErpCatalogEditor': { useErpCatalogEditor: () => editor }
    }
  )
  const { renderer: host, makeNode, allNodes } = renderer()
  const app = host.createApp(component, {
    path: '/erp/units',
    title: 'Unidades',
    form: Vue.defineComponent({ setup: () => () => Vue.h('form') }),
    columns: [{ accessorKey: 'status', header: 'Status' }],
    recordLabel: (record: { short_name?: string, id: number }) => record.short_name ?? `Unidade ${record.id}`
  })
  const passthrough = Vue.defineComponent({
    inheritAttrs: false,
    props: ['label', 'disabled', 'loading', 'color', 'variant'],
    setup(props, { attrs, slots }) {
      return () => Vue.h('button', { ...attrs, disabled: props.disabled }, slots.default?.() ?? String(props.label ?? ''))
    }
  })
  const modal = Vue.defineComponent({
    props: ['open'],
    setup(props, { slots }) {
      return () => Vue.h('div', { 'data-modal-open': String(props.open) }, props.open ? [slots.body?.(), slots.footer?.()] : [])
    }
  })
  for (const name of ['UButton', 'UCard', 'UFormField', 'UInput'])
    app.component(name, passthrough)
  app.component('UDashboardToolbar', Vue.defineComponent({
    props: ['ui'],
    setup(props, { slots }) {
      return () => Vue.h('div', {
        'data-nuxt-ui-component': 'UDashboardToolbar',
        'data-ui-root': props.ui?.root,
        'data-ui-left': props.ui?.left,
        'data-ui-right': props.ui?.right
      }, [slots.left?.(), slots.right?.()])
    }
  }))
  app.component('UDropdownMenu', Vue.defineComponent({
    props: ['items', 'content'],
    setup(props, { attrs, slots }) {
      return () => Vue.h('div', { ...attrs, 'data-nuxt-ui-component': 'UDropdownMenu', 'onMenuCloseAutoFocus': props.content?.onCloseAutoFocus }, [
        slots.default?.(),
        ...((props.items as Array<Array<{ label?: string, onSelect?: () => void }>> | undefined) ?? []).flatMap(group => group.map(item => Vue.h('button', {
          'type': 'button',
          'data-menu-action': item.label,
          'onClick': () => item.onSelect?.()
        }, item.label ?? '')))
      ])
    }
  }))
  app.component('UBadge', Vue.defineComponent({
    props: ['label'],
    setup(props) {
      return () => Vue.h('span', { 'data-nuxt-ui-component': 'UBadge' }, String(props.label ?? ''))
    }
  }))
  app.component('UModal', modal)
  app.component('UAlert', Vue.defineComponent({
    props: ['title'],
    setup(props, { slots }) {
      return () => Vue.h('section', { role: 'alert' }, [Vue.h('p', String(props.title ?? '')), slots.actions?.()])
    }
  }))
  app.component('UPagination', Vue.defineComponent({ setup: () => () => Vue.h('div') }))
  app.component('UTable', table)
  const root = makeNode('root')
  app.mount(root)
  return {
    root,
    allNodes,
    list,
    editor,
    requests,
    user,
    currentCompany,
    setAuthRefresh: (refresh: () => Promise<unknown>) => { authRefresh = refresh },
    settle: async () => {
      for (let turn = 0; turn < 6; turn++) {
        await Vue.nextTick()
        await Promise.resolve()
      }
    },
    stop: () => {
      app.unmount()
      scope.stop()
    }
  }
}

describe('ERP catalog shared resource authority', () => {
  it('blocks the editor when list authority is rejected and ignores its delayed detail response', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const listRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(listRequest)
    const loading = resource.editor.edit(24)
    const detailRequest = resource.requests.find(request => request.url === '/erp/units/24')
    assert.ok(detailRequest)

    listRequest.reject({ statusCode: 403, data: { message: 'Permissão revogada' } })
    await resource.settle()
    assert.equal(resource.editor.authorityBlocked.value, true)
    assert.equal(resource.editor.open.value, false)
    assert.equal(resource.editor.record.value, null)
    assert.equal(detailRequest.options.signal?.aborted, true)

    detailRequest.resolve({ data: { id: 24, short_name: 'Conteúdo protegido' } })
    await loading
    await resource.settle()
    assert.equal(resource.editor.record.value, null)
  })

  it('clears table state when an editor mutation loses authority and ignores a delayed list response', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const firstListRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(firstListRequest)
    firstListRequest.resolve({
      data: [{ id: 8, short_name: 'Quilograma', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await resource.settle()

    const viewing = resource.editor.edit(8)
    const detailRequest = resource.requests.find(request => request.url === '/erp/units/8')
    assert.ok(detailRequest)
    detailRequest.resolve({ data: { id: 8, short_name: 'Quilograma', status: 'active' } })
    await viewing
    await resource.settle()

    const refreshing = resource.list.refresh()
    const lateListRequest = resource.requests.filter(request => request.url === '/erp/units?page=1').at(-1)!
    const saving = resource.editor.save({ short_name: 'Novo nome', actual_name: 'Novo nome' })
    await resource.settle()
    const mutationRequest = resource.requests.find(request => request.options.method === 'PATCH')
    assert.ok(mutationRequest)
    mutationRequest.reject({ statusCode: 403, data: { message: 'Acesso revogado' } })
    await saving
    await resource.settle()

    assert.equal(resource.list.authorityBlocked.value, true)
    assert.equal(resource.list.state.value, null)
    assert.equal(lateListRequest.options.signal?.aborted, true)
    lateListRequest.resolve({ data: [{ id: 999, short_name: 'Resposta antiga' }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })
    await refreshing
    await resource.settle()
    assert.equal(resource.list.state.value, null)
  })

  it('does not let Escape cancel an in-flight save', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const listRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(listRequest)
    listRequest.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } })
    const opening = resource.editor.edit(5)
    const detailRequest = resource.requests.find(request => request.url === '/erp/units/5')
    assert.ok(detailRequest)
    detailRequest.resolve({ data: { id: 5, short_name: 'Peça' } })
    await opening
    await resource.settle()

    const saving = resource.editor.save({ short_name: 'Peça', actual_name: 'Peça' })
    await resource.settle()
    const mutationRequest = resource.requests.find(request => request.options.method === 'PATCH')
    assert.ok(mutationRequest)
    assert.equal(resource.editor.open.value, true)
    const section = descendants(resource.root).find(node => node.tag === 'section')
    assert.ok(section)
    const handler = section.props.onKeydown as ((event: { key: string, preventDefault(): void, stopPropagation(): void }) => void)
    assert.equal(typeof handler, 'function')
    let prevented = false
    handler({
      key: 'Escape',
      preventDefault() {
        prevented = true
      },
      stopPropagation() {}
    })
    assert.equal(resource.editor.open.value, true)
    assert.equal(mutationRequest.options.signal?.aborted, false)
    assert.equal(prevented, true)

    mutationRequest.resolve({ data: { id: 5, short_name: 'Peça' } })
    await resource.settle()
    const postSaveRefresh = resource.requests.filter(request => request.url === '/erp/units?page=1').at(-1)!
    assert.notEqual(postSaveRefresh, listRequest)
    postSaveRefresh.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } })
    await saving
    await resource.settle()
  })

  it('recovers both sides after a same-context auth refresh and a successful list read', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const firstListRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(firstListRequest)
    firstListRequest.reject({ statusCode: 403, data: { message: 'Permissão revogada' } })
    await resource.settle()
    assert.equal(resource.list.authorityBlocked.value, true)
    assert.equal(resource.editor.authorityBlocked.value, true)

    const recovery = descendants(resource.root).find(node => node.tag === 'button' && node.text === 'Revalidar acesso')
    assert.ok(recovery)
    const click = recovery.props.onClick as (() => void) | undefined
    assert.equal(typeof click, 'function')
    click!()
    await resource.settle()
    const retriedListRequest = resource.requests.filter(request => request.url === '/erp/units?page=1').at(-1)!
    assert.notEqual(retriedListRequest, firstListRequest)
    retriedListRequest.resolve({
      data: [{ id: 33, short_name: 'Pacote', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await resource.settle()
    assert.equal(resource.list.authorityBlocked.value, false)
    assert.equal(resource.editor.authorityBlocked.value, false)
    assert.equal(resource.list.state.value?.data[0]?.id, 33)
  })

  it('recovers shared Resource authority when retry is clicked inside the real pagination alert', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const firstListRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(firstListRequest)
    firstListRequest.reject({ statusCode: 403, data: { message: 'Permissão revogada' } })
    await resource.settle()

    const paginationAlert = descendants(resource.root).find(node => node.tag === 'section' && node.props.role === 'alert')
    assert.ok(paginationAlert)
    const retry = descendants(paginationAlert).find(node => node.tag === 'button' && node.text === 'Revalidar acesso')
    assert.ok(retry)
    const click = retry.props.onClick as (() => Promise<void>) | undefined
    assert.ok(click)
    const pendingRecovery = click()
    await resource.settle()

    const retriedListRequest = resource.requests.filter(request => request.url === '/erp/units?page=1').at(-1)!
    assert.notEqual(retriedListRequest, firstListRequest)
    retriedListRequest.resolve({
      data: [{ id: 35, short_name: 'Recuperada pela paginação', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await pendingRecovery
    await resource.settle()

    assert.equal(resource.list.authorityBlocked.value, false)
    assert.equal(resource.editor.authorityBlocked.value, false)
    assert.equal(resource.list.state.value?.data[0]?.id, 35)
    assert.equal(descendants(resource.root).some(node => node.tag === 'button' && node.text === 'Revalidar acesso'), false)
  })

  it('keeps the next company archive confirmation open when the prior company delete finishes late', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const firstListRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(firstListRequest)
    firstListRequest.resolve({
      data: [{ id: 8, short_name: 'Unidade da empresa A', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await resource.settle()

    const archiveA = descendants(resource.root).find(node => node.tag === 'button' && node.text === 'Arquivar')
    assert.ok(archiveA)
    ;(archiveA.props.onClick as (() => void) | undefined)?.()
    await resource.settle()
    const confirmA = descendants(resource.root).find(node => node.tag === 'button' && node.text === 'Confirmar arquivamento')
    assert.ok(confirmA)
    const pendingArchiveA = (confirmA.props.onClick as (() => Promise<void>) | undefined)?.()
    assert.ok(pendingArchiveA)
    await resource.settle()
    const deleteA = resource.requests.filter(request => request.options.method === 'DELETE').at(-1)!
    assert.ok(deleteA)

    resource.currentCompany.value = {
      id: 42,
      status: 'active',
      access_kind: 'direct',
      role: 'owner',
      permissions: ['erp.units.index', 'erp.units.show', 'erp.units.store', 'erp.units.update', 'erp.units.archive'],
      delegated_office_id: null
    }
    await resource.settle()
    const companyBList = resource.requests.filter(request => request.url === '/erp/units?page=1').at(-1)!
    assert.notEqual(companyBList, firstListRequest)
    companyBList.resolve({
      data: [{ id: 9, short_name: 'Unidade da empresa B', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await resource.settle()

    const archiveB = descendants(resource.root).find(node => node.tag === 'button' && node.text === 'Arquivar')
    assert.ok(archiveB)
    ;(archiveB.props.onClick as (() => void) | undefined)?.()
    await resource.settle()
    assert.ok(descendants(resource.root).some(node => node.tag === 'button' && node.text === 'Confirmar arquivamento'))
    assert.equal(resource.requests.filter(request => request.options.method === 'DELETE').length, 1)

    deleteA.resolve({ data: { id: 8 } })
    await pendingArchiveA
    await resource.settle()

    assert.equal(resource.list.state.value?.data[0]?.short_name, 'Unidade da empresa B')
    assert.ok(descendants(resource.root).some(node => node.tag === 'button' && node.text === 'Confirmar arquivamento'), 'the company B archive dialog remains open')
    assert.equal(resource.requests.filter(request => request.options.method === 'DELETE').length, 1, 'the company B record was not archived')
  })

  it('uses a wrapping full-width toolbar and shows only the confirmed server count', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const toolbar = descendants(resource.root).find(node => node.props['data-nuxt-ui-component'] === 'UDashboardToolbar')
    assert.ok(toolbar)
    assert.match(String(toolbar.props['data-ui-root']), /flex-col/)
    assert.match(String(toolbar.props['data-ui-root']), /sm:flex-row/)
    assert.match(String(toolbar.props['data-ui-left']), /min-w-0/)
    assert.match(String(toolbar.props['data-ui-right']), /w-full/)
    const search = descendants(resource.root).find(node => node.props.placeholder === 'Buscar unidades')
    assert.ok(search)
    assert.match(String(search.props.class), /w-full/)
    assert.equal(descendants(resource.root).some(node => node.tag === 'p' && /^\d+ registros?$/.test(node.text)), false)

    const listRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(listRequest)
    listRequest.resolve({
      data: [{ id: 1, short_name: 'kg', status: 'active' }, { id: 2, short_name: 'g', status: 'archived' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 27 }
    })
    await resource.settle()
    assert.ok(descendants(resource.root).some(node => node.tag === 'p' && node.text === '27 registros'))
    assert.ok(descendants(resource.root).some(node => node.props['data-nuxt-ui-component'] === 'UBadge' && node.text === 'Ativo'))
    assert.ok(descendants(resource.root).some(node => node.props['data-nuxt-ui-component'] === 'UBadge' && node.text === 'Arquivado'))
  })

  for (const accessKind of ['direct', 'delegated'] as const) {
    it(`restores the list for a valid ${accessKind} index-only actor without exposing record actions`, async (t) => {
      const resource = setupResource({ accessKind, permissions: ['erp.units.index'] })
      t.after(resource.stop)
      const firstListRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
      assert.ok(firstListRequest)
      firstListRequest.reject({ statusCode: 403, data: { message: 'Falha temporária de autorização' } })
      await resource.settle()

      assert.equal(resource.list.authorityBlocked.value, true)
      assert.equal(resource.editor.authorityBlocked.value, true)
      assert.equal(resource.list.canRead.value, true)
      assert.equal(resource.editor.permissions.show.value, false)
      assert.equal(resource.editor.permissions.store.value, false)
      assert.equal(resource.editor.permissions.edit.value, false)
      assert.equal(resource.editor.permissions.archive.value, false)
      const rejectedContext = resource.editor.contextKey.value

      const recovery = descendants(resource.root).find(node => node.tag === 'button' && node.text === 'Revalidar acesso')
      assert.ok(recovery)
      const click = recovery.props.onClick as (() => Promise<void>) | undefined
      assert.ok(click)
      const pendingRecovery = click()
      await resource.settle()
      const retriedListRequest = resource.requests.filter(request => request.url === '/erp/units?page=1').at(-1)!
      assert.notEqual(retriedListRequest, firstListRequest)
      retriedListRequest.resolve({
        data: [{ id: 73, short_name: 'Unidade permitida', status: 'active' }],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
      })
      await pendingRecovery
      await resource.settle()

      assert.equal(resource.editor.contextKey.value, rejectedContext)
      assert.equal(resource.list.authorityBlocked.value, false)
      assert.equal(resource.editor.authorityBlocked.value, false)
      assert.equal(resource.list.state.value?.data[0]?.id, 73)
      const table = descendants(resource.root).find(node => node.props['data-row-count'] === '1')
      assert.ok(table, 'the successfully revalidated list row is rendered')
      assert.equal(descendants(resource.root).some(node => node.tag === 'button' && ['Ver', 'Editar', 'Arquivar', 'Adicionar unidade'].includes(node.text)), false)
      assert.equal(descendants(resource.root).some(node => node.props['aria-label']?.toString().startsWith('Ações de ')), false, 'index-only users do not get empty row action menus')
    })
  }

  it('hands focus from the closing row menu to the editor without changing ordinary menu focus', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    resource.requests.find(request => request.url === '/erp/units?page=1')!.resolve({
      data: [{ id: 8, short_name: 'Quilograma', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await resource.settle()
    const menu = descendants(resource.root).find(node => node.props['data-nuxt-ui-component'] === 'UDropdownMenu')!
    const closeAutoFocus = menu.props.onMenuCloseAutoFocus as ((event: Event) => Promise<void>) | undefined
    assert.ok(closeAutoFocus, 'The menu exposes its native close-auto-focus handler')
    let prevented = 0
    const event = {
      preventDefault() {
        prevented++
      }
    } as Event
    await closeAutoFocus(event)
    assert.equal(prevented, 0, 'Ordinary menu dismissal keeps native trigger focus')
    const edit = descendants(menu).find(node => node.props['data-menu-action'] === 'Editar')!
    ;(edit.props.onClick as () => void)()
    await resource.settle()
    const region = descendants(resource.root).find(node => node.tag === 'section' && node.props.tabindex === '-1')!
    const focusBeforeClose = region.focusCalls
    await closeAutoFocus(event)
    assert.equal(prevented, 1, 'A closing row menu must not return focus to the hidden list')
    assert.equal(region.focusCalls, focusBeforeClose + 1, 'Focus moves into the editor after native menu dismissal')
    const pendingFocus = closeAutoFocus(event)
    resource.currentCompany.value = { ...resource.currentCompany.value, id: 42 }
    await pendingFocus
    assert.equal(region.focusCalls, focusBeforeClose + 1, 'A stale menu close cannot refocus an old Company editor')
  })

  it('uses record-specific edit headings and restores row-menu focus after cancel and save', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const listRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(listRequest)
    listRequest.resolve({
      data: [{ id: 8, short_name: 'Quilograma', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await resource.settle()

    const trigger = rowActionTrigger(resource.root, 'Ações de Quilograma')
    assert.ok(trigger)
    assert.equal(isAttachedTo(resource.root, trigger), true)
    const edit = descendants(resource.root).find(node => node.props['data-menu-action'] === 'Editar')
    assert.ok(edit)
    ;(edit.props.onClick as (() => void) | undefined)?.()
    await resource.settle()
    const detailRequest = resource.requests.find(request => request.url === '/erp/units/8')
    assert.ok(detailRequest)
    detailRequest.resolve({ data: { id: 8, short_name: 'Quilograma', status: 'active' } })
    await resource.settle()
    assert.ok(descendants(resource.root).some(node => node.tag === 'h2' && node.text.includes('Editar · Quilograma')))

    resource.editor.cancel()
    await resource.settle()
    assert.ok(trigger.focusCalls > 0, 'cancel returns focus to the actual row action button')

    const secondEdit = descendants(resource.root).find(node => node.props['data-menu-action'] === 'Editar')
    assert.ok(secondEdit)
    ;(secondEdit.props.onClick as (() => void) | undefined)?.()
    await resource.settle()
    const secondDetail = resource.requests.filter(request => request.url === '/erp/units/8').at(-1)!
    secondDetail.resolve({ data: { id: 8, short_name: 'Quilograma', status: 'active' } })
    await resource.settle()
    const triggerBeforeSave = rowActionTrigger(resource.root, 'Ações de Quilograma')
    assert.ok(triggerBeforeSave)
    const createTriggerBeforeSave = descendants(resource.root).find(node => node.tag === 'button' && node.text === 'Adicionar unidade')
    assert.ok(createTriggerBeforeSave)
    const focusCallsBeforeSave = triggerBeforeSave.focusCalls
    assert.equal(isAttachedTo(resource.root, triggerBeforeSave), true)
    assert.equal(isAttachedTo(resource.root, createTriggerBeforeSave), true)
    const saving = resource.editor.save({ short_name: 'kg', actual_name: 'Quilograma' })
    await resource.settle()
    assert.equal(resource.editor.open.value, true, 'the editor stays open while the PATCH is pending')
    assert.equal(resource.editor.mutationPending.value, true, 'the PATCH is pending')
    assert.equal(isAttachedTo(resource.root, triggerBeforeSave), true, 'the same row action button remains mounted during the PATCH')
    assert.equal(triggerBeforeSave.props.disabled, true, 'the row action button is disabled during the PATCH')
    assert.equal(isAttachedTo(resource.root, createTriggerBeforeSave), true, 'the create button remains mounted during the PATCH')
    assert.equal(createTriggerBeforeSave.props.disabled, true, 'the create button is disabled during the PATCH')
    assert.equal(triggerBeforeSave.focusCalls, focusCallsBeforeSave, 'focus stays in the editor while the PATCH is pending')
    const mutation = resource.requests.filter(request => request.options.method === 'PATCH').at(-1)!
    mutation.resolve({ data: { id: 8, short_name: 'Quilograma', status: 'active' } })
    await resource.settle()
    const refreshedList = resource.requests.filter(request => request.url === '/erp/units?page=1').at(-1)!
    assert.notEqual(refreshedList, listRequest, 'the save started a distinct list refresh')
    assert.equal(resource.editor.open.value, false, 'the editor closes after the PATCH succeeds')
    assert.equal(resource.editor.mutationPending.value, true, 'the save stays pending while the refreshed list is held')
    assert.equal(isAttachedTo(resource.root, triggerBeforeSave), true, 'the same row action button remains mounted during list refresh')
    assert.equal(triggerBeforeSave.props.disabled, true, 'the row action button remains disabled during list refresh')
    assert.equal(isAttachedTo(resource.root, createTriggerBeforeSave), true, 'the create button remains mounted during list refresh')
    assert.equal(createTriggerBeforeSave.props.disabled, true, 'the create button remains disabled during list refresh')
    assert.equal(triggerBeforeSave.focusCalls, focusCallsBeforeSave, 'focus is not restored before list refresh completes')
    refreshedList.resolve({
      data: [{ id: 8, short_name: 'Quilograma', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await saving
    await resource.settle()
    const triggerAfterSave = rowActionTrigger(resource.root, 'Ações de Quilograma')
    assert.equal(triggerAfterSave, triggerBeforeSave, 'the mounted list keeps the same row action trigger while editing')
    assert.equal(resource.editor.mutationPending.value, false, 'the save and list refresh have settled')
    assert.equal(triggerBeforeSave.props.disabled, false, 'the row action button is enabled after the save settles')
    assert.equal(createTriggerBeforeSave.props.disabled, false, 'the create button is enabled after the save settles')
    assert.equal(descendants(resource.root).find(node => node.tag === 'button' && node.text === 'Adicionar unidade'), createTriggerBeforeSave)
    assert.equal(triggerBeforeSave.focusCalls, focusCallsBeforeSave + 1, 'saving returns focus to the connected row action trigger')
    assert.equal(isAttachedTo(resource.root, triggerBeforeSave), true)
  })

  it('ignores an old authority recovery after the selected company changes', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const firstListRequest = resource.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(firstListRequest)
    firstListRequest.reject({ statusCode: 403, data: { message: 'Acesso revogado na empresa anterior' } })
    await resource.settle()

    let resolveAuth!: (identity: unknown) => void
    const delayedAuth = new Promise<unknown>((resolve) => {
      resolveAuth = resolve
    })
    resource.setAuthRefresh(() => delayedAuth)
    const recovery = descendants(resource.root).find(node => node.tag === 'button' && node.text === 'Revalidar acesso')
    assert.ok(recovery)
    const click = recovery.props.onClick as (() => Promise<void>) | undefined
    assert.ok(click)
    const pendingRecovery = click()
    await resource.settle()

    resource.currentCompany.value = {
      id: 42,
      status: 'active',
      access_kind: 'direct',
      role: 'owner',
      permissions: ['erp.units.index', 'erp.units.show', 'erp.units.store', 'erp.units.update', 'erp.units.archive'],
      delegated_office_id: null
    }
    await resource.settle()
    const nextCompanyList = resource.requests.filter(request => request.url === '/erp/units?page=1').at(-1)!
    assert.notEqual(nextCompanyList, firstListRequest)
    nextCompanyList.resolve({
      data: [{ id: 42, short_name: 'Empresa B', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await resource.settle()

    resolveAuth({ id: 5 })
    await pendingRecovery
    await resource.settle()
    assert.equal(resource.list.authorityBlocked.value, false)
    assert.equal(resource.editor.authorityBlocked.value, false)
    assert.equal(resource.list.state.value?.data[0]?.short_name, 'Empresa B')
    assert.equal(descendants(resource.root).some(node => node.tag === 'button' && node.text === 'Revalidar acesso'), false)
  })
})
