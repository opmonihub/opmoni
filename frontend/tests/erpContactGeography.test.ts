import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
}

function descendants(node: HostNode): HostNode[] {
  return [node, ...node.children.flatMap(descendants)]
}

function mountContact(record: Record<string, unknown> | null = null) {
  const emitted: Array<Record<string, unknown>> = []
  const props = Vue.reactive({ record, mode: record ? 'edit' : 'create', canSave: true, busy: false, fieldErrors: {} as Record<string, string[]> })
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
    setElementText(node, text) { node.text = text; node.children = [] },
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
  const component = compileVueComponent('components/erp/catalogs/ErpContactCatalogForm.vue', {}, {
    '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': referenceSelect
  })
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
    setup(buttonProps) { return () => Vue.h('button', { type: buttonProps.type, disabled: buttonProps.disabled }, String(buttonProps.label ?? '')) }
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
  const root = makeNode('root')
  app.mount(root)
  const input = async (name: string, value: string) => {
    const element = descendants(root).find(node => node.tag === 'input' && node.props.name === name)
    assert.ok(element, `Expected input ${name}`)
    ;(element.props.onInput as (event: Event) => void)({ target: { value } } as unknown as Event)
    await Vue.nextTick()
  }
  const submit = async () => {
    const form = descendants(root).find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: Event) => void)({ preventDefault() {} } as Event)
    await Vue.nextTick()
  }
  return { emitted, input, props, root, submit, unmount: () => app.unmount() }
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
      address: { city: 'São Paulo', state: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' }
    })
    t.after(form.unmount)
    await form.input('state', 'RJ')
    await form.submit()
    assert.deepEqual(form.emitted[0]?.address, { city: 'São Paulo', state: 'RJ', integration_code: 'CLIENT-77' })
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
      address: { city: 'São Paulo', state: 'SP', city_code: '3550308', integration_code: 'CLIENT-77' }
    })
    t.after(form.unmount)
    await form.input('city', '')
    await form.input('state', '')
    await form.input('city_code', '')
    await form.submit()
    assert.deepEqual(form.emitted[0]?.address, { integration_code: 'CLIENT-77' })
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
    assert.deepEqual(form.emitted[0]?.address, { city: 'Campinas', state: 'SP', city_code: '3509502' })
  })
})
