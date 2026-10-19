import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { apiFailure } from '../shared/utils/access.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
  value?: unknown
  options: HostNode[]
  focus(): void
  addEventListener(): void
  removeEventListener(): void
}

interface Request {
  url: string
  options: { signal?: AbortSignal }
  resolve(value: unknown): void
  reject(reason: unknown): void
}

function hostRenderer() {
  const makeNode = (tag: string, text = ''): HostNode => ({
    tag,
    props: {},
    children: [],
    parent: null,
    text,
    options: [],
    focus() {},
    addEventListener() {},
    removeEventListener() {}
  })
  return {
    makeNode,
    renderer: Vue.createRenderer<HostNode, HostNode>({
      patchProp(node, key, _previous, next) {
        node.props[key] = next
        if (key === 'value') node.value = next
      },
      insert(node, parent, anchor = null) {
        if (node.parent) {
          const previous = node.parent.children.indexOf(node)
          if (previous >= 0) node.parent.children.splice(previous, 1)
          const oldOption = node.parent.options.indexOf(node)
          if (oldOption >= 0) node.parent.options.splice(oldOption, 1)
        }
        node.parent = parent
        const index = anchor ? parent.children.indexOf(anchor) : -1
        if (index < 0) parent.children.push(node)
        else parent.children.splice(index, 0, node)
        if (parent.tag === 'select' && node.tag === 'option') parent.options.push(node)
      },
      remove(node) {
        if (!node.parent) return
        const index = node.parent.children.indexOf(node)
        if (index >= 0) node.parent.children.splice(index, 1)
        const optionIndex = node.parent.options.indexOf(node)
        if (optionIndex >= 0) node.parent.options.splice(optionIndex, 1)
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

function setupSelector() {
  const user = Vue.ref<{ id: number } | null>({ id: 5 })
  const currentCompany = Vue.ref({
    id: 41,
    status: 'active',
    access_kind: 'direct',
    role: 'owner',
    permissions: ['erp.units.index'],
    delegated_office_id: null
  })
  const currentAccount = Vue.ref(null)
  const sessionGeneration = Vue.ref(7)
  const requests: Request[] = []
  const api = (url: string, options: Request['options'] = {}) => new Promise<unknown>((resolve, reject) => {
    requests.push({ url, options, resolve, reject })
  })
  const useAuth = () => ({ user, currentCompany, currentAccount, refresh: async () => user.value })
  const useState = <T>(key: string, init: () => T) => key === 'access-session-generation'
    ? sessionGeneration as Vue.Ref<T>
    : Vue.ref(init())
  const bindings = {
    useAuth,
    useState,
    useAccessApi: () => ({ api }),
    computed: Vue.computed,
    onScopeDispose: Vue.onScopeDispose,
    ref: Vue.ref,
    watch: Vue.watch
  }
  const listModule = loadAppComposable<{ useErpCatalogList(path: string): unknown }>(
    'composables/useErpCatalogList.ts',
    { ...bindings, apiFailure }
  )
  const contextModule = loadAppComposable<{ useErpAdministrationContext(): unknown }>(
    'composables/useErpAdministrationContext.ts',
    bindings
  )
  const component = compileVueComponent(
    'components/erp/catalogs/ErpCatalogReferenceSelect.vue',
    bindings,
    {
      '#shared/utils/access': { apiFailure },
      '~/composables/useErpCatalogList': listModule,
      '~/composables/useErpAdministrationContext': contextModule
    }
  )
  const { renderer, makeNode } = hostRenderer()
  const app = renderer.createApp(component, {
    path: '/erp/units',
    label: 'Unidade base',
    labelKey: 'short_name',
    modelValue: 77
  })
  const passthrough = Vue.defineComponent({
    inheritAttrs: false,
    props: ['label', 'disabled', 'loading', 'color', 'variant', 'modelValue'],
    setup(props, { attrs }) {
      return () => Vue.h('button', { ...attrs, disabled: props.disabled }, String(props.label ?? ''))
    }
  })
  app.component('UInput', passthrough)
  app.component('UButton', passthrough)
  const root = makeNode('root')
  app.mount(root)

  return {
    requests,
    user,
    currentCompany,
    root,
    app,
    labels: () => descendants(root).filter(node => node.tag === 'option').map(node => node.text),
    settle: async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Promise.resolve()
      await Vue.nextTick()
    },
    unmount: () => app.unmount()
  }
}

function setupProductEditor() {
  const user = Vue.ref<{ id: number } | null>({ id: 5 })
  const currentCompany = Vue.ref({
    id: 41,
    status: 'active',
    access_kind: 'direct',
    role: 'owner',
    permissions: ['erp.products.index', 'erp.units.index', 'erp.brands.index', 'erp.categories.index'],
    delegated_office_id: null
  })
  const currentAccount = Vue.ref(null)
  const sessionGeneration = Vue.ref(7)
  const api = (_url: string, _options: Request['options'] = {}) => new Promise<unknown>(() => {})
  const useAuth = () => ({ user, currentCompany, currentAccount, refresh: async () => user.value })
  const useState = <T>(key: string, init: () => T) => key === 'access-session-generation'
    ? sessionGeneration as Vue.Ref<T>
    : Vue.ref(init())
  const bindings = {
    useAuth,
    useState,
    useAccessApi: () => ({ api }),
    computed: Vue.computed,
    onScopeDispose: Vue.onScopeDispose,
    ref: Vue.ref,
    watch: Vue.watch
  }
  const listModule = loadAppComposable<{ useErpCatalogList(path: string): unknown }>(
    'composables/useErpCatalogList.ts',
    { ...bindings, apiFailure }
  )
  const contextModule = loadAppComposable<{ useErpAdministrationContext(): unknown }>(
    'composables/useErpAdministrationContext.ts',
    bindings
  )
  const component = compileVueComponent(
    'components/erp/catalogs/ErpProductCatalogForm.vue',
    bindings,
    {
      '#shared/utils/access': { apiFailure },
      '#shared/utils/erpCatalogs': {
        erpCatalogRoutes: { units: '/erp/units', brands: '/erp/brands', categories: '/erp/categories' }
      },
      '~/composables/useErpCatalogList': listModule,
      '~/composables/useErpAdministrationContext': contextModule
    }
  )
  const { renderer, makeNode } = hostRenderer()
  const saved: Array<Record<string, unknown>> = []
  const app = renderer.createApp(component, {
    record: {
      id: 88,
      name: 'Produto salvo',
      sku: 'ABC-88',
      kind: 'goods',
      track_stock: true,
      erp_unit_id: 77,
      erp_brand_id: null,
      erp_category_id: null,
      sale_price: '12.3400',
      warranty_months: null,
      warranty_description: null,
      barcode: null,
      status: 'active',
      variants: [{ id: 89, name: 'Padrão', sku: 'ABC-88', sale_price: '12.3400', barcode: null, status: 'active' }]
    },
    mode: 'edit',
    canSave: true,
    canArchive: true,
    busy: false,
    onSave: (payload: Record<string, unknown>) => saved.push(payload)
  })
  app.component('UInput', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
      return () => Vue.h('input', {
        ...attrs,
        value: props.modelValue,
        onInput: (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).value)
      })
    }
  }))
  app.component('UButton', Vue.defineComponent({
    inheritAttrs: false,
    props: ['label', 'type', 'disabled', 'loading'],
    setup(props, { attrs }) {
      return () => Vue.h('button', { ...attrs, type: props.type ?? 'button', disabled: props.disabled }, String(props.label ?? ''))
    }
  }))
  app.component('UFormField', Vue.defineComponent({
    inheritAttrs: false,
    props: ['label', 'error', 'required'],
    setup(props, { attrs, slots }) {
      return () => Vue.h('label', { ...attrs }, [Vue.h('span', String(props.label ?? '')), ...(slots.default?.() ?? [])])
    }
  }))
  const root = makeNode('root')
  app.mount(root)
  return {
    root,
    saved,
    unmount: () => app.unmount()
  }
}

