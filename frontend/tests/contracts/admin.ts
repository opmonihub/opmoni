// Compiled by pnpm typecheck. @ts-expect-error assertions must keep failing to type-check.
import type { AccountListResponse, AccountIssueResponse, AccountReissueResponse, AccountCommercialDetail, AdminSummary, DetailResponse, ExtendedPlatformAccount, Plan, PlatformUser, ResourceListResponse, Subscription, SupportAccessLog, SupportLogMetadata } from '../../shared/types/admin'
import type { Identity, Membership, CompanyMembership } from '../../shared/types/access'
import type { SerproConnectionMetadata, SerproConnectionPayload, SerproContractingCertificateMetadata, SerproConnectivityResult } from '../../app/types/serpro'

const plan: Plan = { id: 1, slug: 'basic', name: 'Basic', limits: { users: null, clients: 0, monitorings: 2 } }
const plans: Plan[] = [plan]
const subscription: Subscription = { id: 2, status: 'active', plan, account: { id: 27, name: 'Office' } }
const account: ExtendedPlatformAccount = { id: 27, name: 'Office', status: 'active', members_count: 1, pending_owner: null, phone: null, phone_whatsapp: false, state: null, city: null, subscription }
const descriptor: SupportLogMetadata = { resource: 'account_certificates', resource_id: 2, name: 'Public name', email: 'public@example.test', document: null, role: 'user', status: 'active', person_type: null, action: 'update', client_id: 0, certificate_id: null, previous_run_id: 0, count: 0, clients: 0, tags: 0, enabled: false, power_refresh_queued: true }
const log: SupportAccessLog = { id: 2, action: 'update', ip: null, metadata: descriptor, created_at: '2026-10-17', account, super_admin: { id: 1, name: 'Admin', email: 'admin@example.test' } }
const accounts: AccountListResponse = { data: [account], meta: { page: 2, last_page: 3, per_page: 15, total: 31 } }
const user: PlatformUser = { id: 1, name: 'User', email: 'user@example.test', is_super_admin: true, account_links: [{ role: 'user', account }] }
const users: ResourceListResponse<PlatformUser> = { data: [user], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }
const subscriptions: ResourceListResponse<Subscription> = { ...users, data: [subscription] }
const logs: ResourceListResponse<SupportAccessLog> = { ...users, data: [log] }
const detail: DetailResponse<ExtendedPlatformAccount> = { data: account }
const commercial: DetailResponse<AccountCommercialDetail> = { data: { id: 27, name: 'Office', status: 'active', platform_billing_contact: { phone: null, phone_whatsapp: false, state: null, city: null }, subscription } }
const summary: DetailResponse<AdminSummary> = { data: { totals: { accounts: 31, users: 1, subscriptions: 1, plans: 1 }, recent_support_logs: [log] } }
const created: AccountIssueResponse = { data: account, first_access: { url: 'https://example.test/first-access', expires_at: '2026-10-18' } }
const reissued: AccountReissueResponse = { first_access: created.first_access }
const membership: Membership = { id: 27, name: 'Office', status: 'active', role: 'user' }
const company: CompanyMembership = { id: 3, name: 'Company', status: 'active', access_kind: 'delegated', role: 'operator', permissions: ['read'], delegated_office_id: 27 }
const identity: Identity = { id: 1, name: 'Admin', email: 'admin@example.test', is_super_admin: true, accounts: [membership], current_account: membership, companies: [company], current_company: company }
const metadata: SerproConnectionMetadata = { configured: true, consumer_key_hint: 'masked', certificate_source: 'account', contracting_account_id: 27, legacy_account_certificate: false, contracting_document: null, certificate_subject: null, certificate_serial: null, certificate_not_before: null, certificate_not_after: null, updated_at: null }
const source: SerproContractingCertificateMetadata = { configured: false, revision: null, document: null, subject: null, serial: null, not_before: null, not_after: null, updated_at: null }
const upload: SerproConnectionPayload = { consumer_key: 'write-only-dummy', certificate_source: 'upload', password: '' }
const selected: SerproConnectionPayload = { consumer_key: '', certificate_source: 'account', contracting_account_id: 27 }
const diagnostic: SerproConnectivityResult = { ok: false, failed_element: 'certificado', message: null, checked_at: '2026-10-17' }
void [plan, plans, commercial, accounts, users, subscriptions, logs, detail, summary, created, reissued, identity, metadata, source, upload, selected, diagnostic]

