import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { it } from 'node:test'
import * as Vue from 'vue'
import { compileVueComponent, evaluateVueBindings, mountVueComponent, vueSource } from './helpers/compileVueComponent.ts'
import { accessContinuationRouteCaptureVersion, captureAccessContinuationFromLocation, captureAccessContinuationFromRoute, parseAccessContinuationFragment, takeAccessContinuationFragment, takeInvalidAccessContinuationCapture } from '../app/utils/accessContinuationFragment.ts'
import { retryAfterSeconds } from '../shared/utils/access.ts'
import { canRegisterTypedCnpj } from '../app/utils/taxId.ts'
import { formatOfficeErpCompanyDate, officeErpContractAmendmentAction, officeErpContractStatusLabel, officeErpTrialStatusLabel } from '../app/utils/officeErpContractAmendment.ts'

const pagePaths = [
  'pages/gestao-erp/empresas/index.vue',
  'pages/gestao-erp/empresas/nova.vue',
  'components/office-erp/OfficeErpCompanyPortfolioModal.vue',
  'components/office-erp/OfficeErpCompanyInspectionModal.vue',
  'components/office-erp/OfficeErpCompanyAssignmentModal.vue',
  'pages/gestao-erp/assinaturas/index.vue',
  'pages/gestao-erp/assinaturas/contratos.vue',
  'pages/gestao-erp/assinaturas/[id].vue',
  'pages/gestao-erp/assinaturas/empresas/[companyId].vue',
  'pages/gestao-erp/assinaturas/empresas/[companyId]/membros.vue',
  'pages/gestao-erp/assinaturas/empresas/[companyId]/membros/[membershipId]/recuperar.vue',
  'pages/erp/convite.vue',
  'pages/redefinir-senha.vue'
]

it('compiles the real Company, membership, invitation and recovery SFCs', () => {
  for (const path of pagePaths)
    assert.ok(compileVueComponent(path), path)
  for (const path of [
    'components/office-erp/OfficeErpCompanyInspectionModal.vue',
    'components/office-erp/OfficeErpCompanyAssignmentModal.vue',
    'components/office-erp/OfficeErpCompanyPortfolioModal.vue',
    'pages/gestao-erp/assinaturas/empresas/[companyId]/membros.vue'
  ])
    assert.match(vueSource(path), /<template #footer>/, `${path} keeps its primary actions in the installed UModal footer slot`)
  for (const path of [
    'components/office-erp/OfficeErpCompanyPortfolioModal.vue',
    'components/office-erp/OfficeErpCompanyInspectionModal.vue',
    'components/office-erp/OfficeErpCompanyAssignmentModal.vue'
  ]) {
    assert.match(vueSource(path), /useModalFocusReturn/)
    assert.match(vueSource(path), /@after:leave="restoreModalFocus"/)
  }
  const members = vueSource('pages/gestao-erp/assinaturas/empresas/[companyId]/membros.vue')
  assert.match(members, /@after:leave="restoreEditorFocus"/)
  assert.match(members, /@after:leave="restoreDestructiveFocus"/)
})

it('uses the contract API amendment action for module set changes and formats billing dates in Brazil time', () => {
  assert.equal(officeErpContractAmendmentAction(['purchases'], ['purchases', 'stock_transfers']), 'modules.expand')
  assert.equal(officeErpContractAmendmentAction(['purchases', 'stock_transfers'], ['purchases']), 'contract.amend.modules.reduce')
  assert.equal(officeErpContractAmendmentAction(['purchases'], ['purchases']), 'contract.amend.price')
  assert.equal(officeErpContractAmendmentAction(['purchases', 'sales'], ['purchases', 'stock_transfers']), 'modules.expand', 'an added key uses the same action contract as the backend')
  assert.match(formatOfficeErpCompanyDate('2026-11-28'), /28/)
  assert.match(formatOfficeErpCompanyDate('2026-11-28T02:00:00Z'), /27/, 'timestamps are rendered in the billing office time zone')
  assert.equal(officeErpContractStatusLabel('accepted'), 'Aceita · aguardando ativação')
  assert.equal(officeErpContractStatusLabel('effective'), 'Vigente')
  assert.equal(officeErpContractStatusLabel('canceled'), 'Cancelada')
  assert.equal(officeErpContractStatusLabel('unknown'), 'Situação indisponível')
  assert.equal(officeErpTrialStatusLabel('trialing'), 'Em período de teste')
  assert.equal(officeErpTrialStatusLabel('expired'), 'Período de teste encerrado')
  assert.equal(officeErpTrialStatusLabel('converted'), 'Convertido em assinatura')
  assert.equal(officeErpTrialStatusLabel('unknown'), 'Situação indisponível')
  const companyDetail = vueSource('pages/gestao-erp/assinaturas/empresas/[companyId].vue')
  assert.match(companyDetail, /officeErpContractStatusLabel\(subscription\.current_contract\.agreement_status\)/)
  assert.match(companyDetail, /officeErpTrialStatusLabel\(subscription\.trial\.situation\)/)
})

it('renders Company contract and trial states in Portuguese without exposing API codes', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, capability_key: 'cap-1', allowance: { module_keys: [] } })
  const route = Vue.reactive({ path: '/gestao-erp/assinaturas/empresas/41', params: { companyId: '41' }, query: {} as Record<string, string> })
  const profile = { name: 'Empresa Teste LTDA', trade_name: null, tax_regime: 'other', contact_person: null, email: null, phone: null, whatsapp: null, street_type: null, street: null, address_number: null, address_complement: null, district: null, postal_code: null, city: null, state: null, ie: null }
  const company = { id: 41, registration_id: 61, client_id: 71, name: profile.name, cnpj: '123', registered_at: '2026-11-28', owner_setup_status: 'claimed', current_contract_id: 81, capabilities: { relationship: 'current', can_edit_profile: false, can_assign: false, can_manage_members: false, capability_key: 'cap-1' }, profile, profile_version: 'a'.repeat(64) }
  const api = async (path: string) => {
    if (path === '/erp-commercial/companies/41') return { data: company }
    if (path === '/erp-commercial/companies/41/subscription') return { data: { company: { id: 41, name: profile.name }, relationship: 'current', subscription: { id: 21, status: 'canceled', erp_plan_id: 71, erp_plan: { id: 71, name: 'Plano ERP' } }, current_contract: { id: 81, agreement_status: 'canceled', current_revision: null, pending_revision: null, service_anchor_on: null, next_billing_on: null, cancel_effective_on: null, manual_hold: false, invoice_ids: [] }, trial: { ends_at: '2026-11-28T12:00:00Z', situation: 'trialing' }, allocation: null, balance: { outstanding: '0.00', invoice_count: 0 }, action_capabilities: {} } }
    if (path === '/erp-commercial/companies/41/contracts') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    throw new Error(`Unexpected endpoint ${path}`)
  }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({ pending: Vue.ref(false), recoveryKey: Vue.ref(null), execute: async () => ({ status: 'unknown', key: 'unused' }), recover: async () => ({ status: 'unknown' }) }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? Vue.ref(0) : Vue.ref(init()),
    useRoute: () => route,
    useRouter: () => ({ replace: async () => {} }),
    definePageMeta() {}
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} }) }
  }
  const mounted = mountVueComponent(compileVueComponent('pages/gestao-erp/assinaturas/empresas/[companyId].vue', bindings, modules))
  t.after(mounted.unmount)
  for (let index = 0; index < 8; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.match(mounted.text(), /Cancelada/)
  assert.match(mounted.text(), /Em período de teste/)
  assert.doesNotMatch(mounted.text(), /canceled|trialing/)
})

