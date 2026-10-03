## Context

Ver [proposal.md](proposal.md). Hoje o módulo fiscal fala SOAP com Distribuição NF-e/CT-e (`FiscalSource` duplo, `DfeTransport`, cursor em `fiscal_cursors`). A UI e o enum `FiscalModel::Nfse` existem, mas não há conector ADN. O change archivado `add-fiscal-document-capture` excluiu NFS-e nacional por ser API REST própria, chave de 50 dígitos e HTTP 404 com corpo de negócio. O cliente canário acordado é **Auto Center** (produção real). A entrega prevê **loop de debug no endpoint** antes de congelar fixtures e testes fake.

## Goals / Non-Goals

**Goals:**

- Terceira fonte `nfse_adn` no mesmo pipeline (`FiscalCaptureService`, writer, gaps, reconciliação, jobs, dispatcher pós-upload).
- mTLS com A1 do cliente contra `https://adn.nfse.gov.br/contribuintes` (produção restrita quando `FISCAL_ENVIRONMENT=homologacao`).
- Persistir XML cru, chave de 50 dígitos, metadados e modelo `nfse`.
- Comando `fiscal:nfse-probe` para iterar parse/classificação sem fila.
- Canário Auto Center até checklist de sucesso (doc no DB + cursor coerente).

**Non-Goals:**

- Emissão, cancelamento, manifestação ou DANFSe via API.
- Agenda capturando toda a carteira antes do canário.
- NFC-e por UF.
- Deploy/migration em produção sem autorização explícita.

## Decisions

1. **Fonte separada `nfse_adn`, não reutilizar conector SOAP.** A ADN é REST+JSON; misturar com `DfeTransport`/`retDistDFeInt` esconderia diferenças de classificação HTTP e formato de lote. Alternativa descartada: forçar adaptador SOAP inexistente.

2. **Reutilizar writer, cursor, gaps, lock, orçamento de consulta e `FiscalCaptureDispatcher`.** Comportamento observável já está em `fiscal-capture` spec; só entra implementação nova de conector + leitor. Alternativa descartada: pipeline paralelo só NFS-e (duplicaria tenancy e idempotência).

3. **Migration `chave_acesso` → 50 caracteres.** NFS-e nacional não cabe em `char(44)`. Índices unique `(client_id, chave_acesso, stage, event_id)` permanecem. Alternativa descartada: truncar ou hash da chave (quebraria busca e idempotência).

4. **Validação de chave bifurcada: 44 (DV módulo 11 NF-e) e 50 (DV leiaute NFS-e nacional).** `FiscalXmlMetadata` ganha caminho para família `Nfse`; NF-e/CT-e inalterados. Alternativa descartada: tratar 50 como “fora do catálogo”.

5. **Classificação HTTP antes de parse JSON.** Respostas 404 (e outras) com corpo de negócio passam por camada que lê JSON/códigos antes de lançar exceção de transporte — alinhado ao design archivado. Alternativa descartada: confiar só no status HTTP.

6. **Cursor inicial 0; `last_nsu` = maior NSU devolvido no lote** quando o contrato não expõe `UltimoNSU` como na NF-e. Lacunas e reconciliação seguem regras existentes (sem inferir buraco entre NSUs alheios ao CNPJ). Alternativa descartada: incrementar NSU localmente.

7. **Gate `nfse_enabled` (default false)** lido em dispatcher e reconciliação, espelhando `cte_enabled`. Captura manual/comando exige gate ligado; evita tráfego acidental. Alternativa descartada: ligar por default em dev (risco em produção com `FISCAL_ENVIRONMENT` default produção).

8. **Probe síncrono `fiscal:nfse-probe`.** Depura transporte mTLS, URL (`/contribuintes/` com “s”), encoding e shape JSON sem passar pela fila. Saída só metadados seguros; `--save-fixture` grava JSON anonimizado em `tests/Fixtures/fiscal/nfse-adn/`. Alternativa descartada: só tinker ad hoc (não repetível nem auditável).

9. **Tenancy inalterada.** Jobs carregam `account_id`; modelos `BelongsToAccount`; probe e capture recebem `Client` da Account corrente ou id explícito. Acesso de suporte segue mesma regra de upload/captura com auditoria, sem expor certificado.

10. **Segredos.** Mesmas regras do módulo fiscal: sem log de senha, PFX, XML bruto ou tokens; probe imprime contagem e NSU, não payload XML.

## Risks / Trade-offs

- **[Contrato JSON diverge do manual]** → Mitigação: loop probe → capture; fixture real anonimizada antes dos testes fake.
- **[INSERT falha silencioso se migration atrasar]** → Mitigação: migration na primeira PR; teste de schema com chave 50.
- **[Martelar ADN em debug]** → Mitigação: checklist exige respeitar pausa 1h; um cliente canário.
- **[Encoding Latin-1 vs UTF-8]** → Mitigação: bytes crus no disco; normalizar só na leitura/UI.
- **[Chave 50 quebra busca 44]** → Mitigação: spec UI + `FiscalDocuments` aceitam ambos comprimentos.

## Migration Plan

1. Migration aditiva alargando `fiscal_documents.chave_acesso` (e revisar factory/tests que assumem 44).
2. Deploy código com `nfse_enabled` false; registrar conector no registry.
3. Canário Auto Center com `FISCAL_NFSE_ENABLED=true` local; validar checklist.
4. Congelar fixture + testes; só então considerar agenda (fora deste change).

Rollback: desligar `nfse_enabled`; migration reversível se nenhuma chave >44 persistida (ou manter coluna larga — compatível com NF-e).

## Open Questions

- Formato exato dos campos JSON do lote ADN (nomes, compressão base64/gzip) — resolvido na primeira resposta real do probe Auto Center, não bloqueia desenho do pipeline.
- Código de modelo na chave de 50 dígitos vs. forçar `model=nfse` sempre — implementação segue chave quando possível, senão família unitária do conector.
