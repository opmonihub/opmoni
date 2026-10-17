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
