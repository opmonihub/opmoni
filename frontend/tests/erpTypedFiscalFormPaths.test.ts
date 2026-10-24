import assert from 'node:assert/strict'
import { it } from 'node:test'
import { readFileSync } from 'node:fs'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import type { ErpFiscalConfiguration, ErpFiscalModel } from '../shared/types/erpFiscal.ts'
import {
  erpTypedFiscalFieldDescriptors,
  mapErpTypedFiscalFieldErrors,
  requiredErpTypedFiscalFields
} from '../app/utils/erpTypedFiscalForm.ts'
import * as typedFiscalForm from '../app/utils/erpTypedFiscalForm.ts'

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
  value: string
  focused: boolean
  focus(): void
  querySelector(selector: string): HostNode | null
}

function createRenderer() {
  const createNode = (tag: string, text = ''): HostNode => {
    const node = { tag, props: {}, children: [], parent: null, text, value: '', focused: false } as HostNode
    node.focus = () => {
      node.focused = true
    }
    node.querySelector = (selector: string) => {
      const match = /^\[data-fiscal-field-key="([^"]+)"\]$/.exec(selector)
      return match ? findNode(node, child => child.props['data-fiscal-field-key'] === match[1]) ?? null : null
    }
    return node
  }
  const renderer = Vue.createRenderer({
    patchProp(node, key, _old, value) {
      node.props[key] = value
      if (key === 'value') node.value = String(value ?? '')
    },
    insert(node, parent, anchor = null) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const at = anchor ? parent.children.indexOf(anchor) : -1
      if (at < 0) parent.children.push(node)
      else parent.children.splice(at, 0, node)
    },
    remove(node) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    createElement: tag => createNode(tag),
    createText: text => createNode('#text', text),
    createComment: text => createNode('#comment', text),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  return { renderer, createNode }
}

function findNode(root: HostNode, predicate: (node: HostNode) => boolean): HostNode | undefined {
  if (predicate(root)) return root
  for (const child of root.children) {
    const result = findNode(child, predicate)
    if (result) return result
  }
}

