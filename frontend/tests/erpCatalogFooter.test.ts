import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { useErpCatalogFormDirty } from '../app/composables/useErpCatalogFormDirty.ts'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type HostNode = {
  tag: string
  tagName: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
  focusCalls: number
  focus(): void
  getAttribute(name: string): string | null
  hasAttribute(name: string): boolean
  closest(selector: string): HostNode | null
  getClientRects(): Array<object>
}

function descendants(root: HostNode): HostNode[] {
  return [root, ...root.children.flatMap(descendants)]
}

function mountCatalogResource() {
  const makeNode = (tag: string, text = ''): HostNode => {
    const node: HostNode = {
      tag,
      tagName: tag.toUpperCase(),
      props: {},
      children: [],
      parent: null,
      text,
      focusCalls: 0,
      focus() { this.focusCalls++ },
      getAttribute: (name) => {
        const value = node.props[name]
        return value === undefined || value === null ? null : String(value)
      },
      hasAttribute: name => node.props[name] !== undefined && node.props[name] !== false && node.props[name] !== null,
      closest: (selector) => {
        let current: HostNode | null = node
        while (current) {
          if (selector.includes('[hidden]') && current.hasAttribute('hidden')) return current
          if (selector.includes('[inert]') && current.hasAttribute('inert')) return current
          if (selector.includes('[aria-hidden="true"]') && current.getAttribute('aria-hidden') === 'true') return current
          current = current.parent
        }
        return null
      },
      getClientRects: () => node.closest('[hidden], [inert], [aria-hidden="true"]') ? [] : [{}]
    }
    return node
  }
  const renderer = Vue.createRenderer<HostNode, HostNode>({
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

  const busy = Vue.ref(false)
  const open = Vue.ref(false)
  const error = Vue.ref('')
  const fieldErrors = Vue.ref<Record<string, string[]>>({})
  const blocked = Vue.ref(false)
  const context = Vue.ref('company-1')
  const record = Vue.ref<{ id: number } | null>(null)
  const mode = Vue.ref<'create' | 'detail' | 'edit'>('create')
  const advancedProductSectionOpen = Vue.ref(false)
  let saveCalls = 0
  let attemptDrawerClose = () => {}
  const editor = {
    record,
    mode,
    open,
    loadingDetail: Vue.ref(false),
    saving: busy,
    archiving: Vue.ref(false),
    mutationPending: Vue.computed(() => busy.value),
    authorityBlocked: Vue.computed(() => blocked.value),
    permissions: {
      show: Vue.computed(() => true),
      store: Vue.computed(() => true),
      update: Vue.computed(() => true),
      edit: Vue.computed(() => true),
      archive: Vue.computed(() => true)
    },
    canSave: Vue.computed(() => !busy.value),
    error,
    fieldErrors,
    contextKey: Vue.computed(() => context.value),
    create() {
      mode.value = 'create'
      record.value = null
      open.value = true
      error.value = ''
    },
    cancel() {
      if (!busy.value) {
        open.value = false
        record.value = null
      }
    },
    save() {
      if (busy.value) return
      saveCalls++
      busy.value = true
    },
    view: async () => undefined,
    edit: async () => undefined,
    archive: async () => undefined,
    blockAuthority() { blocked.value = true },
    refreshAuthority: async () => true
  }
  const authorityBlocked = Vue.ref(false)
  const list = {
    search: Vue.ref(''),
    sort: Vue.ref(''),
    direction: Vue.ref('asc'),
    sortableFields: ['id', 'name', 'status'],
    page: Vue.ref(1),
    pending: Vue.ref(false),
    searchPending: Vue.ref(false),
    state: Vue.ref({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    error: Vue.ref(''),
    hasLoaded: Vue.ref(true),
    stale: Vue.ref(false),
    authorityBlocked,
    refresh: async () => undefined,
    blockAuthority(message = '') {
      authorityBlocked.value = true
      list.error.value = message
    },
    retry: async () => undefined
  }
  const editorComposable = { useErpCatalogEditor: () => editor }
  const listComposable = { useErpCatalogList: () => list }
  Vue.watch(context, () => {
    open.value = false
    record.value = null
    fieldErrors.value = {}
    error.value = ''
  }, { flush: 'sync' })
  const resource = compileVueComponent(
    'components/erp/catalogs/ErpCatalogResource.vue',
    { computed: Vue.computed, ref: Vue.ref, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose },
    {
      '~/composables/useErpCatalogEditor': editorComposable,
      '~/composables/useErpCatalogList': listComposable,
      '~/components/erp/ErpCatalogPagination.vue': Vue.defineComponent({ setup: () => () => Vue.h('div') })
    }
  )
  const form = Vue.defineComponent({
    props: ['formId', 'record', 'mode', 'canSave', 'canArchive', 'busy', 'fieldErrors'],
    emits: ['save', 'dirty-change'],
    setup(props, { emit }) {
      const input = Vue.ref('')
      Vue.watch(() => props.fieldErrors, (errors) => {
        if (Object.keys(errors ?? {}).some(name => name.startsWith('variants.')))
          advancedProductSectionOpen.value = true
      }, { deep: true })
      return () => Vue.h('form', {
        id: props.formId,
        onSubmit(event: Event) {
          event.preventDefault()
          emit('save', { name: input.value })
        }
      }, [
        Vue.h('input', {
          name: 'name',
          value: input.value,
          onInput(event: Event) {
            input.value = (event.target as HTMLInputElement).value
            emit('dirty-change', true)
          }
        }),
        Vue.h('input', { 'type': 'hidden', 'name': 'erp_unit_id', 'aria-hidden': 'true', 'tabindex': -1 }),
        Vue.h('button', { 'data-erp-field-name': 'erp_unit_id', 'data-control': 'ErpCatalogReferenceSelect' }, 'Unit selector'),
        Vue.h('select', { 'name': 'kind', 'aria-hidden': 'true', 'tabindex': -1 }),
        Vue.h('button', { 'data-erp-field-name': 'kind', 'data-control': 'USelect' }, 'Kind selector'),
        ...(advancedProductSectionOpen.value
          ? [Vue.h('section', { 'data-section': 'product-variants' }, [
              Vue.h('select', { 'name': 'variants.0.barcode_type', 'aria-hidden': 'true', 'tabindex': -1 }),
              Vue.h('button', { 'data-erp-field-name': 'variants.0.barcode_type', 'data-control': 'USelect' }, 'Variant barcode selector')
            ])]
          : [])
      ])
    }
  })
  const app = renderer.createApp(resource, {
    path: '/erp/products',
    title: 'Unidades',
    form,
    columns: [],
    recordLabel: (row: { id: number }) => `Unidade ${row.id}`
  })
  app.component('DataTablePanelList', Vue.defineComponent({
    props: ['title', 'hideHeading'],
    setup: (props, { slots }) => () => Vue.h('div', [props.hideHeading ? null : Vue.h('h2', String(props.title)), slots.toolbar?.(), slots.default?.()])
  }))
  app.component('DataTableColumnMenu', Vue.defineComponent({ setup: () => () => Vue.h('button') }))
  app.component('UButton', Vue.defineComponent({
    props: ['label', 'type', 'form', 'disabled', 'loading'],
    setup(props, { attrs }) {
      return () => Vue.h('button', {
        ...attrs,
        'type': props.type,
        'form': props.form,
        'disabled': props.disabled,
        'aria-busy': props.loading ? 'true' : undefined
      }, String(props.label ?? ''))
    }
  }))
  app.component('USlideover', Vue.defineComponent({
    props: ['open', 'dismissible'],
    emits: ['update:open', 'close:prevent'],
    setup(props, { slots, emit }) {
      attemptDrawerClose = () => emit('close:prevent')
      return () => props.open
        ? Vue.h('aside', { 'data-overlay': 'editor', 'data-dismissible': String(props.dismissible) }, [
            ...(slots.body?.() ?? []), ...(slots.footer?.({}) ?? [])
          ])
        : null
    }
  }))
  app.component('UModal', Vue.defineComponent({
    props: ['open', 'title', 'description', 'dismissible'],
    emits: ['update:open', 'close:prevent'],
    setup(props, { slots, emit }) {
      const isEditor = () => /^(Novo cadastro|Editar)/.test(String(props.title))
      if (isEditor()) attemptDrawerClose = () => emit('close:prevent')
      return () => props.open
        ? Vue.h('dialog', { 'data-overlay': isEditor() ? 'editor' : 'confirmation', 'data-dismissible': String(props.dismissible) }, [
            Vue.h('h2', String(props.title ?? '')),
            Vue.h('p', String(props.description ?? '')),
            ...(slots.body?.() ?? []), ...(slots.footer?.({}) ?? [])
          ])
        : null
    }
  }))
  for (const name of ['UFormField', 'UBadge', 'UDropdownMenu', 'UInput', 'UTabs', 'UTable']) {
    app.component(name, Vue.defineComponent({
      inheritAttrs: false,
      setup(_props, { attrs, slots }) {
        return () => Vue.h('div', attrs, slots.default?.())
      }
    }))
  }
  const root = makeNode('root')
  const instance = app.mount(root) as Vue.ComponentPublicInstance & {
    createAction: { trigger(opener?: HTMLElement): boolean }
  }
  return {
    root,
    editor,
    instance,
    nodes: () => descendants(root),
    setFieldErrors: (errors: Record<string, string[]>) => { fieldErrors.value = errors },
    setContext: (value: string) => { context.value = value },
    saveCalls: () => saveCalls,
    attemptDrawerClose: () => attemptDrawerClose(),
    advancedProductSectionOpen,
    settle: async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Vue.nextTick()
    },
    stop: () => app.unmount()
  }
}

describe('ERP catalog shared editor footer', () => {
  it('tracks edits against the hydrated baseline and returns clean when values are restored', async () => {
    const state = Vue.reactive({ name: 'Original', nested: { value: 'Salvo' } })
    const editable = Vue.ref(true)
    const reported: boolean[] = []
    const scope = Vue.effectScope()
    scope.run(() => {
      const dirty = useErpCatalogFormDirty(state, () => editable.value, value => reported.push(value))
      dirty.markClean()
    })

    state.name = 'Alterado'
    await Vue.nextTick()
    assert.equal(reported.at(-1), true)
    state.name = 'Original'
    await Vue.nextTick()
    assert.equal(reported.at(-1), false, 'restoring the hydrated state removes the dirty marker')
    state.nested.value = 'Outro'
    await Vue.nextTick()
    assert.equal(reported.at(-1), true, 'nested values are included in the shared snapshot')
    editable.value = false
    await Vue.nextTick()
    assert.equal(reported.at(-1), false, 'read-only detail never reports dirty state')
    scope.stop()
  })

  it('associates the reachable primary action with the one mounted native form', async (t) => {
    const resource = mountCatalogResource()
    t.after(resource.stop)
    resource.instance.createAction.trigger()
    await resource.settle()

    const form = resource.nodes().find(node => node.tag === 'form')
    const save = resource.nodes().find(node => node.tag === 'button' && node.props.type === 'submit')
    assert.ok(resource.nodes().some(node => node.tag === 'dialog' && node.props['data-overlay'] === 'editor'), 'bounded creation uses the native modal host')
    assert.ok(form, 'the catalog editor mounts one form')
    assert.ok(form.props.id, 'the mounted form has a stable native id')
    assert.ok(save, 'the shared footer exposes the primary action')
    assert.equal(save.props.type, 'submit')
    assert.equal(save.props.form, form.props.id, 'the footer submits the mounted form by native association')
    assert.equal(resource.nodes().filter(node => node.tag === 'form').length, 1, 'moving actions preserves a single form identity')
  })

  it('keeps dirty input mounted until the shared discard confirmation is accepted', async (t) => {
    const resource = mountCatalogResource()
    t.after(resource.stop)
    resource.instance.createAction.trigger()
    await resource.settle()

    const input = resource.nodes().find(node => node.tag === 'input')!
    ;(input.props.onInput as (event: Event) => void)({ target: { value: 'Rascunho' } } as unknown as Event)
    await resource.settle()
    assert.equal(resource.nodes().find(node => node.tag === 'input')?.props.value, 'Rascunho', 'the mocked child form retains the edited value')
    const cancel = resource.nodes().find(node => node.tag === 'button' && node.text === 'Cancelar')
    assert.ok(cancel, 'the shared footer exposes cancel')
    ;(cancel.props.onClick as () => void)()
    await resource.settle()

    const sameForm = resource.nodes().find(node => node.tag === 'form')
    assert.equal(resource.editor.open.value, true, 'a dirty editor stays open while confirmation is pending')
    assert.ok(resource.nodes().some(node => node.props['data-overlay'] === 'confirmation'))
    assert.equal(sameForm?.children.find(node => node.tag === 'input')?.props.value, 'Rascunho')

    const continueEditing = () => resource.nodes().find(node => node.tag === 'button' && node.text === 'Continuar editando')!
    ;(continueEditing().props.onClick as () => void)()
    await resource.settle()
    assert.equal(resource.nodes().find(node => node.tag === 'form'), sameForm, 'canceling confirmation preserves the same form instance')
    assert.equal(resource.nodes().find(node => node.tag === 'input')?.props.value, 'Rascunho')

    resource.attemptDrawerClose()
    await resource.settle()
    assert.ok(resource.nodes().some(node => node.props['data-overlay'] === 'confirmation'), 'backdrop and close attempts use the same discard confirmation')
    ;(continueEditing().props.onClick as () => void)()
    await resource.settle()

    const region = resource.nodes().find(node => node.tag === 'section' && 'onKeydown' in node.props)!
    const escape = region.props.onKeydown as (event: { key: string, preventDefault(): void, stopPropagation(): void }) => void
    escape({ key: 'Escape', preventDefault() {}, stopPropagation() {} })
    await resource.settle()
    assert.ok(resource.nodes().some(node => node.props['data-overlay'] === 'confirmation'), 'Escape uses the same discard confirmation')
    const discard = resource.nodes().find(node => node.tag === 'button' && node.text === 'Descartar alterações')!
    ;(discard.props.onClick as () => void)()
    await resource.settle()
    assert.equal(resource.editor.open.value, false)
    assert.equal(resource.nodes().some(node => node.tag === 'form'), false)
  })

  it('disables the shared primary action while a save is pending', async (t) => {
    const resource = mountCatalogResource()
    t.after(resource.stop)
    resource.instance.createAction.trigger()
    resource.editor.saving.value = true
    await resource.settle()

    const save = resource.nodes().find(node => node.tag === 'button' && node.props.type === 'submit')
    assert.ok(save)
    assert.equal(save.props.disabled, true)
    assert.equal(save.props['aria-busy'], 'true')
  })

  it('guards duplicate submits and keeps footer actions reachable at narrow and wide widths', async (t) => {
    const resource = mountCatalogResource()
    t.after(resource.stop)
    resource.instance.createAction.trigger()
    await resource.settle()

    const form = resource.nodes().find(node => node.tag === 'form')!
    const submit = form.props.onSubmit as (event: Event) => void
    submit({ preventDefault() {} } as Event)
    submit({ preventDefault() {} } as Event)
    await resource.settle()
    assert.equal(resource.saveCalls(), 1, 'the editor rejects a second submit once the first is pending')

    const footer = resource.nodes().find(node => node.tag === 'div' && String(node.props.class).includes('flex-col-reverse'))
    assert.ok(footer, 'footer actions stack at narrow widths and keep a row layout from sm upward')
    assert.match(String(footer.props.class), /sm:flex-row/)
  })

  it('focuses visible reference and product select triggers instead of hidden native fields', async (t) => {
    const resource = mountCatalogResource()
    t.after(resource.stop)
    resource.instance.createAction.trigger()

    const previousDocument = globalThis.document
    Object.defineProperty(globalThis, 'document', {
      configurable: true,
      value: {
        getElementById: (id: string) => {
          const form = resource.nodes().find(node => node.tag === 'form' && node.props.id === id)
          if (!form) return null
          return {
            querySelectorAll: (selector: string) => descendants(form).filter((node) => {
              if (selector === '[name]')
                return node.hasAttribute('name')
              return node.hasAttribute('name') || node.hasAttribute('data-erp-field-name')
            })
          }
        }
      }
    })
    t.after(() => {
      if (previousDocument)
        Object.defineProperty(globalThis, 'document', { configurable: true, value: previousDocument })
      else
        Reflect.deleteProperty(globalThis, 'document')
    })

    await resource.settle()
    const focusError = async (name: string) => {
      const nodes = resource.nodes()
      const hiddenNativeControl = nodes.find(node => node.props.name === name)
      const visibleTrigger = nodes.find(node => node.props['data-erp-field-name'] === name)
      assert.ok(hiddenNativeControl, `${name} has a hidden native form control`)
      assert.ok(visibleTrigger, `${name} has an explicitly mapped visible trigger`)
      const priorFocusCalls = visibleTrigger.focusCalls
      resource.setFieldErrors({ [name]: ['Valor inválido.'] })
      await resource.settle()
      assert.equal(visibleTrigger.focusCalls, priorFocusCalls + 1, `${name} focuses its visible trigger`)
      assert.equal(hiddenNativeControl.focusCalls, 0, `${name} leaves the hidden native control unfocused`)
    }

    await focusError('erp_unit_id')
    await focusError('kind')
    assert.equal(resource.advancedProductSectionOpen.value, false)
    assert.equal(resource.nodes().some(node => node.props['data-section'] === 'product-variants'), false)
    resource.setFieldErrors({ 'variants.0.barcode_type': ['Simbologia inválida.'] })
    await resource.settle()
    assert.equal(resource.advancedProductSectionOpen.value, true, 'the product error opens its advanced section before focus')
    const hiddenVariantControl = resource.nodes().find(node => node.props.name === 'variants.0.barcode_type')
    const visibleVariantTrigger = resource.nodes().find(node => node.props['data-erp-field-name'] === 'variants.0.barcode_type')
    assert.ok(hiddenVariantControl)
    assert.ok(visibleVariantTrigger)
    assert.equal(visibleVariantTrigger.focusCalls, 1, 'the revealed advanced USelect trigger receives focus')
    assert.equal(hiddenVariantControl.focusCalls, 0)
  })

  it('clears a pending discard prompt when the company context changes', async (t) => {
    const resource = mountCatalogResource()
    t.after(resource.stop)
    resource.instance.createAction.trigger()
    await resource.settle()
    const input = resource.nodes().find(node => node.tag === 'input')!
    ;(input.props.onInput as (event: Event) => void)({ target: { value: 'Empresa anterior' } } as unknown as Event)
    await resource.settle()
    const cancel = resource.nodes().find(node => node.tag === 'button' && node.text === 'Cancelar')!
    ;(cancel.props.onClick as () => void)()
    await resource.settle()

    resource.setContext('company-2')
    await resource.settle()
    assert.equal(resource.editor.open.value, false)
    assert.equal(resource.nodes().some(node => node.props['data-overlay'] === 'confirmation'), false)
    assert.equal(resource.nodes().some(node => node.tag === 'form'), false)
  })

  it('returns focus to the opener when a clean editor closes', async (t) => {
    const resource = mountCatalogResource()
    t.after(resource.stop)
    const opener = {
      isConnected: true,
      focusCalls: 0,
      focus() { this.focusCalls++ }
    }
    resource.instance.createAction.trigger(opener as unknown as HTMLElement)
    await resource.settle()
    const cancel = resource.nodes().find(node => node.tag === 'button' && node.text === 'Cancelar')!
    ;(cancel.props.onClick as () => void)()
    await resource.settle()

    assert.equal(resource.editor.open.value, false)
    assert.equal(opener.focusCalls, 1)
  })
})
