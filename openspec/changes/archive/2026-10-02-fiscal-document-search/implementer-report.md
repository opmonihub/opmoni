# Relatório do implementador — change `fiscal-document-search`

**Status: DONE_WITH_CONCERNS**

## Resumo

A busca por texto (`?q=`) está implementada nos dois lados, seguindo as decisões de `design.md` verbatim:

- **Backend**: igualdade exata no `numero`, igualdade na `chave_acesso` quando o `q` tem exatamente 44 dígitos, `LIKE %q%` insensível a caixa sobre `lower(clients.name)` via `whereHas('client')` (que já abre o `withTrashed` da relação), com `%`, `_` e `\` escapados e `ESCAPE '\\'` explícito no SQL (o SQLite não tem caractere de escape por padrão). O valor entra trimmed e vazio não é filtro; a conjunção com as demais facetas é garantida por ser um `when` a mais na mesma consulta de `filtered()`.
- **Frontend**: `q` é um filtro como outro qualquer em `FiscalListFilters`, parseado com trim (vazio/só-espaços descartado) e serializado só quando tem texto. A tela `documentos.vue` ganhou o `UInput` no slot padrão do `DataTableFilter` (ícone `i-lucide-search`, placeholder "Buscar nº, chave ou cliente…", `maxlength` 200, `:disabled="isLoading"`), rascunho inicializado e sincronizado de `filters.q` (só quando difere), debounce de 300 ms escrevendo via `updateFilters` (volta à página 1), Enter aplicando imediatamente, campo limpo escrevendo `q: null` e "Limpar filtros" limpando o rascunho pela mesma via. O `UEmpty` cita a busca na descrição quando `q` está ativa (`buscaAtiva`).

## Evidência (arquivo:linha)

- `backend/app/Services/Fiscal/Read/FiscalDocuments.php:85-100` — ramo do `q` em `filtered()`: trim, 44 dígitos → igualdade em `chave_acesso`; fora disso, igualdade em `numero` + `whereHas('client')` com `lower(clients.name) like ? escape '\\'` e `addcslashes(mb_strtolower($q), '\\%_')`.
- `backend/app/Services/Fiscal/Read/FiscalDocuments.php:63-84` — docblock de `filtered()` documentando a semântica.
- `backend/app/Http/Requests/Tenant/IndexFiscalDocumentRequest.php:47-52` — regra `'q' => ['sometimes', 'nullable', 'string', 'max:200']` com comentário irmão.
- `backend/tests/Feature/Fiscal/FiscalDocumentSearchTest.php` — 9 testes: número exato (sem prefixo e sem trazê-lo por subconsulta), chave de 44 (traz documento + eventos da mesma chave, não traz outra chave), nome insensível a caixa + fragmento, AND com `model[]`, curingas `%`/`_` literais (o `%` casa só o nome que contém o próprio caractere), isolamento entre contas nos dois sentidos, 200 vazio, `q` vazio/só-espaços sem filtrar e trim nas bordas, 422 acima de 200 nomeando `q`.
- `frontend/app/types/fiscal.ts:321-327` — `q?: string | null` em `FiscalListFilters` com docblock.
- `frontend/app/utils/fiscalFilters.ts:206-211` — parse com trim e descarte do vazio; `:258` — `fiscalQuery` omite `q` vazio/nulo.
- `frontend/tests/fiscalFilters.test.ts:137-141, 227-242, 256-266` — round-trip com `q`, parse trimmed/descartado, serialização, caminho codificado (`%25`), substituição e limpeza via `appliedFiscalFilters`.
- `frontend/app/pages/fiscal/documentos.vue:111-147` — rascunho `busca`, `refDebounced` 300 ms, `watch` de sincronia URL→input, `aplicaBusca()` via `updateFilters`, Enter; `:479-486` — `UInput` no slot padrão do `DataTableFilter`; `:630-634` — descrição do `UEmpty` citando a busca (`buscaAtiva`).

## Verificação (os 4 comandos)

| Comando | Resultado |
|---|---|
| `cd backend && composer test` | **exit 0** — 1262 testes, 1259 passados, 3 skipped (grupo `serpro-trial`, fora por padrão), 0 falhas |
| `cd backend && php artisan test --compact --filter=Fiscal` | **exit 0** — 447 testes, 444 passados, 3 skipped, 0 falhas (nenhum irmão quebrou) |
| `cd backend && vendor/bin/pint --dirty --format agent` | **exit 0** — ajustou só `ordered_imports`/fim de arquivo no teste novo |
| `cd frontend && pnpm lint && pnpm typecheck && pnpm test` | **exit 0** — lint ok, typecheck ok, 371 testes passados, 0 falhas |
| `node --test tests/fiscalFilters.test.ts` (isolado) | 24/24 — casos novos primeiro em vermelho, depois verdes |
| `cd /home/obsidian/dev/opmoni && openspec validate fiscal-document-search` | **exit 0** — "Change 'fiscal-document-search' is valid" |

## Verificação adicional na API de dev (Postgres real)

A suíte roda em SQLite; para provar o `LIKE`/`ESCAPE`/`lower()` no Postgres, fiz login de dev via HTTP (`admin@example.com`, seed do `DevAdminSeeder`) e consultei `GET /api/fiscal/documents` contra as 20.000 linhas do banco de dev:

- `q=<chave de 44 dígitos>` → 1 linha, a certa.
- `q=WISOZK` e `q=wisozk` (fragmento do nome "Wisozk-Ward", caixas opostas) → 5.000 linhas.
- `q=%25` (curinga `%`) → **0** linhas, e não a carteira inteira.
- `q=_` → 0 linhas.
- `q` com espaços nas bordas + chave → 1 linha (trim).
- `q` de 201 caracteres → **422** nomeando `q` ("The q field must not be greater than 200 characters.").
- `q=wisozk&model[]=cte` → 0 (AND com faceta; a dev só tem `nfe`).
- Número exato: nenhum dado de dev tem `numero`, então escrevi `12345` na linha 1 via tinker, conferi `q=12345` → 1 linha, `q=1234`/`q=123456` → 0 (igualdade, não prefixo) e **restaurei `numero` para `null`** — o dado de dev voltou ao estado original.

## Arquivos modificados

- `backend/tests/Feature/Fiscal/FiscalDocumentSearchTest.php` (novo)
- `backend/app/Http/Requests/Tenant/IndexFiscalDocumentRequest.php`
- `backend/app/Services/Fiscal/Read/FiscalDocuments.php`
- `frontend/app/types/fiscal.ts`
- `frontend/app/utils/fiscalFilters.ts`
- `frontend/tests/fiscalFilters.test.ts`
- `frontend/app/pages/fiscal/documentos.vue`
- `openspec/changes/fiscal-document-search/tasks.md` (checkboxes)

Nada foi commitado; o diff fica para revisão do controlador.

## Concerns

1. **Tarefas 3.1 e 3.2 ficaram sem marcar** em `tasks.md` de propósito: o código está escrito, `pnpm lint`/`typecheck`/`test` passam e a lógica está coberta pelo módulo puro + API de dev, mas a verificação delas exige **conferência na tela** (`pnpm dev`) — que o browser do IDE não abriu ("No browser tab available" em todas as tentativas de navegação/aba). Falta conferir visualmente: busca filtra, sobrevive ao F5, limpa, e o empty state citando a busca sem resultado. Um humano (ou o browser do IDE em sessão com aba) fecha isso em poucos minutos.
2. `lower()` no SQLite (suíte) só lida com ASCII; no Postgres de dev a caixa acentuada é coberta pelo collation. A insensibilidade a **acentos** permanece Non-Goal por design (Risks do design.md).
3. O teste de isolamento entre contas usa membro da própria conta em ambos os sentidos (conta corrente → não vê alheia; membro alheio → não vê a casa), e não um membro tentando a conta errada — o `account_id` continua vindo por parâmetro do serviço, inalterado.