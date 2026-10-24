import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
  addEventListener(): void
  removeEventListener(): void
}

function createHostRenderer() {
  const makeNode = (tag: string, text = ''): HostNode => ({
    tag,
    props: {},
    children: [],
    parent: null,
    text,
    addEventListener() {},
    removeEventListener() {}
  })
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    patchProp(node, key, _previous, next) { node.props[key] = next },
    insert(node, parent, anchor = null) {
      if (node.parent) {
        const previous = node.parent.children.indexOf(node)
        if (previous >= 0)
          node.parent.children.splice(previous, 1)
      }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0)
        parent.children.push(node)
      else
        parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (!node.parent)
        return
      const index = node.parent.children.indexOf(node)
      if (index >= 0)
        node.parent.children.splice(index, 1)
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
  return { renderer, makeNode }
}

function descendants(root: HostNode): HostNode[] {
  return [root, ...root.children.flatMap(descendants)]
}

const field = Vue.defineComponent({
  inheritAttrs: false,
  props: ['label', 'name', 'description', 'error', 'required'],
  setup(props, { attrs, slots }) {
    return () => Vue.h('label', { ...attrs, 'data-field': props.name }, [
      Vue.h('span', String(props.label ?? '')),
      ...(slots.default?.() ?? []),
      props.description ? Vue.h('span', String(props.description)) : null,
      props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null
    ])
  }
})
const referenceSelect = Vue.defineComponent({
  inheritAttrs: false,
  props: ['modelValue', 'label', 'name', 'disabled', 'error'],
  emits: ['update:modelValue'],
  setup(props, { attrs, emit }) {
    return () => Vue.h('div', [
      Vue.h('select', {
        ...attrs,
        'name': props.name,
        'aria-label': props.label,
        'value': props.modelValue ?? '',
        'disabled': props.disabled,
        'onChange': (event: Event) => emit('update:modelValue', Number((event.target as HTMLSelectElement).value) || null)
      }, [Vue.h('option', { value: '' }, 'Selecione'), Vue.h('option', { value: '17' }, 'UN')]),
      props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null
    ])
  }
})
let productFormComponent: Vue.Component | undefined

