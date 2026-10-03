## Why

A captura fiscal do produto já cobre NF-e e CT-e pelo Ambiente Nacional (SOAP + A1 do cliente), mas a NFS-e padrão nacional — obrigatória em expansão e distribuída pelo ADN — ficou explicitamente fora do change original. O escritório continua sem visibilidade das NFS-e em que o cliente é prestador, tomador ou intermediário, embora o certificado A1 e o pipeline de cursor, lacunas e reconciliação já existam.

A integração REST do ADN (`/contribuintes/DFe/...`) é distinta o suficiente para merecer uma fonte nova, mas reutiliza as mesmas garantias operacionais (tenancy, idempotência, bloqueio por consumo, canário antes de agenda). Faz sentido agora porque há cliente real com certificado (Auto Center) para validar produção em loop de debug até a primeira captura bem-sucedida.

## What Changes

- **Nova fonte de captura `nfse_adn`**: API REST do ADN para contribuintes, mTLS com o A1 do cliente, lote de até 50 DF-e por NSU, consulta pontual por NSU e por chave, reconciliação e job/comando existentes.
- **Chave de acesso de 50 dígitos** para NFS-e nacional: migration em `fiscal_documents.chave_acesso`, validação de dígito verificador e busca na listagem por chave completa de 44 **ou** 50 dígitos.
- **Metadados e modelo `nfse`**: parse de XML NFS-e/eventos do ADN, persistência no disco `fiscal` e exibição nos filtros já previstos na UI.
- **Gate de instalação `nfse_enabled`** (default desligado), no mesmo espírito de `cte_enabled`: canário manual antes de captura em massa na carteira.
- **Comando de probe** `fiscal:nfse-probe` para debug síncrono no endpoint de produção (Auto Center), sem fila, com logs seguros e export opcional de fixture anonimizada.
- **Fora de escopo**: emissão de NFS-e, manifestação, DANFSe via API (suspensa), NFC-e municipal, captura agendada para toda a carteira antes do canário aprovado.

## Capabilities

### New Capabilities

- Nenhuma (comportamento entra nas specs existentes de captura e UI de documentos).

### Modified Capabilities

- `fiscal-capture`: terceira fonte ADN NFS-e; chaves de 50 dígitos; regras REST (incl. resposta de negócio com HTTP enganoso); gate `nfse_enabled`; inclusão da fonte no despacho pós-upload quando habilitada.
- `fiscal-documents-ui`: busca por chave de acesso de 50 dígitos na tabela de documentos.

## Impact

- **Backend:** `app/Enums/FiscalSource.php`, `app/Services/Fiscal/Nfse/` (transporte, leitor JSON, conector), `FiscalConnectorRegistry`, `FiscalXmlMetadata`, `config/fiscal.php`, migration `chave_acesso`, comando `fiscal:nfse-probe`, `FiscalCaptureDispatcher` / reconciliação / enums de falha; testes em `tests/Feature/Fiscal/` e fixtures `tests/Fixtures/fiscal/nfse-adn/`.
- **Frontend:** ajuste de tipos/comentários de busca (`fiscal.ts`); sem telas novas — filtro NFS-e já existe.
- **Provedor:** ADN NFS-e produção `https://adn.nfse.gov.br/contribuintes` (homologação = produção restrita via `FISCAL_ENVIRONMENT`); tráfego real só no canário acordado; grupo PHPUnit opcional para probe live, fora do CI padrão.
- **Operação:** primeiro cliente canário (Auto Center); respeitar pausa de 1h quando o ADN indicar fila vazia; deploy/migration de produção só com autorização explícita.
