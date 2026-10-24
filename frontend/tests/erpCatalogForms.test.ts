import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import type { ErpCatalogEditorMode } from '../shared/types/erpCatalogs.ts'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
  options: HostNode[]
  addEventListener(): void
  removeEventListener(): void
}

const forms = [
  ['components/erp/catalogs/ErpContactCatalogForm.vue', 'name'],
  ['components/erp/catalogs/ErpProductCatalogForm.vue', 'name'],
  ['components/erp/catalogs/ErpUnitCatalogForm.vue', 'short_name'],
  ['components/erp/catalogs/ErpCategoryCatalogForm.vue', 'name'],
  ['components/erp/catalogs/ErpBrandCatalogForm.vue', 'name'],
  ['components/erp/catalogs/ErpPriceGroupCatalogForm.vue', 'name'],
  ['components/erp/catalogs/ErpCustomerGroupCatalogForm.vue', 'name'],
  ['components/erp/catalogs/ErpDiscountRuleCatalogForm.vue', 'name'],
  ['components/erp/catalogs/ErpTaxRateCatalogForm.vue', 'name'],
  ['components/erp/catalogs/ErpOperationNatureCatalogForm.vue', 'cfop'],
  ['components/erp/catalogs/ErpCarrierCatalogForm.vue', 'legal_name'],
  ['components/erp/catalogs/ErpVehicleCatalogForm.vue', 'plate'],
  ['components/erp/catalogs/ErpDriverCatalogForm.vue', 'name']
] as const

function createHostRenderer() {
  const makeNode = (tag: string, text = ''): HostNode => ({
    tag,
    props: {},
    children: [],
    parent: null,
    text,
    options: [],
    addEventListener() {},
    removeEventListener() {}
  })
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
  return { renderer, makeNode }
}

function descendants(root: HostNode): HostNode[] {
  return [root, ...root.children.flatMap(descendants)]
}

function isEffectivelyDisabled(node: HostNode): boolean {
  let current: HostNode | null = node
  while (current) {
    if (current.props.disabled === true)
      return true
    current = current.parent
  }
  return false
}

function mockNatureRules() {
  const choice = { id: 18, cfop: '6102', reference_version: 'reference-v1', status: 'active', implicit: false }
  const oldChoice = { id: 17, cfop: '5102', reference_version: 'reference-v1', status: 'active', implicit: false }
  const exception = { id: 41, erp_product_id: 91, erp_operation_nature_cfop_choice_id: 17, product: { id: 91, name: 'Café', sku: 'CAF-1' } }
  const updates: Array<{ exception: unknown, choiceId: number }> = []
  const rules = {
    choices: Vue.ref([choice]), exceptions: Vue.ref([] as typeof exception[]), exceptionChoiceRefs: Vue.ref({} as Record<number, typeof oldChoice>),
    cfopResults: Vue.ref([]), productResults: Vue.ref([]), revision: Vue.ref(4), choicesPage: Vue.ref(1), exceptionsPage: Vue.ref(1), cfopsPage: Vue.ref(1), productsPage: Vue.ref(1),
    choiceSearch: Vue.ref(''), exceptionSearch: Vue.ref(''), cfopSearch: Vue.ref(''), productSearch: Vue.ref(''), selectedProduct: Vue.ref(null), selectedCfop: Vue.ref(null), selectedExceptionChoiceCode: Vue.ref(''),
    choiceLastPage: Vue.ref(1), exceptionLastPage: Vue.ref(1), cfopsLastPage: Vue.ref(1), productsLastPage: Vue.ref(1), pending: Vue.ref(false), error: Vue.ref(''), reviewRequired: Vue.ref(false),
    authorityBlocked: Vue.ref(false), requiresReopen: Vue.ref(false), canMutate: Vue.ref(true), canSearchProducts: Vue.ref(false), cfopResultsCurrent: Vue.ref(false), productResultsCurrent: Vue.ref(false),
    load: async () => {}, reviewCurrent: async () => true, retry: async () => true, addChoice: async () => true, setDefault: async () => ({ code: '6102', expected_revision: 4 }),
    removeChoice: async () => true, addException: async () => true, removeException: async () => true,
    updateException: async (row: unknown, choiceId: number) => {
      updates.push({ exception: row, choiceId })
      return true
    },
    searchCfops: async () => {}, searchProducts: async () => {}, selectCfop: () => {}, selectExceptionChoice: () => {}, selectProduct: () => {}
  }
  return { rules, choice, oldChoice, exception, updates }
}