it('marks only the public continuation receiver routes no-store and no-referrer in SSR middleware', () => {
  const middleware = readFileSync(new URL('../server/middleware/access-cache.ts', import.meta.url), 'utf8')
  assert.match(middleware, /\['\/primeiro-acesso', '\/erp\/convite', '\/redefinir-senha'\]/)
  assert.match(middleware, /cache-control', 'no-store'/)
  assert.match(middleware, /referrer-policy', 'no-referrer'/)
})

it('legacy customer routes redirect to the reviewed Company paths with allowlisted query state', () => {
  const listRedirect = vueSource('pages/gestao-erp/clientes/index.vue')
  const registrationRedirect = vueSource('pages/gestao-erp/clientes/novo.vue')
  assert.match(listRedirect, /path: '\/gestao-erp\/empresas'/)
  assert.match(registrationRedirect, /path: '\/gestao-erp\/empresas\/nova'/)
  assert.match(listRedirect, /officeErpCompanyListQuery\(route\.query\)/)
  assert.match(registrationRedirect, /officeErpCompanyListQuery\(route\.query\)/)
  const registrationPage = vueSource('pages/gestao-erp/empresas/nova.vue')
  assert.match(registrationPage, /canRegisterTypedCnpj\(taxId\.value\)/)
  assert.match(registrationPage, /replace\(\/\[\.\/-\]\/g, ''\)\.toUpperCase\(\)/)
  assert.equal(canRegisterTypedCnpj('12.ABC.345/01DE-35'), true)
  assert.equal(canRegisterTypedCnpj('ABC'), false)
})

it('credential receiver pages strip fragments before API requests and clear secrets before submission', () => {
  const invitation = vueSource('pages/erp/convite.vue')
  const recovery = vueSource('pages/redefinir-senha.vue')
  const fragmentPlugin = vueSource('plugins/00-access-continuation.client.ts')
  const fragmentUtility = vueSource('utils/accessContinuationFragment.ts')
  const inviteResolve = invitation.indexOf('await resolve(token)')
  const recoveryResolve = recovery.indexOf('void resolve()')
  assert.match(fragmentPlugin, /captureAccessContinuationFromLocation/)
  assert.match(fragmentUtility, /window\.history\.replaceState/)
  assert.ok(invitation.indexOf('captureAccessContinuationFromLocation') < invitation.indexOf('takeAccessContinuationFragment'))
  assert.ok(recovery.indexOf('captureAccessContinuationFromLocation') < recovery.indexOf('takeAccessContinuationFragment'))
  assert.ok(invitation.indexOf('takeAccessContinuationFragment') < inviteResolve)
  assert.ok(recovery.indexOf('takeAccessContinuationFragment') < recoveryResolve)
  assert.match(invitation, /:disabled="retryAfter > 0"/)
  assert.match(recovery, /:disabled="retryAfter > 0"/)
  assert.ok(invitation.indexOf('password.value = \'\'') < invitation.indexOf('api(\'/erp-member-invitations/accept\''))
  assert.ok(recovery.indexOf('password.value = \'\'') < recovery.indexOf('api<RecoveryState>(\'/password-recovery/complete\''))
  for (const source of [invitation, recovery]) {
    assert.doesNotMatch(source, /sessionStorage|localStorage/)
    assert.doesNotMatch(source, /sendBeacon|analytics/i)
    assert.match(source, /request !== request(Id|Generation) \|\| identity !== identityStamp\.value/)
    assert.match(source, /onBeforeUnmount\(\(\) => \{\s*request(Id|Generation)\+\+/)
  }
  assert.match(invitation, /watch\(identityStamp, \(\) => \{\s*requestId\+\+\s*outcomeUnknown = false\s*pendingToken = null/)
  assert.match(recovery, /watch\(identityStamp, \(\) => \{\s*requestGeneration\+\+\s*pending = null/)
})

it('captures only the two recipient fragment formats and clears each delivery from memory on take', () => {
  assert.deepEqual(parseAccessContinuationFragment('member-invitation', '#token=' + 'a'.repeat(64)), {
    kind: 'member-invitation', token: 'a'.repeat(64)
  })
  assert.deepEqual(parseAccessContinuationFragment('password-recovery', '#token=' + 'b'.repeat(64) + '&delivery=42'), {
    kind: 'password-recovery', token: 'b'.repeat(64), delivery_id: '42'
  })
  assert.equal(parseAccessContinuationFragment('member-invitation', '#token=' + 'a'.repeat(64) + '&delivery=42'), null)
  assert.equal(parseAccessContinuationFragment('password-recovery', '#token=' + 'b'.repeat(64) + '&delivery=42&extra=1'), null)
  assert.equal(parseAccessContinuationFragment('password-recovery', '#token=' + 'b'.repeat(64) + '&delivery=42&delivery=43'), null)
  const original = globalThis.window
  const replacements: Array<{ state: unknown, url: string }> = []
  const invitationToken = 'c'.repeat(64)
  const recoveryToken = 'd'.repeat(64)
  const routerState = { back: '/login', current: '/erp/convite?keep=1#token=' + invitationToken, forward: '/redefinir-senha#delivery=42&%74oken=' + recoveryToken, position: 9, replaced: true, scroll: { left: 0, top: 17 } }
  Object.defineProperty(globalThis, 'window', { configurable: true, value: {
    location: { pathname: '/erp/convite', search: '?keep=1', hash: '#token=' + invitationToken, origin: 'https://office.example.test' },
    history: { state: routerState, replaceState: (state: unknown, _title: string, url: string) => replacements.push({ state, url }) }
  } })
  try {
    assert.deepEqual(captureAccessContinuationFromLocation('member-invitation'), { kind: 'member-invitation', token: invitationToken })
    assert.deepEqual(replacements, [{ state: { back: '/login', current: '/erp/convite?keep=1', forward: '/redefinir-senha', position: 9, replaced: true, scroll: { left: 0, top: 17 } }, url: '/erp/convite?keep=1' }])
    assert.equal(JSON.stringify(replacements[0]?.state).includes(invitationToken), false, 'Vue Router history no longer serializes the active invitation bearer')
    assert.equal(JSON.stringify(replacements[0]?.state).includes(recoveryToken), false, 'reordered and encoded recovery bearer fields in Vue Router navigation state are scrubbed too')
    assert.deepEqual(takeAccessContinuationFragment('member-invitation'), { kind: 'member-invitation', token: invitationToken })
    assert.equal(takeAccessContinuationFragment('member-invitation'), null)
  } finally {
    if (original === undefined) delete (globalThis as { window?: Window }).window
    else Object.defineProperty(globalThis, 'window', { configurable: true, value: original })
  }
})

it('captures an SPA destination bearer before history can retain it and consumes it once', () => {
  const token = 'e'.repeat(64)
  assert.equal(captureAccessContinuationFromRoute('password-recovery', `#delivery=42&%74oken=${token}`), true)
  assert.deepEqual(takeAccessContinuationFragment('password-recovery'), { kind: 'password-recovery', token, delivery_id: '42' })
  assert.equal(takeAccessContinuationFragment('password-recovery'), null, 'SPA continuation is removed from module memory after one take')
  assert.equal(captureAccessContinuationFromRoute('password-recovery', ''), false)
})

it('parses Retry-After seconds and HTTP dates with a bounded delay', () => {
  const now = Date.parse('2026-10-28T12:00:00Z')
  assert.equal(retryAfterSeconds({ response: { headers: new Headers({ 'Retry-After': '7' }) } }, now), 7)
  assert.equal(retryAfterSeconds({ headers: { 'retry-after': 'Wed, 28 Oct 2026 12:00:05 GMT' } }, now), 5)
  assert.equal(retryAfterSeconds({ headers: { 'retry-after': '99999' } }, now), 3600)
})

it('preserves legacy contract ids and Company invoice navigation', () => {
  const contract = vueSource('pages/gestao-erp/assinaturas/[id].vue')
  const company = vueSource('pages/gestao-erp/assinaturas/empresas/[companyId].vue')
  assert.match(company, /<NuxtPage v-else \/>/)
  assert.match(contract, /\/erp-commercial\/contracts\/\$\{route\.params\.id\}/)
  assert.match(contract, /\/gestao-erp\/assinaturas\/contratos/)
  assert.match(company, /\/gestao-erp\/assinaturas\/\$\{subscription\.current_contract\.id\}/)
  assert.match(company, /\/gestao-erp\/faturas\/\$\{invoiceId\}/)
})

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

it('mounted invitation receiver discards an old identity response and an unmounted read', async (t) => {
  const user = Vue.ref<{ id: number, email: string } | null>(null)
  const sessionGeneration = Vue.ref(0)
  const resolveRequest = deferred<unknown>()
  const currentRequest = deferred<unknown>()
  const calls: string[] = []
  const api = (path: string) => {
    calls.push(path)
    if (path.endsWith('/resolve')) return resolveRequest.promise
    return currentRequest.promise
  }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    useHead() {},
    definePageMeta() {},
    useAccessApi: () => ({ api }),
    useAuth: () => ({ user, logout: async () => {} }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? sessionGeneration : Vue.ref(init()),
    navigateTo: async () => {}
  }
  const mocks = {
    '~/utils/accessContinuationFragment': {
      accessContinuationRouteCaptureVersion: () => Vue.ref(0),
      takeAccessContinuationFragment: () => ({ kind: 'member-invitation', token: 'a'.repeat(64) }),
      takeInvalidAccessContinuationCapture: () => false,
      captureAccessContinuationFromLocation: () => null
    },
    '#shared/utils/access': {
      apiFailure: (failure: { status?: number, message?: string }) => ({ status: failure.status ?? 500, message: failure.message ?? 'Falha', errors: {} }),
      passwordLength: (value: string) => value.length,
      retryAfterSeconds: (failure: { retryAfter?: string }) => Number(failure.retryAfter ?? 0)
    }
  }
  const component = compileVueComponent('pages/erp/convite.vue', bindings, mocks, { client: true })
  const mounted = mountVueComponent(component)
  t.after(mounted.unmount)
  await Vue.nextTick()
  assert.deepEqual(calls, ['/erp-member-invitations/resolve'])

  user.value = { id: 9, email: 'other@example.test' }
  await Vue.nextTick()
  assert.deepEqual(calls, ['/erp-member-invitations/resolve', '/erp-member-invitations/current'])
  resolveRequest.resolve({ state: 'valid', operation_key: 'old-operation', data: { invitation: { id: 4, name: 'Old Recipient', email: 'old@example.test', role: 'Viewer', expires_at: '' }, company: { id: 2, name: 'Old Company' } } })
  await Vue.nextTick()
  assert.doesNotMatch(mounted.text(), /Old Recipient|Old Company|old@example\.test/)

  currentRequest.resolve({ state: 'invalid' })
  for (let index = 0; index < 4; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.match(mounted.text(), /Convite indisponível/)
  assert.doesNotMatch(mounted.text(), /Old Recipient|Old Company|old@example\.test/)

  const unmountedRequest = deferred<unknown>()
  const nextApi = (path: string) => path.endsWith('/resolve') ? unmountedRequest.promise : Promise.resolve({ state: 'invalid' })
  const second = mountVueComponent(compileVueComponent('pages/erp/convite.vue', { ...bindings, useAccessApi: () => ({ api: nextApi }) }, mocks, { client: true }))
  await Vue.nextTick()
  second.unmount()
  unmountedRequest.resolve({ state: 'valid', operation_key: 'unmounted', data: { invitation: { id: 5, name: 'Unmounted Recipient', email: 'gone@example.test', role: 'Viewer', expires_at: '' }, company: { id: 3, name: 'Gone Company' } } })
  await new Promise(resolve => setImmediate(resolve))
  assert.doesNotMatch(second.text(), /Unmounted Recipient|Gone Company|gone@example\.test/)
})

it('mounted password receiver keeps 429 resolution retryable and never submits the password until explicit submit', async (t) => {
  const user = Vue.ref(null)
  const sessionGeneration = Vue.ref(0)
  const calls: string[] = []
  const api = async (path: string) => {
    calls.push(path)
    if (path.endsWith('/resolve') && calls.filter(item => item.endsWith('/resolve')).length === 1)
      throw Object.assign(new Error('Aguarde antes de tentar novamente.'), { status: 429, retryAfter: '1' })
    if (path.endsWith('/resolve')) return { state: 'valid' }
    return { state: 'invalid' }
  }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    definePageMeta() {},
    useHead() {},
    useAccessApi: () => ({ api }),
    useAuth: () => ({ user, logout: async () => {} }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? sessionGeneration : Vue.ref(init())
  }
  const mocks = {
    '~/utils/accessContinuationFragment': {
      accessContinuationRouteCaptureVersion: () => Vue.ref(0),
      takeAccessContinuationFragment: () => ({ kind: 'password-recovery', token: 'b'.repeat(64), delivery_id: '42' }),
      takeInvalidAccessContinuationCapture: () => false,
      captureAccessContinuationFromLocation: () => null
    },
    '#shared/utils/access': {
      apiFailure: (failure: { status?: number, message?: string }) => ({ status: failure.status ?? 500, message: failure.message ?? 'Falha', errors: {} }),
      passwordLength: (value: string) => value.length,
      retryAfterSeconds: (failure: { retryAfter?: string }) => Number(failure.retryAfter ?? 0)
    }
  }
  const mounted = mountVueComponent(compileVueComponent('pages/redefinir-senha.vue', bindings, mocks, { client: true }))
  t.after(mounted.unmount)
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.match(mounted.text(), /Aguarde antes de tentar novamente/)
  assert.doesNotMatch(mounted.text(), /Link indisponível/)
  assert.deepEqual(calls, ['/password-recovery/resolve'])
  const renderText = (node: { text?: string, children: Array<{ text?: string, children: unknown[] }> }): string => `${node.text ?? ''}${node.children.map(child => renderText(child as { text?: string, children: Array<{ text?: string, children: unknown[] }> })).join('')}`
  const retryButton = mounted.nodes(node => node.tag === 'button' && renderText(node).includes('Consultar status em 1s'))[0]
  assert.ok(retryButton)
  assert.equal(retryButton.props.disabled, true)
  await new Promise(resolve => setTimeout(resolve, 1100))
  await Vue.nextTick()
  const availableRetry = mounted.nodes(node => node.tag === 'button' && renderText(node).includes('Consultar status'))[0]
  assert.equal(availableRetry?.props.disabled, false)
  ;(availableRetry!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(calls, ['/password-recovery/resolve', '/password-recovery/resolve'])
  assert.doesNotMatch(calls.join(','), /\/complete/)
  assert.match(mounted.text(), /Redefinir senha/)
})

it('mounted public receivers replace token A with SPA token B and ignore A completion', async (t) => {
  const invitationA = 'f'.repeat(64)
  const invitationB = 'e'.repeat(64)
  const firstInvitation = deferred<unknown>()
  const invitationCalls: Array<{ path: string, token?: string }> = []
  captureAccessContinuationFromRoute('member-invitation', `#token=${invitationA}`)
  const invitationBindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    definePageMeta() {},
    useHead() {},
    useAccessApi: () => ({ api: async (path: string, options?: { body?: { token?: string } }) => {
      invitationCalls.push({ path, token: options?.body?.token })
      if (invitationCalls.filter(call => call.path.endsWith('/resolve')).length === 1) return firstInvitation.promise
      return { state: 'ready', operation_key: 'operation-b', data: { invitation: { id: 8, name: 'Recipient B', email: 'b@example.test', role: 'viewer', expires_at: '2026-11-01T00:00:00Z' }, company: { id: 9, name: 'Company B' } } }
    } }),
    useAuth: () => ({ user: Vue.ref(null), logout: async () => {} }),
    useState: () => Vue.ref(0),
    navigateTo: async () => {}
  }
  const continuationMocks = {
    '~/utils/accessContinuationFragment': {
      accessContinuationRouteCaptureVersion,
      captureAccessContinuationFromLocation: () => null,
      takeAccessContinuationFragment,
      takeInvalidAccessContinuationCapture
    },
    '#shared/utils/access': {
      apiFailure: (failure: { status?: number, message?: string }) => ({ status: failure.status ?? 500, message: failure.message ?? 'Falha', errors: {} }),
      passwordLength: (value: string) => value.length,
      retryAfterSeconds: () => 0
    }
  }
  const invitationPage = mountVueComponent(compileVueComponent('pages/erp/convite.vue', invitationBindings, continuationMocks, { client: true }))
  t.after(invitationPage.unmount)
  for (let index = 0; index < 4; index++) await Vue.nextTick()
  assert.equal(invitationCalls[0]?.token, invitationA)
  captureAccessContinuationFromRoute('member-invitation', `#token=${invitationB}`)
  for (let index = 0; index < 6; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(invitationCalls.map(call => call.token), [invitationA, invitationB])
  assert.match(invitationPage.text(), /Recipient B/)
  firstInvitation.resolve({ state: 'invalid', data: { invitation: { id: 7, name: 'Recipient A', email: 'a@example.test', role: 'viewer', expires_at: '' }, company: { id: 8, name: 'Company A' } } })
  await new Promise(resolve => setImmediate(resolve))
  assert.match(invitationPage.text(), /Recipient B/)
  assert.doesNotMatch(invitationPage.text(), /Recipient A|Company A|a@example\.test/)

  const beforeInvalidInvitation = invitationCalls.length
  captureAccessContinuationFromRoute('member-invitation', '#token=malformed')
  for (let index = 0; index < 4; index++) await Vue.nextTick()
  assert.equal(invitationCalls.length, beforeInvalidInvitation, 'malformed replacement must not load the previous signed continuation')
  assert.match(invitationPage.text(), /Convite indisponível/)
  assert.doesNotMatch(invitationPage.text(), /Recipient B|Company B|b@example\.test/)

  const recoveryA = 'd'.repeat(64)
  const recoveryB = 'c'.repeat(64)
  const firstRecovery = deferred<unknown>()
  const recoveryCalls: Array<{ path: string, token?: string, delivery_id?: string }> = []
  captureAccessContinuationFromRoute('password-recovery', `#token=${recoveryA}&delivery=71`)
  const recoveryBindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    definePageMeta() {},
    useHead() {},
    useAccessApi: () => ({ api: async (path: string, options?: { body?: { token?: string, delivery_id?: string } }) => {
      recoveryCalls.push({ path, ...options?.body })
      if (recoveryCalls.filter(call => call.path.endsWith('/resolve')).length === 1) return firstRecovery.promise
      return { state: 'valid' }
    } }),
    useAuth: () => ({ user: Vue.ref(null), logout: async () => {} }),
    useState: () => Vue.ref(0)
  }
  const recoveryPage = mountVueComponent(compileVueComponent('pages/redefinir-senha.vue', recoveryBindings, continuationMocks, { client: true }))
  t.after(recoveryPage.unmount)
  for (let index = 0; index < 4; index++) await Vue.nextTick()
  assert.deepEqual(recoveryCalls[0], { path: '/password-recovery/resolve', token: recoveryA, delivery_id: '71' })
  captureAccessContinuationFromRoute('password-recovery', `#delivery=72&token=${recoveryB}`)
  for (let index = 0; index < 6; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(recoveryCalls.map(call => call.token), [recoveryA, recoveryB])
  assert.match(recoveryPage.text(), /Defina uma nova senha/)
  assert.doesNotMatch(recoveryPage.text(), /Link indisponível/)

  firstRecovery.resolve({ state: 'invalid' })
  await new Promise(resolve => setImmediate(resolve))
  assert.doesNotMatch(recoveryPage.text(), /Link indisponível/)

  const beforeInvalidRecovery = recoveryCalls.length
  captureAccessContinuationFromRoute('password-recovery', '#token=malformed&delivery=72')
  for (let index = 0; index < 4; index++) await Vue.nextTick()
  assert.equal(recoveryCalls.length, beforeInvalidRecovery, 'malformed replacement must not load the previous signed continuation')
  assert.match(recoveryPage.text(), /Link indisponível/)
})

it('mounted member recovery clears reset passwords and reports delivery acceptance precisely', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_maintain: true, capability_key: 'cap-1' })
  const route = Vue.reactive({ params: { companyId: '41', membershipId: '22' } })
  const apiCalls: string[] = []
  const writes: Array<{ path: string, body: unknown }> = []
  const recoveryKey = Vue.ref<string | null>(null)
  const deliveryState = 'transport_accepted'
  const api = async (path: string) => {
    apiCalls.push(path)
    if (path.endsWith('/members/22')) return { data: { kind: 'membership', id: 22, user: { id: 2, name: 'Pessoa usuária', email: 'person@example.test' }, access_auth_revision: 7, role: 'operator', status: 'active', credential_capabilities: { can_direct_reset: true, can_email_recovery: true, direct_reset_unavailable_reason: null } } }
    if (path.endsWith('/access-deliveries/88')) return { data: { id: 88, state: deliveryState, expires_at: '2026-11-01T00:00:00Z', authority_invalidated_at: null } }
    throw new Error(`Unexpected endpoint ${path}`)
  }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false),
      recoveryKey,
      execute: async (_action: string, path: string, body: unknown) => {
        writes.push({ path, body })
        return { status: 'confirmed', data: { company_id: 41, membership_id: 22, auth_revision: 8, delivery_id: 88, delivery_state: deliveryState } }
      },
      recover: async () => ({ status: 'confirmed', data: { company_id: 999, membership_id: 999, auth_revision: 99 } })
    }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? Vue.ref(0) : Vue.ref(init()),
    useRoute: () => route,
    definePageMeta() {}
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }) }) }
  }
  const mounted = mountVueComponent(compileVueComponent('pages/gestao-erp/assinaturas/empresas/[companyId]/membros/[membershipId]/recuperar.vue', bindings, modules))
  t.after(mounted.unmount)
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.match(mounted.text(), /em todas as empresas/)
  assert.match(mounted.text(), /Operador/)
  const passwordInputs = mounted.nodes(node => node.tag === 'input' && node.props.type === 'password')
  assert.equal(passwordInputs.length, 2)
  ;(passwordInputs[0]!.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Temporary-password-88' } })
  ;(passwordInputs[1]!.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Temporary-password-88' } })
  await Vue.nextTick()
  const form = mounted.nodes(node => node.tag === 'form')[0]
  assert.ok(form)
  ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(writes[0], { path: '/erp-commercial/companies/41/members/22/password-reset', body: { expected_auth_revision: 7, password: 'Temporary-password-88', password_confirmation: 'Temporary-password-88' } })
  assert.equal(passwordInputs[0]!.props.value, '')
  assert.equal(passwordInputs[1]!.props.value, '')
  const renderText = (node: { text?: string, children: Array<{ text?: string, children: unknown[] }> }): string => `${node.text ?? ''}${node.children.map(child => renderText(child as { text?: string, children: Array<{ text?: string, children: unknown[] }> })).join('')}`
  const sendButton = mounted.nodes(node => node.tag === 'button' && renderText(node).includes('Enviar recuperação'))[0]
  ;(sendButton.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(writes[1]?.path, '/erp-commercial/companies/41/members/22/recovery')
  assert.match(mounted.text(), /criada para processamento/)
  assert.doesNotMatch(mounted.text(), /recuperação enviada ao endereço/)
  const checkButton = mounted.nodes(node => node.tag === 'button' && renderText(node).includes('Consultar estado'))[0]
  ;(checkButton.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.ok(apiCalls.includes('/erp-commercial/companies/41/access-deliveries/88'))
  assert.match(mounted.text(), /Aceita pelo serviço de e-mail; entrega final não confirmada/)
  recoveryKey.value = 'shared-command-key'
  await Vue.nextTick()
  const recoverButton = mounted.nodes(node => node.tag === 'button' && renderText(node).includes('Consultar resultado'))[0]
  assert.ok(recoverButton)
  ;(recoverButton.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.match(mounted.text(), /Uma operação foi confirmada/)
  assert.doesNotMatch(mounted.text(), /Senha atualizada\. O usuário precisará entrar novamente\./)
})

it('mounted Owner credential page fails closed to direct reset and allows only server-authorized recovery', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_maintain: true, capability_key: 'cap-1' })
  const route = Vue.reactive({ params: { companyId: '41', membershipId: '21' } })
  const writes: Array<{ path: string, body: unknown }> = []
  const api = async (path: string) => {
    assert.equal(path, '/erp-commercial/companies/41/members/21')
    return { data: { kind: 'membership', id: 21, user: { id: 3, name: 'Proprietária', email: 'owner@example.test' }, access_auth_revision: 7, role: 'owner', status: 'active', credential_capabilities: { can_direct_reset: false, can_email_recovery: true, direct_reset_unavailable_reason: 'protected_identity' } } }
  }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false),
      recoveryKey: Vue.ref(null),
      execute: async (_action: string, path: string, body: unknown) => {
        writes.push({ path, body })
        return { status: 'confirmed', data: { company_id: 41, membership_id: 21, delivery_id: 89, delivery_state: 'prepared' } }
      },
      recover: async () => ({ status: 'unknown' })
    }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (_key: string, init: () => unknown) => Vue.ref(init()),
    useRoute: () => route,
    definePageMeta() {}
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} }) }
  }
  const mounted = mountVueComponent(compileVueComponent('pages/gestao-erp/assinaturas/empresas/[companyId]/membros/[membershipId]/recuperar.vue', bindings, modules))
  t.after(mounted.unmount)
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.match(mounted.text(), /Alteração direta indisponível/)
  assert.match(mounted.text(), /Por proteção, esta identidade não pode receber uma senha definida diretamente/)
  assert.doesNotMatch(mounted.text(), /Definir nova senha/)
  assert.equal(mounted.nodes(node => node.tag === 'input' && node.props.type === 'password').length, 0)
  const sendButton = mounted.nodes(node => node.tag === 'button' && node.children.map(child => child.text ?? '').join('').includes('Enviar recuperação'))[0]
  assert.ok(sendButton)
  ;(sendButton.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(writes, [{ path: '/erp-commercial/companies/41/members/21/recovery', body: {} }])
})

