## Why

Navigation across the existing frontend can appear to refresh because route selection becomes stale, reads repeat or outlive their page, filters reset, and settings editors remount or lose drafts. The audit of 105 page files and representative authenticated browser flows also found server-rendered Monitoring data being replaced by defaults during hydration; a bounded Nuxt lifecycle refactor should correct these behaviors before they spread through shared components.

## What Changes

- Keep the existing dashboard shells mounted during ordinary internal navigation and derive active destinations from the current route, including history and nested details.
- Preserve initial SSR results during hydration while retaining intentional revalidation on later visits; cover Monitoring, office certificate and WhatsApp settings.
- Give each read and route-synchronization operation one owner: coalesce concurrent commercial-context reads, eliminate duplicate operational initialization, and cancel obsolete safe reads without changing serialized session transport.
- Preserve supported list search, filters, page size and page selection across tab round trips within the same authority; reset them on identity, session or tenant changes and keep direct URL filters authoritative.
- Preserve unsaved settings fields during unrelated same-company updates and changes limited to query/hash; retain deliberate invalidation after authority loss.
- Add transition, SSR/hydration, read-count, cancellation and draft-preservation regressions using real components and the installed Nuxt APIs.
- Review every inventoried page family, reuse correct existing patterns, and require evidence before promoting lower-confidence audit candidates into implementation work.

## Capabilities

### New Capabilities

- `frontend-navigation-lifecycle`: Persistent SPA shells, reactive route selection, SSR hydration fidelity, authority-scoped list navigation state, and lifecycle ownership of safe reads and drafts across the existing frontend.

### Modified Capabilities

- `office-erp-commercial-management`: Commercial-context read coalescing and stable tab/list behavior without changing commercial admission or operation recovery.
- `erp-company-foundation`: Settings draft preservation and a single initialization owner for company operational workspaces.
- `monitoring-serpro`: Monitoring overview hydration and later-visit freshness without implicit provider actions.
- `work-management`: A single owner for competence-to-URL synchronization across Work views.

## Impact

Frontend changes are limited to existing pages, their supporting components/composables, navigation utilities and tests. The main shared boundaries are `useAccessApi`, its admission queue, `useOfficeErpContext`, `useWorkReferenceMonth`, `useErpCompanySettings`, and the office/company shells. Existing APIs and relative `/api/v1` ingress remain unchanged. No Laravel, database, Redis, fiscal-service, provider, dependency or framework-version change is planned.

The work starts from commit `2454fa8` on branch `codex/frontend-navigation-lifecycle` in `/home/obsidian/dev/opmoni/.worktrees/frontend-navigation-lifecycle`. It builds on the integrated but unarchived `simplify-office-erp-client-onboarding` change and preserves its Planos terminology, direct enrollment, owner setup, trial behavior and financial recovery. Its existing artifacts and canonical synchronization are outside this change. No accepted product decision is superseded.

Non-goals are redesigning the product, converting demonstration screens into real features, retaining sensitive drafts across sessions, adding blanket KeepAlive, removing the global API admission queue, changing authentication/context-switch reloads, enabling registration or production gates, and claiming provider or PostgreSQL acceptance from frontend tests.

The filter policy is session-memory preservation for existing real list surfaces, not restoration of mutation forms or financial command payloads. Record/selection recovery keeps its existing contracts. There are no unresolved product decisions blocking planning; lower-confidence double-fetch candidates and company-browser acceptance remain explicit validation tasks.