function mountForm(
  path: string,
  record: Record<string, unknown> | null = null,
  mode: ErpCatalogEditorMode = 'create',
  initialFieldErrors: Record<string, string[]> = {},
  options: { realNaturePanel?: boolean, rules?: Record<string, unknown>, bindings?: Record<string, unknown> } = {}
) {
  const emitted: Array<Record<string, unknown>> = []
  const componentProps = Vue.reactive({
    record,
    mode,
    canSave: true,
    canArchive: true,
    busy: false,
    fieldErrors: initialFieldErrors
  })
  const referenceSelect = Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'label', 'name', 'error', 'disabled', 'optional'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
      return () => Vue.h('div', { 'data-uform-field': props.name }, [
        Vue.h('select', {
          ...attrs,
          'data-nuxt-ui-component': 'ReferenceSelect',
          'aria-label': props.label,
          'value': props.modelValue ?? '',
          'disabled': props.disabled,
          'onChange': (event: Event) => emit('update:modelValue', Number((event.target as HTMLSelectElement).value) || null)
        }, [Vue.h('option', { value: '' }, 'Selecione')]),
        props.error ? Vue.h('p', { role: 'alert' }, props.error) : null
      ])
    }
  })
  const operationNatureRules = Vue.defineComponent({
    props: ['natureId', 'movement', 'defaultCfop', 'defaultCfopUnverified', 'canRead', 'canWrite', 'rootDirty'],
    emits: ['stage-default'],
    setup(props, { emit }) {
      return () => Vue.h('section', { 'data-operation-nature-rules': true }, [
        props.defaultCfopUnverified ? Vue.h('p', 'O valor histórico não foi verificado.') : null,
        props.canWrite && !props.rootDirty
          ? Vue.h('button', {
              'type': 'button',
              'data-stage-default': true,
              'onClick': () => emit('stage-default', { code: '5102', expected_revision: 4 })
            }, 'Usar 5102 como padrão')
          : null
      ])
    }
  })
  const modules: Record<string, unknown> = {
    '~/composables/useErpNativeUnitDefault': { useErpNativeUnitDefault: () => ({ pending: Vue.ref(false), error: Vue.ref(''), status: Vue.ref('unset'), retry: async () => undefined, markExplicit: () => undefined }) },
    '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': referenceSelect,
    '~/components/erp/catalogs/ErpOperationNatureCfopRules.vue': operationNatureRules,
    '~/composables/useErpAdministrationContext': { useErpAdministrationContext: () => ({ key: Vue.ref('test-context'), capture: () => ({}), isCurrent: () => true }) }
  }
  const bindings = {
    useAccessApi: () => ({ api: async () => ({ data: { configuration_revision: 4 } }) }),
    ...options.bindings
  }
  if (options.realNaturePanel) {
    delete modules['~/components/erp/catalogs/ErpOperationNatureCfopRules.vue']
    modules['~/composables/useErpOperationNatureRules'] = { useErpOperationNatureRules: () => options.rules }
  }
  const component = compileVueComponent(path, bindings, modules)
  const { renderer, makeNode } = createHostRenderer()
  const wrapper = Vue.defineComponent({
    setup() {
      return () => Vue.h(component, {
        ...componentProps,
        onSave: (value: Record<string, unknown>) => { emitted.push(value) }
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
        'data-uform-state': props.state,
        'data-uform-disabled': props.disabled,
        'onSubmit': (event: Event) => emit('submit', event)
      }, slots.default?.())
    }
  }))
  app.component('UAccordion', Vue.defineComponent({
    inheritAttrs: false,
    props: ['items', 'type', 'unmountOnHide', 'defaultValue', 'modelValue'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit, slots }) {
      const initialValue = Array.isArray(props.defaultValue)
        ? props.defaultValue.map(String)
        : props.defaultValue === undefined ? [] : [String(props.defaultValue)]
      const active = Vue.ref<string[]>(initialValue)

      return () => Vue.h('div', {
        ...attrs,
        'data-uaccordion': true,
        'data-accordion-unmount-on-hide': props.unmountOnHide
      }, (props.items as Array<{ label: string, value: string, slot?: string }> | undefined)?.flatMap((item) => {
        const currentValue = props.modelValue === undefined ? active.value : Array.isArray(props.modelValue) ? props.modelValue.map(String) : [String(props.modelValue)]
        const isOpen = currentValue.includes(item.value)
        const shouldRenderContent = props.unmountOnHide === false || isOpen
        const panel = Vue.h('div', {
          'data-accordion-panel': item.value,
          'hidden': !isOpen
        }, shouldRenderContent ? slots[item.slot ?? item.value]?.({ item }) : undefined)

        return [
          Vue.h('button', {
            'type': 'button',
            'data-accordion-trigger': item.value,
            'aria-expanded': isOpen,
            'onClick': () => {
              if (props.type === 'multiple') {
                const nextValue = isOpen
                  ? currentValue.filter(value => value !== item.value)
                  : [...currentValue, item.value]
                if (props.modelValue === undefined)
                  active.value = nextValue
                emit('update:modelValue', nextValue)
              } else {
                const nextValue = isOpen ? [] : [item.value]
                if (props.modelValue === undefined)
                  active.value = nextValue
                emit('update:modelValue', props.type === 'multiple' ? nextValue : nextValue[0])
              }
            }
          }, item.label),
          panel
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
  app.component('UInputMenu', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'searchTerm', 'items', 'disabled'],
    emits: ['update:modelValue', 'update:searchTerm'],
    setup(props, { attrs, emit }) {
      return () => Vue.h('input', {
        ...attrs,
        'data-nuxt-ui-component': 'UInputMenu',
        'value': props.searchTerm,
        'disabled': props.disabled,
        'onInput': (event: Event) => emit('update:searchTerm', (event.target as HTMLInputElement).value)
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
    props: ['label', 'name', 'description', 'error', 'required'],
    setup(props, { attrs, slots }) {
      return () => Vue.h('label', { ...attrs, 'data-uform-field': props.name }, [
        Vue.h('span', String(props.label ?? '')),
        ...(slots.default?.() ?? []),
        props.description ? Vue.h('span', String(props.description)) : null,
        props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null
      ])
    }
  }))
  app.component('UAlert', Vue.defineComponent({
    inheritAttrs: false,
    props: ['title', 'description'],
    setup(props, { attrs, slots }) {
      return () => Vue.h('div', { ...attrs, role: 'status' }, [
        Vue.h('span', String(props.title ?? '')),
        Vue.h('span', String(props.description ?? '')),
        ...(slots.default?.() ?? []),
        ...(slots.actions?.() ?? [])
      ])
    }
  }))
  app.component('USelect', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'items', 'valueKey'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
      return () => Vue.h('select', {
        ...attrs,
        'data-nuxt-ui-component': 'USelect',
        'value': props.modelValue,
        'items': props.items,
        'onChange': (event: Event) => emit('update:modelValue', (event.target as HTMLSelectElement).value)
      }, (props.items as Array<{ label: string, value: string }> | undefined)?.map(item => Vue.h('option', { value: item.value }, item.label)))
    }
  }))
  app.component('UCheckbox', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'label', 'disabled'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
      return () => Vue.h('label', { class: 'inline-flex items-center gap-2' }, [
        Vue.h('input', {
          ...attrs,
          'type': 'checkbox',
          'data-nuxt-ui-component': 'UCheckbox',
          'checked': props.modelValue,
          'disabled': props.disabled,
          'onChange': (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).checked)
        }),
        Vue.h('span', String(props.label ?? ''))
      ])
    }
  }))
  app.component('UTextarea', Vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
      return () => Vue.h('textarea', {
        ...attrs,
        'data-nuxt-ui-component': 'UTextarea',
        'value': props.modelValue,
        'disabled': props.disabled,
        'onInput': (event: Event) => emit('update:modelValue', (event.target as HTMLTextAreaElement).value)
      })
    }
  }))
  app.component('UModal', Vue.defineComponent({
    inheritAttrs: false,
    props: ['open', 'title', 'description'],
    setup(props, { attrs, slots }) {
      return () => props.open
        ? Vue.h('section', { ...attrs, 'role': 'dialog', 'aria-label': props.title }, [
            Vue.h('h2', String(props.title ?? '')),
            Vue.h('p', String(props.description ?? '')),
            ...(slots.footer?.() ?? [])
          ])
        : Vue.h('div')
    }
  }))
  const root = makeNode('root')
  app.mount(root)
  const toggleAccordionSection = async (value: string, open: boolean) => {
    const trigger = descendants(root).find(node => node.tag === 'button' && node.props['data-accordion-trigger'] === value)
    assert.ok(trigger, `Expected accordion trigger ${value} in ${path}`)
    if (trigger.props['aria-expanded'] === open)
      return
    const onClick = trigger.props.onClick as (() => void) | undefined
    assert.ok(onClick, `Expected accordion toggle for ${value} in ${path}`)
    onClick()
    await Vue.nextTick()
  }
  return {
    root,
    emitted,
    inputs: () => descendants(root).filter(node => node.tag === 'input'),
    forms: () => descendants(root).filter(node => node.tag === 'form'),
    formState: () => descendants(root).find(node => node.tag === 'form')?.props['data-uform-state'],
    fieldNames: () => descendants(root).map(node => node.props['data-uform-field']).filter((name): name is string => typeof name === 'string'),
    input: async (name: string, value: string) => {
      const node = descendants(root).find(item => item.tag === 'input' && item.props.name === name)
      assert.ok(node, `Expected input ${name} in ${path}`)
      const onInput = node.props.onInput as ((event: Event) => void) | undefined
      assert.ok(onInput, `Expected an input handler for ${name} in ${path}`)
      onInput({ target: { value } } as unknown as Event)
      await Vue.nextTick()
    },
    inputValue: (name: string) => descendants(root).find(item => item.tag === 'input' && item.props.name === name)?.props.value,
    select: async (label: string, value: number) => {
      const node = descendants(root).find(item => item.tag === 'select' && item.props['aria-label'] === label)
      assert.ok(node, `Expected reference select ${label} in ${path}`)
      const onChange = node.props.onChange as ((event: Event) => void) | undefined
      assert.ok(onChange, `Expected a selection handler for ${label} in ${path}`)
      onChange({ target: { value: String(value) } } as unknown as Event)
      await Vue.nextTick()
    },
    selectValue: (label: string) => descendants(root).find(item => item.tag === 'select' && item.props['aria-label'] === label)?.props.value,
    text: () => descendants(root).map(node => node.text).join(' '),
    buttons: () => descendants(root).filter(node => node.tag === 'button'),
    stageDefault: async () => {
      const button = descendants(root).find(node => node.props['data-stage-default'] === true)
      assert.ok(button, `Expected an explicit default review action in ${path}`)
      const onClick = button.props.onClick as (() => void) | undefined
      assert.ok(onClick)
      onClick()
      await Vue.nextTick()
    },
    clickButton: async (label: string) => {
      const button = descendants(root).find(node => node.tag === 'button' && node.text === label)
      assert.ok(button, `Expected button ${label} in ${path}`)
      const handler = button.props.onClick as (() => unknown) | undefined
      assert.ok(handler)
      await handler()
      await Vue.nextTick()
    },
    accordionPanel: (value: string) => descendants(root).find(node => node.props['data-accordion-panel'] === value),
    openSection: async (value: string) => {
      await toggleAccordionSection(value, true)
    },
    closeSection: async (value: string) => {
      await toggleAccordionSection(value, false)
    },
    setFieldErrors: async (value: Record<string, string[]>) => {
      componentProps.fieldErrors = value
      await Vue.nextTick()
    },
    setBusy: async (value: boolean) => {
      componentProps.busy = value
      await Vue.nextTick()
    },
    submit: async () => {
      const handler = descendants(root).find(node => node.tag === 'form')?.props.onSubmit as ((event: Event) => void) | undefined
      assert.ok(handler, `Expected a form submit handler in ${path}`)
      handler({ preventDefault() {} } as Event)
      await Vue.nextTick()
    },
    unmount: () => app.unmount()
  }
}

