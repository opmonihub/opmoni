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
}

type LookupRequest = {
  url: string
  options: { signal?: AbortSignal }
  resolve(value: unknown): void
  reject(reason: unknown): void
}

function descendants(node: HostNode): HostNode[] {
  return [node, ...node.children.flatMap(descendants)]
}

function mountContact(record: Record<string, unknown> | null = null, realCustomerGroupLookup = false) {
  const emitted: Array<Record<string, unknown>> = []
  const props = Vue.reactive({ record, mode: record ? 'edit' : 'create', canSave: true, busy: false, fieldErrors: {} as Record<string, string[]> })
  const lookupRequests: LookupRequest[] = []
  const menuOptions: Array<Record<string, unknown>> = []
  const user = Vue.ref<{ id: number } | null>({ id: 5 })
  const currentCompany = Vue.ref({
    id: 41,
    status: 'active',
    access_kind: 'direct',
    role: 'owner',
    permissions: ['erp.customer-groups.index'],
    delegated_office_id: null
  })
  const currentAccount = Vue.ref(null)
  const sessionGeneration = Vue.ref(7)
  const api = (url: string, options: LookupRequest['options'] = {}) => new Promise<unknown>((resolve, reject) => {
    lookupRequests.push({ url, options, resolve, reject })
  })
  const bindings: Record<string, unknown> = realCustomerGroupLookup
    ? {
        computed: Vue.computed,
        onScopeDispose: Vue.onScopeDispose,
        ref: Vue.ref,
        watch: Vue.watch,
        useAccessApi: () => ({ api }),
        useAuth: () => ({ user, currentCompany, currentAccount, refresh: async () => user.value }),
        useState: <T>(key: string, init: () => T) => key === 'access-session-generation' ? sessionGeneration as Vue.Ref<T> : Vue.ref(init())
      }
    : {}
  const makeNode = (tag: string, text = ''): HostNode => ({ tag, props: {}, children: [], parent: null, text })
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
  const referenceSelect = Vue.defineComponent({
    props: ['modelValue', 'label', 'name', 'error', 'disabled', 'optional'],
    emits: ['update:modelValue'],
    setup(_props, { slots }) { return () => Vue.h('div', slots.default?.()) }
  })
  const referenceMocks: Record<string, unknown> = { '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': referenceSelect }
  if (realCustomerGroupLookup) {
    const listModule = loadAppComposable('composables/useErpCatalogList.ts', { ...bindings, apiFailure })
    const contextModule = loadAppComposable('composables/useErpAdministrationContext.ts', bindings)
    referenceMocks['~/components/erp/catalogs/ErpCatalogReferenceSelect.vue'] = compileVueComponent(
      'components/erp/catalogs/ErpCatalogReferenceSelect.vue',
      bindings,
      {
        '#shared/utils/access': { apiFailure },
        '~/composables/useErpCatalogList': listModule,
        '~/composables/useErpAdministrationContext': contextModule
      }
    )
  }
  const component = compileVueComponent('components/erp/catalogs/ErpContactCatalogForm.vue', bindings, referenceMocks)
  const wrapper = Vue.defineComponent({
    setup() {
      return () => Vue.h(component, { ...props, onSave: (value: Record<string, unknown>) => emitted.push(value) })
    }
  })
  const app = renderer.createApp(wrapper)
  app.component('UForm', Vue.defineComponent({
    props: ['state', 'disabled'], emits: ['submit'],
    setup(_props, { attrs, emit, slots }) {
      return () => Vue.h('form', { ...attrs, onSubmit: (event: Event) => emit('submit', event) }, slots.default?.())
    }
  }))
  app.component('UInput', Vue.defineComponent({
    props: ['modelValue'], emits: ['update:modelValue'],
    setup(inputProps, { attrs, emit }) {
      return () => Vue.h('input', { ...attrs, value: inputProps.modelValue, onInput: (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).value) })
    }
  }))
  app.component('UButton', Vue.defineComponent({
    props: ['label', 'type', 'disabled'],
    setup(buttonProps, { attrs }) { return () => Vue.h('button', { ...attrs, type: buttonProps.type, disabled: buttonProps.disabled }, String(buttonProps.label ?? '')) }
  }))
  app.component('UFormField', Vue.defineComponent({
    props: ['label', 'name', 'error'],
    setup(fieldProps, { slots }) {
      return () => Vue.h('label', { 'data-uform-field': fieldProps.name }, [Vue.h('span', String(fieldProps.label ?? '')), ...(slots.default?.() ?? []), fieldProps.error ? Vue.h('p', { role: 'alert' }, fieldProps.error) : null])
    }
  }))
  app.component('UCheckbox', Vue.defineComponent({
    props: ['modelValue', 'label'], emits: ['update:modelValue'],
    setup(inputProps, { attrs, emit }) {
      return () => Vue.h('input', { ...attrs, type: 'checkbox', checked: inputProps.modelValue, onChange: (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).checked) })
    }
  }))
  app.component('UTextarea', Vue.defineComponent({
    props: ['modelValue'], emits: ['update:modelValue'],
    setup(inputProps, { attrs, emit }) {
      return () => Vue.h('textarea', { ...attrs, value: inputProps.modelValue, onInput: (event: Event) => emit('update:modelValue', (event.target as HTMLTextAreaElement).value) })
    }
  }))
  app.component('USelectMenu', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'items', 'valueKey', 'labelKey', 'searchInput', 'searchTerm', 'loading', 'disabled', 'ignoreFilter', 'clear', 'required', 'name'],
    emits: ['update:modelValue', 'update:searchTerm'],
    setup(menuProps, { attrs, slots }) {
      return () => {
        menuOptions.push({ ...menuProps })
        return Vue.h('div', { ...attrs, 'data-nuxt-ui-component': 'USelectMenu' }, [
          Vue.h('button', { type: 'button' }, slots.default?.()),
          ...(slots.empty?.() ?? []),
          ...(slots['content-bottom']?.() ?? [])
        ])
      }
    }
  }))
  app.component('UBadge', Vue.defineComponent({
    props: ['label'],
    setup(badgeProps) { return () => Vue.h('span', String(badgeProps.label ?? '')) }
  }))
  app.component('UAlert', Vue.defineComponent({
    props: ['title'],
    setup(alertProps, { slots }) {
      return () => Vue.h('section', { 'role': 'alert', 'data-alert-title': alertProps.title }, [
        Vue.h('p', String(alertProps.title ?? '')),
        ...(slots.description?.() ?? []),
        ...(slots.actions?.() ?? [])
      ])
    }
  }))
  const root = makeNode('root')
  app.mount(root)
  const input = async (name: string, value: string) => {
    inputNow(name, value)
    await Vue.nextTick()
  }
  const inputNow = (name: string, value: string) => {
    const element = descendants(root).find(node => node.tag === 'input' && node.props.name === name)
    assert.ok(element, `Expected input ${name}`)
    ;(element.props.onInput as (event: Event) => void)({ target: { value } } as unknown as Event)
  }
  const settle = async () => {
    await Vue.nextTick()
    await Promise.resolve()
    await Promise.resolve()
    await Vue.nextTick()
  }
  const submit = async () => {
    const form = descendants(root).find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: Event) => void)({ preventDefault() {} } as Event)
    await Vue.nextTick()
  }
  return {
    emitted,
    input,
    inputNow,
    lookupRequests,
    menuOptions,
    props,
    root,
    submit,
    settle,
    clickButton: async (label: string) => {
      const button = descendants(root).find(node => node.tag === 'button' && node.text === label)
      assert.ok(button, `Expected button ${label}`)
      ;(button.props.onClick as (() => void | Promise<void>) | undefined)?.()
      await settle()
    },
    unmount: () => app.unmount()
  }
}

