## Context

A lista de documentos (`GET /api/fiscal/documents`, serviço `FiscalDocuments`) já tem filtragem por facetas em `filtered()` (`backend/app/Services/Fiscal/Read/FiscalDocuments.php:64`), com validação fechada em `IndexFiscalDocumentRequest` e URL como fonte da verdade no frontend (`frontend/app/utils/fiscalFilters.ts`). O padrão de campo de busca já existe na página irmã `/fiscal/clientes`: um `UInput` no slot padrão do `DataTableFilter` com `refDebounced` de 300 ms (`frontend/app/pages/fiscal/clientes.vue`). Diferença estrutural entre as duas páginas: em `clientes.vue` os filtros são estado local; em `documentos.vue` o filtro inteiro vive na URL e toda escrita passa por `updateFilters` (navegação).

## Goals / Non-Goals

**Goals:**

- Achar um documento direto pelo número que o operador tem na mão (e-mail do cliente, papel), pela chave de acesso ou pelo nome do cliente.
- `q` é só mais um filtro da mesma consulta: AND com as facetas, mesma ordenação, mesma paginação, mesmo `available_models`.

**Non-Goals:**

- Busca insensível a acentos no nome do cliente (exigiria `unaccent`/normalização no banco; ver Risks).
- Busca por prefixo de CNPJ de emitente/destinatário — os filtros `issuer`/`recipient` já fazem exatamente isso.
- Busca dentro do XML, índice de busca externo (Meilisearch etc.) ou busca full-text no Postgres.
- Estender a busca às outras tabelas (clientes, tarefas): a change é da lista de documentos.

## Decisions

1. **O `q` casa por igualdade exata no número, igualdade exata na chave de 44 dígitos e substring insensível a caixa no nome do cliente.**
   A jornada-alvo é "o operador tem o número da nota na mão": igualdade no `numero` (`string(20) nullable`, gravado linha a linha pelo writer) resolve o caso com um comparável por índice e sem ambiguidade — o mesmo valor digitado é o mesmo valor procurado. A chave de acesso é `char(44)` de dígitos; o valor entra como igualdade quando o `q` tem exatamente 44 dígitos (um fragmento de chave não casa — o que procura parte da chave usa o detalhe do documento). O nome do cliente é o único campo que o operador digita de memória e por isso casa por `LIKE %q%`, em `lower()`, sobre `clients.name`.
   Alternativas descartadas: prefixo no número (devolveria lotes de notas vizinhas e quebraria a jornada de "achar a nota"), busca difusa/acentos (exige extensão do banco, escopo desproporcional ao P1), busca por fragmento de chave (sem uso real apurado; a chave inteira é o que copia e cola).

2. **A busca casa linha a linha — o número de uma linha de evento não é o da nota.**
   Linhas de `stage=event` não carregam o `numero` da nota, e expandir a busca para "todas as linhas da chave cujo documento casa" exigiria subconsulta e mudaria o significado de `q` conforme a etapa. A linha do tempo continua alcançável pelo detalhe da folha, que já agrupa por `client_id` + `chave_acesso` — igual a qualquer outro filtro. A busca por chave de 44 dígitos, sim, traz as linhas de evento da mesma chave, porque casa na coluna que elas compartilham.
   Alternativa descartada: `whereIn('chave_acesso', subconsulta por numero)` — semântica não literal, custo de subconsulta e `available_models` divergente da página.

3. **O `q` entra no `filtered()` como mais um `when` — conjunção com tudo o que já existe.**
   É a mesma consulta que alimenta página, ordenação e `available_models`; a busca não pode criar um caminho de leitura paralelo. `modelosDisponiveis()` parte do clone da consulta filtrada, então o `q` restringe a lista de opções como qualquer faceta. Nada muda em `sorted()` nem na paginação: busca é filtro, não modo.

