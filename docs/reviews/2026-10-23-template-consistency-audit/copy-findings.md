# Opmoni visible-copy and information-density audit

Read-only snapshot: `/home/obsidian/dev/opmoni/.worktrees/template-consistency-audit-20261023T215943Z/copy`.

## Scope and confidence

This is a source-backed inventory for a later refactoring decision, not an implementation or a browser acceptance report. Product code, dependencies, data, and configuration were not changed. No tests, browser sessions, or design detector were run. The audit follows Impeccable's clarify guidance: say each idea once; preserve consequences, recovery instructions, permissions, fiscal meaning, and product terminology. The skill launcher was deliberately omitted because this assignment authorizes source reading and report files only.

The audience is Brazilian accounting-office staff and ERP company operators completing recurrent operational tasks. Main finding: repeated explanatory framing and engineering vocabulary create more avoidable reading than unusually long titles. Most titles are already short. Length alone does not identify a defect.

Inventory scanned **90 page files, 144 Vue component files, and 56 utility files**. This means path enumeration plus a lexical index of description copy, followed by contextual reading of the strongest candidates and their consumers; it does not mean every line or every runtime state received a complete UX review. All requested families were covered in the source index: ERP (40 pages), admin (10), settings (7), Work (7), customers (4), fiscal (4), monitoring (2), equipe (2), and root/wrapper/auth pages (14).

| Source set | Files indexed | Literal description occurrences | Occurrences over 120 characters |
| --- | ---: | ---: | ---: |
| Pages | 90 | 98 | 6 |
| Vue components | 144 | 90 | 14 |
| Utilities | 56 | 78 | 21 |
| Total | 290 | 266 | 41 |

Counts are lexical triage only: static `description="…"` attributes and single-line, single-quoted `description`/`emptyDescription` object values. They include option help, alerts, conditional/possibly unused copy and source examples; they exclude interpolated descriptions, multiline text, and `<p>` bodies. They are **not counts of rendered paragraphs, distinct strings, or defects**. `inventory.json` contains the exact indexed files and literal occurrences. The 120-character threshold is only a reproducible queue for human inspection. Twelve contextual findings below are the audit's actual recommendations.

Reference tree inventory: `.dashboard/app` has 8 pages and 14 components. Contextual comparisons used `.dashboard/app/pages/settings.vue`, `settings/index.vue`, `customers.vue`, and `index.vue`.

## Shared sources of repetition

| Source | Observed spread | Why it matters |
| --- | --- | --- |
| `frontend/app/components/erp/ErpSettingsSectionForm.vue:110` | 8 page call sites | Every section supplies a title and description to a `UPageCard`. The parent `erp/configuracoes.vue:15` already names the area, and its tabs name the section. Descriptions should be optional editorial choices; warnings and field requirements remain separate. |
| `frontend/app/components/erp/ErpCatalogPageShell.vue:34` | 21 call sites across pages/components | Descriptions become a paragraph before the working surface. The API already makes description optional, so concise pages can omit it without inventing a new primitive. Some intros are useful and must remain. |
| `frontend/app/components/erp/catalogs/ErpCatalogResource.vue:428` | 13 resource instances in 9 pages | The same generic drawer explanation appears for every create/edit/detail flow. This is the highest-reuse location for removing copy that only restates the controls. |
| `frontend/app/components/data-table/PanelList.vue:37` | 9 page call sites | It adds another title/description card below parent navbar and tabs. The helper permits omission of description, but page-level titles still need a hierarchy decision. In Admin, navbar titles already derive from the active page. |
| `frontend/app/utils/erpModulesNav.ts:295` | 8 contracts; Reports visibly consumes its contract description | The registry blends product language with implementation vocabulary: `ledgers`, `snapshots`, `commit`, `retry`, `append-only`, `actor`. Inspect consumption before editing: `ErpModuleHub.vue` has **zero call sites** in pages/components; many registry empty-state strings are therefore latent, not proven current UI. Reports is a confirmed path: `ErpReportsWorkspace.vue:9,122`. Do not report all registry strings as live defects. |
| `frontend/app/components/customers/ClientPortfolioEmpty.vue:39` | Shared filtered/empty/status portfolio states | Long explanations repeat the title before the useful action. One copy change improves several portfolio states, while permission-specific first-use guidance should remain. |
| `frontend/app/utils/pageShell.ts` | Layout tokens | This file contains layout classes, not visible copy. It is not a copy-noise source and should not be rewritten for this finding. |

