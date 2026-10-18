# SERPRO diagnostic and ingress acceptance

Local acceptance on 2026-10-17 (America/Sao_Paulo), generated dummy credentials only. No live SERPRO or paid endpoint called.

- Actual mutual TLS: PHP8.4.26/OpenSSL3.0.22/cURL7.88.1/Guzzle8.2, generated CA, server SAN127.0.0.1, passworded client P12. Server required a verified client certificate and asserted Basic authentication, Role-Type and grant form. Production authenticator retained `verify=true`; only the test client process trusted the generated CA through `curl.cainfo`. One PHPUnit test, four assertions passed. Fixtures deleted in finally.
- Actual Nuxt dev127.0.0.1:3316 → isolated Laravel8316, PostgreSQL and Redis13/14/15: authenticated HTTP sessions/cookies, origin/CSRF rejection, anonymous401, ordinary-member vault403, selected-member connection metadata200, no-store, unknown Account404, POST multipart override parsed PUT, full binary SHA256 equality and persisted decrypted-byte equality, explicit empty password, forged ingress/dev/forwarding headers stripped.
- Certificate >2MiB, body >4MiB, chunked body without Content-Length >4MiB all returned413. Repeated with PHP upload_max_filesize=2M/post_max_size=4M, including a direct backend preparse overflow:413 JSON. PHP must have display_errors=Off so startup warnings do not send headers prematurely; repository php.ini now sets it.

## Reusable harness

Run the PHP commands only through the repository's isolated test runtime launcher. The harness deliberately runs `migrate:fresh`, so never use development/production stores. `platform-ingress-fixture.php` requires explicit OPMONI_INGRESS_FIXTURE=1, testing environment and `postgres`/`opmoni_test`. Use a dedicated network/container whose PostgreSQL/Redis host aliases are `postgres`/`redis`. It is never loaded by production routing.

1. Create a private temporary directory mounted in the backend; set `OPMONI_INGRESS_DIRECTORY` to its absolute path.
2. `env OPMONI_INGRESS_FIXTURE=1 OPMONI_INGRESS_DIRECTORY=<dir> php tests/Integration/platform-ingress-fixture.php init`
3. Start `env OPMONI_INGRESS_FIXTURE=1 OPMONI_INGRESS_DIRECTORY=<dir> php -d display_errors=0 -d post_max_size=4M -d upload_max_filesize=2M -S 0.0.0.0:8316 tests/Integration/platform-ingress-fixture.php` inside the dedicated backend.
4. Start actual frontend with `NUXT_BACKEND_URL=http://<dedicated-backend-IP>:8316 NUXT_INGRESS_KEY=integration-test-only-key pnpm exec nuxt dev --host 127.0.0.1 --port 3316`.
5. From repository root run `OPMONI_INGRESS_FIXTURE=1 OPMONI_DIRECT_BACKEND=http://<dedicated-backend-IP>:8316 node frontend/tests/integration/platformIngress.mjs <dir>`.
6. Run fixture `verify`, stop only the two fixture listeners, then fixture `cleanup`. Remove generated directory. Cleanup resets only dedicated database and Redis13/14/15; leave application stores untouched.

The mutual TLS fixture is self-contained: `php vendor/bin/phpunit tests/Integration/SerproDiagnosticMutualTlsTest.php` in the guarded backend. It creates a random loopback listener and temporary private CA/certificates, stops the process and removes all files.

This proves native client protocol and the actual Nuxt→Laravel request path. Independent-process credential races and production browser/build acceptance are separate gates.
