# Navigation and native initialization acceptance

Source: sidebar worker `5cda885` integrated as `54bb816`; native initialization worker `27c2764` integrated as `ca2336a`. Independent task reviews approved both implementations without findings. This records tasks 2.4, 5.1 and 5.2, not whole-change acceptance.

## Sidebar disclosure

One controlled group spans both ERP menu regions. Active children stay pinned when folded; search and collapsed menus retain all permitted links. Manual folds survive hashes within one module, while route-owner and authority changes reset disclosure.

The integrated checkout passed 42 focused tests across mobile shell, mobile focus, workspace registry and shared sidebar helpers (exit 0). The isolated implementation also passed changed-file ESLint and Nuxt/contracts typecheck. Actual desktop 1366×768 and mobile 390×844 samples are included. Browser checks exercised Space/Enter, cross-region disclosure, pinned children, child navigation, drawer closure and trigger focus restoration. Live authority mutation was covered by SFC tests, not browser interaction. An initial CDP timeout and transient mobile overlay were recovered and are not represented as uninterrupted browser success.

## Native setup

The additive migration records versioned, company-scoped native defaults with composite foreign keys. Provisioning creates locale/time preferences, BRL, whole-unit UN and a non-identifying default customer atomically. Retry preserves existing setup and operator edits. No historical company is backfilled.

The isolated implementation passed 15 new tests / 114 assertions and 12 existing foundation/context/settings tests / 94 assertions, plus Pint (all exit 0), using PHP 8.4.27, Laravel 13 and in-memory SQLite with no network. Tests cover additive settings preservation, four foreign-company pointer rejections, missing-value semantics, retry preservation and fault-injected full provisioning rollback.

PostgreSQL, independent-process races, complete suites and integration with the separately developing typed-settings changes remain pending. Unit/customer/currency consumers, scheme/layout initialization and existing-company reconciliation are separate tasks. No production gate or external provider was enabled.