## Strongest examples and proposed decisions

Severity here is relative to copy/scanability: **Medium** means ambiguity, misleading emphasis, engineering language, or repetition that materially impedes scanning; **Low** means avoidable reading with a clear existing task. No critical legal/security warning is classified as disposable.

### C1 — Company settings intro repeats the form's purpose — Low

Source: `frontend/app/pages/erp/configuracoes/empresa.vue:9-10`; rendered by `components/erp/ErpSettingsSectionForm.vue:113-114`.

Current title: **“Dados da empresa”**.

Current full description: **“Mantenha os dados cadastrais, o endereço e os parâmetros fiscais da empresa.”**

Proposal: remove this description. Keep “Dados da empresa”, the form labels, and field-level fiscal instructions. If an overview is explicitly needed, use “Cadastro, endereço e dados fiscais.”

Reason: the title and immediately visible field groups already disclose the task. The sentence adds neither a rule nor a consequence. Do not remove the section title merely because the parent says “Configurações da empresa”.

### C2 — A catalog introduction defines the same noun as the heading — Low

Source: `frontend/app/pages/erp/cadastros/marcas.vue:20`.

Current title: **“Marcas”**.

Current full description: **“Marcas comerciais disponíveis para os produtos da empresa.”**

Proposal: remove the description; retain “Marcas”, “Adicionar marca”, and “Buscar marca”.

Reason: it defines an obvious catalog rather than helping a decision. The resource at line 36 already has `show-heading=false`, so this is **not** a claim that the brand heading currently appears twice. The remaining redundancy is title plus definition.

### C3 — Generic drawer instructions spread across catalogs — Low

Source: `frontend/app/components/erp/catalogs/ErpCatalogResource.vue:430-431`.

Current title patterns: **“Detalhes · {registro}”**, **“Editar · {registro}”**, **“Novo cadastro · {catálogo}”**.

Current full descriptions by mode: **“Consulta do cadastro.”** / **“Preencha os dados e salve para atualizar o cadastro.”**

Proposal: remove the generic visible descriptions; retain the descriptive title and the save action. Where the component's dialog semantics need a description, use a concise contextual accessible description through its supported API rather than a visible sentence on every record. Verify accessible dialog naming in implementation.

Reason: the first sentence restates “Detalhes”; the second restates form editing and the save button. It also says “atualizar” in create mode. Do not remove record-specific validation, archive consequences, or failure recovery.

### C4 — Reports expose accounting implementation vocabulary — Medium

Source: `frontend/app/utils/erpModulesNav.ts:298`; confirmed visible consumer `frontend/app/components/erp/ErpReportsWorkspace.vue:122`.

Current title: **“Relatórios”**.

Current full description: **“Relatórios reconciliados aos ledgers lançados; rascunhos e tentativas fiscais indeterminadas não inflam totais.”**

Proposal: **“Os totais consideram lançamentos confirmados. Rascunhos e transmissões fiscais sem resultado confirmado ficam de fora.”**

Reason: the exclusion rule is useful product truth, so shortening must preserve it. “Ledgers” and “não inflam” add avoidable interpretation. Place this as help near report totals, rather than requiring every user to read it before choosing report and period. Keep the separate stock/current-position and filter-scope limitations already provided by the report.

### C5 — Accounting deliveries describe projections instead of the user's object — Medium

Source: `frontend/app/pages/erp/contabilidade/index.vue:8`.

Current title: **“Contabilidade”**.

Current full description: **“Acompanhe documentos fiscais autorizados que foram projetados no histórico do escritório delegado.”**

Proposal: **“Acompanhe a entrega de documentos fiscais autorizados ao escritório.”**

Reason: the user tracks document delivery; “projetados no histórico” describes an internal mechanism. Keep delegation/approval status near the selected office and delivery, where it affects the user's action. Do not imply successful delivery until the actual status confirms it.

### C6 — ERP member management uses internal identity vocabulary — Medium

Source: `frontend/app/components/erp/ErpCompanyAdministration.vue:394` (member branch; establishment branch is separate).

