# Reviewed commercial and fiscal integration

Implementation source: `ee98bc2`, isolated branch `codex/align-erp-template-and-product-setup`, 2026-10-24. This accepts tasks 8.1–8.4 within their specified local contracts. UI task 8.5, independent-process races and full acceptance remain separate.

## Changes and review

Commercial snapshots retain reviewed effective tax/CFOP facts, their origins and configuration revisions. Explicit preview and application preserve existing prices, exact totals, line identity, authority and operation recovery. Ordinary edits and posted consumers do not resolve historical classifications from the current catalog.

An explicit sale intent of `55` or `65` checks the applicable commercial source prerequisites before stock/finance effects. Basic sales retain their established contract. Existing-draft intent activation or reselection requires explicit review; changed current NCM/origin can enter a visible reviewed candidate without replacing stock kind, unit or monetary facts. Full fiscal identity, amounts, certificate and issuance validation remain distinct.

Linked fiscal preparation reads the accepted source and checks current issuer compatibility without substituting the current nature or classifications. Complete historical `commercial-v1` snapshots remain usable without invented provenance; incomplete sources fail. The v2 reader validates frozen provenance, accepts the producer's legitimate null intent map and returns a field-level 422 for a malformed enclosing resolution. A missing submitted nature description is derived from the accepted source; explicit null, empty or divergent descriptions are rejected.

Independent source reviews approved the initial commercial changes and their fixes, the readiness fixes, and the final frozen-source fixes. The root reviewed changed diffs in context and integrated fiscal commits `0421214`, `1e24498`, `aa92a43` as `66755b1`, `c36d033`, `ee98bc2`. Ignored specialist review reports are retained in their original worktrees.

## Directly observed integration checks

| Check | Result |
|---|---|
| Canonical snapshot, refresh, prerequisite, readiness and frozen-source feature files | 128 tests, 2,944 assertions; exit 0 |
| Two existing fiscal workflow regressions that previously failed before their intended assertions | 2 tests, 62 assertions; exit 0 |
| Task runtime topology and store verification | Exit 0; whole canonical checkout, PostgreSQL 18 `opmoni_test`, Redis 8 databases 13–15, task-owned volumes/network, no queue or scheduler |
| Additive migration `2026_10_24_170000_add_sale_fiscal_intent` | Applied with `migrate --force --no-interaction --path=...`; status `Ran`, live `fiscal_model` varchar column confirmed |

The feature runs used PHP 8.4.27 and PHPUnit 12.5.39 in the existing image, a whole-checkout mount, network disabled and SQLite in memory. They do not establish PostgreSQL concurrency correctness. Local logs are retained under `.superpowers/sdd/align-erp-template-and-product-setup/` as `backend-ee98bc2-integration.log` and `backend-ee98bc2-fiscal-workflows.log`.

The specialist's earlier complete issuance run of 64 tests / 1,283 assertions is separate evidence and was not rerun or added to the canonical totals. Full regular/backend PostgreSQL profiles, the complete browser matrix, provider issuance and production rollout are not claimed. Existing environments, keys, development stores and production gates were preserved.
