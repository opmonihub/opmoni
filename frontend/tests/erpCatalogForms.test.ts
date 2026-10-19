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

function mountForm(
  path: string,
  record: Record<string, unknown> | null = null,
  mode: ErpCatalogEditorMode = 'create',
  initialFieldErrors: Record<string, string[]> = {}
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
    props: ['modelValue', 'label', 'disabled', 'optional'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
      return () => Vue.h('select', {
        ...attrs,
        'aria-label': props.label,
        'value': props.modelValue ?? '',
        'disabled': props.disabled,
        'onChange': (event: Event) => emit('update:modelValue', Number((event.target as HTMLSelectElement).value) || null)
      }, [Vue.h('option', { value: '' }, 'Selecione')])
    }
  })
  const component = compileVueComponent(path, {}, {
    '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': referenceSelect
  })
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
      return () => Vue.h('label', { ...attrs }, [
        Vue.h('span', String(props.label ?? '')),
        ...(slots.default?.() ?? []),
        props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null
      ])
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
  return {
    root,
    emitted,
    inputs: () => descendants(root).filter(node => node.tag === 'input'),
    forms: () => descendants(root).filter(node => node.tag === 'form'),
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
      id: 17, name: 'Comercial Silva', trade_name: 'Silva', tax_id: '11223344000199', email: 'contato@silva.com',
      phone: '11988887777', notes: 'Ligar após as 14h', is_customer: true, is_supplier: true,
      address: {
        integration_code: 'CLIENT-77', street_type: 'Avenida', street: 'Paulista', number: '1000', complement: 'Conj. 5',
        district: 'Bela Vista', postal_code: '01310-100', city: 'São Paulo', state: 'SP'
      }
    },
    expected: {
      name: 'Comercial Silva Atualizado', trade_name: 'Silva', tax_id: '11223344000199', email: 'contato@silva.com',
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
      barcode: '7890001112223', status: 'active',
      variants: [{ id: 91, name: 'Padrão', sku: 'ABC', sale_price: '19.2500', barcode: '7890001112223', status: 'active' }]
    },
    expected: {
      name: 'Mercadoria Atualizada', sku: 'ABC', kind: 'goods', track_stock: true, erp_unit_id: 4, erp_brand_id: 5,
      erp_category_id: 6, sale_price: '19.2500', warranty_months: 12, warranty_description: '12 meses',
      barcode: '7890001112223',
      variants: [{ id: 91, name: 'Padrão', sku: 'ABC', sale_price: '19.2500', barcode: '7890001112223' }]
    }
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
        assert.deepEqual(form.emitted[0]?.variants, [{ name: 'Padrão', sku: null, sale_price: null, barcode: null }])
      }
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