describe('ERP contact geography', () => {
  it('saves a minimal contact without requiring a fiscal address', async (t) => {
    const form = mountContact()
    t.after(form.unmount)
    await form.input('name', 'Contato comercial')
    await form.submit()
    assert.equal(form.emitted[0]?.name, 'Contato comercial')
    assert.equal(form.emitted[0]?.address, null)
  })

  it('clears an old municipality code when city or state changes and preserves other address extensions', async (t) => {
    const form = mountContact({
      name: 'Comercial Silva', is_customer: true, is_supplier: false,
      address: { city: 'São Paulo', state: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' }
    })
    t.after(form.unmount)
    await form.input('city', 'Campinas')
    await form.submit()
    assert.deepEqual(form.emitted[0]?.address, { city: 'Campinas', state: 'SP', integration_code: 'CLIENT-77' })
  })

  it('clears an old municipality code when the state changes', async (t) => {
    const form = mountContact({
      name: 'Comercial Silva', is_customer: true, is_supplier: false,
      address: { city: 'São Paulo', state: 'SP', uf: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' }
    })
    t.after(form.unmount)
    await form.input('state', 'RJ')
    await form.submit()
    assert.deepEqual(form.emitted[0]?.address, { city: 'São Paulo', state: 'RJ', uf: 'RJ', integration_code: 'CLIENT-77' })
  })

  it('keeps a replacement code entered in the same update batch as a city change', async (t) => {
    const form = mountContact({
      name: 'Comercial Silva', is_customer: true, is_supplier: false,
      address: { city: 'São Paulo', state: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' }
    })
    t.after(form.unmount)
    form.inputNow('city', 'Campinas')
    form.inputNow('city_code', '3509502')
    await Vue.nextTick()
    await form.submit()
    assert.deepEqual(form.emitted[0]?.address, { city: 'Campinas', state: 'SP', city_code: '3509502', integration_code: 'CLIENT-77' })
  })

  it('preserves an existing municipality code when city and state stay unchanged', async (t) => {
    const form = mountContact({
      name: 'Comercial Silva', is_customer: true, is_supplier: false,
      address: { city: 'São Paulo', state: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' }
    })
    t.after(form.unmount)
    await form.submit()
    assert.deepEqual(form.emitted[0]?.address, { city: 'São Paulo', state: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' })
  })

  it('allows explicitly clearing city, state, and municipality code', async (t) => {
    const form = mountContact({
      name: 'Comercial Silva', is_customer: true, is_supplier: false,
      address: { city: 'São Paulo', state: 'SP', uf: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' }
    })
    t.after(form.unmount)
    await form.input('city', '')
    await form.input('state', '')
    await form.input('city_code', '')
    await form.submit()
    assert.deepEqual(form.emitted[0]?.address, { integration_code: 'CLIENT-77' })
  })

  it('updates the fiscal UF extension from an explicitly changed contact state', async (t) => {
    const form = mountContact({
      name: 'Comercial Silva', is_customer: true, is_supplier: false,
      address: { city: 'São Paulo', state: 'SP', uf: 'SP', city_code: '3550308' }
    })
    t.after(form.unmount)
    await form.input('state', 'RJ')
    await form.submit()
    assert.equal((form.emitted[0]?.address as Record<string, string>).uf, 'RJ')
  })

  it('keeps manually entered geography available after a fiscal field error', async (t) => {
    const form = mountContact()
    t.after(form.unmount)
    await form.input('name', 'Contato comercial')
    await form.input('city', 'Campinas')
    await form.input('state', 'SP')
    await form.input('city_code', '3509502')
    form.props.fieldErrors = { 'address.city_code': ['Confira o município antes de usar em uma operação fiscal.'] }
    await Vue.nextTick()
    assert.equal(descendants(form.root).map(node => node.text).join(' ').includes('Confira o município'), true)
    await form.submit()
    assert.deepEqual(form.emitted[0]?.address, { city: 'Campinas', state: 'SP', city_code: '3509502', uf: 'SP' })
  })

  it('recovers the real customer-group lookup while preserving the edited contact and old selection', async (t) => {
    const address = { city: 'São Paulo', state: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' }
    const form = mountContact({
      name: 'Comercial Silva', is_customer: true, is_supplier: false, erp_customer_group_id: 44, address
    }, true)
    t.after(form.unmount)
    await form.input('city', 'Campinas')
    await form.input('city_code', '3509502')
    await form.settle()
    const initialList = form.lookupRequests.find(request => request.url === '/erp/customer-groups?page=1')
    const selectedDetail = form.lookupRequests.find(request => request.url === '/erp/customer-groups/44')
    assert.ok(initialList)
    assert.ok(selectedDetail)

    initialList.reject({ statusCode: 503, data: { message: 'Consulta temporariamente indisponível' } })
    selectedDetail.resolve({ data: { id: 44, name: 'Grupo varejista', status: 'active' } })
    await form.settle()
    assert.ok(descendants(form.root).some(node => node.props['data-alert-title'] === 'Serviço temporariamente indisponível. Tente novamente.'))
    assert.ok(descendants(form.root).some(node => node.text === 'Grupo varejista'))

    await form.clickButton('Tentar novamente')
    const retriedList = form.lookupRequests.filter(request => request.url === '/erp/customer-groups?page=1').at(-1)
    assert.ok(retriedList)
    retriedList.resolve({
      data: [{ id: 44, name: 'Grupo varejista', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 }
    })
    await form.settle()
    assert.ok((form.menuOptions.at(-1)?.items as Array<{ id: number, label: string }>).some(item => item.id === 44 && item.label === 'Grupo varejista'))

    await form.submit()
    assert.equal(form.emitted[0]?.erp_customer_group_id, 44)
    assert.deepEqual(form.emitted[0]?.address, { ...address, city: 'Campinas', city_code: '3509502' })
  })

  it('reinitializes municipality facts when the edited contact record reloads', async (t) => {
    const form = mountContact({
      name: 'Comercial Silva', is_customer: true, is_supplier: false,
      address: { city: 'São Paulo', state: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' }
    })
    t.after(form.unmount)
    await form.input('city', 'Campinas')
    form.props.record = {
      name: 'Comercial Silva Atualizada', is_customer: true, is_supplier: false,
      address: { city: 'Campinas', state: 'SP', city_code: '3509502', integration_code: 'CLIENT-77-2' }
    }
    await form.settle()
    await form.submit()
    assert.deepEqual(form.emitted[0]?.address, { city: 'Campinas', state: 'SP', city_code: '3509502', integration_code: 'CLIENT-77-2' })
  })
})