Current full member description: **“Gerencie o acesso de identidades existentes à empresa e aos seus estabelecimentos.”**

Proposal: **“Gerencie o acesso de usuários já cadastrados à empresa e aos estabelecimentos.”**

Reason: “identidades existentes” is implementation language. “Já cadastrados” preserves the important restriction that this is not a new-user registration flow. Preserve owner-only administration and per-establishment scope explanations near their controls.

### C7 — Office ERP integration mixes navigation, mechanism, and fiscal caveat — Medium

Source: `frontend/app/pages/settings/integracoes-erp.vue:10-14`.

Current title: **“Documentos recebidos”**.

Current full description: **“Após a aprovação e a entrega, consulte o documento no Fiscal deste escritório e use o detalhe e o download XML privados com verificação de integridade. A disponibilidade do XML não confirma manifestação do destinatário nem validação fiscal conclusiva.”**

Proposal main text: **“Após a aprovação e a entrega, os documentos ficam disponíveis no Fiscal deste escritório.”**

Preserve a separate visible fiscal note: **“Ter o XML não confirma a manifestação do destinatário nem a validação fiscal conclusiva.”**

Move the technical detail about private download/integrity to help next to XML download, keeping all actual access and integrity controls.

Reason: three different messages compete in a navigation card. Separating the fiscal limitation preserves its salience while giving the action “Abrir documentos fiscais” a clear introduction. Do not delete the fiscal caveat as mere verbosity.

### C8 — Admin repeats the active page title and a tautological definition — Low

Source: `frontend/app/pages/admin/usuarios.vue:89-91`; parent `frontend/app/pages/admin.vue:20`; title resolver `frontend/app/utils/adminNav.ts:62-63`.

Current visible title: **“Usuários”** in the navbar, the active tab, and the `DataTablePanelList` header.

Current full description: **“Pessoas com acesso ao sistema.”**

Proposal: keep the canonical page title “Usuários”; remove the repeated body header and description when the parent already supplies the page name. Preserve an accessible label for the table if needed.

Reason: the description defines “Usuários” without scope or action information. This is a proven stacked-title case, unlike the catalog example. Apply the hierarchy decision to other Admin lists after checking their meaningful commercial/access descriptions individually; do not blanket-remove them.

### C9 — Platform credential help exposes API behavior in the main paragraph — Medium

Source: `frontend/app/pages/admin/serpro.vue:364-368`.

Current title: **“Conexão com o Integra Contador”**.

Current full description: **“A credencial é única, pertence à plataforma e vale para todas as contas. O segredo é somente escrita: a API nunca o devolve e esta tela não o exibe.”**

Proposal main text: **“Esta conexão é compartilhada por todos os escritórios da plataforma.”**

Preserve near the secret input: **“O segredo salvo não pode ser consultado.”**

Reason: the shared scope is operationally important. “Somente escrita” and “a API nunca o devolve” are implementation details; the user needs to know the saved secret cannot be recovered here. Keep credential replacement/security consequences near the relevant action.

### C10 — Fiscal fallback describes endpoint publication — Medium

Source: `frontend/app/pages/fiscal/clientes.vue:476-477`.

Current title: **“Resumo por cliente ainda não disponível”**.

Current full description: **“O agregado de documentos por cliente ainda não foi publicado. A tabela de documentos continua funcionando normalmente.”**

Proposal title: **“Resumo por cliente indisponível”**.

Proposal description: **“Consulte os documentos na aba Documentos.”**

Reason: “agregado” and “publicado” describe the backend release state rather than a useful next step. Retain the failure state instead of displaying an empty portfolio. The existing documents route is `/fiscal/documentos`; do not assert it is currently healthy merely from a missing summary endpoint.

### C11 — Work empty state explains the table's grouping rather than recovery — Low

Source: `frontend/app/pages/work/processos/index.vue:644-645`.

Current title: **“Nenhum processo neste mês”**.

Current full description: **“Os processos gerados a partir dos modelos aparecem aqui agrupados por processo, cliente e tarefa.”**

Proposal: **“Os processos são gerados a partir dos modelos.”** Move grouping explanation to optional introductory help, if user research indicates it is needed. For managers, an eventual context-aware link to models could make the state actionable; do not promise or implement that behavior as part of this copy-only recommendation.