it('mounted portfolio picker excludes registered Clients from tri-state selection and keeps unknown registration recoverable', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, can_maintain: true, can_configure: true, can_sell_new: true, capability_key: 'cap-1' })
  const apiCalls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const commandCalls: Array<{ path: string, body: unknown }> = []
  let recoverCalls = 0
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const rows = [
    { id: 11, name: 'Cliente elegível', tax_id: '123', erp_registration: null },
    { id: 12, name: 'Cliente já registrado', tax_id: '456', erp_registration: { registration_id: 71, company_id: 81 } },
    ...Array.from({ length: 100 }, (_, index) => ({ id: 100 + index, name: `Cliente ${index + 1}`, tax_id: String(1000 + index), erp_registration: null }))
  ]
  const api = async (path: string, options?: Record<string, unknown>) => {
    apiCalls.push({ path, options })
    return { data: rows, meta: { current_page: 1, last_page: 1, per_page: 100, total: rows.length } }
  }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false),
      recoveryKey: Vue.ref('operation-1'),
      execute: async (_action: string, path: string, body: unknown) => {
        commandCalls.push({ path, body })
        return { status: 'unknown', key: 'operation-1' }
      },
      recover: async () => {
        recoverCalls++
        return { status: 'unknown' }
      }
    }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? Vue.ref(0) : Vue.ref(init()),
    defineModel: undefined
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} }) },
    '~/composables/useOfficeErpListNavigation': { officeErpAvailablePage: (meta: { current_page: number }) => meta.current_page }
  }
  const component = compileVueComponent('components/office-erp/OfficeErpCompanyPortfolioModal.vue', bindings, modules)
  let mobileColumns: string[] = []
  const dataTable = Vue.defineComponent({
    props: ['data', 'mobileColumns'],
    setup(props, { slots }) {
      return () => {
        mobileColumns = props.mobileColumns as string[]
        return Vue.h('table', {}, [
          ...(slots['select-header']?.() ?? []),
          ...(props.data as typeof rows).map(row => Vue.h('tr', {}, [
            ...(slots['select-cell']?.({ row: { original: row } }) ?? []),
            ...(slots['name-cell']?.({ row: { original: row } }) ?? []),
            ...(slots['tax_id-cell']?.({ row: { original: row } }) ?? []),
            ...(slots['actions-cell']?.({ row: { original: row } }) ?? [])
          ]))
        ])
      }
    }
  })
  const mounted = mountVueComponent(component, { OfficeErpDataTable: dataTable }, { open: true })
  t.after(mounted.unmount)
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(apiCalls.filter(call => call.path === '/erp-commercial/clients').length, 1)
  assert.match(mounted.text(), /Cliente já registrado/)
  assert.deepEqual(mobileColumns, ['select', 'name', 'actions'], 'selection and the individual registration action remain visible on compact rows')
  const registeredCheckbox = mounted.nodes(node => node.props.role === 'checkbox' && node.props['aria-label'] === 'Cliente já registrado já está registrado')[0]
  const eligibleCheckbox = mounted.nodes(node => node.props.role === 'checkbox' && node.props['aria-label'] === 'Selecionar Cliente elegível')[0]
  assert.ok(registeredCheckbox)
  assert.equal(registeredCheckbox.props.disabled, true)
  const eligibleRegister = mounted.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Registrar Cliente elegível')[0]
  assert.ok(eligibleRegister, 'eligible Clients have the inline plus action')
  assert.equal(eligibleRegister.props.color, 'neutral', 'the individual portfolio registration action is visually neutral')
  assert.equal(eligibleRegister.props.variant, 'ghost')
  assert.equal(mounted.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Registrar Cliente já registrado').length, 0, 'registered Clients cannot be registered again')
  assert.ok(eligibleCheckbox)
  ;(eligibleCheckbox.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
  await Vue.nextTick()
  assert.match(mounted.text(), /1 selecionados · limite 100/)
  const pageCheckbox = mounted.nodes(node => node.props.role === 'checkbox' && node.props['aria-label'] === 'Selecionar a página atual')[0]
  ;(pageCheckbox.props['onUpdate:modelValue'] as (value: boolean) => void)(true)
  await Vue.nextTick()
  assert.match(mounted.text(), /1 selecionados · limite 100/)
  assert.match(mounted.text(), /ultrapassaria o limite de 100/)
  const renderText = (node: { text?: string, children: Array<{ text?: string, children: unknown[] }> }): string => `${node.text ?? ''}${node.children.map(child => renderText(child as { text?: string, children: Array<{ text?: string, children: unknown[] }> })).join('')}`
  const registerButton = mounted.nodes(node => node.tag === 'button' && renderText(node).includes('Registrar selecionados') && String(node.props.disabled) !== 'true')[0]
  assert.ok(registerButton)
  ;(registerButton.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(commandCalls, [{ path: '/erp-commercial/companies', body: { client_ids: [11] } }])
  assert.match(mounted.text(), /ainda é incerto/)
  assert.match(mounted.text(), /Recupere a operação operation-1/)
  const recoverButton = mounted.nodes(node => node.tag === 'button' && renderText(node).includes('Recuperar operação'))[0]
  assert.ok(recoverButton)
  ;(recoverButton.props.onClick as () => Promise<void>)()
  await Vue.nextTick()
  assert.equal(recoverCalls, 1)
  const searchInput = mounted.nodes(node => node.tag === 'input' && node.props['aria-label'] === 'Buscar cliente')[0]
  assert.ok(searchInput)
  ;(searchInput.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Cliente elegível' } })
  for (let index = 0; index < 150 && !apiCalls.some(call => call.path === '/erp-commercial/clients' && (call.options?.params as Record<string, unknown>)?.search === 'Cliente elegível'); index++) {
    await new Promise(resolve => setTimeout(resolve, 10))
    await Vue.nextTick()
  }
  assert.ok(apiCalls.some(call => call.path === '/erp-commercial/clients' && (call.options?.params as Record<string, unknown>)?.search === 'Cliente elegível'))
})

it('mounted portfolio inline plus registers only its eligible Client and remains visible on mobile', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, capability_key: 'cap-1' })
  const row = { id: 11, name: 'Cliente isolado', tax_id: '123', erp_registration: null }
  const writes: Array<{ path: string, body: unknown }> = []
  const registrations: unknown[] = []
  const api = async () => ({ data: [row], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } })
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false),
      recoveryKey: Vue.ref(null),
      execute: async (_action: string, path: string, body: unknown) => {
        writes.push({ path, body })
        return { status: 'confirmed', data: { created: [{ registration_id: 71, client_id: 11, company_id: 81 }], unchanged: [] } }
      },
      recover: async () => ({ status: 'unknown' })
    }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? Vue.ref(0) : Vue.ref(init())
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} }) },
    '~/composables/useOfficeErpListNavigation': { officeErpAvailablePage: (meta: { current_page: number }) => meta.current_page }
  }
  let mobileColumns: string[] = []
  const dataTable = Vue.defineComponent({
    props: ['data', 'mobileColumns'],
    setup(props, { slots }) {
      return () => {
        mobileColumns = props.mobileColumns as string[]
        return Vue.h('table', {}, (props.data as Array<typeof row>).map(item => Vue.h('tr', {}, [
          ...(slots['select-cell']?.({ row: { original: item } }) ?? []),
          ...(slots['name-cell']?.({ row: { original: item } }) ?? []),
          ...(slots['actions-cell']?.({ row: { original: item } }) ?? [])
        ])))
      }
    }
  })
  const component = compileVueComponent('components/office-erp/OfficeErpCompanyPortfolioModal.vue', bindings, modules)
  const mounted = mountVueComponent(component, { OfficeErpDataTable: dataTable }, { open: true, onRegistered: (value: unknown) => registrations.push(value) })
  t.after(mounted.unmount)
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(mobileColumns, ['select', 'name', 'actions'])
  const plus = mounted.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Registrar Cliente isolado')[0]
  assert.ok(plus)
  ;(plus.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(writes, [{ path: '/erp-commercial/companies', body: { client_ids: [11] } }])
  assert.deepEqual(registrations, [{ created: [{ registration_id: 71, client_id: 11, company_id: 81 }], unchanged: [] }])
})

