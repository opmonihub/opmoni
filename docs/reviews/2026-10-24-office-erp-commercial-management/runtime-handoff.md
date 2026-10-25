# Task 1.2 isolated runtime handoff

The office ERP commercial worktree uses `scripts/office-erp-commercial-runtime/run` from `/home/obsidian/dev/opmoni/.worktrees/office-erp-commercial-design` on branch `codex/office-erp-commercial-design`. The source baseline was `14f9f22fc656327b467761bafd4b43d0bd08ec50` when prepared. No application feature code was changed for this task.

## Preparation and ownership

Run these commands from this worktree root:

```bash
scripts/office-erp-commercial-runtime/run prepare
scripts/office-erp-commercial-runtime/run up
scripts/office-erp-commercial-runtime/run verify
scripts/office-erp-commercial-runtime/run ps
```

`prepare` copies the missing root, backend and frontend `.env` files from the source checkout with mode `0600`, after checking that the locked backend and frontend dependencies match. It copies `backend/vendor` and `frontend/node_modules` into independent directories with reflinks/copies and rejects links resolving outside this worktree. It never edits the source checkout. The root, backend and frontend private files were confirmed as mode `0600`. The copied dependency directories have distinct inode identities from the source (`backend/vendor` 2049:3956992 versus 2049:3146853; `frontend/node_modules` 2049:3968235 versus 2049:1576972).

The lockfile fingerprints at preparation were:

| File | SHA-256 |
| --- | --- |
| `backend/composer.lock` | `4fd38102a1a1a20dd69074bf3adcdfbffc565141e12268271e3e00b7b7ca311b` |
| `frontend/pnpm-lock.yaml` | `a772afd2cbee9ac684380298c5c12f9eb3575c8fee387c289a76131b0268a203` |
| `backend/routes/api.php` | `0057db324352578436281439154f5bdf37670e658728df678e6e9a5b57410466` |
| `frontend/nuxt.config.ts` | `ab0363018218a51c2980adf4ed9032f7d967d9c28092997eea33f7388cbce4db` |
| root `docker-compose.yml` | `69ccd2e854a59bdc713def7a57ec939f17704e5ccf0648954628e23dd48eb5a6` |

The Compose project and network are `opmoni-office-erp-commercial` and `opmoni-office-erp-commercial-net`. Its only containers are backend, PostgreSQL 18 and Redis 8; there are no queue or scheduler processes. Backend mounts the **entire** worktree at `/workspace`, including sibling frontend and root files. The independent named volumes are `opmoni-office-erp-commercial-postgres` and `opmoni-office-erp-commercial-redis`. Published listeners are loopback only: backend `18860`, PostgreSQL `15460`, Redis `16360`; the host Node 24 Nuxt process listens on `3346`. Its PID identity and log are ignored under `.superpowers/sdd/tasks/` with the `office-erp-commercial-` prefix.

`run verify` checks Compose project labels, network members/aliases, mounts, volume labels, published ports, copied source contents, frontend PID/cwd/command, ownership of the actual `127.0.0.1:3346` listening socket, and private ingress presence. `run up` checks port availability before launching Nuxt and waits until its recorded process owns that listener. Its Laravel connection check queries the actual SQL server for `current_database()` and version, and opens each Redis connection to assert database numbers and Redis 8. It also checks the copied ERP rollout values and disabled external dispatch. The current verified output is:

```text
PASS: task ownership, whole-worktree mount/source contents, host frontend PID/cwd and owned loopback listener, private dependencies, aliases, independent volumes, no workers/scheduler
PASS: PostgreSQL 18 opmoni_test, Redis 8 databases 13/14/15, copied ERP gates and disabled external dispatch
```

Run `PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s scripts/office-erp-commercial-runtime -p test_listener.py -v` to check free, occupied, missing and wrong-owner listener behavior without touching other services.

The isolated backend uses the checked-in PostgreSQL integration profile's test credential, so `phpunit.pgsql.xml` and its bootstrap connect without edits. `ERP_OPERATING_WRITES_ENABLED=true`, registration/release remain disabled and release mode remains `preview` from the copied backend configuration. ERP fiscal uses the fake provider and production dispatch is disabled; WhatsApp dispatch is disabled; mail uses the array transport. No development database, Redis 0–2, source `.env`, key, production gate, queue or scheduler was changed. The Nuxt `/` route returned HTTP 302 after its initial compilation; Laravel `/up` returned HTTP 200.

## Baseline commands and exact outcomes

Each `run backend` or `run frontend` invocation repeats container and datastore verification before the command. The regular backend suite uses SQLite in memory; the integration profile uses this task's PostgreSQL/Redis stores.

```text
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/Admin/PlanApiTest.php
OK (19 tests, 79 assertions)

scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/Admin/PlatformContractTest.php
OK (16 tests, 158 assertions)

scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/adminPageStates.test.ts
tests 15; pass 15; fail 0; skipped 0

scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit -c phpunit.pgsql.xml --filter test_concurrent_company_registration_creates_single_company_and_subscription
OK (1 test, 16 assertions)

scripts/office-erp-commercial-runtime/run backend vendor/bin/pint /workspace/scripts/office-erp-commercial-runtime/verify-stores.php --format agent
{"tool":"pint","result":"passed"}
```

The revised Task 2 brief explicitly permits **existing checked-in PHPUnit fixture setup/cleanup**, including its internal `migrate:fresh`, only after `run verify` confirms the independent task-private runtime. The permitted fixture targets are SQLite in memory or this runtime's PostgreSQL `opmoni_test` and Redis 13–15. Preserve every fixture safety assertion. Do not manually run `migrate:fresh`, drop or truncate tables, flush development stores, or run a generic `docker compose exec` from another checkout. When applying additive schema changes here, use `scripts/office-erp-commercial-runtime/run backend php artisan migrate --force --no-interaction` after verification. The complete PostgreSQL, backend and frontend suites, browser acceptance and live providers remain for later tasks.

`vendor/bin/pint --dirty --format agent` was attempted through the container and returned `The [--dirty] option is only available when using Git.` The image has no usable Git view for Pint; the explicit-file command above passed. The initial Nuxt HTTP 503 occurred while Nuxt was still compiling and resolved to HTTP 302 when ready. No application baseline test failed.
