import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent, mountVueComponent, type VueHostNode } from './helpers/compileVueComponent.ts'

const profile = {
  name: 'Empresa da Carteira LTDA', trade_name: 'Loja da Carteira', tax_regime: 'simple_national', ie: 'ISENTO',
  contact_person: 'Contato comercial autorizado', email: 'contato@empresa.test', phone: '11988887777', whatsapp: '11999996666',
  street_type: 'Rua', street: 'das Empresas', address_number: '123', address_complement: 'Sala 4', district: 'Centro', postal_code: '01001000', city: 'São Paulo', state: 'SP'
}
const company = { id: 41, registration_id: 61, client_id: 71, name: 'Loja da Carteira', cnpj: '11222333000181', registered_at: '2026-10-01T12:00:00Z', owner_setup_status: 'claimed', current_contract_id: 17, capabilities: { relationship: 'current', can_edit_profile: true, can_assign: false, can_manage_members: true, capability_key: 'company-1' }, profile, profile_version: 'a'.repeat(64) }
const revision = { id: 9, revision_number: 2, price: '9007199254740993.01', currency: 'BRL', interval_months: 3, due_in_days: 10, module_keys: ['purchases', 'cte'], service_period_start: '2026-10-02', service_period_end: '2027-01-01' }
const detail = { company: { id: 41, name: company.name }, relationship: 'current', subscription: { id: 18, status: 'active', erp_plan_id: 19, erp_plan: { id: 19, name: 'Plano Real da Empresa' } }, current_contract: { id: 17, agreement_status: 'effective', current_revision: revision, pending_revision: null, billing_timezone: 'America/Manaus', service_anchor_on: '2026-10-02', next_billing_on: '2027-01-01', cancel_effective_on: null, manual_hold: false, invoice_ids: [], balance: { outstanding: '12.34', invoice_count: 1 } }, trial: null, allocation: { id: 1, state: 'occupied', expires_at: null, released_at: null }, balance: { outstanding: '12.34', invoice_count: 1 }, action_capabilities: { can_manage_lifecycle: true, can_amend_terms: true, can_cancel: true, can_suspend: true, can_resume: false } }
const text = (node: VueHostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`
async function settle() {
  await Vue.nextTick()
  await new Promise<void>(resolve => setImmediate(resolve))
  await Vue.nextTick()
}
function mountOverview(query: Record<string, string> = { secao: 'company' }, sourceCompany = structuredClone(company), sourceDetail: unknown = structuredClone(detail)) {
  const route = Vue.reactive({ path: '/gestao-erp/assinaturas/empresas/41', params: { companyId: '41' }, query })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const user = Vue.ref({ id: 1, accounts: [{ id: 8, status: 'active', role: 'admin' }] })
  const capabilities = Vue.ref({ can_read: true, can_maintain: true, can_register_company: true, capability_key: 'office-1', allowance: { module_keys: ['purchases', 'cte'] } })
  const calls: string[] = []
  const writes: unknown[][] = []
  let finishCompany: ((value: unknown) => void) | undefined
  let delayCompany = false
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: `${account.value.id}:${capabilities.value.capability_key}` }), isCurrent: (token: { key: string }) => token.key === `${account.value.id}:${capabilities.value.capability_key}` }
  const api = async (path: string) => {
    calls.push(path)
    if (path === '/erp-commercial/companies/41') {
      if (delayCompany) return new Promise((resolve) => {
        finishCompany = resolve
      })
      return { data: sourceCompany }
    }
    if (path === '/erp-commercial/companies/41/subscription') return { data: sourceDetail }
    if (path === '/erp-commercial/companies/41/contracts') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    if (path === '/erp-commercial/companies/41/members') return { data: [{ kind: 'membership', id: 22, user: { id: 3, name: 'Pessoa autorizada', email: 'membro@empresa.test' }, role: 'operator', status: 'active', establishment_scope: 'all', establishment_ids: [] }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
    if (path === '/erp-commercial/companies/41/member-options') return { data: { roles: [], permissions: [], establishments: [] } }
    throw new Error(`Unapproved Company overview read: ${path}`)
  }
  const bindings = {
    onMounted: Vue.onMounted, onBeforeUnmount: Vue.onBeforeUnmount,
    useRoute: () => route, useRouter: () => ({ replace: async (value: { query: Record<string, string> }) => { route.query = value.query } }), definePageMeta() {},
    useAuth: () => ({ user, currentAccount: account }), useState: (_key: string, init: () => unknown) => Vue.ref(init()),
    useOfficeErpContext: () => context, useAccessApi: () => ({ api }),
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false), recoveryKey: Vue.ref(null),
      execute: async (...args: unknown[]) => {
        writes.push(args)
        return { status: 'confirmed', data: { ...sourceCompany, name: 'Empresa Atualizada', profile: { ...sourceCompany.profile, name: 'Empresa Atualizada LTDA' }, profile_version: 'b'.repeat(64) } }
      }
    })
  }
  const modal = { props: ['open'], setup: (props: Record<string, unknown>, { slots }: Vue.SetupContext) => () => props.open ? Vue.h('dialog', {}, [slots.body?.(), slots.footer?.()]) : null }
  const card = { props: ['title', 'description'], setup: (props: Record<string, unknown>, { slots }: Vue.SetupContext) => () => Vue.h('section', {}, [slots.title?.() ?? props.title, props.description, slots.header?.(), slots.default?.(), slots.footer?.()]) }
  const pass = { setup: (_props: unknown, { slots }: Vue.SetupContext) => () => Vue.h('div', {}, slots.default?.()) }
  const components: Record<string, Vue.Component> = {
    UButton: { props: ['to', 'disabled', 'type'], setup: (props, { attrs, slots }) => () => Vue.h(props.to ? 'a' : 'button', { ...attrs, href: props.to, disabled: props.disabled, type: props.type }, slots.default?.()) },
    UModal: modal, UCard: card, UPageCard: card, UDashboardToolbar: pass, UIcon: pass, UAvatar: pass, UDropdownMenu: pass,
    UBreadcrumb: pass, UPopover: pass, UTooltip: pass,
    NuxtLink: { props: ['to'], setup: (props, { slots }) => () => Vue.h('a', { href: typeof props.to === 'string' ? props.to : `${props.to.path}?${new URLSearchParams(props.to.query)}` }, slots.default?.()) },
    UNavigationMenu: { props: ['items'], setup: props => () => Vue.h('nav', { 'data-items': props.items }, (props.items as Array<{ label: string, to?: unknown, onSelect?: () => void }>).map(item => Vue.h('button', { 'data-label': item.label, 'data-to': item.to, 'onClick': item.onSelect }, item.label))) },
    OfficeErpDataTable: { props: ['data'], setup: (props, { slots }) => () => Vue.h('table', (props.data as unknown[]).map(member => Vue.h('tr', slots['user-cell']?.({ row: { original: member } })))) }
  }
  components.OfficeErpCompanyInspectionModal = compileVueComponent('components/office-erp/OfficeErpCompanyInspectionModal.vue', bindings)
  components.OfficeErpCompanyMembers = compileVueComponent('components/office-erp/OfficeErpCompanyMembers.vue', bindings)
  const mounted = mountVueComponent(compileVueComponent('pages/gestao-erp/assinaturas/empresas/[companyId].vue', bindings), components)
  return {
    ...mounted, route, account, capabilities, calls, writes,
    delayNextCompany() { delayCompany = true },
    completeCompany(value: unknown) { finishCompany?.(value) }
  }
}

test('Company detail provides a clear list return and separates registration from related services', async (t) => {
  const view = mountOverview()
  t.after(view.unmount)
  await settle()
  const back = view.nodes(node => node.tag === 'a' && node.props.href === '/gestao-erp/assinaturas' && text(node).includes('Voltar para assinaturas'))[0]
  assert.ok(back, 'return is explicit even when the page was opened directly')
  assert.equal(view.nodes(node => node.tag === 'a' && node.props.href === '/gestao-erp/assinaturas' && text(node).includes('Voltar para assinaturas')).length, 1, 'return is not repeated in a decorative footer')
  const heading = view.nodes(node => node.tag === 'h1')[0]
  assert.ok(heading)
  assert.equal(text(heading).trim(), 'Loja da Carteira')
  assert.ok(view.nodes(node => node.props['aria-label'] === 'Dados cadastrais').length)
  assert.ok(view.nodes(node => node.props['aria-label'] === 'Contato comercial').length)
  assert.ok(view.nodes(node => node.props['aria-label'] === 'Assinatura e cobrança').length)
  const nav = view.nodes(node => node.tag === 'nav')[0]!
  assert.deepEqual((nav.props['data-items'] as Array<{ label: string }>).map(item => item.label), ['Empresa', 'Assinatura', 'Usuários e acessos', 'Faturas', 'Histórico'])
})

test('Company overview query renders authorized registration, business contact, address and exact billing terms', async (t) => {
  const view = mountOverview()
  t.after(view.unmount)
  await settle()
  assert.ok(view.nodes(node => node.props['aria-labelledby'] === 'company-overview-title')[0], 'the explicit Company section must not fall back to subscription')
  for (const value of ['Empresa da Carteira LTDA', 'Loja da Carteira', 'Simples Nacional', 'ISENTO', 'Contato comercial autorizado', 'contato@empresa.test', '11988887777', '11999996666', 'das Empresas', '123', 'Sala 4', 'Centro', '01001000', 'São Paulo', 'SP', 'Plano Real da Empresa', '9.007.199.254.740.993,01', 'America/Manaus', 'BRL', 'CT-e']) assert.ok(view.text().includes(value), `missing authorized field ${value}`)
  assert.match(view.text(), /Moeda da cobrança/)
  assert.match(view.text(), /Fuso da cobrança/)
  assert.match(view.text(), /1 de out\. de 2026/)
  assert.match(view.text(), /2 de out\. de 2026/)
  assert.match(view.text(), /1 de jan\. de 2027/)
  assert.doesNotMatch(view.text(), /Proprietário|Criado por|Vendas em PDV|Fornecedores|Moeda operacional|Fuso operacional/)
  assert.equal(view.calls.filter(path => path === '/erp-commercial/companies/41').length, 1)
  assert.equal(view.calls.filter(path => path.endsWith('/subscription')).length, 1)
  assert.equal(view.calls.some(path => /\/members|\/member-options|\/erp\/|locations|establishments/.test(path)), false)
  const nav = view.nodes(node => node.tag === 'nav')[0]!
  assert.ok((nav.props['data-items'] as Array<{ label: string, to?: unknown }>).find(item => item.label === 'Empresa' && item.to))
})

test('Company identity, legacy profile bookmark and default subscription remain distinct reachable sections', async (t) => {
  const view = mountOverview({ secao: 'profile', origem: 'carteira' })
  t.after(view.unmount)
  await settle()
  assert.ok(view.nodes(node => node.props['aria-labelledby'] === 'company-overview-title')[0])
  assert.equal(view.nodes(node => node.tag === 'form').length, 0, 'the profile bookmark no longer exposes a competing editor')
  assert.equal(text(view.nodes(node => node.tag === 'h1')[0]!).trim(), 'Loja da Carteira')
  const companyTab = (view.nodes(node => node.tag === 'nav')[0]!.props['data-items'] as Array<{ label: string, to: { query: Record<string, string> } }>).find(item => item.label === 'Empresa')!
  assert.equal(companyTab.to.query.secao, 'company')
  assert.equal(companyTab.to.query.origem, 'carteira')
  view.route.query = {}
  await settle()
  assert.ok(view.nodes(node => node.props['aria-labelledby'] === 'company-overview-title').length === 0)
  assert.match(view.text(), /Assinatura atual/)
  view.route.query = { secao: 'members' }
  await settle()
  assert.match(view.text(), /Pessoa autorizada/)
  assert.equal(view.calls.filter(path => path.endsWith('/members')).length, 1)
})

for (const relationship of ['unassigned', 'historical']) {
  test(`Company overview ${relationship} preserves redaction and avoids invented current subscription or members`, async (t) => {
    const record = { ...structuredClone(company), current_contract_id: null, capabilities: { ...company.capabilities, relationship, can_edit_profile: false, can_manage_members: false }, profile: { ...profile, ie: null } }
    const projection = { company: { id: 41, name: company.name }, relationship, subscription: null, current_contract: null, trial: null, allocation: null, balance: { outstanding: '0.00', invoice_count: 0 }, action_capabilities: {} }
    const view = mountOverview({ secao: 'company' }, record, projection)
    t.after(view.unmount)
    await settle()
    assert.ok(view.nodes(node => node.props['aria-labelledby'] === 'company-overview-title')[0])
    assert.match(view.text(), relationship === 'historical' ? /Histórico comercial/ : /Sem assinatura/)
    assert.doesNotMatch(view.text(), /ISENTO|Plano Real|R\$ 0,00|0 usuários|Proprietário/)
    assert.equal(view.calls.some(path => /members|member-options|\/erp\//.test(path)), false)
    view.route.query = { secao: 'members' }
    await settle()
    assert.equal(view.calls.some(path => /members|member-options/.test(path)), false)
  })
}

test('Company overview labels a retained canceled contract as history rather than a current operating period', async (t) => {
  const record = { ...structuredClone(company), current_contract_id: null, capabilities: { ...company.capabilities, relationship: 'historical', can_edit_profile: false, can_manage_members: false }, profile: { ...profile, ie: null } }
  const canceled = { ...structuredClone(detail), relationship: 'historical', subscription: { ...detail.subscription, status: 'canceled' }, current_contract: { ...detail.current_contract, agreement_status: 'canceled' }, action_capabilities: {} }
  const view = mountOverview({ secao: 'company' }, record, canceled)
  t.after(view.unmount)
  await settle()
  assert.match(view.text(), /Cancelada/)
  assert.match(view.text(), /Plano Real da Empresa/)
  assert.match(view.text(), /Fim do período/)
  assert.doesNotMatch(view.text(), /período vigente|Módulos vigentes|ISENTO/)
  assert.equal(view.calls.some(path => /members|member-options|\/erp\//.test(path)), false)
})

test('Company overview clears data on office change and rejects late foreign or stale reads', async (t) => {
  const view = mountOverview()
  t.after(view.unmount)
  await settle()
  assert.match(view.text(), /contato@empresa.test/)
  view.delayNextCompany()
  view.account.value = { id: 9, status: 'active', role: 'admin' }
  await settle()
  assert.doesNotMatch(view.text(), /contato@empresa.test|Plano Real da Empresa/)
  view.capabilities.value = { ...view.capabilities.value, capability_key: 'office-new' }
  view.completeCompany({ data: { ...company, id: 999, name: 'Empresa estrangeira' } })
  await settle()
  assert.doesNotMatch(view.text(), /Empresa estrangeira|contato@empresa.test/)
  assert.equal(view.nodes(node => node.props['aria-labelledby'] === 'company-overview-title').length, 0)
})

test('Company overview preserves authorization denial without requesting profile or operational data', async (t) => {
  const view = mountOverview()
  t.after(view.unmount)
  await settle()
  view.capabilities.value = { ...view.capabilities.value, can_read: false, capability_key: 'office-denied' }
  const before = view.calls.length
  await settle()
  assert.doesNotMatch(view.text(), /contato@empresa.test|Plano Real da Empresa/)
  assert.equal(view.calls.length, before)
  assert.match(view.text(), /não tem permissão/)
})

test('Company overview reuses the trusted profile read in the bounded editor and applies its confirmed snapshot', async (t) => {
  const view = mountOverview()
  t.after(view.unmount)
  await settle()
  const edit = view.nodes(node => node.tag === 'button' && text(node).includes('Editar cadastro'))[0]
  assert.ok(edit)
  ;(edit.props.onClick as () => void)()
  await settle()
  assert.equal(view.calls.filter(path => path === '/erp-commercial/companies/41').length, 1, 'opening the shared modal must not fetch the same loaded entity again')
  const form = view.nodes(node => node.tag === 'form' && node.props.id === 'company-profile')[0]!
  assert.ok(form)
  const unlock = view.nodes(node => node.tag === 'button' && text(node).includes('Editar cadastro')).at(-1)!
  ;(unlock.props.onClick as () => void)()
  await settle()
  const name = view.nodes(node => node.tag === 'input' && node.props.maxlength === 255)[0]!
  ;(name.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Empresa Atualizada LTDA' } })
  ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
  await settle()
  assert.deepEqual(view.writes[0]?.slice(0, 4), ['company.profile.update', '/erp-commercial/companies/41', { expected_profile_version: 'a'.repeat(64), name: 'Empresa Atualizada LTDA' }, 'PATCH'])
  assert.equal(view.calls.filter(path => path === '/erp-commercial/companies/41').length, 1)
  assert.match(view.text(), /Empresa Atualizada LTDA/)
})
