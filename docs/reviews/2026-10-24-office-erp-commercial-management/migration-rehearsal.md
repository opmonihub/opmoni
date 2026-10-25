# P5.3 isolated migration rehearsal evidence

Recorded 2026-10-25. This document accepts the isolated operational migration gate only. It does not claim complete P5.3, product, browser, or global acceptance.

## Pinned sources and rollback meaning

- The accepted current application source used by the controller was `/home/obsidian/dev/opmoni/.worktrees/office-erp-commercial-design` at `01e9fbf8edbbd06a5179874ae2ca2fb35796dd01`.
- `14f9f22fc656327b467761bafd4b43d0bd08ec50` was used only to create the pre-commercial schema and legacy data baseline. It is not the compatible rollback application.
- `d55622aacd2d770d754d020e6fbdea410ad7a135` was the prior application used for the actual read-only post-migration probe. It understands the `suspended` subscription state present in the fixture.
- The earlier preflight document remains a point-in-time record: it observed central HEAD `1bbd02fe393262bc4a1ed4074a42c669f3f917d6`, explicitly not an accepted source SHA, and had no rehearsal runtime. The controller later pinned the accepted SHA above. This documentation worktree was not advanced or merged with application code.

## Isolated runtime and retained state

The rehearsal used Compose project `opmoni-office-erp-commercial-rehearsal`, its dedicated internal network, and separate PostgreSQL/Redis volumes. It published no host ports, had no outbound network, queue, scheduler, or dispatch workers, and used PostgreSQL 18 (`opmoni_test`) plus Redis 8 databases 13, 14, and 15. Backend source and the selected `vendor` tree were mounted read-only. All three backend roles used the host's non-root UID/GID (`1001:1001`); Laravel storage and bootstrap cache used matching tmpfs ownership. Private state was retained with restrictive permissions. No development data, production setting, application key, or private environment credential was changed.

Before running phases, both exact legacy worktrees need an empty ignored nested vendor mountpoint so Docker can overlay the central read-only dependency tree:

```bash
mkdir -p \
  /home/obsidian/dev/opmoni/.worktrees/office-erp-commercial-rehearsal-baseline/backend/vendor \
  /home/obsidian/dev/opmoni/.worktrees/office-erp-commercial-rehearsal-compatible/backend/vendor
```

These directories are mountpoints only: do not install or copy dependencies into them. The first baseline Compose attempt failed at that nested mount before Artisan ran. After creating the empty mountpoints, the controller resumed the same pinned baseline phase successfully. The failed attempt and successful retry logs were retained separately.

## Exact phase commands and outcomes

Run from the pinned current source worktree. `prepare-sources` creates/reuses only the exact revisions above and verifies their dependency manifests; the empty mountpoint prerequisite must be present before the backend phases.

```bash
cd /home/obsidian/dev/opmoni/.worktrees/office-erp-commercial-design
python3 scripts/office-erp-commercial-runtime/rehearsal.py prepare-sources --source-sha 01e9fbf8edbbd06a5179874ae2ca2fb35796dd01
python3 scripts/office-erp-commercial-runtime/rehearsal.py preflight --source-sha 01e9fbf8edbbd06a5179874ae2ca2fb35796dd01
python3 scripts/office-erp-commercial-runtime/rehearsal.py baseline --source-sha 01e9fbf8edbbd06a5179874ae2ca2fb35796dd01
python3 scripts/office-erp-commercial-runtime/rehearsal.py migrate --source-sha 01e9fbf8edbbd06a5179874ae2ca2fb35796dd01
python3 scripts/office-erp-commercial-runtime/rehearsal.py fixture --source-sha 01e9fbf8edbbd06a5179874ae2ca2fb35796dd01
python3 scripts/office-erp-commercial-runtime/rehearsal.py old-probe --source-sha 01e9fbf8edbbd06a5179874ae2ca2fb35796dd01
python3 scripts/office-erp-commercial-runtime/rehearsal.py current-probe --source-sha 01e9fbf8edbbd06a5179874ae2ca2fb35796dd01
```

The accepted output sequence was:

| Phase | Result |
| --- | --- |
| `preflight` | `PASS: isolated PostgreSQL/Redis and private environment guards verified` |
| `baseline` | `PASS: rehearsal phase baseline completed; private state retained` |
| `migrate` | `PASS: rehearsal phase migrate completed; private state retained` |
| `fixture` | `PASS: rehearsal phase fixture completed; private state retained` |
| `old-probe` | `PASS: rehearsal phase old-probe completed; private state retained` |
| `current-probe` | `PASS: rehearsal phase current-probe completed; private state retained` |

