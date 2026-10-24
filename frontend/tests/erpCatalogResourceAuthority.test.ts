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

function setupResource(options: { accessKind?: 'direct' | 'delegated', permissions?: string[], detailPanel?: Vue.Component, detailFormLabel?: string, detailPanelLabel?: string, realUnitForm?: boolean } = {}) {
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
  let referenceMountCount = 0
  const referenceSelect = Vue.defineComponent({
    setup() {
      Vue.onMounted(() => {
        referenceMountCount++
      })
      return () => Vue.h('select', { 'data-reference-select': 'base_unit_id' })
    }
  })
  const resourceForm = options.realUnitForm
    ? compileVueComponent(
        'components/erp/catalogs/ErpUnitCatalogForm.vue',
        bindings,
        { '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': referenceSelect }
      )
    : Vue.defineComponent({
        props: ['fieldErrors'],
        setup(props) {
          const draft = Vue.ref('')
          return () => Vue.h('form', [
            Vue.h('input', {
              'data-draft': 'short_name',
              'value': draft.value,
              'onInput': (event: { target: { value: string } }) => { draft.value = event.target.value }
            }),
            props.fieldErrors?.short_name?.[0]
              ? Vue.h('p', { 'role': 'alert', 'data-field-error': 'short_name' }, props.fieldErrors.short_name[0])
              : null
          ])
        }
      })
  const resourceProps = {
    path: '/erp/units',
    title: 'Unidades',
    detailFormLabel: options.detailFormLabel,
    detailPanelLabel: options.detailPanelLabel,
    form: resourceForm,
    columns: [{ accessorKey: 'status', header: 'Status' }],
    recordLabel: (record: { short_name?: string, id: number }) => record.short_name ?? `Unidade ${record.id}`
  }
  const rootComponent = options.detailPanel
    ? Vue.defineComponent({
        setup: () => () => Vue.h(component, resourceProps, {
          'detail-panel': () => Vue.h(options.detailPanel!, { contactId: 31 })
        })
      })
    : component
  const app = options.detailPanel
    ? host.createApp(rootComponent)
    : host.createApp(rootComponent, resourceProps)
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
  const slideover = Vue.defineComponent({
    props: ['open', 'title', 'description', 'unmountOnHide', 'content'],
    emits: ['update:open'],
    setup(props, { slots }) {
      return () => {
        const mountBody = props.open || props.unmountOnHide !== true
        return Vue.h('div', { 'data-slideover-open': String(props.open), 'data-slideover-unmount-on-hide': String(props.unmountOnHide) }, [
          Vue.h('h2', String(props.title ?? '')),
          Vue.h('p', String(props.description ?? '')),
          ...(mountBody ? [slots.body?.(), slots.footer?.()] : [])
        ])
      }
    }
  })
  for (const name of ['UButton', 'UCard', ...(options.detailPanel || options.realUnitForm ? [] : ['UFormField', 'UInput'])])
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
  app.component('USlideover', slideover)
  app.component('UTabs', Vue.defineComponent({
    props: ['items', 'modelValue', 'unmountOnHide'],
    emits: ['update:modelValue'],
    setup(props, { emit, slots }) {
      return () => Vue.h('div', { 'data-tabs-unmount-on-hide': String(props.unmountOnHide) }, [
        ...((props.items as Array<{ label: string, value: string, slot: string }> | undefined) ?? []).map(item => Vue.h('button', {
          'type': 'button',
          'data-tab-value': item.value,
          'onClick': () => emit('update:modelValue', item.value)
        }, item.label)),
        ...((props.items as Array<{ label: string, value: string, slot: string }> | undefined) ?? [])
          .filter(item => props.unmountOnHide !== true || item.value === props.modelValue)
          .map(item => Vue.h('section', { 'data-tab-panel': item.value, 'hidden': item.value !== props.modelValue }, slots[item.slot]?.()))
      ])
    }
  }))
  app.component('UAlert', Vue.defineComponent({
    props: ['title'],
    setup(props, { slots }) {
      return () => Vue.h('section', { role: 'alert' }, [Vue.h('p', String(props.title ?? '')), slots.actions?.()])
    }
  }))
  if (options.detailPanel || options.realUnitForm) {
    app.component('UForm', Vue.defineComponent({
      props: ['state'],
      emits: ['submit'],
      setup(props, { emit, slots }) {
        return () => Vue.h('form', {
          onSubmit: (event: { preventDefault(): void }) => {
            event.preventDefault()
            emit('submit', { data: props.state })
          }
        }, slots.default?.())
      }
    }))
    app.component('UFormField', Vue.defineComponent({
      props: ['label', 'name', 'error'],
      setup(props, { slots }) {
        return () => Vue.h('div', { 'data-field': props.name }, [
          Vue.h('label', String(props.label ?? '')),
          slots.default?.(),
          props.error ? Vue.h('span', { 'data-error': props.error }, String(props.error)) : null
        ])
      }
    }))
    app.component('UInput', Vue.defineComponent({
      props: ['modelValue', 'name', 'type', 'disabled', 'maxlength'],
      emits: ['update:modelValue'],
      setup(props, { emit }) {
        return () => Vue.h('input', {
          name: props.name,
          type: props.type ?? 'text',
          value: props.modelValue ?? '',
          disabled: props.disabled,
          maxlength: props.maxlength,
          onInput: (event: { target: { value: string } }) => emit('update:modelValue', event.target.value)
        })
      }
    }))
    app.component('UTextarea', Vue.defineComponent({
      props: ['modelValue', 'name', 'disabled', 'maxlength'],
      emits: ['update:modelValue'],
      setup(props, { emit }) {
        return () => Vue.h('textarea', {
          name: props.name,
          value: props.modelValue ?? '',
          disabled: props.disabled,
          maxlength: props.maxlength,
          onInput: (event: { target: { value: string } }) => emit('update:modelValue', event.target.value)
        })
      }
    }))
    app.component('UCheckbox', Vue.defineComponent({
      props: ['modelValue', 'name', 'label', 'disabled'],
      emits: ['update:modelValue'],
      setup(props, { emit }) {
        return () => Vue.h('label', [Vue.h('input', {
          type: 'checkbox',
          name: props.name,
          checked: props.modelValue,
          disabled: props.disabled,
          onChange: (event: { target: { checked: boolean } }) => emit('update:modelValue', event.target.checked)
        }), String(props.label ?? '')])
      }
    }))
    app.component('UFileUpload', Vue.defineComponent({
      props: ['modelValue', 'name', 'disabled', 'fileImage', 'accept'],
      emits: ['update:modelValue'],
      setup(props, { emit }) {
        return () => Vue.h('input', {
          'type': 'file',
          'name': props.name,
          'disabled': props.disabled,
          'accept': props.accept,
          'data-file-image': String(props.fileImage),
          'data-selected-file': (props.modelValue as File | null)?.name ?? '',
          'onChange': (event: { target: { files: File[] } }) => emit('update:modelValue', event.target.files[0] ?? null)
        })
      }
    }))
  }
  app.component('ErpListFooter', compileVueComponent('components/erp/ErpListFooter.vue'))
  app.component('UPagination', Vue.defineComponent({ setup: () => () => Vue.h('div') }))
  app.component('UTable', table)
  const root = makeNode('root')
  const instance = app.mount(root) as Vue.ComponentPublicInstance & { createAction: { label: string, allowed: boolean, busy: boolean, trigger: () => void } }
  return {
    root,
    instance,
    allNodes,
    list,
    editor,
    requests,
    get referenceMountCount() { return referenceMountCount },
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
  it('exposes its permission-gated create action and keeps the list mounted through the focused editor lifecycle', async (t) => {
    const resource = setupResource({ realUnitForm: true })
    t.after(resource.stop)
    const listRequest = resource.requests.find(request => request.url === '/erp/units?page=1')!
    listRequest.resolve({
      data: [{ id: 8, short_name: 'Quilograma', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await resource.settle()

    assert.equal(resource.instance.createAction.allowed, true)
    assert.equal(resource.instance.createAction.label, 'Adicionar unidade')
    assert.equal(resource.editor.open.value, false, 'the editor starts closed')
    assert.equal(descendants(resource.root).some(node => node.tag === 'input' && node.props.name === 'short_name'), false, 'the real form is not mounted while the editor is closed')
    assert.equal(descendants(resource.root).some(node => node.props['data-reference-select'] === 'base_unit_id'), false, 'reference selectors are not mounted while the editor is closed')
    assert.equal(resource.referenceMountCount, 0)
    const list = descendants(resource.root).find(node => node.props['data-row-count'] === '1')
    assert.ok(list, 'the list is rendered before an editor action')

    resource.instance.createAction.trigger()
    await resource.settle()
    assert.equal(resource.editor.open.value, true, 'the explicit navbar action opens create mode')
    assert.equal(descendants(resource.root).some(node => node.props['data-slideover-unmount-on-hide'] === 'true'), true, 'the resource opts into native closed-body unmounting')
    assert.equal(resource.editor.mode.value, 'create')
    assert.equal(resource.referenceMountCount, 1, 'opening creates one editor-session reference selector')
    assert.equal(isAttachedTo(resource.root, list), true, 'the list remains mounted while the editor is open')
    const listRegion = descendants(resource.root).find(node => node.props.class === 'space-y-4' && descendants(node).includes(list))
    assert.ok(listRegion)
    assert.equal(String(listRegion.props.class).includes('hidden'), false, 'the list stays visible behind the native overlay')
    assert.equal(descendants(resource.root).some(node => node.props['data-slideover-open'] === 'true'), true)

    const close = descendants(resource.root).find(node => node.tag === 'button' && node.text === 'Cancelar')
    assert.ok(close)
    ;(close.props.onClick as (() => void) | undefined)?.()
    await resource.settle()
    assert.equal(resource.editor.open.value, false)
    assert.equal(resource.editor.record.value, null)
    assert.equal(isAttachedTo(resource.root, list), true)
    assert.equal(descendants(resource.root).some(node => node.tag === 'input' && node.props.name === 'short_name'), false, 'cancel unmounts the form')
    assert.equal(descendants(resource.root).some(node => node.props['data-reference-select'] === 'base_unit_id'), false, 'cancel unmounts reference selectors')

    resource.instance.createAction.trigger()
    await resource.settle()
    const draft = descendants(resource.root).find(node => node.tag === 'input' && node.props.name === 'short_name')
    assert.ok(draft)
    assert.equal(draft.props.value, '', 'reopening starts with a fresh create draft')
    draft.props.onInput?.({ target: { value: 'Rascunho' } })
    const saving = resource.editor.save({ short_name: 'Rascunho', actual_name: 'Rascunho' })
    await resource.settle()
    const post = resource.requests.find(request => request.options.method === 'POST')
    assert.ok(post)
    post.reject({ statusCode: 422, data: { errors: { short_name: ['A sigla já está em uso.'] } } })
    await saving
    await resource.settle()
    assert.equal(resource.editor.open.value, true, 'validation failures keep the native editor open')
    assert.equal(draft.props.value, 'Rascunho', 'the form draft remains mounted after a rejected save')
    assert.equal(descendants(resource.root).some(node => node.props['data-error'] === 'A sigla já está em uso.'), true, 'the real form displays the rejected field error')
    resource.editor.cancel()
    await resource.settle()

    resource.instance.createAction.trigger()
    await resource.settle()
    const authorityDraft = descendants(resource.root).find(node => node.tag === 'input' && node.props.name === 'short_name')
    assert.ok(authorityDraft)
    assert.equal(authorityDraft.props.value, '', 'canceling a nonempty rejected create draft starts the next session blank')
    authorityDraft.props.onInput?.({ target: { value: 'Rascunho protegido' } })
    await resource.settle()
    assert.equal(authorityDraft.props.value, 'Rascunho protegido')

    resource.currentCompany.value = { ...resource.currentCompany.value, permissions: ['erp.units.index'] }
    await resource.settle()
    assert.equal(resource.instance.createAction.allowed, false, 'the exposed action follows the current store grant')
    assert.equal(resource.editor.open.value, false, 'same-company grant invalidation closes the editor session')
    assert.equal(descendants(resource.root).some(node => node.tag === 'input' && node.props.name === 'short_name'), false, 'grant invalidation unmounts the real form')
    assert.equal(descendants(resource.root).some(node => node.props['data-reference-select'] === 'base_unit_id'), false, 'grant invalidation unmounts reference selectors')
    resource.instance.createAction.trigger()
    await resource.settle()
    assert.equal(resource.editor.open.value, false, 'the guarded navbar action cannot open after its grant is removed')

    resource.currentCompany.value = { ...resource.currentCompany.value, permissions: ['erp.units.index', 'erp.units.store'] }
    await resource.settle()
    resource.instance.createAction.trigger()
    await resource.settle()
    const restoredDraft = descendants(resource.root).find(node => node.tag === 'input' && node.props.name === 'short_name')
    assert.ok(restoredDraft)
    assert.equal(restoredDraft.props.value, '', 'restoring a same-company grant opens a fresh form')
    assert.equal(resource.referenceMountCount, 4, 'each opened editor session mounts a fresh reference selector')
  })

  it('keeps the real Contact notes panel draft and selected File across native-style detail tab switches', async (t) => {
    const noteRow = {
      id: 7,
      heading: 'Acordo comercial',
      description: 'Revisar no próximo mês.',
      is_private: false,
      state: 'active' as const,
      can_change_privacy: true,
      attachments_count: 0,
      created_at: '2026-10-19T12:00:00.000Z',
      updated_at: '2026-10-19T12:00:00.000Z'
    }
    const noteDetail = { ...noteRow, attachments: [], deletion_pending_attachments_count: 0 }
    const rows = Vue.ref([noteRow])
    const selectedNote = Vue.ref<typeof noteDetail | null>(null)
    const contextKey = Vue.ref('actor-5-company-41-contact-31')
    const noteApi = {
      notes: rows,
      selectedNote,
      page: Vue.ref(1),
      filter: Vue.ref<'active' | 'deletion_pending'>('active'),
      listMeta: Vue.ref({ current_page: 1, last_page: 1, per_page: 25, total: 1, state: 'active' as const }),
      attachmentsMeta: Vue.ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 }),
      pending: Vue.ref(false),
      hasLoaded: Vue.ref(true),
      loadingDetail: Vue.ref(false),
      saving: Vue.ref(false),
      archiving: Vue.ref(false),
      uploading: Vue.ref(false),
      archivingAttachment: Vue.ref(false),
      mutationPending: Vue.ref(false),
      error: Vue.ref(''),
      fieldErrors: Vue.ref<Record<string, string[]>>({}),
      stale: Vue.ref(false),
      authorityBlocked: Vue.ref(false),
      noteVisibilityEvent: Vue.ref(null),
      noteVisibilityRecovery: Vue.ref(null),
      permissions: {
        index: Vue.ref(true), show: Vue.ref(true), store: Vue.ref(true), update: Vue.ref(true), archive: Vue.ref(true),
        attachmentStore: Vue.ref(true), attachmentDownload: Vue.ref(true), attachmentArchive: Vue.ref(true)
      },
      contextKey,
      refresh: async () => undefined,
      setFilter: async () => undefined,
      selectNote: async () => { selectedNote.value = noteDetail },
      createNote: async () => false,
      updateNote: async () => false,
      archiveNote: async () => false,
      uploadAttachment: async () => false,
      downloadAttachment: async () => true,
      archiveAttachment: async () => false
    }
    const panel = compileVueComponent(
      'components/erp/catalogs/ErpContactNotesPanel.vue',
      {},
      { '~/composables/useErpContactNotes': { useErpContactNotes: () => noteApi } }
    )
    const resource = setupResource({ detailPanel: panel, detailFormLabel: 'Dados do cadastro', detailPanelLabel: 'Notas e anexos' })
    t.after(resource.stop)
    resource.requests.find(request => request.url === '/erp/units?page=1')!.resolve({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 }
    })
    await resource.settle()

    const opening = resource.editor.view(24)
    resource.requests.find(request => request.url === '/erp/units/24')!.resolve({ data: { id: 24, short_name: 'Contato', status: 'active' } })
    await opening
    await resource.settle()
    const findAll = (predicate: (node: HostNode) => boolean) => descendants(resource.root).filter(predicate)
    const tab = (label: string) => findAll(node => node.tag === 'button' && node.text === label)[0]
    tab('Notas e anexos').props.onClick?.()
    await resource.settle()
    const headingInputs = () => findAll(node => node.tag === 'input' && node.props.name === 'heading')
    headingInputs()[0].props.onInput?.({ target: { value: 'Novo rascunho para depois' } })
    tab('Ver detalhes').props.onClick?.()
    await resource.settle()
    headingInputs()[1].props.onInput?.({ target: { value: 'Editar antes de trocar' } })
    const fileInput = findAll(node => node.tag === 'input' && node.props.type === 'file')[0]
    const file = new File(['contract'], 'contrato.pdf', { type: 'application/pdf' })
    fileInput.props.onChange?.({ target: { files: [file] } })
    await resource.settle()

    tab('Dados do cadastro').props.onClick?.()
    await resource.settle()
    assert.equal(headingInputs().length, 2, 'the hidden notes tab keeps the real panel mounted')
    assert.equal(isAttachedTo(resource.root, fileInput), true, 'the selected File input remains attached while its tab is hidden')
    assert.equal(headingInputs()[0]?.props.value, 'Novo rascunho para depois')
    assert.equal(headingInputs()[1]?.props.value, 'Editar antes de trocar')
    assert.equal(fileInput.props['data-selected-file'], 'contrato.pdf')
    assert.equal(findAll(node => node.props['data-tab-panel'] === 'panel' && node.props.hidden === true).length, 1)

    tab('Notas e anexos').props.onClick?.()
    await resource.settle()
    assert.equal(headingInputs()[0].props.value, 'Novo rascunho para depois')
    assert.equal(headingInputs()[1].props.value, 'Editar antes de trocar')
    assert.equal(fileInput.props['data-selected-file'], 'contrato.pdf')
  })

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

  it('keeps search available and shows only the confirmed server count in the real footer', async (t) => {
    const resource = setupResource()
    t.after(resource.stop)
    const search = descendants(resource.root).find(node => node.props.placeholder === 'Buscar unidades')
    assert.ok(search)
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
    const focusCallsBeforeSave = triggerBeforeSave.focusCalls
    assert.equal(isAttachedTo(resource.root, triggerBeforeSave), true)
    const saving = resource.editor.save({ short_name: 'kg', actual_name: 'Quilograma' })
    await resource.settle()
    assert.equal(resource.editor.open.value, true, 'the editor stays open while the PATCH is pending')
    assert.equal(resource.editor.mutationPending.value, true, 'the PATCH is pending')
    assert.equal(isAttachedTo(resource.root, triggerBeforeSave), true, 'the same row action button remains mounted during the PATCH')
    assert.equal(triggerBeforeSave.props.disabled, true, 'the row action button is disabled during the PATCH')
    assert.equal(resource.instance.createAction.busy, true, 'the exposed create action is busy during the PATCH')
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
    assert.equal(resource.instance.createAction.busy, true, 'the create action remains disabled during list refresh')
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
    assert.equal(resource.instance.createAction.busy, false, 'the create action is enabled after the save settles')
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
