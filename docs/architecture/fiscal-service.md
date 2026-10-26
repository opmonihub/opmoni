# Fiscal service structure

Recorded on 2026-10-12; implementation status reconciled on 2026-10-13. The directory structure below remains an approved extraction target. The separate service implementation and detailed API design remain pending.

## Current integrated implementation

D009 activated the legacy fiscal logic inside the Laravel application. `backend/app/Services/Fiscal/` owns capture, provider transports, reconciliation, and manifestation; Laravel jobs and the Compose queue/scheduler execute these operations. The Nuxt interface uses the private `/api/v1` proxy rather than an implemented standalone fiscal service API.

Private disks currently point to `backend/storage/app/private/certificates` and `backend/storage/app/private/fiscal`; client containers/passwords are encrypted and XML download authorizes the owning Account before private disk access. Local Compose preserves these paths through its backend source bind mount. Dedicated production volumes/persistence and the future service boundary are not proved by that mount.

See [fiscal-documents](../../openspec/specs/fiscal-documents/spec.md), [client-certificates](../../openspec/specs/client-certificates/spec.md), [application-runtime](../../openspec/specs/application-runtime/spec.md), and the [system reconciliation report](system-spec-reconciliation.md). The following sections describe the planned extraction, not the currently deployed directory layout or dependencies.

## Purpose

Provide a separate PHP microservice to capture and store NF-e and CT-e XML documents using each customer's A1 certificate. The Nuxt server consumes the service's API.

## Project layout

```text
opmoni/
├── frontend/                    # Complete Nuxt application
│   ├── app/                     # Nuxt user interface
│   ├── server/                  # Nuxt API and fiscal service integration
│   ├── public/                  # Static assets
│   ├── nuxt.config.ts           # Nuxt configuration
│   └── package.json             # Frontend dependencies and scripts
├── backend/                     # Laravel 13 backend template
├── services/
│   └── fiscal/
│       ├── src/                 # PHP API and NF-e/CT-e capture
│       ├── tests/               # Fiscal service tests
│       ├── composer.json        # NFePHP dependencies
│       ├── composer.lock        # Locked PHP dependency versions
│       ├── Dockerfile           # Fiscal service container
│       └── .env.example         # Configuration example without secrets
├── docs/
│   └── architecture/
│       └── fiscal-service.md
└── docker-compose.yml           # Local service orchestration
```

These paths describe the planned layout; recording it does not scaffold or implement the service.

## Dependencies and boundaries

- `services/fiscal/` owns the PHP runtime and fiscal integration.
- Composer installs `nfephp-org/sped-nfe` and `nfephp-org/sped-cte` into `services/fiscal/vendor/`. The generated dependency directory is excluded from Git.
- `frontend/server/` calls the fiscal API from the Nuxt server. The browser does not receive service credentials or certificate passwords.
- `frontend/app/` contains the interface that uses the Nuxt API.
- `docker-compose.yml` defines the containers and persistent volumes. Environment variables are declared explicitly for each service.

## Persistent storage

Customer A1 certificates (`.pfx`/`.p12`) and captured XML documents belong in persistent Docker volumes, outside the Git repository. Certificates and their passwords must be stored encrypted. Real credentials must not appear in `.env.example`, source code, or logs.

Volume names, container mount paths, storage implementation, API contracts, customer isolation, and capture scheduling will be defined in the service design before implementation.


## ERP issuance source boundary — 2026-10-24

ERP fiscal issuance is separate from Office capture and remains inside `backend/app/Erp/Fiscal/`; it does not implement the extraction above. The archived [template/product setup change](../../openspec/changes/archive/2026-10-24-align-erp-template-and-product-setup/proposal.md) adds reviewed commercial source facts consumed by ERP issuance. Product tax profiles and operational CFOP resolution are independent; explicit review freezes their effective facts and provenance without changing money, unit or stock identity.

A nullable sale `fiscal_model` of 55/65 enables applicable pre-post commercial-source prerequisite checks. `source_prerequisites_checked` is a saved source state, not certificate validation, full model readiness or SEFAZ authorization. Basic nonfiscal sales retain their contract. Linked fiscal preparation uses accepted frozen facts, verifies current issuer-nature compatibility before numbering and rejects incomplete/malformed evidence rather than resolving it from today's catalog. Complete historical commercial-v1 sources remain readable without invented provenance; commercial-v2 keeps its validated resolution/configuration evidence and legitimate null intent maps.

The [integration report](../reviews/2026-10-23-erp-template-product-setup/commercial-fiscal-integration.md) records these reader contracts. The [final local acceptance](../reviews/2026-10-23-erp-template-product-setup/final-local-acceptance.md) adds full regular/PostgreSQL/frontend and sequential real-API results, with explicit provider boundaries. Native queue/authorization/unknown-result recovery and private certificate/XML storage remain authoritative. No production provider, release or registration gate was enabled by this change; local source acceptance does not establish live issuance or move capture into a separate service.
