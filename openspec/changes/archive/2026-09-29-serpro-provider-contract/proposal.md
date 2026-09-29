## Why

`serpro-contract-and-mailbox` foi arquivada com a leitura de mensagem implementada e itens que só fecham com chamadas reais ao provedor. Esta change fecha o que o trial consegue provar.

## What Changes

- Gravar as fixtures do `SITFIS` a partir de respostas reais do trial, no lugar do exemplo documental `sitfis-relatorio.json`.
- Conferir contra o trial o `path` do `MSGDETALHAMENTO62`. A documentação do serviço não o publica, e `Consultar` tinha sido deduzido do serviço irmão `MSGCONTRIBUINTE61`.

## Fora do escopo

A prova do termo de autorização no ambiente de demonstração (aceite do termo, papéis do documento, `304` com o token no `ETag` e a vigência `+30 days`) saiu desta change em 2026-09-29, porque o trial não a prova e não há acesso ao ambiente de demonstração com assinatura real. **A emissão do termo continua bloqueada em produção pelo gate de prova de contrato**, e o raciocínio sobre a vigência está no `tasks.md` arquivado de `complete-serpro-integration` (4.6a). Retomar exige uma change nova.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

Nenhuma. As fixtures e a conferência do `path` não mudam comportamento.

## Impact

- Backend: fixtures em `tests/Fixtures/serpro/` e `SerproContractFixtureTest`.