// @ts-expect-error Resource pagination cannot replace foundational Account meta.page.
const badAccountPage: AccountListResponse = { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
// @ts-expect-error Resource pages require current_page.
const badResourcePage: ResourceListResponse<PlatformUser> = { data: [], meta: { page: 1, last_page: 1, per_page: 15, total: 0 } }
// @ts-expect-error Plans retain the raw array contract.
const badPlans: Plan[] = { data: [plan] }
// @ts-expect-error Creation includes first_access alongside data.
const badCreate: AccountIssueResponse = { data: account }
// @ts-expect-error Reissue is not an Account detail envelope.
const badReissue: AccountReissueResponse = { data: account, first_access: created.first_access }
// @ts-expect-error Safe Account resources never contain private settings.
const badAccount: ExtendedPlatformAccount = { ...account, settings: {} }
// @ts-expect-error Real membership is mandatory; a support role is not a membership.
const badRole: Membership = { ...membership, role: 'support' }
// @ts-expect-error Identity remains top-level and has no foreign support context.
const badIdentity: Identity = { ...identity, support_context: { account_id: 5 } }
// @ts-expect-error No effective support elevation in Identity.
const badEffectiveRole: Identity = { ...identity, effective_role: 'admin' }
// @ts-expect-error ERP company access remains required in Identity.
const missingCompanies: Identity = { id: 1, name: 'User', email: 'user@example.test', is_super_admin: false, accounts: [], current_account: null }
// @ts-expect-error Session identifiers are never a public Identity projection.
const badSession: Identity = { ...identity, session_id: 'dummy' }
// @ts-expect-error Only writer-emitted resource names are public.
const badResource: SupportLogMetadata = { resource: 'account' }
// @ts-expect-error Sanitizer rejects nested containers, including known fields.
const badNested: SupportLogMetadata = { name: { nested: 'value' } }
// @ts-expect-error Sanitizer rejects arbitrary descriptors.
const badDescriptor: SupportLogMetadata = { arbitrary: true }
// @ts-expect-error Raw credentials cannot appear in log descriptors.
const badLogSecret: SupportLogMetadata = { consumer_secret: 'dummy' }
// @ts-expect-error Log counts cannot contain nested data.
const badCount: SupportLogMetadata = { clients: [1] }
// @ts-expect-error Public connection metadata never includes a complete key.
const badKey: SerproConnectionMetadata = { ...metadata, consumer_key: 'dummy' }
// @ts-expect-error Public connection metadata never includes secrets.
const badSecret: SerproConnectionMetadata = { ...metadata, consumer_secret: 'dummy' }
// @ts-expect-error Public connection metadata never includes passwords.
const badPassword: SerproConnectionMetadata = { ...metadata, password: '' }
// @ts-expect-error Public connection metadata never includes certificate material.
const badCertificate: SerproConnectionMetadata = { ...metadata, certificate: 'dummy' }
// @ts-expect-error Platform vault metadata excludes private paths.
const badSourcePath: SerproContractingCertificateMetadata = { ...source, path: 'dummy' }
// @ts-expect-error Platform vault metadata excludes certificate material.
const badSourceBytes: SerproContractingCertificateMetadata = { ...source, pkcs12: 'dummy' }
// @ts-expect-error Platform vault metadata excludes passwords.
const badSourcePassword: SerproContractingCertificateMetadata = { ...source, password: '' }
// @ts-expect-error The source selector is explicit and named upload/account.
const badSource: SerproConnectionPayload = { consumer_key: '', certificate_source: 'file' }
// @ts-expect-error Diagnostic verdict has exactly four failure elements.
const badVerdict: SerproConnectivityResult = { ...diagnostic, failed_element: 'network' }
void [badAccountPage, badResourcePage, badPlans, badCreate, badReissue, badAccount, badRole, badIdentity, badEffectiveRole, missingCompanies, badSession, badResource, badNested, badDescriptor, badLogSecret, badCount, badKey, badSecret, badPassword, badCertificate, badSourcePath, badSourceBytes, badSourcePassword, badSource, badVerdict]
// @ts-expect-error Raw keys never enter audit descriptors.
const badLogKey: SupportLogMetadata = { consumer_key: 'dummy' }
// @ts-expect-error Passwords never enter audit descriptors.
const badLogPassword: SupportLogMetadata = { password: '' }
// @ts-expect-error Certificate containers never enter audit descriptors.
const badLogCertificate: SupportLogMetadata = { certificate: 'dummy' }
// @ts-expect-error Session identifiers never enter audit descriptors.
const badLogSession: SupportLogMetadata = { session_id: 'dummy' }
void [badLogKey, badLogPassword, badLogCertificate, badLogSession]