function mountProductForm(record: Record<string, unknown> | null = null) {
  const emitted: Array<Record<string, unknown>> = []
  const componentProps = Vue.reactive({
    record,
    mode: record ? 'edit' : 'create',
    canSave: true,
    canArchive: true,
    busy: false,
    fieldErrors: {}
  })
  productFormComponent ??= compileVueComponent('components/erp/catalogs/ErpProductCatalogForm.vue', {}, {
    '~/composables/useErpNativeUnitDefault': { useErpNativeUnitDefault: () => ({ pending: Vue.ref(false), error: Vue.ref(''), status: Vue.ref('unset'), retry: async () => undefined, markExplicit: () => undefined }) },
    '~/components/erp/catalogs/ErpCatalogField.vue': field,
    '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': referenceSelect
  })
  const { renderer, makeNode } = createHostRenderer()
  const wrapper = Vue.defineComponent({
    setup() {
      return () => Vue.h(productFormComponent!, {
        ...componentProps,
        onSave: (payload: Record<string, unknown>) => emitted.push(payload)
      })
    }
  })
  const app = renderer.createApp(wrapper)
  app.component('UForm', Vue.defineComponent({
    inheritAttrs: false,
    props: ['state', 'disabled'],
    emits: ['submit'],
    setup(props, { attrs, emit, slots }) {
      return () => Vue.h('form', {
        ...attrs,
        'data-form-state': props.state,
        'onSubmit': (event: Event) => emit('submit', event)
      }, slots.default?.())
    }
  }))
  app.component('UFormField', field)
  app.component('UAccordion', Vue.defineComponent({
    props: ['items', 'type', 'unmountOnHide', 'modelValue'],
    emits: ['update:modelValue'],
    setup(props, { emit, slots }) {
      return () => Vue.h('div', { 'data-accordion-unmount': props.unmountOnHide }, (props.items as Array<{ label: string, value: string, slot: string }>).flatMap((item) => {
        const active = Array.isArray(props.modelValue) ? props.modelValue : []
        const isOpen = active.includes(item.value)
        return [
          Vue.h('button', {
            'type': 'button',
            'data-accordion-value': item.value,
            'aria-expanded': isOpen,
            'onClick': () => {
              emit('update:modelValue', isOpen
                ? active.filter(value => value !== item.value)
                : [...active, item.value])
            }
          }, item.label),
          Vue.h('section', { 'data-accordion-panel': item.value, 'hidden': !isOpen }, slots[item.slot]?.({ item }))
        ]
      }))
    }
  }))
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
  app.component('USelect', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'items'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
      return () => Vue.h('div', [
        Vue.h('select', {
          'name': attrs.name,
          'aria-hidden': 'true',
          'tabindex': -1,
          'value': props.modelValue,
          'onChange': (event: Event) => emit('update:modelValue', (event.target as HTMLSelectElement).value)
        }, (props.items as Array<{ label: string, value: string }> | undefined)?.map(item => Vue.h('option', { value: item.value }, item.label))),
        Vue.h('button', {
          'data-erp-field-name': attrs['data-erp-field-name'],
          'data-select-trigger': attrs.name,
          'type': 'button'
        }, String((props.items as Array<{ label: string, value: string }> | undefined)?.find(item => item.value === props.modelValue)?.label ?? 'Selecionar'))
      ])
    }
  }))
  app.component('UCheckbox', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'label', 'disabled'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
      return () => Vue.h('label', [
        Vue.h('input', {
          ...attrs,
          type: 'checkbox',
          checked: props.modelValue,
          disabled: props.disabled,
          onChange: (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).checked)
        }),
        Vue.h('span', String(props.label ?? ''))
      ])
    }
  }))
  app.component('UAlert', Vue.defineComponent({ props: ['title'], setup: props => () => Vue.h('div', { role: 'alert' }, String(props.title ?? '')) }))
  app.component('UButton', Vue.defineComponent({
    inheritAttrs: false,
    props: ['label', 'type', 'disabled', 'loading'],
    setup(props, { attrs }) {
      return () => Vue.h('button', { ...attrs, type: props.type ?? 'button', disabled: props.disabled }, String(props.label ?? ''))
    }
  }))
  app.component('UModal', Vue.defineComponent({
    props: ['open', 'title', 'description'],
    setup(props, { slots }) {
      return () => props.open ? Vue.h('section', { role: 'dialog' }, [String(props.title ?? ''), slots.footer?.()]) : Vue.h('div')
    }
  }))

  const root = makeNode('root')
  app.mount(root)
  const find = (predicate: (node: HostNode) => boolean) => descendants(root).find(predicate)
  return {
    root,
    emitted,
    find,
    unmount: () => app.unmount(),
    input: async (name: string, value: string) => {
      const node = find(item => item.tag === 'input' && item.props.name === name)
      assert.ok(node, `Expected input ${name}`)
      const onInput = node.props.onInput as ((event: Event) => void) | undefined
      assert.ok(onInput, `Expected input handler ${name}`)
      onInput({ target: { value } } as unknown as Event)
      await Vue.nextTick()
    },
    inputValue: (name: string) => find(item => item.tag === 'input' && item.props.name === name)?.props.value,
    setFieldErrors: async (fieldErrors: Record<string, string[]>) => {
      componentProps.fieldErrors = fieldErrors
      await Vue.nextTick()
    },
    sectionOpen: (value: string) => find(item => item.tag === 'button' && item.props['data-accordion-value'] === value)?.props['aria-expanded'] === true,
    select: async (name: string, value: string) => {
      const node = find(item => item.tag === 'select' && (item.props.name === name || item.props['aria-label'] === name))
      assert.ok(node, `Expected select ${name}`)
      const onChange = node.props.onChange as ((event: Event) => void) | undefined
      assert.ok(onChange, `Expected select handler ${name}`)
      onChange({ target: { value } } as unknown as Event)
      await Vue.nextTick()
    },
    clickSection: async (value: string) => {
      const trigger = find(item => item.tag === 'button' && item.props['data-accordion-value'] === value)
      assert.ok(trigger, `Expected accordion trigger ${value}`)
      ;(trigger.props.onClick as (() => void) | undefined)?.()
      await Vue.nextTick()
    },
    submit: async () => {
      const form = find(item => item.tag === 'form')
      assert.ok(form, 'Expected product form')
      const onSubmit = form.props.onSubmit as ((event: Event) => void) | undefined
      assert.ok(onSubmit, 'Expected submit handler')
      onSubmit({ preventDefault() {} } as Event)
      await Vue.nextTick()
    }
  }
}

