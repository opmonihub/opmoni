---
version: 1
slug: "frontend-app-pages-gestao-erp-vue"
primary_target: "frontend/app/pages/gestao-erp.vue"
related_targets: ["frontend/app/pages/gestao-erp"]
---

# ERP management surface

Scope: /gestao-erp and its commercial child pages, excluding invoice print output. Operate mode. Office operators prioritize registering clients and activating ERP; mobile supports complete operation. Build directly with installed Nuxt UI, preserve API and commercial command behavior.

Confirmed follow-up: keep page headings small (16px), subtitles short, plan rows compact, module names readable behind a compact disclosure, and reuse the application's existing filter panel rather than expose every date input in the toolbar.

Grounded structures, ranked: (1) task hub, (2) client workspace, (3) operational center, (4) activation journey, (5) master/detail workspace, (6) billing planner, (7) compact workbench. The user selected the operational center from dealt structures 3, 4, 7 through the structured question fallback after serve-question exited without a browser.

## Direction contract

THESIS: A commercial operation center makes registration visible and keeps operating lists readable, replacing scattered cards and overloaded filter bars.

OWN-WORLD: Inherit Opmoni's green primary, zinc neutrals, Public Sans, Lucide icons and Nuxt UI components. Support the existing light/dark preference for office work across changing ambient light.

User-approved final refinement: registration, save, activation and ordinary operational commands use native neutral buttons, including the shared default. Main commands use solid neutral and secondary actions retain outline/ghost variants. Green remains a selective brand/focus/active-state accent; success, warning and error colors preserve their meanings.

STORY: Register a client and activate ERP, then follow subscriptions, invoices and receipts through explicit route navigation. Existing recovery and authorization states stay visible.

FIRST VIEWPORT: A compact dashboard header places Cadastrar cliente at the right. Desktop route navigation sits below; mobile uses a section selector. Overview presents the activation entry before a restrained commercial summary. Lists use a consistent heading, search, optional filters, table and responsive pagination. Signature interaction: reveal filters without leaving the list. Motion uses Nuxt UI's built-in disclosure transition and respects reduced motion.

FORM: Operational center, grounded candidate 3, seed a5fa713b. Code-led choice confirmed by the user; no approved comp.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## User screenshot correction

The user's 1046px dashboard captures supersede the first visual acceptance: client rows required horizontal scrolling and subscription cells repeated client, company, plan and owner setup. Preserve compact essential fields, move CNPJ/company/owner/dates into explicit row expansion, and use UCollapsible mobile records rather than requiring sideways scrolling for clients and subscriptions. Remove decorative settings subtitles and keep policy/trial explanations in optional help. Plans retain their compact module disclosure. Debounce invoice search to avoid a context and list request per keystroke; preserve current authorization confirmation and honest loading states.

Finish-review correction: keep section navigation in selector form below 1280px, use title-adjacent contextual help popovers without standalone body help rows, and label the mobile enrollment action Ativar ERP for clients without a subscription.
