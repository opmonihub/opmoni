import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import { apiFailure } from '../shared/utils/access.ts'

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  textContent: string
}

interface CatalogRequest {
  url: string
  options: { signal?: AbortSignal }
  resolve(value: unknown): void
  reject(reason: unknown): void
}

const currentCompany = Vue.ref({
  id: 41,
  status: 'active',
  access_kind: 'direct',
  role: 'owner',
  permissions: ['erp.read'],
  delegated_office_id: null
})
const currentAccount = Vue.ref(null)
const user = Vue.ref({ id: 5 })
const sessionGeneration = Vue.ref(7)

function catalogRuntime() {
  const requests: CatalogRequest[] = []
  const lists: Array<{
    state: Vue.Ref<{ data: Array<Record<string, unknown>> } | null>
    refresh(): Promise<void>
    retry(): Promise<void>
  }> = []
  const api = (url: string, options: { signal?: AbortSignal } = {}) => new Promise<unknown>((resolve, reject) => {
    requests.push({ url, options, resolve, reject })
  })
  const useAuth = () => ({ user, currentCompany, currentAccount, refresh: async () => user.value })
  const useState = <T>(key: string, init: () => T) => key === 'access-session-generation'
    ? sessionGeneration as Vue.Ref<T>
    : Vue.ref(init())
  const bindings = {
    useAuth,
    useState,
    useAccessApi: () => ({ api, refreshCsrf: async () => undefined }),
    apiFailure,
    ref: Vue.ref,
    computed: Vue.computed,
    watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose
  }
  const { useErpCatalogList } = loadAppComposable<{ useErpCatalogList(path: string): (typeof lists)[number] }>(
    'composables/useErpCatalogList.ts', bindings
  )
  const editorModule = loadAppComposable<Record<string, unknown>>('composables/useErpCatalogEditor.ts', bindings)
  const createList = (path: string) => {
    const list = useErpCatalogList(path)
    lists.push(list)
    return list
  }
  return { requests, lists, createList, bindings, editorModule }
}