The failed first baseline attempt is not counted as a completed phase. The harness resumed the same private state after the mountpoint correction. Preserve that state on any future interruption: do not reset, delete, roll back, or recreate the private database or volumes. Resume only the next incomplete phase with the same source SHA. The harness rejects a changed source, already completed phase, or unexpected existing private resource.

## Migration, preservation, and compatibility evidence

Exactly these five additive migration records were verified:

1. `2026_10_24_090000_add_erp_commercial_to_plans_table`
2. `2026_10_24_100000_create_office_erp_commercial_core`
3. `2026_10_24_110000_create_office_erp_billing`
4. `2026_10_24_120000_create_office_erp_receipt_proofs`
5. `2026_10_24_130000_create_office_erp_owner_invitations`

The 16 `office_erp_*` tables present in the schema snapshot were `office_erp_sales_plans`, `office_erp_sales_plan_revisions`, `office_erp_contracts`, `office_erp_contract_revisions`, `office_erp_licence_allocations`, `office_erp_commercial_operations`, `office_erp_audit_events`, `office_erp_invoice_counters`, `office_erp_invoices`, `office_erp_invoice_adjustments`, `office_erp_receipts`, `office_erp_receipt_allocations`, `office_erp_receipt_reversals`, `office_erp_commercial_settings`, `office_erp_receipt_proofs`, and `office_erp_owner_invitations`.

The migration probe validated seven required constraints (`office_erp_contract_client_account_fk`, `office_erp_contract_current_revision_fk`, `office_erp_operations_identity_unique`, `office_erp_invoices_account_number_unique`, `office_erp_allocations_receipt_invoice_unique`, `office_erp_owner_invitation_contract_fk`, and `erp_subscriptions_office_contract_company_fk`), the recurring invoice unique index, and both append-only billing triggers (`office_erp_invoice_adjustments_append_only` and `office_erp_receipt_reversals_append_only`).

The baseline snapshot covered nine legacy tables and 11 rows. Its deterministic table counts and sorted-row SHA-256 snapshot matched after migration. The migration probe also confirmed exactly the five commercial migration records, retained the unlinked pre-existing ERP subscription, and found no change to the APP_KEY digest. No migration rollback, schema reset, or destructive fixture operation was used.

The service-created fixture exercised a `BRL 100.00` invoice, a `BRL 40.00` receipt leaving `BRL 60.00` outstanding, a second `BRL 60.00` receipt settling the invoice, and reversal restoring `BRL 60.00` outstanding. It also persisted a manual hold and suspended subscription. The compatible and current probes each checked the bounded existing-company subscription resource/relationship read and verified that suspended status denies ERP operating writes. Each probe preserved the full table, migration, schema, and key-digest snapshot; the fixture, compatible, and current snapshots were equal.

## Evidence limits and retained artifacts

The detailed phase logs and private state remain under the controller's ignored `.superpowers/sdd/2026-10-24-office-erp-commercial-management/` evidence directory. The final logs are `rehearsal-preflight-01e9fbf.log`, `rehearsal-baseline-prepared-01e9fbf.log`, `rehearsal-migrate-01e9fbf.log`, `rehearsal-fixture-01e9fbf.log`, `rehearsal-old-probe-01e9fbf.log`, and `rehearsal-current-probe-01e9fbf.log`; the failed initial baseline attempt remains separately in `rehearsal-baseline-01e9fbf.log`. This document intentionally contains no row data, identifiers, raw keys, or digest values.

An earlier private preflight failed during Laravel `PackageManifest` bootstrap because the tmpfs owner was hardcoded to UID/GID 1000 while the actual backend process and host were 1001:1001. The FIXROUND4 harness change made container and tmpfs ownership dynamic and added runtime checks; the accepted preflight log above then passed. The prior static FIXROUND4 report and the earlier source-advance preflight remain intact. These were environment/source-pin findings, not migration or schema failures; the later logs supplement them without overwriting them.

This rehearsal does not establish browser login/onboarding, full UI workflows, live fiscal/payment/provider acceptance, concurrency behavior, or overall P5.3/global acceptance. Those gates remain pending.
