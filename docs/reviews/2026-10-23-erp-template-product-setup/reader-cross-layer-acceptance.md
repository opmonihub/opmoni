# Historical readers and cross-layer acceptance

Source: `15f6e48`, isolated `codex/align-erp-template-and-product-setup`, 2026-10-24. Tasks 8.6 and 10.3 are accepted within the contracts below. Root reviewed all three incoming diffs before integration; no production implementation changed in this package.

| Contract | Exercised coverage |
|---|---|
| Purchase-line nature compatibility | Explicit different nature references remain attached per line; omitted references stay null; foreign references fail without extra purchases/lines; no CFOP resolution metadata is added. |
| Historical accepted sale and return | Complete frozen `commercial-v1` source remains unchanged and carries no fabricated resolution; return codes 1202/2202 succeed and 1102 fails; the actual posted return and frozen source-line identity remain mandatory. |
| Native transport sources | CT-e/MDF-e retain their distinct typed groups and required-field checks, including native operation nature/CFOP and manifest referenced documents. |
| Profile adoption and archive | Omission/null preserve legacy intent; preview does not mutate; explicit adoption is required; archived selected profiles fail without company-default substitution. |
| Nature rules and tenant authority | Compatible legacy scalar writes preserve identity; default replacement is atomic; archive retains history; foreign relationships fail; exact delegated grants are attributed and revocation blocks subsequent actions. |
| Reviewed classification and recovery | Changed defaults invalidate proof; explicit fresh review adopts current classification while retaining money/number/unit facts. Equal-key application replays the original receipt, lookup finds it, and no commercial effects are duplicated. |

Direct canonical checks used PHP 8.4.27 / PHPUnit 12.5.39, whole-checkout source, network disabled and SQLite memory:

- Reader compatibility plus durable fiscal attempts: **20 tests / 366 assertions, exit 0**, log `backend-15f6e48-readers.log`.
- Selected profile/rules/authority/refresh contracts: **15 tests / 294 assertions, exit 0**, log `backend-15f6e48-cross-layer.log`.

The new reader tests are commit `9b5ef21` integrated as `e69fbcf`. Existing fiscal fixtures were completed in `cfd2792` / `f47f6a2`, without changing test assertions. `bd405ba` / `15f6e48` sets the durable test's in-memory release mode beside its existing release-enabled setting; the inherited preview environment had rejected random fixture companies. Temporary diagnostics were removed. Persistent environments, authority checks, provider mocks and queue fakes were preserved. The earlier failed durable run remains in the specialist report; its result is not represented as passing.

Focused purchase edit retention passed separately (one test / 12 assertions). Previously reviewed commercial/fiscal integration and PostgreSQL race evidence remain separate runs. No combined full-suite result, external issuance, production release or historical repair is claimed. Local logs and diagnostic history remain in ignored task reports.
