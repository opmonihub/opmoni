# Frontend

Nuxt 4 application. The package manager is pnpm 12.11.2. Run scripts in `frontend/` or as `pnpm --dir frontend <script>` from the repository root. `frontend/README.md` still contains dashboard template text; setup lives in the root `README.md`.

## Scripts

- `pnpm dev`
- `pnpm lint` runs ESLint.
- `pnpm typecheck` runs `nuxt typecheck` and `tsc --project tsconfig.contracts.json`.
- `pnpm test` runs the Node test runner on `tests-access.test.ts` and `tests/*.test.ts`.
- `pnpm build`

The default test script does not start a live ingress listener.

## API

- Call `$api` from `app/plugins/api.ts` or `useAccessApi().api` with paths such as `/clients`, without the `/api/v1` prefix. Both use `useAccessApi`, which adds that prefix; `$api('/clients')` requests `/api/v1/clients` in the browser and during SSR.
- The proxy is `server/api/v1/[...path].ts`. `NUXT_BACKEND_URL` and `NUXT_INGRESS_KEY` stay server-only. The ingress key matches backend `FRONTEND_INGRESS_KEY`.
- Tenant calls send `X-Account-Id`. ERP company calls send `X-Company-Id`. Mutating requests send the CSRF token.
- Product pages use the `auth` middleware. Platform admin adds `super-admin`. Account settings add `account-admin`. ERP pages use the `erp` layout.
- Reuse components under `app/components/` and the shells in `app/utils/pageShell.ts` before adding another page frame.
- Copy is inline Brazilian Portuguese. There is no i18n module. UI components come from `@nuxt/ui` v4.

## Tests

Add or update a test under `frontend/tests/` for the behavior you change. Contract tests mirror API shapes; Vue tests compile the real single-file component.