it('mounted Company inspection preserves a 409 draft and shows named validation errors until explicit reload', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, can_maintain: true, can_configure: true, can_sell_new: true, capability_key: 'cap-1' })
  const profile = { name: 'Empresa de Exemplo LTDA', trade_name: 'Exemplo', tax_regime: 'other', contact_person: null, email: null, phone: null, whatsapp: null, street_type: null, street: null, address_number: null, address_complement: null, district: null, postal_code: null, city: null, state: null, ie: null }
  const latestProfile = { ...profile, name: 'Empresa Nova Sincronizada LTDA' }
  let profileReads = 0
  const writes: Array<{ path: string, body: unknown }> = []
  const deferredSave = deferred<unknown>()
  const api = async (path: string) => {
    assert.equal(path, '/erp-commercial/companies/41')
    profileReads++
    const next = profileReads > 1 ? latestProfile : profile
    return { data: { id: 41, registration_id: 61, client_id: 71, name: next.name, cnpj: '123', registered_at: '', owner_setup_status: 'not_started', current_contract_id: null, capabilities: { relationship: 'unassigned', can_edit_profile: true, can_assign: true, can_manage_members: false, capability_key: 'cap-1' }, profile: next, profile_version: String(profileReads).padStart(64, '0') } }
  }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  let writeAttempt = 0
  const bindings = {
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false),
      recoveryKey: Vue.ref(null),
      execute: async (_action: string, path: string, body: unknown) => {
        writes.push({ path, body })
        writeAttempt++
        if (writeAttempt === 1) return { status: 'failed', failure: { http_status: 409, message: 'conflict', errors: {} } }
        if (writeAttempt === 2) return { status: 'failed', failure: { http_status: 422, message: 'invalid', errors: { name: ['Razão social não aceita.'] } } }
        return deferredSave.promise
      },
      recover: async () => ({ status: 'unknown' })
    }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? Vue.ref(0) : Vue.ref(init())
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} }) }
  }
  const component = compileVueComponent('components/office-erp/OfficeErpCompanyInspectionModal.vue', bindings, modules)
  let updatedEvents = 0
  const mounted = mountVueComponent(component, {}, {
    open: true,
    companyId: 41,
    onUpdated: () => {
      updatedEvents++
    }
  })
  t.after(mounted.unmount)
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const nameInput = mounted.nodes(node => node.tag === 'input' && node.props.maxlength === 255)[0]
  assert.ok(nameInput)
  ;(nameInput.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Empresa com rascunho LTDA' } })
  await Vue.nextTick()
  const renderText = (node: { text?: string, children: Array<{ text?: string, children: unknown[] }> }): string => `${node.text ?? ''}${node.children.map(child => renderText(child as { text?: string, children: Array<{ text?: string, children: unknown[] }> })).join('')}`
  const button = (label: string) => mounted.nodes(node => node.tag === 'button' && renderText(node).includes(label))[0]
  ;(button('Salvar perfil').props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(profileReads, 1)
  assert.equal(nameInput.props.value, 'Empresa com rascunho LTDA')
  assert.match(mounted.text(), /rascunho foi preservado/)
  assert.ok(button('Recarregar dados'))
  ;(button('Recarregar dados').props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(profileReads, 2)
  const refreshedName = mounted.nodes(node => node.tag === 'input' && node.props.maxlength === 255)[0]
  assert.equal(refreshedName.props.value, 'Empresa Nova Sincronizada LTDA')
  ;(refreshedName.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Razão social muito curta' } })
  await Vue.nextTick()
  ;(button('Salvar perfil').props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(writes.map(write => write.body), [
    { expected_profile_version: String(1).padStart(64, '0'), name: 'Empresa com rascunho LTDA' },
    { expected_profile_version: String(2).padStart(64, '0'), name: 'Razão social muito curta' }
  ])
  assert.match(mounted.text(), /Razão social não aceita/)
  assert.equal(refreshedName.props.value, 'Razão social muito curta')
  ;(refreshedName.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Empresa com resposta tardia LTDA' } })
  await Vue.nextTick()
  ;(button('Salvar perfil').props.onClick as () => Promise<void>)()
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(writeAttempt, 3)
  mounted.unmount()
  deferredSave.resolve({ status: 'confirmed', data: { id: 41, profile: { ...latestProfile, name: 'Empresa atualizada LTDA' }, profile_version: 'b'.repeat(64) } })
  for (let index = 0; index < 4; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(updatedEvents, 0, 'a late profile completion cannot emit after modal disposal')
})

it('mounted Company assignment targets the selected Company and preserves large decimal money as text', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, can_maintain: true, can_configure: true, can_sell_new: true, capability_key: 'cap-1' })
  const route = Vue.reactive({ path: '/gestao-erp/assinaturas/empresas/41', params: { companyId: '41' }, query: { secao: 'profile' } as Record<string, string> })
  const pendingProfile = deferred<unknown>()
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const writes: Array<{ path: string, body: unknown }> = []
  const profile = { name: 'Empresa Histórica LTDA', trade_name: null, tax_regime: 'other', contact_person: null, email: null, phone: null, whatsapp: null, street_type: null, street: null, address_number: null, address_complement: null, district: null, postal_code: null, city: null, state: null, ie: null }
  const company = { id: 41, registration_id: 61, client_id: 71, name: profile.name, cnpj: '123', registered_at: '', owner_setup_status: 'not_started', current_contract_id: null, capabilities: { relationship: 'historical', can_edit_profile: true, can_assign: true, can_manage_members: true, capability_key: 'cap-1' }, profile, profile_version: 'a'.repeat(64) }
  const api = async (path: string, options?: Record<string, unknown>) => {
    calls.push({ path, options })
    if (path === '/erp-commercial/companies/41') return { data: company }
    if (path === '/erp-commercial/companies/41/subscription') return { data: { company: { id: 41, name: profile.name }, relationship: 'historical', subscription: null, current_contract: null, trial: null, allocation: null, balance: { outstanding: '0.00', invoice_count: 0 }, action_capabilities: { can_assign: true } } }
    if (path === '/erp-commercial/companies/41/contracts') return { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }
    if (path === '/erp-commercial/enrollment-plans') return { data: [{ id: 71, name: 'Plano comercial', module_keys: ['erp.sales'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 1, available_module_keys: ['erp.sales'] } }
    if (path === '/erp-commercial/settings') return { data: { billing_timezone: 'America/Sao_Paulo' } }
    throw new Error(`Unexpected endpoint ${path}`)
  }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false),
      recoveryKey: Vue.ref(null),
      execute: async (_action: string, path: string, body: unknown) => {
        writes.push({ path, body })
        if (path === '/erp-commercial/companies/41') return pendingProfile.promise
        return { status: 'unknown', key: 'assign-op' }
      },
      recover: async () => ({ status: 'unknown' })
    }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? Vue.ref(0) : Vue.ref(init()),
    useRoute: () => route,
    useRouter: () => ({ replace: async () => {} }),
    definePageMeta() {}
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} }) }
  }
  const component = compileVueComponent('pages/gestao-erp/assinaturas/empresas/[companyId].vue', bindings, modules)
  const assignmentModal = compileVueComponent('components/office-erp/OfficeErpCompanyAssignmentModal.vue', bindings, modules)
  const mounted = mountVueComponent(component, { OfficeErpCompanyAssignmentModal: assignmentModal })
  t.after(mounted.unmount)
  for (let index = 0; index < 8; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const renderText = (node: { text?: string, children: Array<{ text?: string, children: unknown[] }> }): string => `${node.text ?? ''}${node.children.map(child => renderText(child as { text?: string, children: Array<{ text?: string, children: unknown[] }> })).join('')}`
  const button = (label: string) => mounted.nodes(node => node.tag === 'button' && renderText(node).includes(label))[0]
  assert.ok(button('Adicionar assinatura'), 'historical relationship can use its explicit can_assign capability')
  ;(button('Adicionar assinatura').props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const priceInput = mounted.nodes(node => node.tag === 'input' && node.props.inputmode === 'decimal')[0]
  assert.ok(priceInput)
  ;(priceInput.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '9.007.199.254.740.993,01' } })
  await Vue.nextTick()
  ;(button('Confirmar assinatura').props.onClick as () => Promise<void>)()
  for (let index = 0; index < 6; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(writes.length, 1)
  assert.equal(writes[0]?.path, '/erp-commercial/companies/41/assignments')
  assert.deepEqual(writes[0]?.body, { erp_plan_id: 71, price: '9007199254740993.01', currency: 'BRL', interval_months: 1, due_in_days: 0, start_on: (writes[0]?.body as { start_on: string }).start_on, billing_timezone: 'America/Sao_Paulo' })
  assert.match((writes[0]?.body as { start_on: string }).start_on, /^\d{4}-\d{2}-\d{2}$/)
  assert.match(mounted.text(), /Consulte a operação assign-op/)
  assert.ok(calls.some(call => call.path === '/erp-commercial/companies/41/subscription'))
  const nameInput = mounted.nodes(node => node.tag === 'input' && node.props.maxlength === 255)[0]!
  ;(nameInput.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Empresa alteração tardia LTDA' } })
  await Vue.nextTick()
  ;(button('Salvar perfil').props.onClick as () => Promise<void>)()
  await new Promise(resolve => setImmediate(resolve))
  const priorReads = calls.length
  mounted.unmount()
  pendingProfile.resolve({ status: 'confirmed', data: { ...company, id: 999, profile, profile_version: 'b'.repeat(64) } })
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(calls.length, priorReads, 'a profile completion after route disposal cannot refresh or apply to a stale Company')
})

