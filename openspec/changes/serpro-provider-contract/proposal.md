## Why

`serpro-contract-and-mailbox` foi arquivada com a leitura de mensagem implementada e três itens que só fecham com chamadas reais ao provedor, no trial e no ambiente de demonstração. A emissão do termo continua bloqueada pelo gate de prova de contrato até eles fecharem.

## What Changes

- Gravar a fixture do `SITFIS` a partir de uma resposta real do trial, no lugar do exemplo documental `sitfis-relatorio.json`.
- Provar com o ambiente de demonstração que o termo de autorização é aceito, que os papéis do documento batem com o gateway e que o reenvio de um termo válido responde `304` com o token no `ETag`, e cobrir isso com teste de contrato.
- Conferir contra o gateway o `path` do `MSGDETALHAMENTO62`. A documentação do serviço não o publica, e `Consultar` foi deduzido do serviço irmão `MSGCONTRIBUINTE61`.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `serpro-connection`: a vigência do termo passa de valor não confirmado a valor provado contra o provedor.

## Impact

- Backend: fixtures em `tests/Fixtures/serpro/`, teste de contrato do grupo `serpro-trial`, possivelmente `config/integra-contador.php` (`MSGDETALHAMENTO62.path`) e a constante de vigência em `SerproTermSigner`.
- Provedor: exige credenciais do trial e do ambiente de demonstração.
