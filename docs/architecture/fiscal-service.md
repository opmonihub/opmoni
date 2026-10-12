# Fiscal service structure

Recorded on 2026-10-12. The directory structure below is approved; the service implementation and detailed API design remain pending.

## Purpose

Provide a separate PHP microservice to capture and store NF-e and CT-e XML documents using each customer's A1 certificate. The Nuxt server consumes the service's API.

## Project layout

```text
opmoni/
├── app/                         # Nuxt user interface
├── server/                      # Nuxt API and fiscal service integration
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
- `server/` calls the fiscal API from the Nuxt server. The browser does not receive service credentials or certificate passwords.
- `app/` contains the interface that uses the Nuxt API.
- `docker-compose.yml` defines the containers and persistent volumes. Environment variables are declared explicitly for each service.

## Persistent storage

Customer A1 certificates (`.pfx`/`.p12`) and captured XML documents belong in persistent Docker volumes, outside the Git repository. Certificates and their passwords must be stored encrypted. Real credentials must not appear in `.env.example`, source code, or logs.

Volume names, container mount paths, storage implementation, API contracts, customer isolation, and capture scheduling will be defined in the service design before implementation.
