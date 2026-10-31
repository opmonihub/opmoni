import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type Node = { tag?: string, text?: string, props: Record<string, unknown>, children: Node[], parent?: Node | null }

function allNodes(node: Node): Node[] {
  return [node, ...node.children.flatMap(allNodes)]
}

function text(node: Node): string {
  return `${node.text ?? ''}${node.children.map(text).join('')}`
}

function remove(node: Node) {
  const siblings = node.parent!.children
  const index = siblings.indexOf(node)
  if (index >= 0) siblings.splice(index, 1)
}

const currentRevision = {
  id: 41,
  revision_number: 2,
  price: '125.50',
  interval_months: 1,
  due_in_days: 10,
  module_keys: ['purchases', 'expenses'],
  service_period_start: '2026-10-25',
  service_period_end: '2026-11-24'
}
const pendingRevision = {
  id: 52,
  revision_number: 3,
  price: '130.00',
  interval_months: 3,
  due_in_days: 15,
  module_keys: ['purchases', 'expenses', 'cte'],
  service_period_start: '2026-11-25',
  service_period_end: '2027-02-24'
}
const contract = {
  id: 17,
  agreement_status: 'effective',
  current_revision: currentRevision,
  pending_revision: pendingRevision,
  manual_hold: false,
  cancel_effective_on: null,
  next_billing_on: '2026-11-24'
}