type FormCase = {
  path: string
  field: string
  original: string
  changed: string
  record: Record<string, unknown>
  expected: Record<string, unknown>
}

const editCases: FormCase[] = [
  {
    path: 'components/erp/catalogs/ErpContactCatalogForm.vue',
    field: 'name',
    original: 'Comercial Silva',
    changed: 'Comercial Silva Atualizado',
    record: {
      id: 17, erp_customer_group_id: 8, name: 'Comercial Silva', trade_name: 'Silva', tax_id: '11223344000199', email: 'contato@silva.com',
      phone: '11988887777', notes: 'Ligar após as 14h', is_customer: true, is_supplier: true,
      address: {
        integration_code: 'CLIENT-77', street_type: 'Avenida', street: 'Paulista', number: '1000', complement: 'Conj. 5',
        district: 'Bela Vista', postal_code: '01310-100', city: 'São Paulo', state: 'SP'
      }
    },
    expected: {
      erp_customer_group_id: 8, name: 'Comercial Silva Atualizado', trade_name: 'Silva', tax_id: '11223344000199', email: 'contato@silva.com',
      phone: '11988887777', notes: 'Ligar após as 14h', is_customer: true, is_supplier: true,
      address: {
        integration_code: 'CLIENT-77', street_type: 'Avenida', street: 'Paulista', number: '1000', complement: 'Conj. 5',
        district: 'Bela Vista', postal_code: '01310-100', city: 'São Paulo', state: 'SP'
      }
    }
  },
  {
    path: 'components/erp/catalogs/ErpProductCatalogForm.vue',
    field: 'name',
    original: 'Mercadoria',
    changed: 'Mercadoria Atualizada',
    record: {
      id: 77, name: 'Mercadoria', sku: 'ABC', kind: 'goods', track_stock: true, erp_unit_id: 4, erp_brand_id: 5,
      erp_category_id: 6, sale_price: '19.2500', warranty_months: 12, warranty_description: '12 meses',
      barcode: '7890001112223', barcode_type: 'EAN13', ncm: '00', cest: '1234567', origin_code: '0', icms_cst: '00', icms_csosn: '000',
      pis_cst: '01', cofins_cst: '02', ipi_cst: '03', icms_rate: '0.0001', pis_rate: '0.0000',
      cofins_rate: '1.2300', ipi_rate: '0.5000', simple_national_credit_rate: '0.0001',
      ibs_cbs_cst: '999', ibs_cbs_classification_code: '123456', status: 'active',
      variants: [{ id: 91, name: 'Padrão', sku: 'ABC', sale_price: '19.2500', barcode: '7890001112223', barcode_type: null, status: 'active' }]
    },
    expected: {
      name: 'Mercadoria Atualizada', sku: 'ABC', kind: 'goods', track_stock: true, erp_unit_id: 4, erp_brand_id: 5,
      erp_category_id: 6, sale_price: '19.2500', warranty_months: 12, warranty_description: '12 meses',
      barcode: '7890001112223', barcode_type: 'EAN13', ncm: '00', cest: '1234567', origin_code: '0', icms_cst: '00', icms_csosn: '000',
      pis_cst: '01', cofins_cst: '02', ipi_cst: '03', icms_rate: '0.0001', pis_rate: '0.0000',
      cofins_rate: '1.2300', ipi_rate: '0.5000', simple_national_credit_rate: '0.0001',
      ibs_cbs_cst: '999', ibs_cbs_classification_code: '123456',
      variants: [{ id: 91, name: 'Padrão', sku: 'ABC', sale_price: '19.2500', barcode: '7890001112223', barcode_type: null }]
    }
  },
  {
    path: 'components/erp/catalogs/ErpCustomerGroupCatalogForm.vue',
    field: 'name',
    original: 'Atacado',
    changed: 'Atacado Sul',
    record: { id: 51, name: 'Atacado', percentage_adjustment: '-2.5000', status: 'active' },
    expected: { name: 'Atacado Sul', percentage_adjustment: '-2.5000' }
  },
  {
    path: 'components/erp/catalogs/ErpUnitCatalogForm.vue',
    field: 'short_name',
    original: 'kg',
    changed: 'g',
    record: { id: 8, short_name: 'kg', actual_name: 'Quilograma', allow_decimal: true, base_unit_id: 9, conversion_factor: '1000.00000000', status: 'active' },
    expected: { short_name: 'g', actual_name: 'Quilograma', allow_decimal: true, base_unit_id: 9, conversion_factor: '1000.00000000' }
  },
  {
    path: 'components/erp/catalogs/ErpCategoryCatalogForm.vue',
    field: 'name',
    original: 'Bebidas',
    changed: 'Bebidas e cafés',
    record: { id: 12, name: 'Bebidas', parent_id: 3, status: 'active' },
    expected: { name: 'Bebidas e cafés', parent_id: 3 }
  },
  {
    path: 'components/erp/catalogs/ErpBrandCatalogForm.vue',
    field: 'name',
    original: 'Serra Azul',
    changed: 'Serra Azul Comércio',
    record: { id: 19, name: 'Serra Azul', status: 'active' },
    expected: { name: 'Serra Azul Comércio' }
  },
  {
    path: 'components/erp/catalogs/ErpPriceGroupCatalogForm.vue',
    field: 'name',
    original: 'Varejo',
    changed: 'Varejo Sul',
    record: { id: 24, name: 'Varejo', percentage_adjustment: '-2.5000', is_default: true, status: 'active' },
    expected: { name: 'Varejo Sul', percentage_adjustment: '-2.5000', is_default: true }
  },
  {
    path: 'components/erp/catalogs/ErpDiscountRuleCatalogForm.vue',
    field: 'name',
    original: 'Cupom de lançamento',
    changed: 'Cupom de lançamento Sul',
    record: { id: 25, name: 'Cupom de lançamento', kind: 'fixed', value: '15.7500', coupon_code: 'INAUGURA', status: 'active' },
    expected: { name: 'Cupom de lançamento Sul', kind: 'fixed', value: '15.7500', coupon_code: 'INAUGURA' }
  },
  {
    path: 'components/erp/catalogs/ErpTaxRateCatalogForm.vue',
    field: 'name',
    original: 'ICMS interno',
    changed: 'ICMS interno SP',
    record: { id: 30, erp_establishment_id: 2, name: 'ICMS interno', rate: '18.0000', tax_kind: 'icms', status: 'active' },
    expected: { erp_establishment_id: 2, name: 'ICMS interno SP', rate: '18.0000', tax_kind: 'icms' }
  },
  {
    path: 'components/erp/catalogs/ErpOperationNatureCatalogForm.vue',
    field: 'cfop',
    original: '5102',
    changed: '6102',
    record: { id: 32, erp_establishment_id: 2, cfop: '5102', description: 'Venda de mercadoria adquirida', movement: 'out', status: 'active' },
    expected: { erp_establishment_id: 2, cfop: '6102', description: 'Venda de mercadoria adquirida', movement: 'out' }
  },
  {
    path: 'components/erp/catalogs/ErpCarrierCatalogForm.vue',
    field: 'legal_name',
    original: 'Transportes Rio Ltda.',
    changed: 'Transportes Rio e Sul Ltda.',
    record: {
      id: 41, legal_name: 'Transportes Rio Ltda.', tax_id: '12345678000190', ie: '110042490114',
      address: { integration_code: 'CARRIER-8', street_type: 'Rua', street: 'Pátio', number: '10', city: 'Santos', state: 'SP' },
      status: 'active'
    },
    expected: {
      legal_name: 'Transportes Rio e Sul Ltda.', tax_id: '12345678000190', ie: '110042490114',
      address: { integration_code: 'CARRIER-8', street_type: 'Rua', street: 'Pátio', number: '10', city: 'Santos', state: 'SP' }
    }
  },
  {
    path: 'components/erp/catalogs/ErpVehicleCatalogForm.vue',
    field: 'plate',
    original: 'ABC1D23',
    changed: 'DEF4G56',
    record: {
      id: 52, erp_carrier_id: 41, vehicle_type: 'truck', plate: 'ABC1D23', uf: 'SP', color: 'Branco',
      model: 'VM 270', brand: 'Volvo', wheel_type: '6x2', body_type: 'Baú', owner_type: 'próprio', status: 'active'
    },
    expected: {
      erp_carrier_id: 41, vehicle_type: 'truck', plate: 'DEF4G56', uf: 'SP', color: 'Branco',
      model: 'VM 270', brand: 'Volvo', wheel_type: '6x2', body_type: 'Baú', owner_type: 'próprio'
    }
  },
  {
    path: 'components/erp/catalogs/ErpDriverCatalogForm.vue',
    field: 'name',
    original: 'Marcos Souza',
    changed: 'Marcos Souza Neto',
    record: { id: 63, name: 'Marcos Souza', tax_id: '12345678900', license_number: '00012345678', status: 'active' },
    expected: { name: 'Marcos Souza Neto', tax_id: '12345678900', license_number: '00012345678' }
  }
]