describe('ERP product progressive sections', () => {
  it('keeps basic product fields visible and discloses optional details without unmounting them', (t) => {
    const source = readFileSync(new URL('../app/components/erp/catalogs/ErpProductCatalogForm.vue', import.meta.url), 'utf8')
    const template = source.slice(source.indexOf('<template>'))
    assert.match(template, /<UAccordion\b/)
    assert.match(template, /:unmount-on-hide="false"/)
    assert.match(source, /label:\s*'Detalhes comerciais'.*slot:\s*'commercial'/)
    assert.match(source, /label:\s*'Fiscal'.*slot:\s*'fiscal'/)
    assert.match(source, /label:\s*'Variantes'.*slot:\s*'variants'/)
    assert.match(template, /<section aria-labelledby="product-basic-title"[\s\S]*?name="name"[\s\S]*?name="sku"[\s\S]*?name="kind"[\s\S]*?name="erp_unit_id"[\s\S]*?name="sale_price"[\s\S]*?<UAccordion\b/)

    const form = mountProductForm()
    t.after(form.unmount)
    assert.ok(form.find(node => node.tag === 'input' && node.props.name === 'name'))
    assert.ok(form.find(node => node.tag === 'select' && node.props['aria-label'] === 'Unidade'))
    assert.equal(form.find(node => node.props['data-accordion-panel'] === 'fiscal')?.props.hidden, true)
  })

  it('saves a named product with a selected unit and no fiscal classifications', async (t) => {
    const form = mountProductForm()
    t.after(form.unmount)
    await form.input('name', 'Produto básico')
    await form.select('Unidade', '17')
    await form.submit()

    assert.equal(form.emitted[0]?.name, 'Produto básico')
    assert.equal(form.emitted[0]?.erp_unit_id, 17)
    assert.equal(form.emitted[0]?.ncm, null)
    assert.equal(form.emitted[0]?.icms_rate, null)
  })

  it('disables stock control and submits false for a service while details stay closed', async (t) => {
    const form = mountProductForm()
    t.after(form.unmount)
    await form.input('name', 'Consultoria')
    await form.select('kind', 'service')
    assert.equal(form.find(node => node.tag === 'input' && node.props.name === 'track_stock')?.props.disabled, true)
    await form.submit()

    assert.equal(form.emitted[0]?.kind, 'service')
    assert.equal(form.emitted[0]?.track_stock, false)
  })

  it('keeps edited fiscal values after collapsing and reopening the section', async (t) => {
    const form = mountProductForm()
    t.after(form.unmount)
    await form.clickSection('fiscal')
    await form.input('ncm', '12345678')
    await form.clickSection('fiscal')
    assert.equal(form.find(node => node.props['data-accordion-panel'] === 'fiscal')?.props.hidden, true)
    await form.clickSection('fiscal')

    assert.equal(form.inputValue('ncm'), '12345678')
    await form.submit()
    assert.equal(form.emitted[0]?.ncm, '12345678')
  })

  it('opens every advanced section that receives server validation errors while preserving entered values', async (t) => {
    const form = mountProductForm({
      id: 78,
      name: 'Mercadoria',
      sku: 'ABC',
      kind: 'goods',
      track_stock: true,
      erp_unit_id: 17,
      sale_price: '19.2500',
      warranty_months: null,
      warranty_description: null,
      barcode: null,
      barcode_type: 'C128',
      ncm: null,
      cest: null,
      origin_code: null,
      icms_cst: '00',
      icms_csosn: null,
      icms_rate: '0.0000',
      simple_national_credit_rate: null,
      pis_cst: null,
      pis_rate: null,
      cofins_cst: null,
      cofins_rate: null,
      ipi_cst: null,
      ipi_rate: null,
      ibs_cbs_cst: null,
      ibs_cbs_classification_code: null,
      status: 'active',
      variants: [{ id: 91, name: 'Padrão', sku: 'ABC', sale_price: null, barcode: null, barcode_type: null, status: 'active' }]
    })
    t.after(form.unmount)

    for (const section of ['commercial', 'fiscal', 'variants'])
      assert.equal(form.find(node => node.props['data-accordion-panel'] === section)?.props.hidden, true)

    await form.input('ncm', '12345678')
    await form.input('variants.0.sku', 'ABC-EDITED')
    await form.setFieldErrors({
      'erp_brand_id': ['Selecione uma marca válida.'],
      'icms_rate': ['Revise a alíquota.'],
      'variants.0.sku': ['Este SKU já está em uso.'],
      'variants.0.barcode_type': ['Simbologia inválida.'],
      'variants': ['Há uma variante inválida.']
    })

    for (const section of ['commercial', 'fiscal', 'variants']) {
      assert.equal(form.sectionOpen(section), true, `Expected ${section} to open for its server error`)
      assert.equal(form.find(node => node.props['data-accordion-panel'] === section)?.props.hidden, false)
    }
    const variantBarcodeNativeField = form.find(node => node.tag === 'select' && node.props.name === 'variants.0.barcode_type')
    const variantBarcodeTrigger = form.find(node => node.tag === 'button' && node.props['data-erp-field-name'] === 'variants.0.barcode_type')
    assert.equal(variantBarcodeNativeField?.props['aria-hidden'], 'true')
    assert.ok(variantBarcodeTrigger, 'the advanced product USelect exposes its visible trigger mapping')
    assert.equal(form.find(node => node.props['data-accordion-panel'] === 'variants')?.props.hidden, false, 'the server error reveals the section containing the visible trigger')
    for (const message of ['Selecione uma marca válida.', 'Revise a alíquota.', 'Este SKU já está em uso.', 'Há uma variante inválida.'])
      assert.ok(descendants(form.root).some(node => node.text.includes(message)), `Expected visible server error: ${message}`)
    assert.equal(form.inputValue('ncm'), '12345678')
    assert.equal(form.inputValue('variants.0.sku'), 'ABC-EDITED')
  })

  it('preserves existing product values, variant IDs, archive state and inherited variant prices on a basic edit', async (t) => {
    const form = mountProductForm({
      id: 77,
      name: 'Mercadoria',
      sku: 'ABC',
      kind: 'goods',
      track_stock: true,
      erp_unit_id: 17,
      erp_brand_id: 5,
      erp_category_id: 6,
      sale_price: '19.2500',
      warranty_months: 12,
      warranty_description: '12 meses',
      barcode: '7890001112223',
      barcode_type: 'EAN13',
      ncm: '00',
      cest: '1234567',
      origin_code: '0',
      icms_cst: '00',
      icms_csosn: '000',
      icms_rate: '0.0000',
      simple_national_credit_rate: null,
      pis_cst: '01',
      pis_rate: '0.0000',
      cofins_cst: '02',
      cofins_rate: null,
      ipi_cst: '03',
      ipi_rate: null,
      ibs_cbs_cst: '999',
      ibs_cbs_classification_code: '123456',
      status: 'active',
      variants: [
        { id: 91, name: 'Padrão', sku: 'ABC', sale_price: null, barcode: '7890001112223', barcode_type: null, status: 'active' },
        { id: 92, name: 'Antiga', sku: 'ABC-OLD', sale_price: '18.0000', barcode: null, barcode_type: null, status: 'archived' }
      ]
    })
    t.after(form.unmount)
    await form.input('name', 'Mercadoria revisada')
    await form.submit()

    assert.equal(form.emitted[0]?.name, 'Mercadoria revisada')
    assert.equal(form.emitted[0]?.erp_brand_id, 5)
    assert.equal(form.emitted[0]?.erp_category_id, 6)
    assert.equal(form.emitted[0]?.sale_price, '19.2500')
    assert.equal(form.emitted[0]?.ncm, '00')
    assert.equal(form.emitted[0]?.icms_rate, '0.0000')
    assert.deepEqual(form.emitted[0]?.variants, [
      { id: 91, name: 'Padrão', sku: 'ABC', sale_price: null, barcode: '7890001112223', barcode_type: null },
      { id: 92, name: 'Antiga', sku: 'ABC-OLD', sale_price: '18.0000', barcode: null, barcode_type: null, status: 'archived' }
    ])
  })
})