4. **Curingas do `LIKE` são escapados no serviço, e a sanitização de entrada é trim + teto de tamanho.**
   O `q` é texto livre de operador, então `%`, `_` e `\` viram literal antes do `LIKE` (com `ESCAPE` explícito, que vale para SQLite e Postgres) — um `?q=%` que devolvesse a carteira inteira seria um filtro que mente. O valor entra trimmed; vazio vira ausência de filtro. O teto do Request é `max:200` (nomes de cliente são o texto mais longo plausível).
   Alternativa descartada: validar só dígitos como em `issuer`/`recipient` — mataria a busca por nome, que é um dos três casos-alvo.

5. **Contrato HTTP: `GET /api/fiscal/documents?q=<texto>`, 200 com lista filtrada; 422 nomeando `q` acima do teto.**
   A regra no `IndexFiscalDocumentRequest` é `['sometimes', 'nullable', 'string', 'max:200']` depois do trim do serviço — a autorização continua morando no Request e o formato na mesma lista fechada dos demais filtros. O frontend limita o input (`maxlength`) e envia o texto como está, sem truncar: cortar um nome mudaria a resposta em silêncio, e o 422 só fica alcançável por URL escrita à mão, no padrão do que o módulo `fiscalFilters.ts` já documenta para os outros campos.

6. **UI: `UInput` de busca no slot padrão do `DataTableFilter`, valor derivado da URL, debounce de 300 ms com Enter aplicando imediatamente.**
   Espelha `clientes.vue` (ícone `i-lucide-search`, placeholder "Buscar nº, chave ou cliente…", `min-w-0 flex-1`, `:disabled="isLoading"`), mas a fonte é a URL: o input é rascunho sincronizado de `filters.q`, e a escrita passa por `updateFilters`, que volta à página 1 — a navegação é o que faz a busca parecer aplicada. Enter aplica sem esperar o debounce (o operador que colou a chave não deve esperar 300 ms); limpar o campo escreve `q: null` na URL; "Limpar filtros" já devolve a query vazia e limpa o rascunho junto.
   Alternativa descartada: rascunho aplicado só num botão (o padrão do popover de intervalo) — para busca, digitar e ver é o valor; 800 páginas de diff a cada tecla seriam piores que a navegação debounced.

7. **Empty state cita a busca quando ela está ativa.**
   O `UEmpty` de `documentos.vue` ganha descrição que nomeia a busca (e não só "filtros") quando `q` está na URL, seguindo o padrão de `clientes.vue` (`hasActiveFilters` escolhendo a copy). O ícone `i-lucide-search-x` já é o da busca sem resultado.

8. **Guardas de regressão: PHPUnit feature + `node --test`, sem contagens fixas ao repositório.**
   Backend: teste feature em `backend/tests/Feature/Fiscal/` cobrindo os cenários da spec (número exato, chave de 44, nome insensível a caixa, AND com faceta, curinga literal, isolamento por Account, 422 do teto), no padrão de `FiscalDocumentApiTest` (factories, `RefreshDatabase` opt-in por classe). Frontend: casos novos em `frontend/tests/fiscalFilters.test.ts` para parse (mantém `q` trimmed, descarta vazio) e serialização (`fiscalQuery` omite `q` vazio/nulo; `fiscalDocumentosPath` codifica), importando o fonte com extensão explícita. Nenhum teste fixa contagens que mudam com o próprio repositório.

**Tenancy e papéis:** nenhuma tabela nova e nenhum job novo — o `q` é aplicado dentro da consulta que já abre com `where('account_id', $accountId)` e o `account_id` do caminho de leitura continua vindo por parâmetro do serviço. Autorização inalterada: a leitura segue aberta a qualquer Membro (`FiscalDocumentPolicy::viewAny`), o acesso de suporte já escreve com auditoria quando escreve e aqui não escreve nada. Segredos: o `q` é texto de operador, não toca certificado, token nem XML; nada novo entra em log.

## Risks / Trade-offs

- [Acento digitado errado não acha o cliente ("Acucar" vs "Açúcar")] → Limitação assumida e registrada como Non-Goal; o filtro de cliente por `client_id` e a página `/fiscal/clientes` (que normaliza acentos no cliente, já que os dados estão na tela) cobrem o caso. Um `unaccent` no Postgres é a evolução natural, mas exige extensão do banco e não cabe no P1.
- [`LIKE` insensível a caixa sobre `clients.name` sem índice específico] → A consulta já roda sobre o `account_id` filtrado e a busca por nome é uma das três regras, não a única porta de entrada; se o `EXPLAIN` em carteira representativa acusar varredura custosa, migração de índice (ex. `lower(name)` expressional) é tarefa futura isolada, sem mudança de contrato.
- [`q` na URL pode divergir do rascunho do input] → A sincronia é uma via de mão só no sentido URL→input; escrita sempre via `updateFilters`, e o `watch` de sincronia só atua quando o valor difere. Teste do módulo puro fixa o contrato de parse/serialização.
- [Debounce gera navegação no meio de uma digitação] → 300 ms como em `clientes.vue`; Enter aplica na hora, e uma navegação com `q` mais curto substitui a anterior sem pilha de histórico extra (a URL é substituída pela query nova, padrão de `navigateTo`).

## Migration Plan

Sem migration, sem variável de ambiente, sem feature flag: é um filtro a mais num endpoint existente, aditivo (sem `q`, resposta idêntica à de hoje). Rollback é o revert do código; nada fica gravado. Sem deploy em produção sem autorização explícita.

## Open Questions

Nenhuma — a semântica do `q`, o contrato HTTP e a interação de UI foram fixados nas decisões acima.