it('mounted Companies row opens the shared assignment modal and navigates only to its confirmed pending Company', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, can_maintain: true, can_configure: true, can_sell_new: true, capability_key: 'cap-1' })
  const route = Vue.reactive({ path: '/gestao-erp/empresas', params: {}, query: {} })
  const apiCalls: string[] = []
  const writes: Array<{ path: string, body: unknown }> = []
  const navigations: string[] = []
  let assignmentResult: 'confirmed' | 'unknown' = 'confirmed'
  const pendingCommand = Vue.ref(false)
  const sharedRecoveryKey = Vue.ref<string | null>(null)
  const company = { id: 6, registration_id: 16, client_id: 26, name: 'Empresa AP1 LTDA', cnpj: '12345678000190', registered_at: '2026-10-01T00:00:00Z', owner_setup_status: 'not_started', current_contract_id: null, capabilities: { relationship: 'unassigned', can_edit_profile: true, can_assign: true, can_manage_members: false, capability_key: 'cap-1' } }
  const api = async (path: string) => {
    apiCalls.push(path)
    if (path === '/erp-commercial/companies') return { data: [company], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
    if (path === '/erp-commercial/companies/6') return { data: { id: 6, current_contract_id: null } }
    if (path === '/erp-commercial/enrollment-plans') return { data: [{ id: 71, name: 'Plano AP1', module_keys: ['erp.sales'] }], eligibility: { can_provision: true, reasons: [], licence_limit: 10, used_licences: 9, available_module_keys: ['erp.sales'] } }
    if (path === '/erp-commercial/settings') return { data: { billing_timezone: 'America/Sao_Paulo' } }
    throw new Error(`Unexpected endpoint ${path}`)
  }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key, generation: 1 }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    onScopeDispose: Vue.onScopeDispose,
    refDebounced: (source: { value: string }) => source,
    useRoute: () => route,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: pendingCommand,
      recoveryKey: sharedRecoveryKey,
      execute: async (_action: string, path: string, body: unknown) => {
        writes.push({ path, body })
        if (assignmentResult === 'unknown') {
          pendingCommand.value = true
          sharedRecoveryKey.value = 'assignment-unknown'
          return { status: 'unknown', key: 'assignment-unknown' }
        }
        return { status: 'confirmed', data: { company_id: 6, registration_id: 16, contract_id: 91, subscription_id: 51, allocation_id: 41, agreement_status: 'accepted', licence_status: 'pending', invoice_id: null } }
      },
      recover: async () => {
        pendingCommand.value = false
        sharedRecoveryKey.value = null
        return { status: 'confirmed', data: { company_id: 999, contract_id: 999 } }
      }
    }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (_key: string, init: () => unknown) => Vue.ref(init()),
    navigateTo: async (path: string) => { navigations.push(path) },
    definePageMeta() {}
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} }) },
    '~/composables/useOfficeErpListNavigation': { useOfficeErpListNavigation: (_key: string, initial: unknown) => Vue.ref(initial), officeErpAvailablePage: () => 1 }
  }
  let mobileColumns: string[] = []
  const dataTable = Vue.defineComponent({
    props: ['data', 'mobileColumns'],
    setup(props, { slots }) {
      return () => {
        mobileColumns = props.mobileColumns as string[]
        return Vue.h('div', {}, (props.data as typeof company[]).map(row => Vue.h('article', {}, [
          ...(slots['name-cell']?.({ row: { original: row } }) ?? []),
          ...(slots['actions-cell']?.({ row: { original: row } }) ?? [])
        ])))
      }
    }
  })
  let mobileMenuItems: Array<Array<{ label: string, onSelect: () => unknown }>> = []
  const dropdown = Vue.defineComponent({
    props: ['items'],
    setup(props, { slots }) {
      return () => {
        mobileMenuItems = props.items as Array<Array<{ label: string, onSelect: () => unknown }>>
        return Vue.h('div', { 'data-mobile-actions': 'true' }, slots.default?.())
      }
    }
  })
  let assignmentProps: { open: boolean, companyId: number | null, canAssign: boolean } | null = null
  const assignmentHost = Vue.defineComponent({
    props: ['open', 'companyId', 'companyName', 'canAssign', 'currentContractId'],
    emits: ['update:open', 'assigned'],
    setup(props, { emit }) {
      return () => {
        assignmentProps = { open: Boolean(props.open), companyId: props.companyId as number | null, canAssign: Boolean(props.canAssign) }
        return Vue.h(assignmentComponent, {
          ...props,
          'onUpdate:open': (value: boolean) => emit('update:open', value),
          'onAssigned': (value: { company_id: number, contract_id: number }) => emit('assigned', value)
        })
      }
    }
  })
  const assignmentComponent = compileVueComponent('components/office-erp/OfficeErpCompanyAssignmentModal.vue', bindings, modules)
  const listComponent = compileVueComponent('pages/gestao-erp/empresas/index.vue', bindings, modules)
  const mounted = mountVueComponent(listComponent, { OfficeErpDataTable: dataTable, UDropdownMenu: dropdown, OfficeErpCompanyAssignmentModal: assignmentHost })
  t.after(mounted.unmount)
  for (let index = 0; index < 8; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const buttons = (label: string) => mounted.nodes(node => node.tag === 'button' && String(node.children.map(child => child.text ?? '').join('')).includes(label))
  const rowAssign = buttons('Adicionar assinatura')[0]
  assert.ok(rowAssign, 'the unassigned Company row exposes its can_assign action')
  assert.equal(rowAssign.props.color, 'neutral', 'ordinary subscription assignments use the neutral design-system color')
  assert.equal(rowAssign.props.variant, 'outline')
  assert.match(String(rowAssign.props.onClick), /openAssignment/)
  assert.ok(mobileColumns.includes('actions'), 'row actions remain visible in the compact mobile table')
  const assignmentMenu = mounted.nodes(node => node.props['data-mobile-actions'] === 'true')[0]
  assert.ok(assignmentMenu, 'mobile row actions use the Nuxt UI action menu')
  assert.ok(apiCalls.includes('/erp-commercial/companies'))
  const mobileAssignment = mobileMenuItems.flat().find(item => item.label === 'Adicionar assinatura')
  assert.ok(mobileAssignment, 'the mobile action menu includes the target-bound assignment action')
  ;(rowAssign.props.onClick as () => void)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(assignmentProps, { open: true, companyId: 6, canAssign: true })
  for (let index = 0; index < 8; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.ok(apiCalls.includes('/erp-commercial/enrollment-plans'), apiCalls.join(', '))
  assert.match(mounted.text(), /Empresa AP1 LTDA/)
  assert.match(mounted.text(), /Plano AP1/)
  const priceInput = mounted.nodes(node => node.tag === 'input' && node.props.inputmode === 'decimal')[0]
  assert.ok(priceInput)
  ;(priceInput.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '90,01' } })
  await Vue.nextTick()
  const confirm = buttons('Confirmar assinatura')[0]
  assert.ok(confirm)
  ;(confirm.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 8; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(writes, [{ path: '/erp-commercial/companies/6/assignments', body: { erp_plan_id: 71, price: '90.01', currency: 'BRL', interval_months: 1, due_in_days: 0, start_on: (writes[0]?.body as { start_on: string }).start_on, billing_timezone: 'America/Sao_Paulo' } }])
  assert.deepEqual(navigations, ['/gestao-erp/assinaturas/empresas/6?secao=subscription'])

  assignmentResult = 'unknown'
  ;(rowAssign.props.onClick as () => void)()
  for (let index = 0; index < 8; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(buttons('Cancelar')[0]?.props.disabled, false, 'a retained unknown outcome can be closed and recovered after reopening')
  const retryPrice = mounted.nodes(node => node.tag === 'input' && node.props.inputmode === 'decimal')[0]
  assert.ok(retryPrice)
  ;(retryPrice.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '91,00' } })
  await Vue.nextTick()
  ;(buttons('Confirmar assinatura')[0]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 8; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(writes.length, 2, 'unknown execution is not automatically resent')
  ;(buttons('Cancelar')[0]!.props.onClick as () => void)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  ;(rowAssign.props.onClick as () => void)()
  for (let index = 0; index < 8; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.ok(buttons('Consultar resultado')[0], 'the retained recovery action remains available after closing and reopening the modal')
  assert.notEqual(buttons('Consultar resultado')[0]!.props.disabled, true, 'a retained unknown operation can be looked up even while command.pending is true')
  ;(buttons('Consultar resultado')[0]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 8; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.ok(apiCalls.includes('/erp-commercial/companies/6'), 'recovery verifies the selected Company with a fresh authorized read')
  assert.equal(writes.length, 2, 'recovery performs no assignment write')
  assert.deepEqual(navigations, ['/gestao-erp/assinaturas/empresas/6?secao=subscription'], 'a foreign global receipt cannot navigate to a target that has no linked new contract')
})

it('mounted portfolio registration event refreshes the parent Companies list without navigating away', async (t) => {
  let reloads = 0
  const navigations: string[] = []
  const { registered } = evaluateVueBindings(vueSource('pages/gestao-erp/empresas/index.vue'), ['registered'], {
    loadCompanies: async () => { reloads++ },
    navigateTo: async (path: string) => { navigations.push(path) }
  }) as { registered: (outcome: { created: Array<{ company_id: number }>, unchanged: Array<{ company_id: number }> }) => Promise<void> }
  const portfolioPicker = Vue.defineComponent({
    emits: ['registered'],
    setup(_props, { emit }) {
      return () => Vue.h('button', { onClick: () => emit('registered', { created: [{ company_id: 81 }], unchanged: [] }) }, 'Confirmar registro individual')
    }
  })
  const parent = Vue.defineComponent({
    setup() {
      return () => Vue.h(portfolioPicker, { onRegistered: registered })
    }
  })
  const mounted = mountVueComponent(parent)
  t.after(mounted.unmount)
  const button = mounted.nodes(node => node.tag === 'button' && node.text === 'Confirmar registro individual')[0]
  assert.ok(button)
  ;(button.props.onClick as () => void)()
  await Vue.nextTick()
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(reloads, 1)
  assert.deepEqual(navigations, [], 'registering one Client in the portfolio keeps the picker flow open instead of navigating away')
})

it('mounted Company registration clears drafts and selection on Account switch and ignores the old command result', async (t) => {
  const pending = deferred<unknown>()
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, can_register_company: true, capability_key: 'cap-1' })
  const route = Vue.reactive({ path: '/gestao-erp/empresas/nova', params: {}, query: { modo: 'novo', cliente: '51' } })
  const router = {
    replace: async ({ query: next }: { query: Record<string, unknown> }) => {
      route.query = next
    },
    push: async () => {}
  }
  const submitted: unknown[] = []
  const navigations: string[] = []
  const api = async (path: string) => path.endsWith('/clients')
    ? { data: [{ id: 51, name: 'Cliente A', tax_id: '11222333000181', erp_registration: null }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
    : { data: null }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    useRoute: () => route,
    useRouter: () => router,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false),
      recoveryKey: Vue.ref(null),
      execute: async (_action: string, _path: string, body: unknown) => {
        submitted.push(body)
        return submitted.length === 1 ? pending.promise : { status: 'confirmed', data: { created: [], unchanged: [] } }
      },
      recover: async () => ({ status: 'unknown' })
    }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (_key: string, init: () => unknown) => Vue.ref(init()),
    definePageMeta() {},
    navigateTo: async (path: string) => { navigations.push(path) }
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }) }) },
    '~/composables/useOfficeErpListNavigation': { officeErpAvailablePage: () => 1 }
  }
  const mounted = mountVueComponent(compileVueComponent('pages/gestao-erp/empresas/nova.vue', bindings, modules))
  t.after(mounted.unmount)
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const input = (predicate: (node: { props: Record<string, unknown> }) => boolean) => mounted.nodes(node => node.tag === 'input' && predicate(node))[0]!
  ;(input(node => node.props.placeholder === '00.000.000/0000-00').props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '11222333000181' } })
  ;(input(node => node.props.autocomplete === 'organization').props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Empresa do escritório A' } })
  const form = mounted.nodes(node => node.tag === 'form')[0]!
  ;(form.props.onSubmit as (event: { preventDefault(): void }) => void)({ preventDefault() {} })
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(submitted.length, 1)
  account.value = { id: 9, status: 'active', role: 'admin' }
  await Vue.nextTick()
  assert.equal(input(node => node.props.placeholder === '00.000.000/0000-00').props.value, '')
  assert.equal(input(node => node.props.autocomplete === 'organization').props.value, '')
  assert.equal(route.query.cliente, undefined)
  const nodeText = (node: { text?: string, children: Array<{ text?: string, children: unknown[] }> }): string => `${node.text ?? ''}${node.children.map(child => nodeText(child as { text?: string, children: Array<{ text?: string, children: unknown[] }> })).join('')}`
  const portfolioButton = mounted.nodes(node => node.tag === 'button' && nodeText(node).includes('Selecionar da carteira'))[0]!
  ;(portfolioButton.props.onClick as () => void)()
  await Vue.nextTick()
  assert.match(mounted.text(), /0 de 100 selecionados/)
  pending.resolve({ status: 'confirmed', data: { created: [{ company_id: 81 }], unchanged: [] } })
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(navigations, [])
  assert.doesNotMatch(mounted.text(), /empresa registrada/)
  const clientCheckbox = mounted.nodes(node => node.tag === 'button' && node.props['aria-label'] === 'Selecionar Cliente A')[0]!
  ;(clientCheckbox.props.onClick as () => void)()
  await Vue.nextTick()
  const registerButton = mounted.nodes(node => node.tag === 'button' && nodeText(node).includes('Registrar selecionados'))[0]!
  ;(registerButton.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(submitted[1], { client_ids: [51] })
  assert.equal(account.value.id, 9)
})