function createHostRenderer() {
  const createNode = (tag: string, textContent = ''): HostNode => ({ tag, props: {}, children: [], parent: null, textContent })
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    patchProp(node, key, _old, value) { node.props[key] = value },
    insert(node, parent, anchor = null) {
      if (node.parent) {
        const previousIndex = node.parent.children.indexOf(node)
        if (previousIndex >= 0) node.parent.children.splice(previousIndex, 1)
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
    createElement: tag => createNode(tag),
    createText: text => createNode('#text', text),
    createComment: text => createNode('#comment', text),
    setText(node, text) { node.textContent = text },
    setElementText(node, text) {
      node.textContent = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insertStaticContent(content, parent, anchor) {
      const node = createNode('#static', content.replace(/<[^>]*>/g, ''))
      this.insert(node, parent, anchor)
      return [node, node]
    }
  })
  return { renderer, createNode }
}

function hostText(node: HostNode): string {
  return node.textContent + node.children.map(hostText).join('')
}

function findHostNodes(root: HostNode, predicate: (node: HostNode) => boolean): HostNode[] {
  const found: HostNode[] = []
  function visit(node: HostNode) {
    if (predicate(node)) found.push(node)
    node.children.forEach(visit)
  }
  visit(root)
  return found
}

function slotWrapper(name: string, tag: string) {
  return Vue.defineComponent({
    name,
    setup(_, { slots, attrs }) {
      return () => Vue.h(tag, attrs, [slots.header?.(), slots.default?.(), slots.actions?.()].filter(Boolean))
    }
  })
}

function mountCatalogPage(path: string) {
  const runtime = catalogRuntime()
  const page = compileVueComponent(path, {
    ...runtime.bindings,
    useErpCatalogList: runtime.createList,
    definePageMeta() {},
    useHead() {}
  }, {
    '~/composables/useErpCatalogList': { useErpCatalogList: runtime.createList },
    '~/composables/useErpCatalogEditor': runtime.editorModule
  })
  const pagination = compileVueComponent('components/erp/ErpCatalogPagination.vue')
  const { renderer, createNode } = createHostRenderer()
  const app = renderer.createApp(page)
  app.component('ErpCatalogPageShell', slotWrapper('ErpCatalogPageShell', 'main'))
  app.component('UCard', slotWrapper('UCard', 'section'))
  app.component('UFormField', slotWrapper('UFormField', 'label'))
  app.component('UModal', Vue.defineComponent({
    props: ['open'],
    setup(props, { slots }) {
      return () => props.open ? Vue.h('dialog', {}, [slots.body?.(), slots.footer?.()]) : null
    }
  }))
  app.component('UInput', Vue.defineComponent({
    name: 'UInput',
    props: ['modelValue', 'placeholder'],
    setup(props) { return () => Vue.h('input', { value: props.modelValue, placeholder: props.placeholder }) }
  }))
  app.component('UTable', Vue.defineComponent({
    name: 'UTable',
    props: ['data', 'loading', 'columns'],
    setup(props) {
      return () => Vue.h('table', { 'data-loading': props.loading }, (props.data as Record<string, unknown>[]).map(row =>
        Vue.h('tr', {}, [Vue.h('td', {}, JSON.stringify(row))])
      ))
    }
  }))
  app.component('UAlert', Vue.defineComponent({
    name: 'UAlert',
    props: ['title', 'description', 'color'],
    setup(props, { slots }) {
      return () => Vue.h('div', { role: 'alert' }, [
        Vue.h('strong', {}, String(props.title ?? '')),
        Vue.h('span', {}, String(props.description ?? '')),
        slots.actions?.()
      ])
    }
  }))
  app.component('UButton', Vue.defineComponent({
    name: 'UButton',
    inheritAttrs: false,
    props: ['label', 'loading', 'color', 'variant', 'type'],
    setup(props, { attrs }) {
      return () => Vue.h('button', { ...attrs, type: props.type ?? 'button' }, String(props.label ?? ''))
    }
  }))
  app.component('UPagination', Vue.defineComponent({
    name: 'UPagination',
    props: ['page', 'total', 'itemsPerPage', 'disabled'],
    setup() { return () => Vue.h('nav', {}, 'Paginação') }
  }))
  app.component('ErpCatalogPagination', pagination)
  const root = createNode('root')
  app.mount(root)

  return {
    ...runtime,
    root,
    text: () => hostText(root),
    tables: () => findHostNodes(root, node => node.tag === 'table'),
    buttons: () => findHostNodes(root, node => node.tag === 'button'),
    click: (button: HostNode) => {
      const handler = button.props.onClick as (() => void) | Array<() => void> | undefined
      if (Array.isArray(handler)) handler.forEach(callback => callback())
      else handler?.()
    },
    unmount: () => app.unmount()
  }
}

async function settle() {
  await Vue.nextTick()
  await Promise.resolve()
  await Vue.nextTick()
}

describe('ERP catalog list SFC states', () => {
  it('mounts the real Products page without rendering initial or failed loads as an empty table', async (t) => {
    const page = mountCatalogPage('pages/erp/cadastros/produtos.vue')
    t.after(page.unmount)
    assert.equal(page.requests.length, 1)
    assert.equal(page.tables().length, 0)
    assert.match(page.text(), /carregando/i)

    page.requests[0]!.reject({ statusCode: 503 })
    await settle()
    assert.equal(page.tables().length, 0)
    assert.match(page.text(), /temporariamente indisponível/i)
    assert.equal(page.buttons().length, 1)

    page.click(page.buttons()[0]!)
    assert.equal(page.requests.length, 2)
    page.requests[1]!.resolve({
      data: [{ id: 1, name: 'Produto confirmado', sku: 'P-1', sale_price: '10.00', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    await settle()
    assert.equal(page.tables().length, 1)
    assert.match(page.text(), /Produto confirmado/)

    const refresh = page.lists[0]!.refresh()
    page.requests[2]!.reject({ statusCode: 503 })
    await refresh
    await settle()
    assert.equal(page.tables().length, 1)
    assert.match(page.text(), /lista desatualizada/i)
    assert.match(page.text(), /Produto confirmado/)
  })

  it('keeps the real Pricing page lists independent through pending, error, retry and confirmed empty', async (t) => {
    const page = mountCatalogPage('pages/erp/cadastros/precos.vue')
    t.after(page.unmount)
    assert.equal(page.requests.length, 2)
    assert.equal(page.tables().length, 0)
    assert.equal((page.text().match(/carregando/gi) ?? []).length, 2)

    page.requests[0]!.resolve({
      data: [{ id: 1, name: 'Grupo normal', percentage_adjustment: '0.0000', is_default: true, status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 }
    })
    page.requests[1]!.reject({ statusCode: 503 })
    await settle()
    assert.equal(page.tables().length, 1)
    assert.match(page.text(), /Grupo normal/)
    assert.match(page.text(), /temporariamente indisponível/i)
    assert.equal(page.lists[0]!.state.value?.data.length, 1)
    assert.equal(page.lists[1]!.state.value, null)

    const retry = page.lists[1]!.retry()
    assert.equal(page.requests.length, 3)
    page.requests[2]!.resolve({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 }
    })
    await retry
    await settle()
    assert.equal(page.tables().length, 1)
    assert.match(page.text(), /nenhum registro encontrado/i)
    assert.match(page.text(), /Grupo normal/)
  })
})
