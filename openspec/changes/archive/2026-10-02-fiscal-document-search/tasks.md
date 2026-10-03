## 1. Backend — semântica do `q`

- [x] 1.1 Criar `backend/tests/Feature/Fiscal/FiscalDocumentSearchTest.php` (factories, padrão de `FiscalDocumentApiTest.php`) com um teste por cenário da spec: número exato, chave de 44 dígitos, nome do cliente insensível a caixa, AND com filtro de modelo, curinga `%`/`_` literal, isolamento entre Accounts, 422 acima do teto. Verificar rodando `cd backend && php artisan test --compact --filter=FiscalDocumentSearch` e confirmando que falha (vermelho).

- [x] 1.2 Adicionar a regra de `q` em `IndexFiscalDocumentRequest::rules()` (`sometimes`, `nullable`, `string`, `max:200`), seguindo os comentários irmãos. Verificar com o teste do teto de 1.1 passando no ramo de validação.

- [x] 1.3 Implementar o ramo do `q` em `FiscalDocuments::filtered()`: trim do valor, igualdade em `numero`, igualdade em `chave_acesso` quando o valor tem exatamente 44 dígitos, `LIKE` escapado (`%`, `_`, `\` como literal, com escape explícito) sobre `lower(clients.name)` via relação de cliente, em docblock seguindo o padrão do arquivo. Verificar com a suíte de 1.1 verde.

- [x] 1.4 Rodar `cd backend && vendor/bin/pint --dirty --format agent` e `cd backend && php artisan test --compact --filter=Fiscal` para garantir que nenhum teste irmão da leitura fiscal quebrou.

## 2. Frontend — módulo puro e tipos

- [x] 2.1 Acrescentar `q?: string | null` a `FiscalListFilters` em `frontend/app/types/fiscal.ts`. Verificar com `cd frontend && pnpm typecheck`.

- [x] 2.2 Acrescentar casos em `frontend/tests/fiscalFilters.test.ts` (importando o fonte com extensão explícita): `parseFiscalFilters` mantém `q` trimmed e descarta vazio/só-espaços; `fiscalQuery` omite `q` nulo/vazio e o envia quando preenchido; `appliedFiscalFilters` substitui `q` e o limpa com string vazia; `fiscalDocumentosPath` codifica o `q` na query. Verificar rodando `node --test tests/fiscalFilters.test.ts` e confirmando que os casos novos falham (vermelho).

- [x] 2.3 Implementar o parse e a serialização do `q` em `frontend/app/utils/fiscalFilters.ts` (trim na leitura, omissão do padrão vazio na escrita), seguindo os docblocks irmãos. Verificar com `node --test tests/fiscalFilters.test.ts` verde.

## 3. Frontend — tela de documentos

- [x] 3.1 Adicionar o campo de busca em `frontend/app/pages/fiscal/documentos.vue`, espelhando o padrão de `clientes.vue`: `UInput` no slot padrão do `DataTableFilter` (ícone `i-lucide-search`, placeholder "Buscar nº, chave ou cliente…", `maxlength` 200, `:disabled="isLoading"`), rascunho sincronizado de `filters.q`, debounce de 300 ms escrevendo via `updateFilters` (volta à página 1), Enter aplicando imediatamente, campo limpo escrevendo `q: null` e "Limpar filtros" limpando o rascunho junto. Verificar com `cd frontend && pnpm lint && pnpm typecheck` e conferindo na tela (`pnpm dev`) que a busca filtra, sobrevive ao F5 e limpa.
  - Conferência na tela feita pelo controlador no navegador (stack de dev, 2026-10-02): `q=Zboncak` via debounce → 5.000 documentos/página 200 de 800; F5 preserva `?q=` e reidrata o campo; "Limpar filtros" remove o `q` da URL e volta a 20.000.

- [x] 3.2 Ajustar o `UEmpty` de `documentos.vue` para citar a busca na descrição quando `q` está ativa (padrão de `hasActiveFilters` em `clientes.vue`, copy em português com o vocabulário do `CONTEXT.md`). Verificar na tela com uma busca sem resultado.
  - Verificado no navegador: `?q=zzzzsemresultado999` → "Nenhum documento corresponde aos filtros" com descrição citando a busca ("A busca e os filtros aplicados não correspondem a nenhum documento capturado nesta conta...") e ação "Limpar filtros".

- [x] 3.3 Rodar `cd frontend && pnpm lint && pnpm typecheck && pnpm test` para fechar a tela sem regressão nos testes irmãos.

## 4. Verificação integrada

- [x] 4.1 Rodar as duas suítes completas antes de qualquer PR: `cd backend && composer test` e `cd frontend && pnpm lint && pnpm typecheck && pnpm test`. Verificar que todas passam e descrever o que foi verificado no corpo do PR.

- [x] 4.2 Conferir a conformidade final com a spec da change: `cd /home/obsidian/dev/opmoni && openspec validate fiscal-document-search` e releitura dos cenários de `specs/fiscal-documents-ui/spec.md` contra os testes de 1.1 e 2.2.
> Fechada (2026-10-02): 12/12 tarefas. Revisão por tarefa aprovada (0 Critical); fix pós-revisão aplicado (identificadores frontend em inglês + teste do curinga `\`). Delta sincronizado na main spec `fiscal-documents-ui` (Requirement "Filtros da tabela" com os 11 cenários); `openspec validate --specs` 23 passed. Minors de corrida URL→input (aceito no design) e `q` só-de-espaços (cosmético) registrados para follow-up.
