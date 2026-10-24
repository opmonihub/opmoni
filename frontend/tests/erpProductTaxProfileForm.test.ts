import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type Node = { tag: string, props: Record<string, unknown>, text: string, children: Node[], parent: Node | null }
type Request = { url: string, options?: { method?: string, body?: Record<string, unknown>, signal?: AbortSignal }, resolve(value: unknown): void, reject(reason: unknown): void }

function walk(root: Node): Node[] {
  return [root, ...root.children.flatMap(walk)]
}

function mountProduct(record: Record<string, unknown> | null = null, permissions = ['erp.product-tax-profiles.index', 'erp.products.update', 'erp.company.settings.show', 'erp.company.settings.update']) {
  const requests: Request[] = []
  const user = Vue.ref<{ id: number } | null>({ id: 5 })
  const company = Vue.ref({
    id: 41, status: 'active', access_kind: 'direct', role: 'owner', delegated_office_id: null,
    permissions
  })
  const account = Vue.ref(null)
  const generation = Vue.ref(1)
  const api = (url: string, options?: Request['options']) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }))
  let formState: Record<string, unknown> | null = null
  const emitted: Record<string, unknown>[] = []
  const componentProps = Vue.reactive({ record, mode: record ? 'edit' : 'create', canSave: true, canArchive: true, busy: false, fieldErrors: {} })
  const nativeUnit = { pending: Vue.ref(false), error: Vue.ref(''), status: Vue.ref('ready'), retry: async () => undefined, markExplicit: () => undefined }
  const component = compileVueComponent('components/erp/catalogs/ErpProductCatalogForm.vue', {
    useAuth: () => ({ user, currentCompany: company, currentAccount: account }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? generation : Vue.ref(init()),
    useAccessApi: () => ({ api }),
    computed: Vue.computed,
    onScopeDispose: Vue.onScopeDispose,
    reactive: Vue.reactive,
    ref: Vue.ref,
    watch: Vue.watch
  }, {
    '~/composables/useErpNativeUnitDefault': { useErpNativeUnitDefault: () => nativeUnit },
    '~/components/erp/catalogs/ErpCatalogField.vue': Vue.defineComponent({
      props: ['label', 'name', 'error', 'description', 'required'],
      setup(props, { slots }) {
        return () => Vue.h('label', { 'data-field': props.name }, [String(props.label ?? ''), ...(slots.default?.() ?? []), props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null])
      }
    }),
    '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': Vue.defineComponent({
      props: ['modelValue', 'path', 'label', 'labelKey', 'name', 'error', 'disabled', 'optional'],
      emits: ['update:modelValue'],
      setup(props, { emit }) {
        return () => Vue.h('label', { 'data-reference-path': props.path, 'data-field': props.name }, [
          String(props.label ?? ''),
          Vue.h('select', {
            'name': props.name,
            'aria-label': props.label,
            'value': props.modelValue ?? '',
            'onChange': (event: Event) => emit('update:modelValue', Number((event.target as HTMLSelectElement).value) || null)
          }, [Vue.h('option', { value: '' }, 'Nenhum'), Vue.h('option', { value: '12' }, 'Perfil A')]),
          props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null
        ])
      }
    })
  })
  const root: Node = { tag: 'root', props: {}, text: '', children: [], parent: null }
  const renderer = Vue.createRenderer<Node, Node>({
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
    createElement: tag => ({ tag, props: {}, text: '', children: [], parent: null }),
    createText: text => ({ tag: '#text', props: {}, text, children: [], parent: null }),
    createComment: text => ({ tag: '#comment', props: {}, text, children: [], parent: null }),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insertStaticContent(content, parent, anchor) {
      const node: Node = { tag: '#static', props: {}, text: content.replace(/<[^>]*>/g, ''), children: [], parent: null }
      this.insert(node, parent, anchor)
      return [node, node]
    }
  })
  const app = renderer.createApp(Vue.defineComponent({
    setup() {
      return () => Vue.h(component, {
        ...componentProps,
        onSave: (payload: Record<string, unknown>) => emitted.push(payload)
      })
    }
  }))
  app.component('UForm', Vue.defineComponent({
    props: ['state', 'disabled'],
    emits: ['submit'],
    setup(props, { attrs, emit, slots }) {
      Vue.watch(() => props.state, (value) => {
        formState = value as Record<string, unknown>
      }, { immediate: true })
      formState = props.state as Record<string, unknown>
      return () => Vue.h('form', { ...attrs, onSubmit: (event: Event) => emit('submit', event) }, slots.default?.())
    }
  }))
  app.component('UFormField', Vue.defineComponent({
    props: ['label', 'name', 'error', 'description', 'required'],
    setup(props, { slots }) { return () => Vue.h('label', { 'data-field': props.name }, [String(props.label ?? ''), ...(slots.default?.() ?? []), props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null]) }
  }))
  app.component('UAccordion', Vue.defineComponent({
    props: ['items', 'modelValue', 'unmountOnHide'],
    emits: ['update:modelValue'],
    setup(props, { emit: _emit, slots }) {
      return () => Vue.h('div', {}, (props.items as Array<{ label: string, value: string, slot: string }>).flatMap(item => [
        Vue.h('button', { 'type': 'button', 'data-accordion-value': item.value }, item.label),
        Vue.h('section', { 'data-accordion-panel': item.value }, slots[item.slot]?.({ item }))
      ]))
    }
  }))
  app.component('UInput', Vue.defineComponent({
    props: ['modelValue'], emits: ['update:modelValue'],
    setup(props, { attrs, emit }) { return () => Vue.h('input', { ...attrs, value: props.modelValue, onInput: (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).value) }) }
  }))
  app.component('USelect', Vue.defineComponent({
    props: ['modelValue', 'items'], emits: ['update:modelValue'],
    setup(props, { attrs, emit }) { return () => Vue.h('select', { ...attrs, value: props.modelValue, onChange: (event: Event) => emit('update:modelValue', (event.target as HTMLSelectElement).value) }, (props.items as Array<{ label: string, value: string }> ?? []).map(item => Vue.h('option', { value: item.value }, item.label))) }
  }))
  app.component('UCheckbox', Vue.defineComponent({
    props: ['modelValue', 'label'], emits: ['update:modelValue'],
    setup(props, { attrs, emit }) { return () => Vue.h('label', [Vue.h('input', { ...attrs, type: 'checkbox', checked: props.modelValue, onChange: (event: Event) => emit('update:modelValue', (event.target as HTMLInputElement).checked) }), String(props.label ?? '')]) }
  }))
  app.component('UButton', Vue.defineComponent({
    props: ['label', 'type', 'disabled', 'loading'],
    setup(props, { attrs, slots }) { return () => Vue.h('button', { ...attrs, type: props.type ?? 'button', disabled: props.disabled, onClick: attrs.onClick }, slots.default?.() ?? String(props.label ?? '')) }
  }))
  app.component('UAlert', Vue.defineComponent({ props: ['title', 'description'], setup: props => () => Vue.h('div', { role: 'alert' }, [String(props.title ?? ''), String(props.description ?? '')]) }))
  app.component('UBadge', Vue.defineComponent({ props: ['label'], setup: props => () => Vue.h('span', String(props.label ?? '')) }))
  app.component('UIcon', Vue.defineComponent({ props: ['name'], setup: () => () => Vue.h('span') }))
  app.component('UModal', Vue.defineComponent({ props: ['open', 'title', 'description'], setup: props => () => Vue.h('div', props.open ? { role: 'dialog' } : {}, String(props.title ?? '')) }))
  app.mount(root)
  const find = (predicate: (node: Node) => boolean) => walk(root).find(predicate)
  const text = () => walk(root).map(node => node.text).join(' ')
  return {
    root,
    requests,
    emitted,
    text,
    find,
    state: () => formState,
    unmount: () => app.unmount(),
    async submit() {
      const form = find(node => node.tag === 'form')
      assert.ok(form)
      ;(form.props.onSubmit as ((event: Event) => void) | undefined)?.({ preventDefault() {} } as Event)
      await Vue.nextTick()
    },
    async click(label: string) {
      const button = walk(root).find(node => node.tag === 'button' && node.text.includes(label))
      assert.ok(button, `Expected button ${label}`)
      ;(button.props.onClick as (() => void) | undefined)?.()
      await Vue.nextTick()
    },
    async select(name: string, value: string) {
      const select = walk(root).find(node => node.tag === 'select' && node.props.name === name)
      assert.ok(select, `Expected select ${name}`)
      ;(select.props.onChange as ((event: Event) => void) | undefined)?.({ target: { value } } as unknown as Event)
      await Vue.nextTick()
    }
  }
}

