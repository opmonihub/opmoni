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

function visibleText(root: HostNode): string {
  return descendants(root).map(node => node.text).join(' ')
}

function setupSelector(options: { optional?: boolean } = {}) {
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
  const menuOptions: Array<Record<string, unknown>> = []
  const emittedModels: unknown[] = []
  let emitModel = (_value: unknown) => {}
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
    'path': '/erp/units',
    'label': 'Unidade base',
    'labelKey': 'short_name',
    'name': 'base_unit_id',
    'optional': options.optional ?? false,
    'modelValue': 77,
    'onUpdate:modelValue': (value: unknown) => emittedModels.push(value)
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
  app.component('UFormField', Vue.defineComponent({
    inheritAttrs: false,
    props: ['name', 'label', 'error', 'description', 'required'],
    setup(props, { attrs, slots }) {
      return () => Vue.h('div', { ...attrs, 'data-field-name': props.name }, [
        Vue.h('span', String(props.label ?? '')),
        ...(slots.default?.() ?? []),
        props.description ? Vue.h('span', String(props.description)) : null,
        props.error ? Vue.h('p', { role: 'alert' }, String(props.error)) : null
      ])
    }
  }))
  app.component('USelectMenu', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'items', 'valueKey', 'labelKey', 'searchInput', 'searchTerm', 'loading', 'disabled', 'ignoreFilter', 'clear', 'required', 'name'],
    emits: ['update:modelValue', 'update:searchTerm'],
    setup(props, { attrs, slots, emit }) {
      emitModel = value => emit('update:modelValue', value)
      return () => {
        menuOptions.push({ ...props })
        return Vue.h('div', { ...attrs, 'data-nuxt-ui-component': 'USelectMenu', 'data-disabled': String(props.disabled) }, [
          Vue.h('button', { 'type': 'button', 'data-selector-trigger': 'true' }, slots.default?.()),
          ...(slots['content-bottom']?.() ?? [])
        ])
      }
    }
  }))
  app.component('UBadge', Vue.defineComponent({
    props: ['label'],
    setup(props) {
      return () => Vue.h('span', { 'data-nuxt-ui-component': 'UBadge' }, String(props.label ?? ''))
    }
  }))
  app.component('UAlert', Vue.defineComponent({
    props: ['title'],
    setup(props, { slots }) {
      return () => Vue.h('section', { 'role': 'alert', 'data-alert-title': props.title }, [
        Vue.h('p', String(props.title ?? '')),
        ...(slots.description?.() ?? []),
        ...(slots.actions?.() ?? [])
      ])
    }
  }))
  const root = makeNode('root')
  app.mount(root)

  return {
    requests,
    menuOptions,
    emittedModels,
    user,
    currentCompany,
    root,
    app,
    text: () => visibleText(root),
    buttons: () => descendants(root).filter(node => node.tag === 'button'),
    updateModel: (value: unknown) => emitModel(value),
    settle: async () => {
      await Vue.nextTick()
      await Promise.resolve()
      await Promise.resolve()
      await Vue.nextTick()
    },
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
    assert.ok(app.text().includes('Referência atual #77'))
    assert.ok(!app.text().includes('Confidencial'))
  })

  it('keeps the selected archived reference visibly marked when its detail is still authorized', async (t) => {
    const app = setupSelector()
    t.after(app.unmount)
    const detailRequest = app.requests.find(request => request.url === '/erp/units/77')
    assert.ok(detailRequest)
    detailRequest.resolve({ data: { id: 77, short_name: 'Unidade antiga', status: 'archived' } })
    await app.settle()
    assert.ok(app.text().includes('Unidade antiga · Arquivado'))
  })

  it('uses Nuxt UI value-key search, remote filtering, required and optional clear contracts', async (t) => {
    const required = setupSelector()
    const optional = setupSelector({ optional: true })
    t.after(required.unmount)
    t.after(optional.unmount)
    const requiredMenu = required.menuOptions.at(-1)
    const optionalMenu = optional.menuOptions.at(-1)
    assert.ok(requiredMenu)
    assert.equal(requiredMenu.valueKey, 'id')
    assert.equal(requiredMenu.labelKey, 'label')
    assert.equal(requiredMenu.ignoreFilter, true)
    assert.equal(requiredMenu.required, true)
    assert.equal(requiredMenu.clear, false)
    assert.equal((requiredMenu.searchInput as Record<string, unknown>)?.placeholder, 'Buscar unidade base')
    assert.equal((requiredMenu.searchInput as Record<string, unknown>)?.['aria-label'], 'Unidade base · buscar opções')
    assert.equal(descendants(required.root).find(node => node.props['data-nuxt-ui-component'] === 'USelectMenu')?.props['aria-label'], 'Unidade base')
    assert.equal(optionalMenu?.clear, true)
    assert.equal(optionalMenu?.required, false)

    required.updateModel('88')
    optional.updateModel(null)
    await required.settle()
    await optional.settle()
    assert.deepEqual(required.emittedModels, [88])
    assert.deepEqual(optional.emittedModels, [null])
  })

  it('keeps authority recovery visible when the native selector is disabled', async (t) => {
    const app = setupSelector()
    t.after(app.unmount)
    const initialList = app.requests.find(request => request.url === '/erp/units?page=1')
    assert.ok(initialList)
    initialList.reject({ statusCode: 403, data: { message: 'Acesso revogado' } })
    await app.settle()

    const menu = descendants(app.root).find(node => node.props['data-nuxt-ui-component'] === 'USelectMenu')
    const alert = descendants(app.root).find(node => node.props['data-alert-title'] === 'Acesso revogado')
    const recovery = app.buttons().find(node => node.text === 'Revalidar opções')
    assert.ok(menu)
    assert.equal(menu.props['data-disabled'], 'true')
    assert.ok(alert)
    assert.ok(recovery)
    assert.equal(descendants(menu).includes(alert), false)
    assert.equal(descendants(menu).includes(recovery), false)
  })
})
