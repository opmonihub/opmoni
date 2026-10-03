## Why

A página Carteira › Documentos (`/fiscal/documentos`) não tem busca por texto: com uma carteira de 20.000 documentos, achar uma nota específica exige crawlar até 800 páginas de 25. A auditoria de interface (`score 27/40`, snapshot em `.impeccable/critique/`) aponta isto como o P1 da tela, e a jornada-alvo é concreta: o operador tem o número da nota na mão — veio de e-mail do cliente ou de papel — e precisa achar o documento direto.

## What Changes

- Nova busca por texto (`?q=`) na tabela de documentos, que casa por **número da nota exato**, **chave de acesso exata** e **nome do cliente** (LIKE, insensível a maiúsculas).
- `q` é só mais um filtro: combina por AND com modelo, tipo, cliente, emitente, destinatário, período e valor, entra na URL como qualquer outro filtro e participa da mesma ordenação e paginação.
- Campo de busca no `DataTableFilter` da página, espelhando o padrão já existente em `/fiscal/clientes` (input com debounce de 300 ms no slot padrão), com aplicação imediata no Enter e limpeza pelo "Limpar filtros".
- Empty state da lista cita a busca quando ela está ativa, em vez do texto genérico de filtro.
- Guardas de regressão nos dois lados: PHPUnit da lista (semântica do `q`, escapes de LIKE, isolamento por Account) e teste `node --test` de `fiscalFilters.ts` (parse e serialização do `?q=`).

## Capabilities

### New Capabilities

Nenhuma

### Modified Capabilities

- `fiscal-documents-ui`: o requisito "Filtros da tabela" passa a incluir a busca por texto (`?q=`) e os cenários da semântica dela (número, chave de acesso, nome do cliente, combinação com os outros filtros).

## Impact

**Backend**

- `backend/app/Http/Requests/Tenant/IndexFiscalDocumentRequest.php`: nova regra para `q` (string, teto de tamanho, sanitização por trim).
- `backend/app/Services/Fiscal/Read/FiscalDocuments.php`: `filtered()` ganha o ramo do `q` (número exato, chave exata, LIKE no nome do cliente com escape de curingas).
- `backend/tests/Feature/Fiscal/FiscalDocumentApiTest.php` (ou teste irmão): guardas da semântica do `q`.

**Frontend**

- `frontend/app/types/fiscal.ts`: `FiscalListFilters` ganha `q?: string | null`.
- `frontend/app/utils/fiscalFilters.ts`: `parseFiscalFilters`/`fiscalQuery` leem e serializam `?q=`; `appliedFiscalFilters` já o cobre por composição.
- `frontend/app/pages/fiscal/documentos.vue`: campo de busca no `DataTableFilter` (padrão de `clientes.vue`), sincronia com a URL, empty state citando a busca.
- `frontend/tests/fiscalFilters.test.ts`: guardas de parse/serialização.

**Provedor**: nenhum — é leitura da base já capturada, sem chamada nova ao SERPRO.