const existingRecord = {
  id: 77, name: 'Mercadoria', sku: 'A-1', kind: 'goods', track_stock: true,
  erp_unit_id: 17, erp_brand_id: null, erp_category_id: null, sale_price: '10.0000',
  warranty_months: null, warranty_description: null, barcode: null, barcode_type: 'C128',
  ncm: null, cest: null, origin_code: null,
  icms_cst: '01', icms_csosn: null, icms_rate: '0.0000', simple_national_credit_rate: null,
  pis_cst: null, pis_rate: null, cofins_cst: null, cofins_rate: null, ipi_cst: null, ipi_rate: null,
  ibs_cbs_cst: null, ibs_cbs_classification_code: null,
  erp_product_tax_profile_id: 12,
  tax_field_intents: { icms_cst: 'inherit', icms_rate: 'value', pis_cst: 'unset' },
  status: 'active',
  variants: [{ id: 91, name: 'Padrão', sku: 'A-1', sale_price: null, barcode: null, barcode_type: null, status: 'active' }]
}

describe('ERP product tax profile form', () => {
  it('offers an explicit company-default preview and a profile-free basic-save choice', (t) => {
    const form = mountProduct()
    t.after(form.unmount)
    assert.match(form.text(), /Padrão da empresa/)
    assert.match(form.text(), /Sem configuração tributária/)
    assert.match(form.text(), /Pré-visualizar tributos/)
  })

  it('round-trips existing profile identity, per-field intent and exact fiscal strings without implicit adoption', async (t) => {
    const form = mountProduct(existingRecord)
    t.after(form.unmount)
    await form.submit()
    assert.equal(form.emitted[0]?.erp_product_tax_profile_id, 12)
    assert.deepEqual(form.emitted[0]?.tax_field_intents, { icms_cst: 'inherit', icms_rate: 'value', pis_cst: 'unset' })
    assert.equal(Object.hasOwn(form.emitted[0]!, 'icms_cst'), false, 'inherited raw values are omitted from the payload')
    assert.equal(form.emitted[0]?.icms_rate, '0.0000')
    assert.equal(form.emitted[0]?.pis_cst, null)
    assert.deepEqual(form.emitted[0]?.variants, [{ id: 91, name: 'Padrão', sku: 'A-1', sale_price: null, barcode: null, barcode_type: null }])
  })

  it('previews a candidate through the non-mutating product endpoint and shows provenance before adoption', async (t) => {
    const form = mountProduct(existingRecord, ['erp.products.update'])
    t.after(form.unmount)
    await form.click('Pré-visualizar tributos')
    assert.equal(form.requests.length, 1)
    assert.equal(form.requests[0]?.url, '/erp/products/tax-preview')
    assert.equal(form.requests[0]?.options?.method, 'POST')
    assert.equal(form.requests[0]?.options?.body?.product_id, 77)
    assert.equal(form.requests[0]?.options?.body?.erp_product_tax_profile_id, 12)
    assert.equal('icms_cst' in (form.requests[0]?.options?.body ?? {}), false)
    form.requests[0]!.resolve({ data: {
      version: 'product-tax-v1', product_id: 77,
      values: { icms_cst: '01', icms_rate: '0.0000' },
      sources: { icms_cst: { source: 'profile', intent: 'inherit', profile_id: 12, revision: 3, revision_id: 31 }, icms_rate: { source: 'product', intent: 'value' } },
      unresolved_fields: ['pis_cst'],
      profile: { profile_id: 12, revision: 3, revision_id: 31 },
      company_default: { revision: 4, profile_id: 12, profile_revision: 3, profile_revision_id: 31 },
      context: { tax_regime: '1', kind: 'goods', movement: null },
      applicability: { profile: null, company_profile: null },
      company_settings_fingerprint: 'settings-4', fingerprint: 'preview-4',
      previous: { version: 'product-tax-v1', product_id: 77, values: { icms_cst: '01' }, sources: {}, unresolved_fields: [], profile: null, company_default: { revision: 4, profile_id: 12, profile_revision: 3, profile_revision_id: 31 }, context: { tax_regime: '1', kind: 'goods', movement: null }, applicability: { profile: null, company_profile: null }, company_settings_fingerprint: 'settings-4', fingerprint: 'previous-3' }
    } })
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
    assert.match(form.text(), /Prévia tributária/)
    assert.match(form.text(), /0\.0000/)
    assert.match(form.text(), /Perfil #12 · revisão 3/)
    assert.match(form.text(), /pis_cst/)
    assert.match(form.text(), /Adotar configuração revisada/)
    assert.deepEqual(form.state()?.tax_field_intents, existingRecord.tax_field_intents, 'preview alone cannot adopt inheritance')
  })

  it('keeps existing field intent unchanged on profile selection and adopts only after explicit review', async (t) => {
    const legacyProduct = { ...existingRecord, erp_product_tax_profile_id: null, tax_field_intents: { icms_cst: 'unset', icms_rate: 'value' } }
    const form = mountProduct(legacyProduct)
    t.after(form.unmount)
    await form.select('erp_product_tax_profile_id', '12')
    assert.deepEqual(form.state()?.tax_field_intents, legacyProduct.tax_field_intents)
    await form.click('Pré-visualizar tributos')
    assert.equal(form.requests[0]?.options?.body?.erp_product_tax_profile_id, 12)
    form.requests[0]!.resolve({ data: {
      version: 'product-tax-v1', product_id: 77,
      values: { icms_cst: '01', icms_rate: '0.0000' },
      sources: { icms_cst: { source: 'profile', intent: 'inherit', profile_id: 12, revision: 3, revision_id: 31 }, icms_rate: { source: 'product', intent: 'value' } },
      unresolved_fields: [], profile: { profile_id: 12, revision: 3, revision_id: 31 },
      company_default: { revision: 4, profile_id: 12, profile_revision: 3, profile_revision_id: 31 },
      context: { tax_regime: '1', kind: 'goods', movement: null },
      applicability: { profile: null, company_profile: null }, company_settings_fingerprint: 'settings-4', fingerprint: 'preview-5',
      previous: { version: 'product-tax-v1', product_id: 77, values: { icms_cst: '01' }, sources: {}, unresolved_fields: [], profile: null, company_default: { revision: 4, profile_id: 12, profile_revision: 3, profile_revision_id: 31 }, context: { tax_regime: '1', kind: 'goods', movement: null }, applicability: { profile: null, company_profile: null }, company_settings_fingerprint: 'settings-4', fingerprint: 'previous-4' }
    } })
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
    assert.deepEqual(form.state()?.tax_field_intents, legacyProduct.tax_field_intents)
    await form.click('Adotar configuração revisada')
    assert.equal((form.state()?.tax_field_intents as Record<string, string>).icms_cst, 'inherit')
    assert.equal((form.state()?.tax_field_intents as Record<string, string>).icms_rate, 'value')
  })

  it('keeps the last preview marked stale after a revision conflict without changing the draft', async (t) => {
    const form = mountProduct(existingRecord, ['erp.products.update'])
    t.after(form.unmount)
    await form.click('Pré-visualizar tributos')
    form.requests[0]!.resolve({ data: {
      version: 'product-tax-v1', product_id: 77, values: { icms_cst: '01' },
      sources: { icms_cst: { source: 'profile', intent: 'inherit', profile_id: 12, revision: 3, revision_id: 31 } },
      unresolved_fields: [], profile: { profile_id: 12, revision: 3, revision_id: 31 },
      company_default: { revision: 4, profile_id: 12, profile_revision: 3, profile_revision_id: 31 },
      context: { tax_regime: '1', kind: 'goods', movement: null }, applicability: { profile: null, company_profile: null },
      company_settings_fingerprint: 'settings-4', fingerprint: 'preview-good'
    } })
    await Vue.nextTick()
    await form.click('Pré-visualizar tributos')
    form.requests[1]!.reject({ statusCode: 409, data: { message: 'A revisão tributária mudou; atualize a prévia.' } })
    await new Promise(resolve => setImmediate(resolve))
    await Vue.nextTick()
    assert.match(form.text(), /Prévia desatualizada/)
    assert.match(form.text(), /preview-good/)
    assert.deepEqual(form.state()?.tax_field_intents, existingRecord.tax_field_intents)
    assert.equal(form.emitted.length, 0)
  })

  it('keeps a profile-free save free of hidden tax relationship and intent metadata', async (t) => {
    const form = mountProduct()
    t.after(form.unmount)
    const state = form.state()!
    state.name = 'Produto sem perfil'
    state.erp_unit_id = 17
    await form.submit()
    assert.equal(form.emitted[0]?.name, 'Produto sem perfil')
    assert.equal(Object.hasOwn(form.emitted[0]!, 'erp_product_tax_profile_id'), false)
    assert.equal(Object.hasOwn(form.emitted[0]!, 'tax_field_intents'), false)
  })
})
