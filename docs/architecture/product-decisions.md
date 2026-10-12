# Product decisions

Started on 2026-10-12.

## Review process

Present one part of the product at a time for user review. Record acceptance or requested changes before presenting the next part. A proposal remains pending until the user explicitly accepts it. Agreement on one part does not approve the remaining product design or implementation.

## Confirmed decisions

### D001 — Fiscal capture purpose

Capture NF-e and CT-e XML documents using each customer's A1 certificate, through a microservice integrating NFePHP.

### D002 — Fiscal service location

Place the PHP service in `services/fiscal/`, with the Nuxt interface in `frontend/app/` and its server integration in `frontend/server/`. Composer owns the PHP dependencies. Certificates and XML documents use persistent Docker volumes outside Git.

See [Fiscal service structure](fiscal-service.md) for the recorded directory layout.

### D003 — Incremental product review

Review and accept each part separately, keeping confirmed decisions and pending proposals distinct in this document.

### D004 — First release scope

Accepted by the user on 2026-10-12:

- Access and Account membership to isolate each accounting office's data.
- Clients: registration, client details, and A1 certificate management.
- Fiscal: capture overview, capture status by client, document listing, details, and XML download for NF-e and CT-e.

Monitoring/SERPRO, Work, WhatsApp, and commercial plan/subscription management are deferred.

### D005 — Application backend (superseded by D006)

Accepted by the user on 2026-10-12: implement access and application data in the Nuxt `frontend/server/` directory. PHP is limited to the separate `services/fiscal/` microservice. Do not introduce a Laravel `backend/` into this project.

### D006 — Separate frontend and Laravel backend

Accepted by the user on 2026-10-12 after D005: move the complete Nuxt application to `frontend/` and initialize a Laravel 13 template in `backend/`, without frontend scaffolding. This supersedes the prohibition on a Laravel backend in D005. The existing Nuxt sample handlers remain in `frontend/server/`; no application API migration or authentication integration is implemented by this initialization. The fiscal service remains separately planned under `services/fiscal/`.

## Reference context

The source review of `/home/obsidian/dev/legacy` identified an accounting-office product organized around Accounts and client portfolios, with Fiscal, Monitoring, Work, Team, Settings, and global administration modules. This is reference context, not approval to reproduce every module. Runtime behavior was not verified during that review.

## Pending proposals

### P002 — Access and Accounts foundation

Status: requires architecture revision for D006 before review or apply. Original proposal: [add-access-accounts-foundation](../../openspec/changes/add-access-accounts-foundation/proposal.md). Detailed pages, API behavior, persistence, and authorization differences remain pending acceptance.

Use the legacy's access, Account membership, roles, provisioning, and page flows as reference, adapting implementation to the Nuxt frontend and Laravel backend (D006) and explicitly documenting proposed corrections. This proposal does not implement the remaining Clients or Fiscal pages. Capture scheduling and manifestation policy require later decisions.
