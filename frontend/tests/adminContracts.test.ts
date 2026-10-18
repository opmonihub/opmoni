import assert from 'node:assert/strict'
import { it } from 'node:test'
import type { AccountListResponse, AdminSummary, DetailResponse, Plan, ExtendedPlatformAccount, PlatformUser, ResourceListResponse, Subscription, SupportAccessLog } from '../shared/types/admin'
import { readPaginatedMeta } from '../app/utils/adminListFilters.ts'

const plan: Plan = { id: 1, slug: 'basico', name: 'Básico', limits: { users: null, clients: 0, monitorings: null } }
const subscription: Subscription = { id: 1, status: 'canceled', account: { id: 7, name: 'Office' }, plan }
const account: ExtendedPlatformAccount = { id: 7, name: 'Office', status: 'active', members_count: 1, pending_owner: null, phone: null, phone_whatsapp: false, state: null, city: null, subscription }
const accounts: AccountListResponse = { data: [account], meta: { page: 2, last_page: 3, per_page: 15, total: 31 } }
const user: PlatformUser = { id: 1, name: 'User', email: 'user@example.test', is_super_admin: true, account_links: [{ role: 'user', account }] }
const users: ResourceListResponse<PlatformUser> = { data: [user], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
const log: SupportAccessLog = { id: 1, action: 'update', created_at: '2026-10-17T12:00:00Z', account, metadata: { resource: 'clients', resource_id: 7 } }
const summary: DetailResponse<AdminSummary> = { data: { totals: { accounts: 31, users: 1, subscriptions: 1, plans: 1 }, recent_support_logs: [log] } }
it('preserves raw plans and both real pagination envelopes', () => {
  assert.equal(readPaginatedMeta(accounts).current_page, 2)
  assert.equal(readPaginatedMeta(users).current_page, 1)
  assert.equal(Array.isArray([plan]), true)
  assert.equal(summary.data.recent_support_logs[0]?.metadata?.resource_id, 7)
  assert.equal(account.subscription?.status, 'canceled')
  assert.equal(account.pending_owner, null)
})
it('no-office Super Admin keeps all Admin destinations on focus; lost privilege uses ordinary access', async () => {
  const { focusAccessDestination } = await import('../shared/utils/access.ts')
  const identity = { id: 1, name: 'Admin', email: 'admin@example.test', is_super_admin: true, accounts: [], current_account: null, companies: [], current_company: null }
  for (const route of ['/admin', '/admin/contas', '/admin/planos', '/admin/assinaturas', '/admin/usuarios', '/admin/suporte', '/admin/serpro', '/admin/erp/planos', '/admin/erp/assinaturas']) {
    assert.equal(focusAccessDestination(identity, route), null)
    assert.equal(focusAccessDestination({ ...identity, is_super_admin: false }, route), '/acesso-indisponivel')
  }
  assert.equal(focusAccessDestination(identity, '/fiscal'), '/acesso-indisponivel')
  assert.equal(focusAccessDestination({ ...identity, is_super_admin: false }, '/acesso-indisponivel'), null)
})