const referenceErrorCases = [
  {
    path: 'components/erp/catalogs/ErpTaxRateCatalogForm.vue',
    referenceLabel: 'Estabelecimento',
    referenceField: 'erp_establishment_id',
    referenceId: 3,
    message: 'O estabelecimento não pertence à empresa selecionada.'
  },
  {
    path: 'components/erp/catalogs/ErpOperationNatureCatalogForm.vue',
    referenceLabel: 'Estabelecimento',
    referenceField: 'erp_establishment_id',
    referenceId: 3,
    message: 'O estabelecimento está inativo.'
  },
  {
    path: 'components/erp/catalogs/ErpVehicleCatalogForm.vue',
    referenceLabel: 'Transportadora',
    referenceField: 'erp_carrier_id',
    referenceId: 42,
    message: 'A transportadora não está autorizada.'
  }
] as const

describe('ERP catalog family forms', () => {
  for (const item of editCases) {
    it(`edits and submits the saved ${item.path.split('/').at(-1)} fixture without losing its references or stored values`, async (t) => {
      const form = mountForm(item.path, item.record, 'edit')
      t.after(form.unmount)
      assert.equal(form.forms().length, 1)
      assert.equal(form.inputValue(item.field), item.original)
      await form.input(item.field, item.changed)
      await form.submit()
      assert.deepEqual(form.emitted, [item.expected])
    })
  }

  for (const [path, requiredField] of forms) {
    it(`creates a ${path.split('/').at(-1)} record from entered ${requiredField} data`, async (t) => {
      const form = mountForm(path)
      t.after(form.unmount)
      await form.input(requiredField, `Novo ${requiredField}`)
      await form.submit()
      assert.equal(form.emitted.length, 1)
      assert.equal(form.emitted[0]?.[requiredField], `Novo ${requiredField}`)
      if (path.endsWith('ErpProductCatalogForm.vue')) {
        assert.deepEqual(form.emitted[0]?.variants, [{ name: 'Padrão', sku: null, sale_price: null, barcode: null, barcode_type: null }])
      }
      if (path.endsWith('ErpCustomerGroupCatalogForm.vue'))
        assert.equal(form.emitted[0]?.percentage_adjustment, '0.0000')
    })
  }

  it('keeps a product in readonly detail mode and ignores a form submit event', async (t) => {
    const item = editCases.find(candidate => candidate.path.endsWith('ErpProductCatalogForm.vue'))!
    const form = mountForm(item.path, item.record, 'detail')
    t.after(form.unmount)
    assert.equal(form.buttons().some(button => button.props.type === 'submit'), false)
    await form.submit()
    assert.deepEqual(form.emitted, [])
  })

  it('keeps collapsed product disclosure fields mounted and their values after reopening', async (t) => {
    const form = mountForm('components/erp/catalogs/ErpProductCatalogForm.vue', editCases[1]!.record, 'edit')
    t.after(form.unmount)

    const fiscalPanel = form.accordionPanel('fiscal')
    assert.ok(fiscalPanel)
    assert.equal(fiscalPanel.props.hidden, true)
    assert.equal(descendants(form.root).find(node => node.props['data-uaccordion'] === true)?.props['data-accordion-unmount-on-hide'], false)
    assert.ok(descendants(fiscalPanel).some(node => node.tag === 'input' && node.props.name === 'icms_cst'))

    await form.openSection('fiscal')
    await form.input('icms_cst', '00')
    await form.closeSection('fiscal')
    assert.equal(form.accordionPanel('fiscal')?.props.hidden, true)
    assert.equal(form.inputValue('icms_cst'), '00')

    await form.openSection('fiscal')
    assert.equal(form.accordionPanel('fiscal')?.props.hidden, false)
    assert.equal(form.inputValue('icms_cst'), '00')
  })

  it('keeps fiscal codes and decimal strings lossless, supports optional clearing, and uses the customer group reference', async (t) => {
    const contact = mountForm('components/erp/catalogs/ErpContactCatalogForm.vue', editCases[0]!.record, 'edit')
    const product = mountForm('components/erp/catalogs/ErpProductCatalogForm.vue', editCases[1]!.record, 'edit')
    const newProduct = mountForm('components/erp/catalogs/ErpProductCatalogForm.vue')
    t.after(contact.unmount)
    t.after(product.unmount)
    t.after(newProduct.unmount)

    await product.openSection('fiscal')

    assert.equal(contact.selectValue('Grupo de clientes'), 8)
    await contact.select('Grupo de clientes', 12)
    await contact.submit()
    assert.equal(contact.emitted[0]?.erp_customer_group_id, 12)

    await product.input('icms_cst', '00')
    await product.input('icms_csosn', '000')
    await product.input('icms_rate', '0.0001')
    await product.setFieldErrors({ icms_rate: ['Revise a alíquota.'] })
    assert.equal(product.inputValue('icms_cst'), '00')
    assert.equal(product.inputValue('icms_csosn'), '000')
    assert.equal(product.inputValue('icms_rate'), '0.0001')
    await product.submit()
    assert.equal(product.emitted[0]?.icms_cst, '00')
    assert.equal(product.emitted[0]?.icms_csosn, '000')
    assert.equal(product.emitted[0]?.icms_rate, '0.0001')

    await newProduct.input('name', 'Produto sem dados fiscais')
    await newProduct.submit()
    for (const field of ['ncm', 'cest', 'origin_code', 'icms_cst', 'icms_csosn', 'icms_rate', 'simple_national_credit_rate', 'pis_cst', 'pis_rate', 'cofins_cst', 'cofins_rate', 'ipi_cst', 'ipi_rate', 'ibs_cbs_cst', 'ibs_cbs_classification_code'])
      assert.equal(newProduct.emitted[0]?.[field], null, `${field} should remain optional`)
  })

  it('uses a reactive Nuxt UI form state and registered field names for every catalog family', async (t) => {
    for (const item of editCases) {
      const form = mountForm(item.path, item.record, 'edit')
      t.after(form.unmount)
      assert.equal(form.forms().length, 1, item.path)
      const state = form.formState() as Record<string, unknown> | undefined
      assert.ok(state && typeof state === 'object', `Expected UForm state for ${item.path}`)
      assert.ok(form.fieldNames().includes(item.field), `Expected a named UFormField for ${item.field}`)
      await form.input(item.field, item.changed)
      assert.equal((form.formState() as Record<string, unknown>)[item.field], item.changed, item.path)
    }
  })

  it('uses Nuxt UI controls for enums, named booleans and shared notes', async (t) => {
    const product = mountForm('components/erp/catalogs/ErpProductCatalogForm.vue', editCases[1]!.record, 'edit')
    const discount = mountForm('components/erp/catalogs/ErpDiscountRuleCatalogForm.vue', editCases[6]!.record, 'edit')
    const operation = mountForm('components/erp/catalogs/ErpOperationNatureCatalogForm.vue', editCases[8]!.record, 'edit')
    const contact = mountForm('components/erp/catalogs/ErpContactCatalogForm.vue', editCases[0]!.record, 'edit')
    const unit = mountForm('components/erp/catalogs/ErpUnitCatalogForm.vue', editCases[2]!.record, 'edit')
    const priceGroup = mountForm('components/erp/catalogs/ErpPriceGroupCatalogForm.vue', editCases[5]!.record, 'edit')
    for (const form of [product, discount, operation, contact, unit, priceGroup])
      t.after(form.unmount)

    await product.openSection('commercial')

    for (const [form, expectedSelectCount] of [[product, 3], [discount, 1], [operation, 1]] as const) {
      assert.equal(descendants(form.root).filter(node => node.props['data-nuxt-ui-component'] === 'USelect').length, expectedSelectCount)
      assert.equal(descendants(form.root).filter(node => node.tag === 'select' && !node.props['data-nuxt-ui-component']).length, 0)
    }
    assert.equal(descendants(product.root).filter(node => node.props['data-nuxt-ui-component'] === 'UCheckbox').length, 1)
    assert.equal(descendants(contact.root).filter(node => node.props['data-nuxt-ui-component'] === 'UCheckbox').length, 2)
    assert.equal(descendants(unit.root).filter(node => node.props['data-nuxt-ui-component'] === 'UCheckbox').length, 1)
    assert.equal(descendants(priceGroup.root).filter(node => node.props['data-nuxt-ui-component'] === 'UCheckbox').length, 1)
    assert.equal(descendants(contact.root).filter(node => node.props['data-nuxt-ui-component'] === 'UTextarea').length, 1)

    const booleanFields = [
      [contact, 'is_customer'],
      [contact, 'is_supplier'],
      [product, 'track_stock'],
      [unit, 'allow_decimal'],
      [priceGroup, 'is_default']
    ] as const
    for (const [form, name] of booleanFields) {
      assert.ok(form.fieldNames().includes(name), `Expected a named form field for ${name}`)
      const message = `Revise ${name}.`
      await form.setFieldErrors({ [name]: [message] })
      assert.ok(form.text().includes(message), `Expected server error for ${name} to be visible`)
    }
  })

  it('marks an existing operation-nature CFOP as unverified until the operator deliberately adopts a reference', async (t) => {
    const form = mountForm('components/erp/catalogs/ErpOperationNatureCatalogForm.vue', {
      id: 32,
      erp_establishment_id: 2,
      cfop: '5102',
      description: 'Venda de mercadoria adquirida',
      movement: 'out',
      configuration_revision: 4,
      cfop_reference_version: null
    }, 'edit')
    t.after(form.unmount)

    assert.match(form.text(), /não (foi )?verificado/i)
    assert.equal(form.inputValue('cfop'), '5102')
    await form.input('description', 'Venda revisada')
    await form.submit()

    assert.deepEqual(form.emitted, [{
      erp_establishment_id: 2,
      cfop: '5102',
      description: 'Venda revisada',
      movement: 'out'
    }])
  })

  it('submits only a deliberately reviewed CFOP default with its captured revision', async (t) => {
    const form = mountForm('components/erp/catalogs/ErpOperationNatureCatalogForm.vue', {
      id: 32,
      erp_establishment_id: 2,
      cfop: '5102',
      description: 'Venda de mercadoria adquirida',
      movement: 'out',
      configuration_revision: 4,
      cfop_reference_version: null
    }, 'edit')
    t.after(form.unmount)

    await form.stageDefault()
    await form.submit()

    assert.deepEqual(form.emitted, [{ cfop: '5102', expected_revision: 4 }])
  })

  it('locks other root fields after staging a default in the mounted root and rules panel', async (t) => {
    const harness = mockNatureRules()
    const form = mountForm('components/erp/catalogs/ErpOperationNatureCatalogForm.vue', {
      id: 32, erp_establishment_id: 2, cfop: '5102', description: 'Venda original', movement: 'out', configuration_revision: 4, cfop_reference_version: null
    }, 'edit', {}, { realNaturePanel: true, rules: harness.rules })
    t.after(form.unmount)

    await form.clickButton('Usar como padrão')
    const description = descendants(form.root).find(node => node.tag === 'input' && node.props.name === 'description')

    assert.ok(description)
    assert.equal(isEffectivelyDisabled(description), true)
    assert.match(form.text(), /demais campos ficam bloqueados/i)
  })

  it('keeps an exception target hydrated off-page savable in the mounted rules panel', async (t) => {
    const harness = mockNatureRules()
    harness.rules.choices.value = [harness.oldChoice]
    harness.rules.exceptions.value = [harness.exception]
    harness.rules.exceptionChoiceRefs.value = {
      [harness.choice.id]: harness.choice,
      19: { id: 19, cfop: '9999', reference_version: null, status: 'archived', implicit: false }
    }
    const form = mountForm('components/erp/catalogs/ErpOperationNatureCatalogForm.vue', {
      id: 32, erp_establishment_id: 2, cfop: '5102', description: 'Venda', movement: 'out', configuration_revision: 4, cfop_reference_version: null
    }, 'edit', {}, { realNaturePanel: true, rules: harness.rules })
    t.after(form.unmount)
    await Vue.nextTick()
    const selector = descendants(form.root).find(node => node.tag === 'select' && node.props['aria-label'] === 'CFOP permitido para exceção')
    assert.ok(selector)
    assert.deepEqual((selector.props.items as Array<{ value: string }>).map(item => item.value), ['5102', '6102'])
    ;(selector.props.onChange as (event: Event) => void)({ target: { value: '6102' } } as unknown as Event)
    await Vue.nextTick()

    await form.clickButton('Atualizar CFOP')

    assert.deepEqual(harness.updates, [{ exception: harness.exception, choiceId: 18 }])
  })

  it('allows an explicit fresh root review after a stale staged save while preserving its CFOP draft', async (t) => {
    const harness = mockNatureRules()
    const reviewRequests: string[] = []
    let failReview = true
    const form = mountForm('components/erp/catalogs/ErpOperationNatureCatalogForm.vue', {
      id: 32, erp_establishment_id: 2, cfop: '5102', description: 'Venda', movement: 'out', configuration_revision: 4, cfop_reference_version: null
    }, 'edit', {}, {
      realNaturePanel: true,
      rules: harness.rules,
      bindings: { useAccessApi: () => ({ api: async (path: string) => {
        reviewRequests.push(path)
        if (failReview)
          throw new Error('Unavailable')
        return { data: { configuration_revision: 9, cfop: '5202', description: 'Descrição atual', movement: 'out' } }
      } }) }
    })
    t.after(form.unmount)
    await form.clickButton('Usar como padrão')
    await form.submit()

    assert.deepEqual(form.emitted, [{ cfop: '6102', expected_revision: 4 }])
    await form.setFieldErrors({ expected_revision: ['A configuração mudou.'] })
    await form.clickButton('Revisar configuração atual')
    assert.deepEqual(form.emitted, [{ cfop: '6102', expected_revision: 4 }])
    assert.equal(form.inputValue('description'), 'Venda')
    await form.submit()
    assert.deepEqual(form.emitted, [{ cfop: '6102', expected_revision: 4 }])
    failReview = false
    await form.clickButton('Revisar configuração atual')
    await form.submit()

    assert.deepEqual(reviewRequests, ['/erp/tax-settings/operation-natures/32', '/erp/tax-settings/operation-natures/32'])
    assert.deepEqual(form.emitted.at(-1), { cfop: '6102', expected_revision: 9 })
    assert.equal(form.inputValue('description'), 'Venda')
  })

  for (const item of editCases) {
    it(`disables ${item.field} and hides submit in ${item.path.split('/').at(-1)} detail mode`, async (t) => {
      const form = mountForm(item.path, item.record, 'detail')
      t.after(form.unmount)
      const primaryField = descendants(form.root).find(node => node.tag === 'input' && node.props.name === item.field)
      assert.ok(primaryField, `Expected primary field ${item.field} in ${item.path}`)
      assert.equal(isEffectivelyDisabled(primaryField), true)
      assert.equal(form.buttons().some(button => button.props.type === 'submit'), false)
      await form.submit()
      assert.deepEqual(form.emitted, [])
    })

    it(`retains ${item.field} after server field validation in ${item.path.split('/').at(-1)}`, async (t) => {
      const form = mountForm(item.path, item.record, 'edit')
      t.after(form.unmount)
      const message = 'Revise este campo.'
      await form.input(item.field, item.changed)
      await form.submit()
      assert.deepEqual(form.emitted, [item.expected])

      await form.setFieldErrors({ [item.field]: [message] })
      assert.ok(form.text().includes(message))
      assert.equal(form.inputValue(item.field), item.changed)
    })
  }

  for (const referenceCase of referenceErrorCases) {
    it(`keeps ${referenceCase.referenceLabel.toLocaleLowerCase()} and edits visible after ${referenceCase.referenceField} validation fails`, async (t) => {
      const item = editCases.find(candidate => candidate.path === referenceCase.path)!
      const form = mountForm(item.path, item.record, 'edit')
      t.after(form.unmount)

      await form.select(referenceCase.referenceLabel, referenceCase.referenceId)
      await form.input(item.field, item.changed)
      await form.submit()
      assert.deepEqual(form.emitted, [{ ...item.expected, [referenceCase.referenceField]: referenceCase.referenceId }])

      await form.setFieldErrors({ [referenceCase.referenceField]: [referenceCase.message] })
      assert.ok(form.text().includes(referenceCase.message))
      assert.equal(form.selectValue(referenceCase.referenceLabel), referenceCase.referenceId)
      assert.equal(form.inputValue(item.field), item.changed)
    })

    it(`disables ${referenceCase.referenceLabel.toLocaleLowerCase()} and form controls in ${referenceCase.path.split('/').at(-1)} detail mode`, async (t) => {
      const item = editCases.find(candidate => candidate.path === referenceCase.path)!
      const form = mountForm(item.path, item.record, 'detail')
      t.after(form.unmount)
      const fieldsets = descendants(form.root).filter(node => node.tag === 'fieldset')
      assert.equal(fieldsets.length, 1)
      assert.equal(fieldsets[0]?.props.disabled, true)
      const reference = descendants(form.root).find(node => node.tag === 'select' && node.props['aria-label'] === referenceCase.referenceLabel)
      assert.ok(reference)
      assert.equal(reference.props.disabled, true)
      assert.equal(form.buttons().some(button => button.props.type === 'submit'), false)
      await form.submit()
      assert.deepEqual(form.emitted, [])
    })
  }

  it('disables actual variant inputs in product detail and while a mutation is pending', async (t) => {
    const item = editCases.find(candidate => candidate.path.endsWith('ErpProductCatalogForm.vue'))!
    const detail = mountForm(item.path, item.record, 'detail')
    const edit = mountForm(item.path, item.record, 'edit')
    t.after(detail.unmount)
    t.after(edit.unmount)
    await detail.openSection('variants')
    await edit.openSection('variants')

    const detailVariantInputs = detail.inputs().filter(input => String(input.props.name).startsWith('variants.'))
    assert.equal(detailVariantInputs.length, 4)
    assert.ok(detailVariantInputs.every(input => input.props.disabled === true))
    assert.equal(detail.buttons().some(button => button.text.includes('Arquivar variante')), false)

    await edit.setBusy(true)
    const busyVariantInputs = edit.inputs().filter(input => String(input.props.name).startsWith('variants.'))
    assert.equal(busyVariantInputs.length, 4)
    assert.ok(busyVariantInputs.every(input => input.props.disabled === true))
    const archive = edit.buttons().find(button => button.text.includes('Arquivar variante'))
    assert.ok(archive)
    assert.equal(archive.props.disabled, true)
  })

  it('uses the standard titled variant archive dialog and ignores confirmation while busy', async (t) => {
    const item = editCases.find(candidate => candidate.path.endsWith('ErpProductCatalogForm.vue'))!
    const form = mountForm(item.path, item.record, 'edit')
    t.after(form.unmount)
    await form.openSection('variants')
    const archive = form.buttons().find(button => button.text.includes('Arquivar variante'))
    assert.ok(archive)
    const openArchive = archive.props.onClick as (() => void) | undefined
    assert.ok(openArchive)
    openArchive()
    await Vue.nextTick()

    const dialog = descendants(form.root).find(node => node.tag === 'section' && node.props.role === 'dialog')
    assert.ok(dialog)
    assert.equal(dialog.props['aria-label'], 'Arquivar variante')
    await form.setBusy(true)
    const confirm = form.buttons().find(button => button.text.includes('Confirmar arquivamento'))
    assert.ok(confirm)
    assert.equal(confirm.props.disabled, true)
    const confirmArchive = confirm.props.onClick as (() => void) | undefined
    assert.ok(confirmArchive)
    confirmArchive()

    await form.setBusy(false)
    await form.submit()
    assert.equal((form.emitted[0]?.variants as Array<Record<string, unknown>>)[0]?.status, undefined)
  })
})