describe('ERP catalog reference selector', () => {
  it('aborts and discards an off-page detail response after the real catalog read loses authority', async (t) => {
    const app = setupSelector()
    t.after(app.unmount)
    const listRequest = app.requests.find(request => request.url === '/erp/units?page=1')
    const detailRequest = app.requests.find(request => request.url === '/erp/units/77')
    assert.ok(listRequest)
    assert.ok(detailRequest)

    listRequest.reject({ statusCode: 403, data: { message: 'Permissão revogada' } })
    await app.settle()
    assert.equal(detailRequest.options.signal?.aborted, true)

    detailRequest.resolve({ data: { id: 77, short_name: 'Confidencial' } })
    await app.settle()
    assert.ok(app.labels().includes('Referência atual #77'))
    assert.ok(!app.labels().some(label => label.includes('Confidencial')))
  })

  it('keeps the selected archived reference visibly marked when its detail is still authorized', async (t) => {
    const app = setupSelector()
    t.after(app.unmount)
    const detailRequest = app.requests.find(request => request.url === '/erp/units/77')
    assert.ok(detailRequest)
    detailRequest.resolve({ data: { id: 77, short_name: 'Unidade antiga', status: 'archived' } })
    await app.settle()
    assert.ok(app.labels().includes('Unidade antiga · arquivado'))
  })

  it('prevents Enter in a nested product reference search from submitting unsaved edits', async (t) => {
    const app = setupProductEditor()
    t.after(app.unmount)
    const search = descendants(app.root).find(node =>
      node.tag === 'input' && String(node.props['aria-label']).includes('Unidade · buscar opções')
    )
    const form = descendants(app.root).find(node => node.tag === 'form')
    assert.ok(search)
    assert.ok(form)
    const onKeydown = search.props.onKeydown as ((event: KeyboardEvent) => void) | undefined
    let defaultPrevented = false
    const event = {
      key: 'Enter',
      preventDefault() { defaultPrevented = true }
    } as KeyboardEvent
    if (Array.isArray(onKeydown))
      onKeydown.forEach(handler => (handler as (event: KeyboardEvent) => void)(event))
    else
      onKeydown?.(event)

    if (!defaultPrevented) {
      const submit = form.props.onSubmit as ((event: Event) => void) | undefined
      assert.ok(submit)
      submit({ preventDefault() {} } as Event)
    }
    await Vue.nextTick()
    assert.equal(defaultPrevented, true)
    assert.deepEqual(app.saved, [])
  })
})
