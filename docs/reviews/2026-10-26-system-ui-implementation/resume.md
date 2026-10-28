# Inline implementation checkpoint

Change: `standardize-system-ui-patterns`; spec-driven tasks are authoritative.

Resumed 2026-10-27 in the current conversation at the user request. The previous Paseo agent was canceled before edits. Single writer: this session, integration checkout `.worktrees/erp-management-density`, base `dac7293`. Existing specialist drafts remain preserved. No merge, push, cleanup, environment edits or development-data changes.

## Pre-flight

- Backend sorting interfaces are integrated, except the reviewed related-client null ordering correction; a real PostgreSQL regression was added before that correction.
- Shared frontend foundation exists. Its count/clear/action slots and OfficeErpPanel `action` contract must be retained by consumer wiring.
- The previous agent left a failing commercial-sort test (missing helper) and a stable-ID forwarding test. Both failures were independently reproduced before implementation.
- Native Table and Modal metadata/examples were consulted through Nuxt UI MCP; installed APIs were inspected too. Nuxt 4 contracts remain authoritative over the generic skill's Nuxt 5 guidance.
- Root planning artifacts are retained as the canonical planning location; implementation/evidence live in this integration worktree. Task updates are synchronized only after verified completion.

## Verification

- PostgreSQL RED: `test_contract_client_ordering_keeps_deleted_clients_last_in_both_directions` failed with the deleted client first in DESC (1 test, 6 assertions).
- Scoped PostgreSQL run after correction: 62 tests, 800 assertions, 2 errors and 1 failure. One new catalog fixture used duplicate brand names, forbidden by PostgreSQL uniqueness; switched that generic tie-ordering test to contact names, where duplicates are valid. Two other failures are outside sorting: pre-existing establishment migration rollback dependency and stream identity comparison. Do not count this run as a passing suite.

## Pending

Verify focused query families on PostgreSQL; finish authoritative criteria/navigation and consumer wiring; complete commercial paired pilot before expansion; migrate the four modal hosts; finish consumer waves, checks, review, documentation and strict validation. All unchecked OpenSpec tasks remain pending until their complete behavior and verification are established.

- PostgreSQL GREEN after the reviewed fix and valid duplicate-contact fixture: commercial ListOrdering, AdminListFilters and ErpCoreCatalogs — 34 tests / 254 assertions. Separate selected-establishment sort regression — 1 test / 8 assertions. These confirm query ordering/nulls/ties/counts across the three new families; they do not establish full-suite or concurrent command acceptance. Tasks 4.1 and 5.3 completed.
- Host PHP 8.4.1 Pint --dirty --format agent passed. The backend-only test container cannot resolve worktree Git metadata, so its Pint --dirty invocation was not counted; host execution is the required result.
- Shared/navigation presentation smoke: 9/9 passed. Commercial pilot tests: 48/48 passed before subsequent responsive consolidation; final responsive test rerun remains required.
- The commercial sort API is resource-allowlisted, remembered under the existing authority registry and included in query identity. Native header triggers and shared ordering menus reset to omitted parameters. Shared Table forwards stable domain IDs. The commercial plan editor now uses a native modal/footer form ID and retains command recovery; dirty dismissal prompts explicitly.

## Inline wave checkpoint