function mountDocumentForm(model: ErpFiscalModel, fieldErrors: Record<string, string>) {
  const sourceFile = new URL('../app/components/erp/ErpFiscalDocumentForm.vue', import.meta.url)
  const source = readFileSync(sourceFile, 'utf8')
  const { descriptor } = parse(source, { filename: sourceFile.pathname })
  const script = compileScript(descriptor, { id: 'erp-typed-fiscal-paths-script' })
  const compiledScript = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const exports = {} as { default: Record<string, unknown> }
  const globals = { ref: Vue.ref, computed: Vue.computed, reactive: Vue.reactive, watch: Vue.watch, nextTick: Vue.nextTick }
  new Function('require', 'exports', ...Object.keys(globals), compiledScript.outputText)((name: string) => {
    if (name === 'vue') return Vue
    if (name === '#shared/utils/erpFiscal') return { buildErpFiscalDraftSource: () => ({}) }
    if (name === '~/utils/erpTypedFiscalForm') return typedFiscalForm
    throw new Error(`Unexpected fiscal form import: ${name}`)
  }, exports, ...Object.values(globals))

  const template = compileTemplate({
    source: descriptor.template?.content ?? '',
    filename: sourceFile.pathname,
    id: 'erp-typed-fiscal-paths-template',
    compilerOptions: { mode: 'function', hoistStatic: false, bindingMetadata: script.bindings, expressionPlugins: ['typescript'] }
  })
  const compiledTemplate = ts.transpileModule(template.code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const component = exports.default as { render?: (context: unknown, cache: unknown) => unknown }
  component.render = new Function('Vue', compiledTemplate.outputText)(Vue) as typeof component.render

  const { renderer, createNode } = createRenderer()
  const configuration = {
    environment: 'homologation',
    release_gates: { provider: 'fake', production_transmission_enabled: false },
    models: Object.fromEntries(['55', '65', '57', '58'].map(value => [value, { readiness: { status: 'ready' }, nfce_qr: { mode: 'online' } }]))
  } as unknown as ErpFiscalConfiguration
  const app = renderer.createApp(component as never, {
    establishmentId: 6,
    configuration,
    model,
    sales: [],
    selectedSale: null,
    returns: [],
    selectedReturn: null,
    originalDocuments: [],
    canCreate: true,
    fieldErrors,
    onCreate: () => {}
  })

  app.component('UForm', Vue.defineComponent({
    inheritAttrs: false,
    emits: ['submit'],
    setup(_props, { attrs, slots, emit }) {
      return () => Vue.h('form', { ...attrs, onSubmit: (event: { preventDefault: () => void }) => {
        event.preventDefault()
        emit('submit', event)
      } }, slots.default?.())
    }
  }))
  app.component('UFormField', Vue.defineComponent({
    props: ['label', 'name', 'error'],
    setup(field, { attrs, slots }) {
      Vue.provide('typed-fiscal-field', field.name)
      return () => Vue.h('label', { ...attrs, 'data-field': field.name }, [Vue.h('span', String(field.label ?? '')), slots.default?.(), field.error ? Vue.h('span', { role: 'alert' }, String(field.error)) : null])
    }
  }))
  app.component('UInput', Vue.defineComponent({
    props: ['modelValue', 'type', 'maxlength', 'inputmode'],
    emits: ['update:modelValue'],
    setup(input, { attrs, emit }) {
      const fieldName = Vue.inject<string>('typed-fiscal-field', '')
      return () => Vue.h('input', { ...attrs, 'type': input.type ?? 'text', 'data-field': fieldName, 'maxlength': input.maxlength, 'inputmode': input.inputmode, 'value': input.modelValue ?? '', 'onInput': (event: { target: { value: string } }) => emit('update:modelValue', event.target.value) })
    }
  }))
  app.component('UTextarea', Vue.defineComponent({
    props: ['modelValue'],
    emits: ['update:modelValue'],
    setup(input, { attrs, emit }) {
      const fieldName = Vue.inject<string>('typed-fiscal-field', '')
      return () => Vue.h('textarea', { ...attrs, 'data-field': fieldName, 'value': input.modelValue ?? '', 'onInput': (event: { target: { value: string } }) => emit('update:modelValue', event.target.value) })
    }
  }))
  app.component('USelect', Vue.defineComponent({
    props: ['modelValue', 'items'],
    emits: ['update:modelValue'],
    setup(select, { attrs, emit }) {
      const fieldName = Vue.inject<string>('typed-fiscal-field', '')
      return () => Vue.h('select', { ...attrs, 'data-field': fieldName, 'value': select.modelValue == null ? '' : String(select.modelValue), 'onChange': (event: { target: { value: string } }) => emit('update:modelValue', event.target.value) }, (select.items as Array<{ label: string, value: unknown }> ?? []).map(item => Vue.h('option', { value: String(item.value) }, item.label)))
    }
  }))
  for (const name of ['UAlert', 'UBadge']) {
    app.component(name, Vue.defineComponent({
      setup(_props, { slots }) {
        return () => slots.default?.() ?? null
      }
    }))
  }
  app.component('UButton', Vue.defineComponent({
    props: ['type', 'disabled', 'loading'],
    setup(button, { attrs, slots }) {
      return () => Vue.h('button', { ...attrs, type: button.type ?? 'button', disabled: button.disabled }, slots.default?.())
    }
  }))

  const root = createNode('root')
  app.mount(root as never)
  return { app, root }
}

it('maps nested CT-e builder errors to focused controls and preserves the entered facts', async () => {
  const fieldErrors = Vue.reactive<Record<string, string>>({})
  const { app, root } = mountDocumentForm('57', fieldErrors)
  try {
    const street = findNode(root, node => node.tag === 'input' && node.props['data-fiscal-field-key'] === 'sender_street')
    assert.ok(street, 'the sender address field renders a real input')
    ;(street.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Rua editada' } })
    await Vue.nextTick()

    fieldErrors.source = 'Dados fiscais obrigatórios ausentes ou inválidos: transport_service.sender.address.street, transport_service.tax.rate.'
    await Vue.nextTick()
    await Vue.nextTick()

    const rate = findNode(root, node => node.tag === 'input' && node.props['data-fiscal-field-key'] === 'tax_rate')
    const rateError = findNode(root, node => node.tag === 'span' && node.props.role === 'alert' && node.text.length > 0 && node.parent?.props['data-field'] === 'source.transport_service.tax.rate')
    const streetError = findNode(root, node => node.tag === 'span' && node.props.role === 'alert' && node.text.length > 0 && node.parent?.props['data-field'] === 'source.transport_service.sender.address.street')
    assert.equal(street.value, 'Rua editada', 'the rejected draft keeps the operator-entered address')
    assert.ok(streetError, 'the nested party path is rendered beside its actual field')
    assert.ok(rateError, 'the nested tax path is rendered beside its actual field')
    assert.equal(rate?.focused, true, 'the first rejected nested fact receives focus')
    assert.equal(street.focused, false, 'focus stays on the first concrete rejection')
  } finally {
    app.unmount()
  }
})

it('maps MDF-e route and vehicle rejections to the mounted inputs', async () => {
  const fieldErrors = Vue.reactive<Record<string, string>>({
    source: 'Dados fiscais obrigatórios ausentes ou inválidos: transport_manifest.route_ufs, transport_manifest.vehicle.plate.'
  })
  const { app, root } = mountDocumentForm('58', fieldErrors)
  try {
    await Vue.nextTick()
    await Vue.nextTick()

    const route = findNode(root, node => node.tag === 'input' && node.props['data-fiscal-field-key'] === 'manifest_route_ufs')
    const vehicle = findNode(root, node => node.tag === 'input' && node.props['data-fiscal-field-key'] === 'vehicle_plate')
    assert.ok(findNode(root, node => node.tag === 'span' && node.props.role === 'alert' && node.parent?.props['data-field'] === 'source.transport_manifest.route_ufs'))
    assert.ok(findNode(root, node => node.tag === 'span' && node.props.role === 'alert' && node.parent?.props['data-field'] === 'source.transport_manifest.vehicle.plate'))
    assert.equal(route?.focused, true, 'the rejected route control receives focus')
    assert.equal(vehicle?.focused, false, 'a later rejected vehicle field remains available for correction')
  } finally {
    app.unmount()
  }
})

it('applies typed CT-e required conditions only to facts the builder requires', () => {
  const shortCargo = requiredErpTypedFiscalFields('57', 'transport_service', { taker_role: '0', cargo_description: 'Carga curta' }).map(field => field.key)
  const otherTaker = requiredErpTypedFiscalFields('57', 'substitution', { taker_role: '4', cargo_description: 'Carga com descrição maior que vinte' }).map(field => field.key)

  assert.equal(shortCargo.includes('taker_cnpj'), false, 'the alternate taker details are optional for roles 0 to 3')
  assert.equal(shortCargo.includes('cargo_measure_type'), false, 'the builder can use a short description as its measure type')
  assert.equal(otherTaker.includes('taker_cnpj'), true, 'role 4 requires a tax identifier')
  assert.equal(otherTaker.includes('taker_street'), true, 'role 4 requires the taker address')
  assert.equal(otherTaker.includes('cargo_measure_type'), true, 'long cargo descriptions need a separate measure type')
  assert.ok(erpTypedFiscalFieldDescriptors('57', 'complement').some(field => field.path === 'transport_service.tax.rate'))
  assert.deepEqual(erpTypedFiscalFieldDescriptors('58', 'transport_service'), [], 'MDF-e descriptors do not leak into CT-e kinds')
})

it('keeps unrecognized and malformed 422 errors in the summary without guessing a field', () => {
  const unknown = mapErpTypedFiscalFieldErrors({ source: 'Revisão fiscal indisponível.' }, '57', 'transport_service')
  const nested = mapErpTypedFiscalFieldErrors({ source: { transport_service: { tax: { rate: ['Alíquota incompatível.'] } } } }, '57', 'transport_service')
  const parsed = mapErpTypedFiscalFieldErrors({ source: 'Dados fiscais obrigatórios ausentes ou inválidos: transport_service.not_in_form.' }, '57', 'transport_service')
  const builderAlias = mapErpTypedFiscalFieldErrors({ source: 'Dados CT-e obrigatórios ausentes: transport_service.sender.city_code.' }, '57', 'transport_service')

  assert.deepEqual(unknown.fields, {})
  assert.deepEqual(unknown.summary, ['Revisão fiscal indisponível.'])
  assert.equal(nested.fields.tax_rate, 'Alíquota incompatível.')
  assert.deepEqual(nested.summary, [])
  assert.deepEqual(parsed.fields, {})
  assert.equal(parsed.summary.length, 1, 'unknown builder paths stay visible and do not select an unrelated input')
  assert.match(builderAlias.fields.sender_city_code ?? '', /código ibge/, 'the builder participant path focuses the shared municipality control')
})

it('offers explicit correction targets for the factory aggregate MDF-e geography errors', async () => {
  const cases = [
    {
      path: 'transport_manifest.uf',
      targets: [
        { key: 'manifest_route_ufs', label: 'UFs do percurso' },
        { key: 'vehicle_uf', label: 'UF do veículo' }
      ],
      fact: 'SP,XX'
    },
    {
      path: 'transport_manifest.municipalities.code',
      targets: [
        { key: 'manifest_load_municipality_code', label: 'Código IBGE do município de carregamento' },
        { key: 'manifest_unload_municipality_code', label: 'Código IBGE do município de descarregamento' }
      ],
      fact: 'abc'
    },
    {
      path: 'transport_manifest.municipalities.name',
      targets: [
        { key: 'manifest_load_municipality_name', label: 'Município de carregamento' },
        { key: 'manifest_unload_municipality_name', label: 'Município de descarregamento' }
      ],
      fact: 'X'
    }
  ]

  for (const item of cases) {
    const fieldErrors = Vue.reactive<Record<string, string>>({})
    const { app, root } = mountDocumentForm('58', fieldErrors)
    try {
      const firstControl = findNode(root, node => node.tag === 'input' && node.props['data-fiscal-field-key'] === item.targets[0]!.key)
      assert.ok(firstControl, `the first explicit correction target ${item.targets[0]!.key} is mounted`)
      ;(firstControl.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: item.fact } })
      await Vue.nextTick()

      fieldErrors.source = `Dados fiscais obrigatórios ausentes ou inválidos: ${item.path}.`
      await Vue.nextTick()
      await Vue.nextTick()

      for (const target of item.targets) {
        const action = findNode(root, node => node.tag === 'button' && node.props['data-fiscal-error-target'] === target.key)
        assert.ok(action, `${item.path} exposes an explicit correction action for ${target.label}`)
        assert.equal(action.props['aria-label'], `Ir para ${target.label}`)
        ;(action.props.onClick as () => void)()
        await Vue.nextTick()
        const control = findNode(root, node => node.tag === 'input' && node.props['data-fiscal-field-key'] === target.key)
        assert.equal(control?.focused, true, `${target.label} action focuses its corresponding real control`)
        assert.equal(firstControl.value, item.fact, 'the server rejection does not discard the entered fact')
        if (target.key !== item.targets[0]!.key) firstControl.focused = false
      }
    } finally {
      app.unmount()
    }
  }
})