Reason: the title already states the empty period. Repeating the future layout does not help users understand where processes originate. Preserve the month distinction and the separate no-filter-results state. `work/modelos/index.vue:117` has useful explanatory first-use copy and should not be removed by a blanket rule.

### C12 — Customer no-results state says the same fact twice — Low

Source: `frontend/app/components/customers/ClientPortfolioEmpty.vue:44-45`.

Current title: **“Nenhum cliente encontrado”**.

Current full description: **“Não há clientes que correspondam à busca ou aos filtros aplicados. Ajuste os critérios para continuar.”**

Proposal: **“Ajuste a busca ou limpe os filtros.”**

Reason: the first sentence repeats the title; the second can name the existing recovery actions directly. Preserve distinct first-use, permission-specific, and certificate/procuração status states, which are meaningful differences rather than redundant variants.

## Useful copy to preserve

1. **Fiscal/legal consequences, even when long.** `frontend/app/pages/fiscal/processamento.vue:134-136`: “A ciência da emissão (210210) e a disponibilidade do XML não confirmam a operação. A manifestação conclusiva permanece sob responsabilidade do destinatário em até 90 dias da autorização pelo fisco, conforme as regras aplicáveis. O prazo de elegibilidade da ciência automática é de 10 dias da autorização e não substitui esse prazo conclusivo.” This distinguishes different actions and deadlines. Keep it visible and factually intact unless reviewed through the fiscal decision process; no legal revalidation was done in this audit.
2. **Honest release gates and local-only outcome.** `frontend/app/components/erp/ErpFiscalDocumentForm.vue:228-229`: title “Transmissão indisponível”; description “A emissão permanece bloqueada enquanto o serviço fiscal não estiver disponível. O rascunho pode ser preparado para validação local.” It tells users what is blocked and what remains possible. Preserve the nearby production-blocked warning too.
3. **Concrete destructive consequence.** `frontend/app/pages/customers/empresa/[id].vue:1333-1334`: title “Excluir cliente”; description “A exclusão tira o cliente da carteira e elimina o certificado A1 ativo.” This adds material information beyond the button label. Keep it and the actual deletion confirmation.

Other preservation boundaries: unknown costs and historical valuation limits; delivery/delegation approval; quotas and billed SERPRO operations; message-opening science and deadlines; read-only account state; stale data versus empty data; unconfirmed operations and instructions against repeating submissions; sale returns that do not refund payments. None are generic fluff.

## Reference comparison and future editing rules

The template supports restraint, not indiscriminate deletion:

- `.dashboard/app/pages/customers.vue:212` and `index.vue:28` use a compact navbar title with actions and the actual working surface; they do not add a generic prose definition of “Customers” or “Home”. ERP catalog/workspace intros can follow that restraint.
- `.dashboard/app/pages/settings.vue:38` names the settings area, while `settings/index.vue:58-59` names the Profile subsection and supplies a specific consequence (“These informations will be displayed publicly.”). Those titles have different scope; that is not the same duplication as Admin navbar “Usuários” plus body “Usuários”.
- The reference is not a product-copy authority. Opmoni still has English sample helper copy in `settings/index.vue:75,88,102,143`, a demonstration disclaimer at `:57`, and sample security/notification claims. Example full current English text at `:75`: “Will appear on receipts, invoices, and other communication.” Do **not** simply translate it to a production claim: this form is explicitly a demonstration. Prefer removing irrelevant sample promises, translating actual labels, and retaining the explicit no-save disclaimer. Security/deletion claims must match real behavior before publication; this audit did not exercise those flows.
- Meaningful product distinctions should stay: ERP commercial contacts versus office portfolio clients (`erp/cadastros/contatos.vue:24`), import preview versus commit (`erp/cadastros/importacao.vue:25`), and first-use explanation of models (`work/modelos/index.vue:117`). These are stronger reasons for a description than a count of its characters.

A future copy pass should decide each paragraph's function before editing: orient the first-time user, state a consequence, explain a requirement, report a state, or offer recovery. Omit sentences that only repeat the title or button; move uncommon technical background to contextual help; keep consequential warnings visible. Avoid universal length caps, changing regulatory terms casually, and deleting component descriptions without checking dialog accessibility. No product refactoring has been performed.