function component(path: string, props: Record<string, unknown>) {
  const events: Array<{ name: string, payload: unknown }> = []
  const instance = compileVueComponent(path, {
    computed: Vue.computed,
    nextTick: Vue.nextTick,
    onBeforeUnmount: Vue.onBeforeUnmount,
    ref: Vue.ref,
    toRef: Vue.toRef,
    watch: Vue.watch,
    useModalFocusReturn: () => () => {}
  })
  const originalSetup = instance.setup
  assert.equal(typeof originalSetup, 'function')
  const root: Node = { props: {}, children: [] }
  const renderer = Vue.createRenderer({
    patchProp(node: Node, key, _oldValue, nextValue) { node.props[key] = nextValue },
    insert(node: Node, parent: Node, anchor?: Node) {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove,
    createElement(tag) { return { tag, props: {}, children: [], parent: null } },
    createText(value) { return { text: value, props: {}, children: [], parent: null } },
    createComment(value) { return { text: value, props: {}, children: [], parent: null } },
    setText(node: Node, value) { node.text = value },
    setElementText(node: Node, value) {
      node.text = value
      node.children = []
    },
    parentNode(node: Node) { return node.parent ?? null },
    nextSibling(node: Node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp({
    ...instance,
    setup(rawProps, context) {
      const wrappedContext = { ...context, emit: (name: string, payload: unknown) => events.push({ name, payload }) }
      return originalSetup!(rawProps, wrappedContext)
    }
  }, props)
  app.component('UModal', {
    setup(_modalProps, context) {
      return () => Vue.h('section', { 'data-modal': true }, [context.slots.body?.(), context.slots.footer?.()])
    }
  })
  app.component('UAlert', {
    props: ['title', 'description'],
    setup(alertProps, context) {
      return () => Vue.h('div', {}, [alertProps.title, alertProps.description, context.slots.description?.()])
    }
  })
  app.component('UBadge', {
    setup(_badgeProps, context) {
      return () => Vue.h('span', {}, context.slots.default?.())
    }
  })
  app.component('UForm', {
    emits: ['submit'],
    setup(_props, context) {
      return () => Vue.h('form', { ...context.attrs, onSubmit: () => context.emit('submit', {}) }, context.slots.default?.())
    }
  })
  app.component('UFormField', {
    setup(_fieldProps, context) {
      return () => Vue.h('label', {}, context.slots.default?.())
    }
  })
  app.component('UButton', {
    props: ['disabled', 'loading', 'type'],
    setup(buttonProps, context) {
      return () => Vue.h('button', {
        ...context.attrs,
        disabled: buttonProps.disabled,
        type: buttonProps.type ?? 'button',
        onClick: context.attrs.onClick
      }, context.slots.default?.())
    }
  })
  app.component('UInput', {
    props: ['modelValue', 'type'],
    setup(inputProps, context) {
      return () => Vue.h('input', { ...context.attrs, type: inputProps.type ?? 'text', value: inputProps.modelValue, onInput: (event: Event) => context.emit('update:modelValue', (event.target as HTMLInputElement).value) })
    }
  })
  app.component('UTextarea', {
    props: ['modelValue'],
    setup(inputProps, context) {
      return () => Vue.h('textarea', { ...context.attrs, value: inputProps.modelValue, onInput: (event: Event) => context.emit('update:modelValue', (event.target as HTMLTextAreaElement).value) })
    }
  })
  app.component('USelect', {
    props: ['modelValue', 'items'],
    setup(inputProps, context) {
      return () => Vue.h('select', { ...context.attrs, value: inputProps.modelValue, onChange: (event: Event) => context.emit('update:modelValue', (event.target as HTMLSelectElement).value) }, (inputProps.items as Array<{ label: string, value: string }>).map(item => Vue.h('option', { value: item.value }, item.label)))
    }
  })
  app.component('USwitch', {
    props: ['modelValue', 'label', 'disabled'],
    setup(inputProps, context) {
      return () => Vue.h('label', {}, [Vue.h('input', { ...context.attrs, 'type': 'checkbox', 'aria-label': inputProps.label ?? context.attrs['aria-label'], 'checked': inputProps.modelValue, 'disabled': inputProps.disabled, 'onChange': (event: Event) => context.emit('update:modelValue', (event.target as HTMLInputElement).checked) }), inputProps.label as string])
    }
  })
  const wrapper = app.mount(root)
  return { app, root, wrapper, events, nodes: () => allNodes(root), content: () => text(root) }
}

test('subscription modal shows server terms and emits a full revision amendment without a plan change', async () => {
  const page = component('components/office-erp/OfficeErpSubscriptionManagementModal.vue', {
    open: true,
    companyName: 'Mercado Central',
    companyId: 4,
    contract,
    subscriptionStatus: 'active',
    trial: { ends_at: '2026-11-30T23:59:59.000000Z', situation: 'trialing' },
    balance: { outstanding: '50.00', invoice_count: 2 },
    capabilities: { can_manage_lifecycle: true, can_amend_terms: true, can_cancel: true, can_suspend: true, can_resume: false },
    pending: false,
    error: null,
    recoveryKey: null
  })

  assert.match(page.content(), /125,50/)
  assert.match(page.content(), /130,00/)
  assert.match(page.content(), /Alteração agendada para o próximo ciclo/)
  assert.match(page.content(), /30 de nov\. de 2026/)
  assert.match(page.content(), /Em período de teste/)
  assert.equal(page.content().includes('trialing'), false)
  const price = page.nodes().find(node => node.tag === 'input' && node.props['aria-label'] === 'Valor por período')
  assert.ok(price, 'editable price reflects the pending terms snapshot')
  ;(price!.props.onInput as (event: Event) => void)({ target: { value: '135,75' } } as unknown as Event)
  const submit = page.nodes().find(node => node.tag === 'button' && text(node).includes('Agendar alteração'))
  assert.ok(submit)
  ;(page.nodes().find(node => node.tag === 'form' && node.props.id === submit!.props.form)!.props.onSubmit as () => void)()
  await Vue.nextTick()
  assert.deepEqual(page.events.at(-1), {
    name: 'command',
    payload: {
      contractId: 17,
      companyId: 4,
      action: 'contract.amend',
      body: {
        price: '135.75',
        interval_months: 3,
        due_in_days: 15,
        module_keys: ['cte', 'expenses', 'purchases'],
        expected_pending_revision_id: 52
      }
    }
  })
  assert.equal(JSON.stringify(page.events).includes('erp_plan_id'), false)
  page.app.unmount()
})

test('subscription modal requires a cancellation reason and hides lifecycle commands without server authority', async () => {
  const page = component('components/office-erp/OfficeErpSubscriptionManagementModal.vue', {
    open: true,
    companyName: 'Mercado Central',
    companyId: 4,
    contract: { ...contract, pending_revision: null },
    subscriptionStatus: 'active',
    trial: null,
    balance: null,
    capabilities: { can_manage_lifecycle: false, can_amend_terms: false, can_cancel: false, can_suspend: false, can_resume: false },
    pending: false,
    error: null,
    recoveryKey: null
  })
  assert.equal(page.nodes().some(node => node.tag === 'button' && /Cancelar assinatura/.test(text(node))), false)
  assert.equal(page.nodes().some(node => node.tag === 'button' && /Suspender assinatura/.test(text(node))), false)
  assert.equal(page.events.length, 0)
  page.app.unmount()
})

test('subscription cancellation emits only after a required reason and binds to the displayed contract', async () => {
  const page = component('components/office-erp/OfficeErpSubscriptionManagementModal.vue', {
    open: true,
    companyName: 'Mercado Central',
    companyId: 4,
    planName: 'Contábil Essencial',
    contract: { ...contract, pending_revision: null },
    subscriptionStatus: 'active',
    trial: null,
    balance: null,
    capabilities: { can_manage_lifecycle: true, can_amend_terms: true, can_cancel: true, can_suspend: true, can_resume: false },
    pending: false,
    error: null,
    recoveryKey: null
  })
  const cancel = page.nodes().find(node => node.tag === 'button' && text(node).includes('Cancelar assinatura'))
  assert.ok(cancel)
  ;(cancel!.props.onClick as () => void)()
  await Vue.nextTick()
  const confirm = page.nodes().find(node => node.tag === 'button' && text(node).includes('Confirmar cancelamento'))
  assert.ok(confirm)
  ;(page.nodes().find(node => node.tag === 'form' && node.props.id === confirm!.props.form)!.props.onSubmit as () => void)()
  await Vue.nextTick()
  assert.equal(page.events.some(event => event.name === 'command'), false)
  assert.match(page.content(), /Informe o motivo para continuar/)
  const reason = page.nodes().find(node => node.tag === 'textarea' && node.props['aria-label'] === 'Motivo da alteração')
  assert.ok(reason)
  ;(reason!.props.onInput as (event: Event) => void)({ target: { value: 'Encerramento solicitado pelo escritório' } } as unknown as Event)
  await Vue.nextTick()
  ;(page.nodes().find(node => node.tag === 'form' && node.props.id === 'office-erp-subscription-action')!.props.onSubmit as () => void)()
  assert.deepEqual(page.events.at(-1), {
    name: 'command',
    payload: { contractId: 17, companyId: 4, action: 'contract.cancel', body: { mode: 'period_end', reason: 'Encerramento solicitado pelo escritório' } }
  })
  page.app.unmount()
})

test('module modal keeps current and pending snapshots distinct and submits the next-cycle revision', async () => {
  const page = component('components/office-erp/OfficeErpCompanyModulesModal.vue', {
    open: true,
    companyName: 'Mercado Central',
    companyId: 4,
    planName: 'Contábil Essencial',
    contractId: 17,
    currentRevision,
    pendingRevision,
    availableModuleKeys: ['purchases', 'expenses', 'cte', 'mdfe'],
    canAmend: true,
    pending: false,
    error: null,
    recoveryKey: null
  })
  assert.match(page.content(), /vigentes/)
  assert.match(page.content(), /próximo ciclo/)
  assert.match(page.content(), /Transferências de estoque/)
  const mdfe = page.nodes().find(node => node.tag === 'input' && node.props.type === 'checkbox' && node.props['aria-label'] === 'MDF-e')
  assert.ok(mdfe, 'an allowed module can be added to the scheduled set')
  ;(mdfe!.props.onChange as (event: Event) => void)({ target: { checked: true } } as unknown as Event)
  const submit = page.nodes().find(node => node.tag === 'button' && text(node).includes('Agendar alterações'))
  assert.ok(submit)
  ;(page.nodes().find(node => node.tag === 'form' && node.props.id === submit!.props.form)!.props.onSubmit as () => void)()
  assert.deepEqual(page.events.at(-1), {
    name: 'submit',
    payload: {
      contractId: 17,
      companyId: 4,
      terms: {
        price: '130.00',
        interval_months: 3,
        due_in_days: 15,
        module_keys: ['cte', 'expenses', 'mdfe', 'purchases'],
        expected_pending_revision_id: 52
      }
    }
  })
  page.app.unmount()
})

test('module modal does not grant new modules that are outside the office allowance', () => {
  const page = component('components/office-erp/OfficeErpCompanyModulesModal.vue', {
    open: true,
    companyName: 'Mercado Central',
    companyId: 4,
    planName: 'Contábil Essencial',
    contractId: 17,
    currentRevision,
    pendingRevision: null,
    availableModuleKeys: ['purchases', 'expenses'],
    canAmend: true,
    pending: false,
    error: null,
    recoveryKey: null
  })
  const cte = page.nodes().find(node => node.tag === 'input' && node.props.type === 'checkbox' && node.props['aria-label'] === 'CT-e')
  assert.ok(cte)
  assert.equal(cte!.props.disabled, true)
  page.app.unmount()
})

test('retained unknown outcomes can be closed and recovered without resubmitting', async () => {
  const page = component('components/office-erp/OfficeErpSubscriptionManagementModal.vue', {
    open: true,
    companyName: 'Mercado Central',
    companyId: 4,
    contract,
    subscriptionStatus: 'active',
    trial: null,
    balance: null,
    capabilities: { can_manage_lifecycle: true, can_amend_terms: true, can_cancel: true, can_suspend: true, can_resume: false },
    pending: false,
    error: null,
    recoveryKey: 'bba1048f-9cec-44a2-a8b9-22d7f765aa78'
  })
  const verify = page.nodes().find(node => node.tag === 'button' && text(node).includes('Verificar resultado'))
  assert.ok(verify)
  ;(verify!.props.onClick as () => void)()
  assert.deepEqual(page.events.at(-1), { name: 'recover', payload: 'bba1048f-9cec-44a2-a8b9-22d7f765aa78' })
  const close = page.nodes().find(node => node.tag === 'button' && text(node).includes('Fechar'))
  assert.ok(close)
  assert.equal(close!.props.disabled, false)
  ;(close!.props.onClick as () => void)()
  assert.deepEqual(page.events.at(-1), { name: 'update:open', payload: false })
  assert.equal(page.events.some(event => event.name === 'command'), false)
  page.app.unmount()
})