it('mounted member editor hides Owner mutations, strips Viewer write permissions, and confirms removal', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, can_maintain: true, capability_key: 'cap-1' })
  const route = Vue.reactive({ path: '/gestao-erp/assinaturas/empresas/41/membros', params: { companyId: '41' } })
  const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
  const writes: Array<{ action: string, path: string, body: unknown, method: string }> = []
  const members = [
    { kind: 'membership', id: 21, user: { id: 3, name: 'Proprietária', email: 'owner@example.test' }, access_auth_revision: 1, role: 'owner', status: 'active', establishment_scope: 'all', establishment_ids: [], action_permissions: [], effective_permissions: [] },
    { kind: 'membership', id: 22, user: { id: 2, name: 'Leitora', email: 'reader@example.test' }, access_auth_revision: 1, role: 'viewer', status: 'active', establishment_scope: 'all', establishment_ids: [], action_permissions: ['read_companies', 'write_members'], effective_permissions: ['read_companies'] },
    { kind: 'membership', id: 23, user: { id: 1, name: 'Conta atual', email: 'self@example.test' }, access_auth_revision: 1, role: 'operator', status: 'active', establishment_scope: 'all', establishment_ids: [], action_permissions: [], effective_permissions: [] },
    { kind: 'membership', id: 25, user: { id: 4, name: 'Gerente padrão', email: 'manager@example.test' }, access_auth_revision: 1, role: 'manager', status: 'active', establishment_scope: 'all', establishment_ids: [], action_permissions: null, effective_permissions: ['read_companies'] },
    { kind: 'membership', id: 26, user: { id: 5, name: 'Operador legado', email: 'operator@example.test' }, access_auth_revision: 1, role: 'operator', status: 'active', establishment_scope: 'all', establishment_ids: [], action_permissions: [], effective_permissions: ['read_companies'] },
    { kind: 'invitation', id: 24, name: 'Convidada pendente', email: 'pending@example.test', role: 'manager', permissions_mode: 'defaults', permissions: [], status: 'pending', expires_at: '', establishment_scope: 'all', establishment_ids: [] }
  ]
  const options = { permissions: [{ permission: 'read_companies', label: 'Consultar empresas', write: false }, { permission: 'write_members', label: 'Editar usuários', write: true }], roles: [{ role: 'owner', default_permissions: [] }, { role: 'manager', default_permissions: [] }, { role: 'operator', default_permissions: [] }, { role: 'viewer', default_permissions: ['read_companies'] }], establishments: [{ id: 8, name: 'Matriz', cnpj: '123', status: 'active' }] }
  const api = async (path: string, request?: Record<string, unknown>) => {
    calls.push({ path, options: request })
    if (path.endsWith('/member-options')) return { data: options }
    if (path.endsWith('/access-deliveries/88')) return { data: { id: 88, state: 'transport_accepted', expires_at: '', authority_invalidated_at: null } }
    if (path.endsWith('/members')) return { data: members, meta: { current_page: 1, last_page: 1, per_page: 25, total: members.length } }
    throw new Error(`Unexpected endpoint ${path}`)
  }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({
      pending: Vue.ref(false),
      recoveryKey: Vue.ref(null),
      execute: async (action: string, path: string, body: unknown, method: string) => {
        writes.push({ action, path, body, method })
        return action === 'member.invitation.issue' || action === 'member.invitation.reissue'
          ? { status: 'confirmed', data: { company_id: 41, delivery: { id: 88, state: 'prepared' } } }
          : { status: 'confirmed', data: { status: 'removed' } }
      },
      recover: async () => ({ status: 'unknown' })
    }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (key: string, init: () => unknown) => key === 'access-session-generation' ? Vue.ref(0) : Vue.ref(init()),
    useRoute: () => route,
    definePageMeta() {}
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }) }) }
  }
  const mounted = mountVueComponent(compileVueComponent('pages/gestao-erp/assinaturas/empresas/[companyId]/membros.vue', bindings, modules))
  t.after(mounted.unmount)
  for (let index = 0; index < 6; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const renderText = (node: { text?: string, children: Array<{ text?: string, children: unknown[] }> }): string => `${node.text ?? ''}${node.children.map(child => renderText(child as { text?: string, children: Array<{ text?: string, children: unknown[] }> })).join('')}`
  const buttons = (label: string) => mounted.nodes(node => node.tag === 'button' && renderText(node).includes(label))
  assert.equal(buttons('Editar acesso').length, 5)
  assert.equal(buttons('Recuperar acesso').length, 5, 'credential recovery remains available for Owner and self memberships when allowed by target policy')
  assert.ok(buttons('Recuperar acesso').some(button => button.props.to === '/gestao-erp/assinaturas/empresas/41/membros/21/recuperar'))
  assert.ok(buttons('Recuperar acesso').some(button => button.props.to === '/gestao-erp/assinaturas/empresas/41/membros/23/recuperar'))
  assert.equal(buttons('Editar acesso')[0]!.props.disabled, true, 'Owner editing is disabled')
  assert.equal(buttons('Editar acesso')[2]!.props.disabled, true, 'self editing is disabled')
  assert.equal(buttons('Remover acesso').length, 4, 'Owner removal is absent')
  assert.equal(buttons('Remover acesso')[1]!.props.disabled, true, 'self removal is disabled')
  ;(buttons('Editar acesso')[1]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const roleSelect = mounted.nodes(node => node.tag === 'select' && renderText(node).includes('Gerente'))[0]
  assert.ok(roleSelect)
  assert.doesNotMatch(renderText(roleSelect), /Proprietário/)
  const permissions = mounted.nodes(node => node.tag === 'fieldset' && typeof node.props['data-items'] === 'string')[0]
  assert.ok(permissions)
  assert.match(String(permissions.props['data-items']), /Consultar empresas/)
  assert.doesNotMatch(String(permissions.props['data-items']), /Editar usuários/)

  ;(buttons('Editar acesso')[3]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const defaultsSelect = mounted.nodes(node => node.tag === 'select' && renderText(node).includes('Padrões do papel'))[0]
  assert.ok(defaultsSelect)
  assert.equal(defaultsSelect.props.value, 'defaults')
  ;(buttons('Salvar configuração')[0]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.ok(writes.some(write => write.path.endsWith('/members/25') && write.method === 'PATCH' && JSON.stringify(write.body) === JSON.stringify({ role: 'manager', permissions_mode: 'defaults', action_permissions: null, establishment_scope: 'all', establishment_ids: [] })))
  ;(buttons('Editar acesso')[4]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(defaultsSelect.props.value, 'defaults', 'legacy empty grants retain role defaults')
  ;(buttons('Salvar configuração')[0]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.ok(writes.some(write => write.path.endsWith('/members/26') && write.method === 'PATCH' && JSON.stringify(write.body) === JSON.stringify({ role: 'operator', permissions_mode: 'defaults', action_permissions: null, establishment_scope: 'all', establishment_ids: [] })))

  ;(buttons('Convidar usuário')[0]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 4; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const inviteName = mounted.nodes(node => node.tag === 'input' && node.props.autocomplete === 'name')[0]
  const inviteEmail = mounted.nodes(node => node.tag === 'input' && node.props.type === 'email')[0]
  ;(inviteName.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Pessoa convidada' } })
  ;(inviteEmail.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'new@example.test' } })
  await Vue.nextTick()
  ;(buttons('Preparar convite')[0]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.match(mounted.text(), /Convite preparado para entrega/)
  assert.doesNotMatch(mounted.text(), /Convite enviado|e-mail enviado/i)
  ;(buttons('Consultar estado')[0]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.ok(calls.some(call => call.path === '/erp-commercial/companies/41/access-deliveries/88'))
  assert.match(mounted.text(), /Aceita pelo serviço de e-mail; entrega final não confirmada/)

  const reissueButton = buttons('Reenviar convite')[0]
  assert.ok(reissueButton)
  ;(reissueButton.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.ok(writes.some(write => write.action === 'member.invitation.reissue' && write.path.endsWith('/member-invitations/24/reissue') && JSON.stringify(write.body) === '{}'))

  ;(buttons('Remover acesso')[0]!.props.onClick as () => void)()
  await Vue.nextTick()
  ;(buttons('Confirmar')[0]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.deepEqual(writes.slice(-1), [{ action: 'member.remove', path: '/erp-commercial/companies/41/members/22', body: { confirmed: true }, method: 'DELETE' }])
  const invitationAction = writes.find(write => write.action === 'member.invitation.issue')
  assert.deepEqual(invitationAction, { action: 'member.invitation.issue', path: '/erp-commercial/companies/41/member-invitations', body: { name: 'Pessoa convidada', email: 'new@example.test', role: 'operator', permissions_mode: 'defaults', permissions: [], establishment_scope: 'all', establishment_ids: [] }, method: 'POST' })
  assert.ok(writes.some(write => write.action === 'member.invitation.reissue' && write.body && Object.keys(write.body).length === 0))
  ;(buttons('Revogar convite')[0]!.props.onClick as () => void)()
  await Vue.nextTick()
  ;(buttons('Confirmar')[0]!.props.onClick as () => Promise<void>)()
  for (let index = 0; index < 4; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.ok(writes.some(write => write.action === 'member.invitation.revoke' && write.path.endsWith('/member-invitations/24/revoke') && write.body && Object.keys(write.body).length === 0))
})

it('mounted member editor ignores delayed Company A options after an Account switch and opens the new context', async (t) => {
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, can_maintain: true, capability_key: 'cap-a' })
  const route = Vue.reactive({ path: '/gestao-erp/assinaturas/empresas/41/membros', params: { companyId: '41' } })
  const delayedOptions = deferred<unknown>()
  let optionReads = 0
  const member = { kind: 'membership', id: 25, user: { id: 4, name: 'Gerente', email: 'manager@example.test' }, access_auth_revision: 1, role: 'manager', status: 'active', establishment_scope: 'all', establishment_ids: [], action_permissions: null, effective_permissions: ['read_companies'] }
  const options = { permissions: [{ permission: 'read_companies', label: 'Consultar empresas', write: false }], roles: [{ role: 'owner', default_permissions: [] }, { role: 'manager', default_permissions: [] }, { role: 'operator', default_permissions: [] }, { role: 'viewer', default_permissions: ['read_companies'] }], establishments: [] }
  const api = async (path: string) => {
    if (path.endsWith('/member-options')) {
      optionReads++
      if (optionReads === 1) throw new Error('initial catalog unavailable')
      if (optionReads === 2) return delayedOptions.promise
      return { data: options }
    }
    if (path.endsWith('/members')) return { data: [member], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
    throw new Error(`Unexpected endpoint ${path}`)
  }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({ pending: Vue.ref(false), recoveryKey: Vue.ref(null), execute: async () => ({ status: 'unknown' }), recover: async () => ({ status: 'unknown' }) }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (_key: string, init: () => unknown) => Vue.ref(init()),
    useRoute: () => route,
    definePageMeta() {}
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }) }) }
  }
  const mounted = mountVueComponent(compileVueComponent('pages/gestao-erp/assinaturas/empresas/[companyId]/membros.vue', bindings, modules))
  t.after(mounted.unmount)
  for (let index = 0; index < 6; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const renderText = (node: { text?: string, children: Array<{ text?: string, children: unknown[] }> }): string => `${node.text ?? ''}${node.children.map(child => renderText(child as { text?: string, children: Array<{ text?: string, children: unknown[] }> })).join('')}`
  const editButton = () => mounted.nodes(node => node.tag === 'button' && renderText(node).includes('Editar acesso'))[0]!
  ;(editButton().props.onClick as () => Promise<void>)()
  await Vue.nextTick()
  assert.equal(optionReads, 2)
  account.value = { id: 9, status: 'active', role: 'admin' }
  capabilities.value = { can_read: true, can_maintain: true, capability_key: 'cap-b' }
  for (let index = 0; index < 6; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  delayedOptions.resolve({ data: options })
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const roleSelect = () => mounted.nodes(node => node.tag === 'select' && renderText(node).includes('Gerente'))[0]!
  assert.equal(roleSelect().props.value, 'operator', 'Company A completion cannot repopulate the editor after context switch')
  ;(editButton().props.onClick as () => Promise<void>)()
  for (let index = 0; index < 4; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.equal(roleSelect().props.value, 'manager', 'the next editor can open with the newly loaded context')
})

it('mounted member list yields to the credential child and ignores a delayed editor completion', async (t) => {
  const delayedOptions = deferred<unknown>()
  const user = Vue.ref({ id: 1 })
  const account = Vue.ref({ id: 8, status: 'active', role: 'admin' })
  const capabilities = Vue.ref({ can_read: true, can_maintain: true, capability_key: 'cap-1' })
  const route = Vue.reactive({ path: '/gestao-erp/assinaturas/empresas/41/membros', params: { companyId: '41' }, query: {} })
  const calls: string[] = []
  let optionReads = 0
  const member = { kind: 'membership', id: 25, user: { id: 4, name: 'Gerente', email: 'manager@example.test' }, access_auth_revision: 1, role: 'manager', status: 'active', establishment_scope: 'all', establishment_ids: [], action_permissions: null, effective_permissions: [] }
  const api = async (path: string) => {
    calls.push(path)
    if (path.endsWith('/member-options')) {
      optionReads++
      if (optionReads === 1) throw new Error('initial options failure')
      return delayedOptions.promise
    }
    if (path.endsWith('/members')) return { data: [member], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
    throw new Error(`Unexpected endpoint ${path}`)
  }
  const context = { capabilities, refresh: async () => {}, capture: () => ({ key: capabilities.value.capability_key }), isCurrent: (token: { key: string }) => token.key === capabilities.value.capability_key }
  const bindings = {
    onMounted: Vue.onMounted,
    onBeforeUnmount: Vue.onBeforeUnmount,
    useRoute: () => route,
    useAccessApi: () => ({ api }),
    useOfficeErpContext: () => context,
    useOfficeErpCommand: () => ({ pending: Vue.ref(false), recoveryKey: Vue.ref(null), execute: async () => ({ status: 'unknown' }), recover: async () => ({ status: 'unknown' }) }),
    useAuth: () => ({ user, currentAccount: account }),
    useState: (_key: string, init: () => unknown) => Vue.ref(init()),
    definePageMeta() {}
  }
  const modules = {
    '~/composables/useApiError': { apiErrorMessage: () => null },
    '~/composables/useOfficeErpReadIdentity': { useOfficeErpReadIdentity: (getIdentity: () => unknown, getCapability?: () => unknown) => Vue.computed(() => JSON.stringify([getIdentity(), getCapability?.()])) },
    '~/composables/useOfficeErpReads': { useOfficeErpReads: () => ({ begin: () => ({ current: () => true, signal: undefined }), cancel() {} }) }
  }
  const recoveryPage = Vue.defineComponent({ setup: () => () => Vue.h('p', {}, 'Recuperação do usuário selecionado') })
  const mounted = mountVueComponent(compileVueComponent('pages/gestao-erp/assinaturas/empresas/[companyId]/membros.vue', bindings, modules), { NuxtPage: recoveryPage })
  t.after(mounted.unmount)
  for (let index = 0; index < 7; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  const edit = mounted.nodes(node => node.tag === 'button' && node.children.some(child => child.text?.includes('Editar acesso')))[0]
  assert.ok(edit)
  const openingEditor = (edit.props.onClick as () => Promise<void>)()
  await Vue.nextTick()
  assert.equal(optionReads, 2)
  route.path = '/gestao-erp/assinaturas/empresas/41/membros/25/recuperar'
  await Vue.nextTick()
  delayedOptions.resolve({ data: { permissions: [], roles: [], establishments: [] } })
  await openingEditor
  for (let index = 0; index < 5; index++) {
    await Vue.nextTick()
    await new Promise(resolve => setImmediate(resolve))
  }
  assert.match(mounted.text(), /Recuperação do usuário selecionado/)
  assert.doesNotMatch(mounted.text(), /Usuários e acessos|Gerente|Convidar usuário|Salvar configuração/)
  assert.equal(calls.filter(path => path.endsWith('/members')).length, 1, 'the member list does not restart beneath the child route')
})
