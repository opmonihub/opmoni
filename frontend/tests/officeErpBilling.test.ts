import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'
import { formatOfficeErpMoney, parseOfficeErpMoney } from '../shared/utils/officeErpCommercial.ts'

interface HostNode {
  tag: string
  text?: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
}

function mountComponent(name: 'OfficeErpAdjustmentForm' | 'OfficeErpReceiptModal' | 'OfficeErpReversalModal', props: Record<string, unknown> = {}) {
  const root: HostNode = { tag: 'root', props: {}, children: [], parent: null }
  const renderer = Vue.createRenderer<HostNode, HostNode>({
    createElement: tag => ({ tag, props: {}, children: [], parent: null }),
    createText: text => ({ tag: '#text', text, props: {}, children: [], parent: null }),
    createComment: text => ({ tag: '#comment', text, props: {}, children: [], parent: null }),
    setText: (node, text) => { node.text = text },
    setElementText: (node, text) => {
      node.text = text
      node.children = []
    },
    patchProp: (node, key, _previous, next) => { node.props[key] = next },
    insert: (node, parent, anchor) => {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
    },
    remove: (node) => {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const component = compileVueComponent(`components/office-erp/${name}.vue`, {}, {
    '#shared/utils/officeErpCommercial': { parseOfficeErpMoney, formatOfficeErpMoney }
  })
  const componentProps = Vue.reactive(props)
  const app = renderer.createApp({ render: () => Vue.h(component, componentProps) })
  app.component('UApp', { setup: (_props, context) => () => Vue.h('div', context.slots.default?.()) })
  app.component('UForm', { emits: ['submit'], setup: (_props, context) => () => Vue.h('form', { onSubmit: (event: unknown) => context.emit('submit', event) }, context.slots.default?.()) })
  app.component('UFormField', { props: ['label', 'error', 'description', 'name'], setup: (field, context) => () => Vue.h('label', { 'data-field': field.name }, [String(field.label ?? ''), ...(context.slots.default?.() ?? []), field.error ? Vue.h('span', { role: 'alert' }, String(field.error)) : null]) })
  app.component('UInput', { props: ['modelValue', 'label', 'type', 'accept'], emits: ['update:modelValue', 'change'], setup: (field, context) => () => Vue.h('input', { ...context.attrs, 'type': field.type ?? 'text', 'accept': field.accept, 'aria-label': field.label, 'value': field.modelValue, 'onInput': (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value), 'onChange': (event: unknown) => context.emit('change', event) }) })
  app.component('UTextarea', { props: ['modelValue', 'label'], emits: ['update:modelValue'], setup: (field, context) => () => Vue.h('textarea', { ...context.attrs, 'aria-label': field.label, 'value': field.modelValue, 'onInput': (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value) }) })
  app.component('USelect', { props: ['modelValue', 'label', 'items'], emits: ['update:modelValue'], setup: (field, context) => () => Vue.h('select', { 'aria-label': field.label, 'value': field.modelValue, 'onChange': (event: { target: { value: string } }) => context.emit('update:modelValue', event.target.value) }, (field.items as Array<{ label: string, value: string }> ?? []).map(item => Vue.h('option', { value: item.value }, item.label))) })
  app.component('UCheckboxGroup', { props: ['modelValue', 'items'], emits: ['update:modelValue'], setup: (field, context) => () => Vue.h('fieldset', (field.items as Array<{ label: string, value: string }> ?? []).map(item => Vue.h('button', { 'type': 'button', 'data-choice': item.value, 'onClick': () => {
    const selected = field.modelValue as string[]
    context.emit('update:modelValue', selected.includes(item.value) ? selected.filter(value => value !== item.value) : [...selected, item.value])
  } }, item.label))) })
  app.component('UModal', { props: ['open', 'title', 'description'], emits: ['update:open'], setup: (modal, context) => () => modal.open ? Vue.h('section', { 'role': 'dialog', 'aria-label': modal.title }, [Vue.h('h2', String(modal.title ?? '')), Vue.h('p', String(modal.description ?? '')), ...(context.slots.body?.() ?? []), ...(context.slots.footer?.() ?? []), Vue.h('button', { 'type': 'button', 'aria-label': 'Fechar modal', 'onClick': () => context.emit('update:open', false) }, 'Fechar modal')]) : null })
  app.component('UAlert', { props: ['title', 'description'], setup: (alert, context) => () => Vue.h('div', { role: 'alert' }, [String(alert.title ?? ''), String(alert.description ?? ''), ...(context.slots.default?.() ?? [])]) })
  app.component('UButton', { props: ['label', 'type', 'disabled', 'loading'], setup: (button, context) => () => Vue.h('button', { ...context.attrs, 'type': button.type ?? 'button', 'disabled': button.disabled, 'aria-busy': button.loading ? 'true' : undefined }, context.slots.default?.() ?? String(button.label ?? '')) })
  app.mount(root)
  const find = (predicate: (node: HostNode) => boolean): HostNode | undefined => {
    const visit = (node: HostNode): HostNode | undefined => predicate(node) ? node : node.children.map(visit).find(Boolean)
    return visit(root)
  }
  const all = (predicate: (node: HostNode) => boolean): HostNode[] => {
    const result: HostNode[] = []
    const visit = (node: HostNode) => {
      if (predicate(node)) result.push(node)
      node.children.forEach(visit)
    }
    visit(root)
    return result
  }
  const text = (node: HostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`
  return { app, root, props: componentProps, find, all, text }
}

function input(mounted: ReturnType<typeof mountComponent>, label: string, value: string) {
  const node = mounted.find(candidate => candidate.tag === 'input' && candidate.props['aria-label'] === label)
  assert.ok(node, `Missing input ${label}`)
  ;(node.props.onInput as (event: { target: { value: string } }) => void)({ target: { value } })
  return node
}

describe('office ERP billing form components', () => {
  it('emits a positive credit amount and preserves it with a server validation error', async (t) => {
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpAdjustmentForm', { direction: 'credit', errors: {}, onSubmit: (payload: unknown) => submitted.push(payload) })
    t.after(() => mounted.app.unmount())
    const amount = input(mounted, 'Valor do ajuste', '60,00')
    const reason = mounted.find(node => node.tag === 'textarea' && node.props['aria-label'] === 'Motivo')
    assert.ok(reason)
    ;(reason.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Correção de cobrança' } })
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(submitted[0], { direction: 'credit', amount: '60.00', reason: 'Correção de cobrança' })
    mounted.props.errors = { amount: ['O valor excede o saldo disponível.'] }
    await Vue.nextTick()
    assert.equal(amount.props.value, '60,00', 'a server validation error keeps the visible amount draft')
    assert.match(mounted.text(mounted.root), /O valor excede o saldo disponível/)
    assert.equal(parseOfficeErpMoney('9.007.199.254.740.993,01'), '9007199254740993.01')
  })

  it('emits partial multi-invoice allocations as canonical string amounts', async (t) => {
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', {
      open: true,
      invoices: [{ id: 7, label: 'Fatura 7', outstanding: '100.00' }, { id: 8, label: 'Fatura 8', outstanding: '12.50' }],
      onSubmit: (payload: unknown) => submitted.push(payload)
    })
    t.after(() => mounted.app.unmount())
    const choice = mounted.find(node => node.props['data-choice'] === '7')
    assert.ok(choice)
    ;(choice.props.onClick as () => void)()
    await Vue.nextTick()
    input(mounted, 'Alocação para fatura 7', '40,00')
    const receivedOn = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Data do recebimento')
    assert.ok(receivedOn)
    ;(receivedOn.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '2026-10-25' } })
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(submitted[0], { amount: '40.00', received_on: '2026-10-25', allocations: [{ invoice_id: 7, amount: '40.00' }] })
    assert.match(mounted.text(mounted.root), /manual|manualmente/i)
  })

  it('sums allocations across selected invoices with integer cents and enforces each outstanding balance', async (t) => {
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', {
      open: true,
      invoices: [{ id: 7, label: 'Fatura 7', outstanding: '40.00' }, { id: 8, label: 'Fatura 8', outstanding: '12.50' }],
      onSubmit: (payload: unknown) => submitted.push(payload)
    })
    t.after(() => mounted.app.unmount())
    for (const id of ['7', '8']) {
      const choice = mounted.find(node => node.props['data-choice'] === id)
      assert.ok(choice)
      ;(choice.props.onClick as () => void)()
      await Vue.nextTick()
    }
    input(mounted, 'Alocação para fatura 7', '40.00')
    input(mounted, 'Alocação para fatura 8', '2,50')
    const date = mounted.find(node => node.tag === 'input' && node.props['aria-label'] === 'Data do recebimento')
    assert.ok(date)
    ;(date.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '2026-10-25' } })
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(submitted[0], {
      amount: '42.50',
      received_on: '2026-10-25',
      allocations: [{ invoice_id: 7, amount: '40.00' }, { invoice_id: 8, amount: '2.50' }]
    })
    input(mounted, 'Alocação para fatura 7', '40.01')
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    await Vue.nextTick()
    assert.equal(submitted.length, 1, 'allocation above the selected invoice balance is rejected locally')
    assert.match(mounted.text(mounted.root), /excede o saldo em aberto/i)
  })

  it('keeps recovery available while a receipt command is unknown and blocks resubmission', async (t) => {
    const recovered: string[] = []
    const submitted: unknown[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', {
      open: true, busy: false, unknown: true, recoveryKey: '11111111-2222-4333-8444-555555555555', invoices: [],
      onRecover: (key: string) => recovered.push(key), onSubmit: (payload: unknown) => submitted.push(payload)
    })
    t.after(() => mounted.app.unmount())
    const recover = mounted.find(node => node.tag === 'button' && mounted.text(node).includes('Consultar operação'))
    assert.ok(recover)
    assert.equal(recover.props.disabled, undefined)
    ;(recover.props.onClick as () => void)()
    assert.deepEqual(recovered, ['11111111-2222-4333-8444-555555555555'])
    const submit = mounted.find(node => node.tag === 'form')
    assert.equal(submit, undefined, 'unknown command must not render an editable submit form')
    assert.deepEqual(submitted, [])
  })

  it('validates private proof MIME and 5 MiB size before emitting a separate upload intent', async (t) => {
    const uploads: File[] = []
    const mounted = mountComponent('OfficeErpReceiptModal', { open: true, invoices: [], onUploadProof: (file: File) => uploads.push(file) })
    t.after(() => mounted.app.unmount())
    const fileInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')
    assert.ok(fileInput)
    assert.equal(fileInput.props.accept, '.pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg')
    const tooLarge = new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'grande.pdf', { type: 'application/pdf' })
    ;(fileInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [tooLarge] } })
    await Vue.nextTick()
    assert.match(mounted.text(mounted.root), /no máximo 5 MiB/i)
    const unsupported = new File(['text'], 'comprovante.txt', { type: 'text/plain' })
    const retryInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')!
    ;(retryInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [unsupported] } })
    await Vue.nextTick()
    assert.match(mounted.text(mounted.root), /Envie um arquivo PDF, PNG ou JPEG/i)
    const atLimit = new File([new Uint8Array(5 * 1024 * 1024)], 'limite.pdf', { type: 'application/pdf' })
    const boundaryInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')!
    ;(boundaryInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [atLimit] } })
    await Vue.nextTick()
    assert.deepEqual(uploads, [atLimit], 'exactly 5 MiB remains eligible for private upload')
    const valid = new File(['%PDF-1.7\nfixture'], 'comprovante.pdf', { type: 'application/pdf' })
    const refreshedInput = mounted.find(node => node.tag === 'input' && node.props.type === 'file')!
    ;(refreshedInput.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [valid] } })
    await Vue.nextTick()
    assert.deepEqual(uploads, [atLimit, valid])
  })

  it('requires a reversal reason and keeps modal dismissal parent-controlled', async (t) => {
    const reversed: unknown[] = []
    const dismissed: boolean[] = []
    const openUpdates: boolean[] = []
    const mounted = mountComponent('OfficeErpReversalModal', { 'open': true, 'onSubmit': (payload: unknown) => reversed.push(payload), 'onCancel': () => dismissed.push(true), 'onUpdate:open': (open: boolean) => openUpdates.push(open) })
    t.after(() => mounted.app.unmount())
    const form = mounted.find(node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(reversed, [])
    const reason = mounted.find(node => node.tag === 'textarea' && node.props['aria-label'] === 'Motivo da reversão')
    assert.ok(reason)
    ;(reason.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Registro lançado em duplicidade' } })
    ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
    assert.deepEqual(reversed, [{ reason: 'Registro lançado em duplicidade' }])
    const close = mounted.find(node => node.props['aria-label'] === 'Fechar modal')
    assert.ok(close)
    ;(close.props.onClick as () => void)()
    assert.deepEqual(dismissed, [true])
    assert.deepEqual(openUpdates, [false])
  })
})
