## Why

The office needs to register an ERP customer or select an existing portfolio client and assign a subscription. The delivered workspace instead requires a published retail offer and owner invitation, exposes resale restrictions as page errors, and has no usable seven-day ERP trial. This proposal centers administration on customers and subscriptions and makes self-service evaluation attributable to the office that shared the link.

## What Changes

- Add direct ERP customer enrollment from a new company-client registration or an active client already in the selected office portfolio, with technical ERP plan selection and explicit subscription terms. Published retail offers and owner invitation acceptance are not prerequisites to assigning a subscription.
- Separate Company provisioning and commercial administration from user access. New office-created Companies may await verified owner setup; no fictitious user, overwritten password, automatic office ownership, or automatic accounting delegation is permitted. Existing Companies require current-owner authorization before association.
- Add an office-specific, revocable share link for ERP self-registration. Successful registration creates or explicitly reuses that office's portfolio client, establishes the authenticated owner/Company, and starts exactly seven days of ERP-only trial access atomically.
- Provide visible trial dates and explicit paid conversion on the same Company/subscription. No trial invoice, automatic collection or automatic paid conversion occurs; expiration blocks operating writes while preserving authorized reads and data.
- **BREAKING:** Replace invitation-centric primary navigation with customer/subscription administration and defer the Offers entry. Preserve existing offer APIs, issued invitation continuations, accepted contracts, invoices, receipts and operation recovery.
- **BREAKING:** Decouple core customer administration and commercial-policy configuration from the legacy retail-offer resale flag. Keep active Account, real admin membership, office-subscription eligibility, explicit licence/module limits and Company access guards; never interpret zero capacity as unlimited.
- Present business restrictions as actionable eligibility states instead of retryable service failures, and remove the duplicate selected-office name from the ERP workspace header.

## Capabilities

### New Capabilities

- `erp-office-trials`: Office-attributed share links, atomic self-registration, seven-day ERP-only eligibility, expiry and explicit conversion without repeated trials or automatic billing.

### Modified Capabilities

- `office-erp-commercial-management`: Direct customer enrollment, subscription/owner-access separation, bounded allocation across administrative and trial paths, configuration eligibility, and customer-first workspace navigation.
- `erp-commercial-access`: Explicit trial provisioning and operating-write entitlement, administrative activation without mandatory invitation acceptance, and preserved legacy registration/access contracts.
- `erp-company-foundation`: Represent office-provisioned Companies awaiting verified owner setup without granting implicit operating access or changing existing ownership/delegation rules.
- `client-portfolio`: Reuse portfolio clients and add narrowly scoped office-link registration without exposing public portfolio CRUD or silently overwriting client records.

## Impact

- Laravel: reuse `ClientManager`, `ErpCommercialRegistration`, `ErpCompanyProvisioning`, `ErpCommercialGate`, `OfficeErpAdmission`, allocation/operation services and existing contract/invoice lifecycle; add small enrollment, trial and share-link services/controllers and additive schema.
- Nuxt: reuse `/gestao-erp/clientes`, `/gestao-erp/clientes/novo`, subscription/configuration pages, established forms and command recovery; add `/erp/testar/[code]` on the current origin, without requiring office membership for public signup.
- Data: preserve existing contracts and immutable financial history. Add administrative-origin attribution, owner-setup state, share-link configuration and immutable trial timestamps/eligibility. Keep one current subscription per Company and one occupied licence for each licensed Company.
- Tests: cover actual routes and rendered SFC handlers, fresh-session recovery, exact expiration, provider failure, unauthorized/foreign association and independent PostgreSQL last-slot/CNPJ/conversion races.
- Dependencies: no new runtime library, payment gateway, messaging provider or fiscal service. Continue using Laravel/Nuxt, PostgreSQL/Redis and existing Nuxt UI components.

### Superseded behavior and preserved decisions

This change explicitly supersedes the mandatory invitation/published-offer onboarding and no-free-trial clauses in the current commercial specs. It preserves D009's real-membership Account boundary, Company ownership, explicit delegation, exact-money accounting and existing financial history. Fiscal/SERPRO, provider-dispatch, registration-release and operating-release gates remain controlled; proposing a trial does not flip any deployed environment flag.

### Scope and planning assumptions

The user approved new-client registration, portfolio selection, seven-day ERP-only self-service trial, office-attributed links, postponing Offers, configuration feedback/eligibility correction and header simplification. Trial time starts at committed successful registration, lasts 168 hours, and expires inclusively at its end; expired trials retain read access and data. One trial is available per normalized Company CNPJ globally; changing offices or recreating a link cannot restart it. These concrete defaults are reviewable design choices, not claims about existing implementation.

Paid terms are supplied explicitly by the office; no price is invented. Offices manage subscriptions but gain no Company operating access from the commercial relationship alone. Trial and paid enrollment retain centrally configured licence and module ceilings; zero capacity requires platform configuration rather than an automatic quota increase.

### Non-goals

A retail-offer redesign, automatic email delivery, checkout/bank/Pix integration, production fiscal enablement, automatic office delegation, arbitrary reassignment of another Company's ownership, multiple offices sharing the same current licence, existing-data deletion and production rollout are outside this change. No implementation is authorized by this planning workflow.
