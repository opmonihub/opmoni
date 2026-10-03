## Why

A distribuição DF-e entrega, antes da manifestação do destinatário, apenas **resumos** (`resNFe`) da NF-e de terceiros — o XML completo só é liberado com NSU próprio depois do evento de manifestação (NT 2014.002 v1.30, §3.7.3). O cliente canário recebeu 44 resumos e poucos XML completos, e o change arquivado `add-fiscal-document-capture` deliberadamente não incluiu assinatura XML nem manifestação, o que hoje impede fechar a lacuna pela via recomendada. A consulta pontual por chave (`consChNFe`) só devolve o XML completo se o CNPJ destinatário já manifestou — por qualquer sistema —, então a ciência da emissão (210210) enviada cedo pela plataforma é o que destrava tanto o NSU quanto a consulta.

## What Changes

- Assinatura digital XMLDSig do evento de manifestação com o certificado A1 do cliente — primeira assinatura do módulo fiscal (a distribuição não assina; ver DfeTransport).
- Serviço de manifestação de ciência da emissão (210210) via `nfeRecepcaoEvento`, reutilizando o transporte mTLS (`HttpPkcs12ClientOptions`/`DfeTransport`) e o certificado do próprio cliente.
- Integração com a captura: após gravar um resumo (`resNFe`), enfileirar a ciência (dentro do prazo de 90 dias, com orçamento e janelas anti-bloqueio) para o Ambiente Nacional gerar NSU com o procNFe.
- Tratamento da rejeição 573 ("duplicidade de evento" — já manifestado por terceiro) como estado conhecido, seguindo para a consulta pontual em vez de retries.
- Documentação do reuso da consulta por chave (`consChNFe`) para urgências/lacunas — comportamento já coberto pela spec `fiscal-capture` (consulta pontual com teto de 20/h e bloqueio de 1h); ressincronização de resumos pendentes entra como tarefa opcional.
- Gate de configuração `fiscal.manifestacao_enabled` (default false) e registro de auditoria da manifestação (quem, operação, data) com tenancy `account_id` explícita.
- UI mínima: coluna/estado na tabela de documentos indicando "resumo aguardando XML" versus "XML completo".

**Fora do escopo (decisões de produto pendentes ou inviáveis):** confirmação da operação (210200) automática — só manual ou decisão futura, pois bloqueia o cancelamento pelo emitente; baixa/desmanifestação de evento; manifestação de CT-e; `NfeDownloadNF` (serviço desativado desde 01/06/2017, nunca usar).

## Capabilities

### New Capabilities

- `fiscal-manifestacao`: assinatura XMLDSig do evento com o A1 do cliente, envio automático da ciência da emissão (210210) após resumo capturado, deduplicação da rejeição 573, gate de configuração, auditoria e janelas de consumo — fluxo recomendado distNSU + ciência cedo + `consChNFe` para urgências/lacunas.

### Modified Capabilities

- `fiscal-capture`: o requirement "Módulos fora do escopo" hoje proíbe enviar manifestação do destinatário; passa a deixar a manifestação regida pela nova capability `fiscal-manifestacao`, mantendo as proibições de emissão de documento e de renderização de DANFE.
- `fiscal-documents-ui`: a tabela de documentos passa a expor, por linha, o estado de completude do XML (resumo aguardando XML versus XML completo), para o escritório enxergar o que ainda não foi destravado pela manifestação.

## Impact

**Backend** (`backend/`):
- Novos serviços em `backend/app/Services/Fiscal/Manifestacao/` (assinatura XMLDSig, montagem do evento, conector `nfeRecepcaoEvento`, classificação da rejeição 573), reutilizando `backend/app/Support/HttpPkcs12ClientOptions.php` e `backend/app/Services/Fiscal/Support/DfeTransport.php` (ou adaptador próximo).
- Hook no pipeline de captura (`FiscalCaptureService` e jobs em `backend/app/Jobs/`) para enfileirar ciência após gravar resumo, respeitando `FiscalLookupBudget` e janelas de bloqueio.
- Migration aditiva para auditoria/estado de manifestação (modelos com `BelongsToAccount`, `account_id` explícito em jobs).
- Novas chaves em `backend/config/fiscal.php` (`manifestacao_enabled`, default false) no padrão de `cte_enabled`/`nfse_enabled`.
- Dependência nova para assinatura: `robrichards/xmlseclibs` (biblioteca xmlseclibs canônica) — decisão tomada na design e aprovada pelo usuário.

**Frontend** (`frontend/`):
- Coluna/estado de completude na tabela de documentos (`frontend/app/pages/` e tipos em `frontend/app/types/`), reutilizando tokens e componentes do DESIGN.md.

**Provedor:** SEFAZ Ambiente Nacional — serviço `nfeRecepcaoEvento` (evento 210210, XML assinado) e regras da NT 2014.002 e NT 2020.001 v1.60 (prazo de 90 dias, rejeição 573).