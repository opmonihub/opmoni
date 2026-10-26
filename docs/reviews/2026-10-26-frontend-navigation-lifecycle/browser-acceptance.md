# Frontend navigation lifecycle browser acceptance

Status: in progress; intermediate source observations are not final-source acceptance.

## Isolation and method

QA report branch `codex/navigation-browser-qa` uses its own worktree. Product source is the separately owned `frontend-navigation-lifecycle` worktree. Browser session is named `navigation-browser-qa`; origin is `http://localhost:3354`.

Independently verified backend mount points only to the target checkout; PostgreSQL and Redis have exclusive `opmoni-navigation-lifecycle-*` volumes. Runtime configuration resolves to database `opmoni_test`, Redis databases 13/14/15, and exact frontend origin 3354. Additive synthetic fixtures created one real office admin/super-admin membership, two office clients and Work templates, direct Company owner membership and a separate delegated Company with a read allowlist. Both companies have active basic subscriptions. No original development stores were touched. No credentials are included in this report.

Counts below use actual backend listener logs, excluding distinct endpoint paths. Browser resource timing's default 250-entry buffer fills with development modules; an empty API slice is not treated as zero reads. Hydration is independently checked through the mounted Nuxt application's `isHydrating` flag and rendered controls. Cold development builds and source integration trigger substantial compilation/HMR delays; timeouts are unavailable evidence, not successful acceptance or product timing claims.

## Task 2.2 intermediate baseline

| Case | Source | Actual reads | Render evidence | Verdict |
| --- | --- | --- | --- | --- |
| Work template direct `/work/modelos/1` | Stable `4afa3a8`; raw loader blob `6f92846263125f8ff0b91775698806cfefb8e27d` | Exact `GET /api/v1/process-templates/1` at 14:19:00 UTC and 14:19:49 UTC, total 2 | Real mounted Nuxt reports `isHydrating=false`; heading and title field contain synthetic Model 1 | Duplicate SSR/hydration read reproduced before candidate conversion |
| Work supporting reads | Same window | `/tags`, `/account/members/directory`, `/departments`, `/process-templates/1/preview` each once | Real Work editor rendered | Distinct reads excluded from entity count |
| Client direct `/customers/empresa/1` initial | Integration window `7f105fe` to `4afa3a8`; loader blob `3fad18044005c1e3eccd96cfff44fd5242d096a4` | Exact `/clients/1` at 14:17:42 and 14:17:57 UTC; distinct dispatch-contact reads counted separately | Client 1 rendered | Inconclusive due source integration/HMR; do not promote from this observation alone |
| Client warm repeat | `4afa3a8` at start | SSR `/clients/1` at 14:20:22 UTC; hydration still pending at bounded wait | Hydration wait timed out | Pending stable reproduction |

Native dynamic-ID page remount acceptance remains pending; no broad page-key overrides were applied by QA.

## Runtime observations and limitations

Initial dev compilation took 21.2 seconds for the first page and caused CDP navigation/snapshot timeouts. The QA-only unresponsive browser daemon was restarted after verifying its session environment. Authentication subsequently returned 200. These startup failures do not establish a frontend regression.

The baseline client detail emits a Vue warning for unresolved `DispatchContactFormModal`, followed by an SSR/client hydration node mismatch. This was observed before the lifecycle changes were fully integrated; root was notified for scope assessment. No UI provider, consent or financial command was executed.

Final browser matrix, responsive/theme/focus/print, Company/delegated contexts, error/retry cases and final source hashes remain pending. This report does not establish PostgreSQL concurrency, provider delivery or production rollout acceptance.
