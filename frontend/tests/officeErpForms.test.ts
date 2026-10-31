import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent, mountVueComponent, type VueHostNode } from './helpers/compileVueComponent.ts'

const noop = () => {}
const profile = { name: 'Empresa de Exemplo LTDA', trade_name: null, tax_regime: 'other', contact_person: null, email: null, phone: null, whatsapp: null, street_type: null, street: null, address_number: null, address_complement: null, district: null, postal_code: null, city: null, state: null, ie: null }
const company = { id: 41, name: profile.name, cnpj: '11222333000181', registered_at: '2026-10-01', profile, profile_version: 'a'.repeat(64), owner_setup_status: 'unclaimed', current_contract_id: 17, capabilities: { can_edit_profile: true, can_assign: true, can_manage_members: true, relationship: 'current' } }
const revision = { id: 9, price: '125.00', interval_months: 1, due_in_days: 10, module_keys: ['purchases'] }
const contract = { id: 17, agreement_status: 'effective', current_revision: revision, pending_revision: null, manual_hold: false, cancel_effective_on: null }
const capabilities = Vue.ref({ can_read: true, can_maintain: true, can_configure: true, can_sell_new: true, can_manage_trial_link: true, capability_key: 'forms', allowance: { module_keys: ['purchases'] } })
const route = Vue.reactive({ path: '/gestao-erp/assinaturas/empresas/41', params: { companyId: '41', id: '17' }, query: {} as Record<string, string> })
const bindings = {
  onMounted: Vue.onMounted, onBeforeUnmount: Vue.onBeforeUnmount,
  useRoute: () => route, useRouter: () => ({ replace: noop }), definePageMeta: noop, useHead: noop, useToast: () => ({ add: noop }),
  useAuth: () => ({ user: Vue.ref({ id: 1 }), currentAccount: Vue.ref({ id: 8, status: 'active', role: 'admin' }) }), useState: (_key: string, init: () => unknown) => Vue.ref(init()),
  useOfficeErpContext: () => ({ capabilities, refresh: async () => {}, capture: () => ({}), isCurrent: () => true }),
  useOfficeErpCommand: () => ({ pending: Vue.ref(false), recoveryKey: Vue.ref(null), execute: async () => ({ status: 'failed', failure: { message: 'Revise os dados.', errors: { price: ['Preço rejeitado.'] } } }) }),
  useAccessApi: () => ({ api: async (path: string) => {
    if (path.endsWith('/enrollment-plans')) return { data: [{ id: 1, name: 'Plano', module_keys: ['purchases'] }], eligibility: { can_provision: true } }
    if (path.endsWith('/settings')) return { data: { mode: 'manual', grace_days: 0, billing_timezone: 'America/Sao_Paulo' } }
    if (path.endsWith('/trial-link')) return { data: { configured: true, enabled: true, revision: 1, url: null, erp_plan_id: 1, plan: null, plans: [{ id: 1, name: 'Plano', module_keys: [] }] } }
    if (path.endsWith('/member-options')) return { data: { roles: [{ role: 'operator', default_permissions: [] }], permissions: [], establishments: [] } }
    if (path.endsWith('/members')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    if (path.endsWith('/subscription')) return { data: { company: { id: 41, name: company.name }, current_contract: contract, subscription: null, action_capabilities: {}, balance: null, trial: null } }
    if (path.includes('/contracts/')) return { data: { ...contract, client: { name: 'Cliente' }, company: { id: 41, name: company.name }, trial: null, revisions: [], balance: { outstanding: '0.00', invoice_count: 0 } } }
    if (path.includes('/invoices/')) return { data: { id: 17, contract_id: 17, document_status: 'draft', items_snapshot: [{ description: 'Serviço', amount: '10.00', discount: '0.00' }], period_start: '2026-10-01', period_end: '2026-10-31', due_on: '2026-11-01', seller_snapshot: {}, bill_to_snapshot: {}, adjustments: [], balance: { original_total: '10.00', adjusted_charge: '10.00', effective_received: '0.00', outstanding: '10.00' } } }
    if (path.endsWith('/contracts') || path.endsWith('/clients')) return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    return { data: company }
  } })
}
const card = { setup: (_props: unknown, { slots }: Vue.SetupContext) => () => Vue.h('section', [slots.header?.(), slots.default?.(), slots.footer?.()]) }
const modal = { props: ['open'], setup: (props: Record<string, unknown>, { slots }: Vue.SetupContext) => () => props.open ? Vue.h('section', {}, [Vue.h('div', { 'data-body': true }, slots.body?.()), Vue.h('footer', slots.footer?.())]) : null }
const text = (node: VueHostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`
async function settle() {
  await Vue.nextTick()
  await new Promise<void>(resolve => setImmediate(resolve))
  await Vue.nextTick()
}

for (const scenario of [
  { path: 'OfficeErpPlanEditor', props: { open: true, canSellNew: true, moduleKeys: ['purchases'] }, fields: ['name', 'description', 'price', 'interval_months', 'due_in_days', 'module_keys', 'published'] },
  { path: 'OfficeErpCompanyAssignmentModal', props: { open: true, companyId: 41, companyName: 'Empresa', canAssign: true }, fields: ['erp_plan_id', 'price', 'interval_months', 'due_in_days', 'start_on', 'billing_timezone'] },
  { path: 'OfficeErpSubscriptionManagementModal', props: { open: true, companyName: 'Empresa', companyId: 41, contract, subscriptionStatus: 'active', trial: null, balance: null, capabilities: { can_amend_terms: true, can_manage_lifecycle: true, can_cancel: true, can_suspend: true, can_resume: false } }, fields: ['price', 'interval_months', 'due_in_days'] },
  { path: 'OfficeErpCompanyModulesModal', props: { open: true, companyName: 'Empresa', companyId: 41, contractId: 17, currentRevision: revision, pendingRevision: null, availableModuleKeys: ['purchases'], canAmend: true }, fields: ['module_keys'] },
  { path: 'OfficeErpReceiptModal', props: { open: true, invoices: [] }, fields: ['allocations', 'received_on', 'reference', 'proof_id'] },
  { path: 'OfficeErpReversalModal', props: { open: true }, fields: ['reason'] },
  { path: 'OfficeErpCompanyPortfolioModal', props: { open: true }, fields: ['client_ids'] }
]) {
  test(`${scenario.path} submits its named form from the native modal footer`, async (t) => {
    const mounted = mountVueComponent(compileVueComponent(`components/office-erp/${scenario.path}.vue`, bindings), { UModal: modal, USwitch: { setup: () => () => Vue.h('button') } }, scenario.props)
    t.after(mounted.unmount)
    await settle()
    const form = mounted.nodes(node => node.tag === 'form')[0]
    assert.ok(form, 'the editor must render UForm rather than click-only controls')
    assert.ok(form.props.id, 'a footer submission needs a stable form id')
    assert.equal(typeof form.props.onError, 'function', 'native validation errors use the shared focus handler')
    for (const name of scenario.fields) assert.ok(mounted.nodes(node => node.props['data-field'] === name)[0], `missing named field ${name}`)
    const submit = mounted.nodes(node => node.tag === 'button' && node.props.type === 'submit')[0]
    assert.ok(submit)
    assert.equal(submit.props.form, form.props.id)
    assert.equal(submit.parent?.tag === 'footer' || submit.parent?.parent?.tag === 'footer', true)
  })
}

test('assignment reports an invalid monetary draft beside price without sending', async (t) => {
  const mounted = mountVueComponent(compileVueComponent('components/office-erp/OfficeErpCompanyAssignmentModal.vue', bindings), { UModal: modal }, { open: true, companyId: 41, companyName: 'Empresa', canAssign: true })
  t.after(mounted.unmount)
  await settle()
  const form = mounted.nodes(node => node.tag === 'form')[0]
  assert.ok(form)
  ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault: noop })
  await settle()
  const price = mounted.nodes(node => node.props['data-field'] === 'price')[0]
  assert.ok(price)
  assert.match(text(price), /preço|reais/i)
})

for (const scenario of [
  { path: 'pages/gestao-erp/assinaturas/empresas/[companyId].vue', section: 'subscription', fields: ['owner.full_name', 'owner.email'] },
  { path: 'pages/gestao-erp/assinaturas/[id].vue', section: '', fields: ['price', 'interval_months', 'due_in_days', 'module_keys'] },
  { path: 'pages/gestao-erp/faturas/[id]/index.vue', section: '', fields: ['period_start', 'period_end', 'due_on', 'items.0.description', 'items.0.amount', 'items.0.discount'] }
]) {
  test(`${scenario.path} ${scenario.section} names its keyboard-submittable fields`, async (t) => {
    route.query = { secao: scenario.section }
    const mounted = mountVueComponent(compileVueComponent(scenario.path, bindings), { UModal: modal, UCard: card })
    t.after(mounted.unmount)
    await settle()
    assert.ok(mounted.nodes(node => node.tag === 'form')[0], 'the operating page uses UForm')
    for (const name of scenario.fields) assert.ok(mounted.nodes(node => node.props['data-field'] === name)[0], name)
    assert.ok(mounted.nodes(node => node.tag === 'button' && node.props.type === 'submit')[0])
  })
}

test('member editor submits named fields and preserves field errors in its overlay', async (t) => {
  route.query = {}
  const mounted = mountVueComponent(compileVueComponent('components/office-erp/OfficeErpCompanyMembers.vue', bindings), { UModal: modal, UCard: card })
  t.after(mounted.unmount)
  await settle()
  const invite = mounted.nodes(node => node.tag === 'button' && text(node).includes('Convidar usuário'))[0]!
  ;(invite.props.onClick as () => void)()
  await settle()
  const form = mounted.nodes(node => node.tag === 'form')[0]
  assert.ok(form)
  for (const name of ['name', 'email', 'role', 'permissions_mode', 'establishment_scope']) assert.ok(mounted.nodes(node => node.props['data-field'] === name)[0], name)
  ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault: noop })
  await settle()
  assert.match(text(mounted.nodes(node => node.props['data-field'] === 'name')[0]!), /nome/i)
})

test('confirmed Company profile recovery restores read mode in the same mounted form', async (t) => {
  const recoveryKey = Vue.ref<string | null>(null)
  const pending = Vue.ref(false)
  const command = {
    recoveryKey, pending,
    async execute() {
      recoveryKey.value = 'profile-key'
      pending.value = true
      return { status: 'unknown', key: 'profile-key' }
    },
    async recover() {
      recoveryKey.value = null
      pending.value = false
      return { status: 'confirmed', data: { ...company, profile: { ...profile, name: 'Empresa Atualizada LTDA' }, profile_version: 'b'.repeat(64) } }
    }
  }
  const mounted = mountVueComponent(compileVueComponent('components/office-erp/OfficeErpCompanyInspectionModal.vue', { ...bindings, useOfficeErpCommand: () => command }), { UModal: modal }, { open: true, companyId: 41 })
  t.after(mounted.unmount)
  await settle()
  const form = mounted.nodes(node => node.tag === 'form')[0]!
  const name = mounted.nodes(node => node.tag === 'input' && node.props.maxlength === 255)[0]!
  ;(mounted.nodes(node => node.tag === 'button' && text(node).includes('Editar cadastro'))[0]!.props.onClick as () => void)()
  await settle()
  ;(name.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Empresa Atualizada LTDA' } })
  ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault: noop })
  await settle()
  ;(mounted.nodes(node => node.tag === 'button' && text(node).includes('Recuperar operação'))[0]!.props.onClick as () => void)()
  await settle()
  assert.equal(mounted.nodes(node => node.tag === 'form')[0], form)
  assert.equal(name.props.value, 'Empresa Atualizada LTDA')
  assert.equal(name.props.readonly, true, 'a confirmed recovery finishes editing like a confirmed save')
})

test('subscription clears a corrected local error before emitting valid terms', async (t) => {
  const emitted: unknown[] = []
  const mounted = mountVueComponent(compileVueComponent('components/office-erp/OfficeErpSubscriptionManagementModal.vue', bindings), { UModal: modal }, {
    open: true, companyName: 'Empresa', companyId: 41, contract, subscriptionStatus: 'active', trial: null, balance: null,
    capabilities: { can_amend_terms: true, can_manage_lifecycle: true, can_cancel: true, can_suspend: true, can_resume: false }, onCommand: (intent: unknown) => emitted.push(intent)
  })
  t.after(mounted.unmount)
  const price = mounted.nodes(node => node.tag === 'input' && node.props['aria-label'] === 'Valor por período')[0]!
  const form = mounted.nodes(node => node.tag === 'form')[0]!
  const submit = () => (form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault: noop })
  ;(price.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'inválido' } })
  submit()
  await settle()
  assert.match(text(mounted.nodes(node => node.props['data-field'] === 'price')[0]!), /Informe o valor/)
  ;(price.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '150,00' } })
  submit()
  await settle()
  assert.equal(emitted.length, 1)
  assert.doesNotMatch(text(mounted.nodes(node => node.props['data-field'] === 'price')[0]!), /Informe o valor/)
})

test('member recovery failure stays inside the editor that initiated the operation', async (t) => {
  const recoveryKey = Vue.ref<string | null>(null)
  const pending = Vue.ref(false)
  const command = {
    recoveryKey, pending,
    async execute() {
      recoveryKey.value = 'member-key'
      pending.value = true
      return { status: 'unknown', key: 'member-key' }
    },
    async recover() {
      pending.value = false
      recoveryKey.value = null
      return { status: 'failed', failure: { message: 'Convite rejeitado ao recuperar.', errors: { email: ['Endereço rejeitado.'] } } }
    }
  }
  const mounted = mountVueComponent(compileVueComponent('components/office-erp/OfficeErpCompanyMembers.vue', { ...bindings, useOfficeErpCommand: () => command }), { UModal: modal })
  t.after(mounted.unmount)
  await settle()
  ;(mounted.nodes(node => node.tag === 'button' && text(node).includes('Convidar usuário'))[0]!.props.onClick as () => void)()
  await settle()
  for (const [name, value] of [['name', 'Pessoa convidada'], ['email', 'person@example.test']]) {
    const field = mounted.nodes(node => node.props['data-field'] === name)[0]!
    const input = field.children.flatMap(node => node.children.length ? node.children : [node]).find(node => node.tag === 'input')!
    ;(input.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: value! } })
  }
  const form = mounted.nodes(node => node.tag === 'form')[0]!
  ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault: noop })
  await settle()
  ;(mounted.nodes(node => node.tag === 'button' && text(node).includes('Recuperar operação')).at(-1)!.props.onClick as () => void)()
  await settle()
  assert.match(text(form), /Convite rejeitado ao recuperar/)
  assert.match(text(mounted.nodes(node => node.props['data-field'] === 'email')[0]!), /Endereço rejeitado/)
})

test('policy and trial-link settings each have independent keyboard forms', async (t) => {
  const mounted = mountVueComponent(compileVueComponent('pages/gestao-erp/configuracoes.vue', bindings), { UModal: modal, USwitch: { setup: () => () => Vue.h('button') }, UPopover: { setup: (_props, { slots }) => () => Vue.h('div', slots.default?.()) } })
  t.after(mounted.unmount)
  await settle()
  const forms = mounted.nodes(node => node.tag === 'form')
  assert.equal(forms.length, 2)
  assert.notEqual(forms[0]?.props.id, forms[1]?.props.id)
  for (const form of forms) assert.equal(typeof form.props.onError, 'function')
  for (const name of ['mode', 'grace_days', 'billing_timezone', 'erp_plan_id', 'enabled']) assert.ok(mounted.nodes(node => node.props['data-field'] === name)[0], name)
})
