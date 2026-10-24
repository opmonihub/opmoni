# Full PostgreSQL acceptance

Task 10.5 passed on 2026-10-24 at backend source `53ed7e5ffe0a8740b00fceafa969533258d04b39`. This accepts the local PostgreSQL integration profile, separately from regular backend, frontend, browser and external-provider acceptance.

The user explicitly authorized only a new disposable project, `opmoni-full-pg-acceptance-20261024`, including destructive test table recreation and Redis clearing inside those new memory-backed services. Development and retained task services were excluded. Three task-labelled containers used PostgreSQL 18, Redis 8 and the supported PHP 8.4 application image on their own internal network, without host ports, persistent volumes or development mounts. The source remained frozen throughout the run.

| Run | Result |
|---|---|
| Initial complete profile | Exit 2: 229 tests, 12,109 assertions, 159 errors and ten failures; failed evidence retained |
| Bounded migration correction | Four tests, 43 assertions, exit 0; function reentry and immutable revision behavior |
| Second complete profile | **Exit 0: 229 tests, 26,884 assertions, zero errors, failures or skips**, 28:48.187, 93 MB |

The duplicate-function failure in the first run was corrected with one migration line: `CREATE OR REPLACE FUNCTION` replaces `CREATE FUNCTION`. The immutable function body, trigger and schema meaning remain unchanged. Root and a separate strong reviewer inspected the change; scoped formatting passed. No assertion, concurrency test or production guard was removed.

The successful native command was:

```sh
vendor/bin/phpunit -c phpunit.pgsql.xml \
  --cache-directory /tmp/phpunit-cache \
  --log-junit /tmp/full-pg-run-2-junit.xml \
  --display-skipped --display-incomplete \
  --fail-on-skipped --fail-on-incomplete
```

The verified test database was `opmoni_test`; Redis databases were 13–15. Independent PHP/PostgreSQL processes and native blocking barriers ran as part of the profile. Source, proposal, dependency and topology manifests retained the same identities before and after. The retained task-store records are byte-identical before the run, after the run and after cleanup. The development environment was outside the allowed network, mount and cleanup scope.

Results were saved before removing only the three authorized containers and their network. Cleanup verification reports no remaining disposable containers, network or volumes. An initial cleanup command refused missing required interpolation variables before taking action; both that refusal and the corrected guarded cleanup are recorded. No retained fixtures or stores were reset.

Raw evidence is retained in the ignored implementation evidence directory, `.superpowers/sdd/align-erp-template-and-product-setup/full-pg-acceptance/results/run-2/`: JUnit, output, exit status, source/topology manifests, retained-store comparisons, ownership checks and cleanup absence. The root rechecked JUnit counts and these content hashes:

- `junit.xml`: `a4501b40a0ef0cfbedffb9a74d7a24b8eef64ac6d5d012f226f78a33a78bc29f`.
- `phpunit.log`: `2a4a079e2a8d57851b796238f050b4e5f03a84584765d1a43816e36b47a82d8f`.

Later frontend lifecycle commits through `e6e7916` leave the tested backend tree unchanged. Later regular-suite fixture corrections require their own result identity. This report does not claim live SEFAZ/SERPRO/payment-provider acceptance, fiscal production release, merge, push, spec synchronization or archive.