- Inline work now includes the five commercial sorting controls and authority-owned criteria, catalog resource sorting by supported headers, Admin Account/users/subscriptions authoritative sorting, and bounded local Admin-plan ordering. Default requests omit both ordering keys.
- Commercial history/authority/caller suite: 61/61 passed, including explicit sorted URL/history precedence and exact legacy reset. Combined catalog/commercial/plan suite after type fixes: 72/72 passed. Catalog modal/resource/presentation suite after native-host fixture updates: 22/22 passed. Admin ordering page tests: 3/3 passed. Local Admin-plan directory ordering: 1/1 passed. Dirty-modal policy: 2/2 passed. Named form-error focus: 1/1 passed.
- Native commercial expansion was consolidated across desktop and mobile. Initial 320px captures reproduced clipping from CSS-hidden columns; correction uses native column visibility, multiline cells and stable IDs, pruning expansion for absent records. Browser ordering proof: DESC first record becomes Client 37; expanded count remains 0 instead of moving details to another ID.
- Commercial pilot capture matrix: 32 initial and 32 confirmed views in `.superpowers/system-ui-patterns/captures/`. Reference Profile/Members and product clients/contracts/plans/settings, both themes, desktop/phone; clients additionally 1280/768/390/375 widths and canonical 320px. Actual theme and document widths recorded in confirmed-matrix.json; two initial reference Members light captures were dark due hydration timing and were replaced through the native Appearance/Light control. Confirmed matrices have no document overflow. Phone client/contract facts and actions were visually inspected after correction.
- Office plan editor is a native modal with external footer form ID, local/server invalid focus and explicit dirty discard. Module selection now uses native CheckboxGroup for distinct accessible module names. Unknown command recovery remains parent-owned. Admin plan/subscription modal dismissal shares useDirtyModal; direct authority/save closure bypasses user discard prompts. Catalog detail stays a slideover; create/edit use a single dynamic native modal host with existing dirty, nested, focus and close listeners.
- Scalar commercial Settings adopted subtle PageCards and the shared form measure/field rows; Work model identity fields use the shared scalar row composition, retaining step grids; fiscal processing heading/actions use the shared naked PageCard without changing fiscal text, criteria, refresh or queue effects.
- Full `pnpm test` is running through pinned pnpm 12.11.2 (global launcher 12.4.2). Full host regular PHPUnit with process-only test key and FISCAL_ENVIRONMENT=producao is running. Initial full frontend failures exposed newly introduced route-hook requirements in reference picker harnesses; the route is now consulted only for remembered supported catalog lists. The account-filter harness now supplies its real Nuxt route boundary. Focused reruns are running. No full-suite green claim yet.
- Serialize formatter mutations with edits: one background ESLint --fix pass overwrote the local Admin-plan ordering draft it read earlier; this was detected by the failing focused test and the ordering was reapplied and verified. No formatter remains active while source is being edited.

## Still pending

Full checks and typecheck/build after latest changes; broader consumer audit and browser evidence, catalog nested-modal browser flows, final independent review and reconciliation/strict OpenSpec validation. Do not infer completion of unchecked tasks from these partial reports. Root planning is canonical; task/evidence synchronization only follows verified completion.

## Validation continuation

- Catalog URL/context regression reproduced duplicate replacement-authority reads. The context watcher now owns those transitions, retaining normalized explicit URL criteria; catalog lifecycle/editor/presentation/authority rerun: 57/57 GREEN.
- Initial full frontend run: 2149/2275 passed, 126 failed. Reference picker route requirements were corrected; shared test boundary now supplies a canonical empty Nuxt route unless overridden. Native PanelList header-slot fixtures and modal/control assertions were updated. Focused reruns: 179/179 GREEN across settings, onboarding, plans and Admin pages; 17/17 Office-plan regression GREEN. A clean whole-suite run is in /tmp/system-ui-clean-frontend-tests.log.
- Browser catalog unit editor retained entered fields and focused the invalid sigla following synthetic422. Native discard returned focus to Adicionar unidade. Office editor returned focus to its original Editar plano button after nested discard following a new shared focus-return correction (unit RED 0 != 1, then GREEN). Admin hosts share that correction.
- Direct entry exposed static Novo plano disabled-attribute hydration mismatch when capability confirmation completed after SSR markup. Keep this static action disabled until mounted, then use the original capability/command gates. Browser recheck pending.
- PHP full-suite first process spent >20min before any output; restarted with --debug to observe progress. The second attempt exposed an invalid synthetic process key (26 decoded bytes); discarded that attempt and restarted with generated 32-byte process-only APP_KEY. No .env or stored key was changed. Current full-suite debug log: /tmp/system-ui-full-backend-debug.log.
- Final format must use frontend cwd (root invocation lacked its generated config and was not accepted). Correct scoped formatter finished successfully before further edits; rerun on later edits required.
