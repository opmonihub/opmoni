## Why

A change `complete-serpro-integration` foi arquivada com três itens que não fecham só com código local. Dois dependem de chamadas reais ao provedor (trial e ambiente de demonstração). O terceiro, a leitura de mensagem da caixa postal, tem efeito jurídico: `MSGDETALHAMENTO62` registra a ciência da intimação e abre prazo legal (D19). Por isso a rota não pode entrar como uma leitura qualquer.

## What Changes

- Gravar a fixture do `SITFIS` a partir de uma resposta real do trial, no lugar do exemplo documental `sitfis-relatorio.json` (antiga 3.3).
- Provar com o ambiente de demonstração que o termo de autorização é aceito, que os papéis do documento batem com o gateway e que o reenvio de um termo válido responde `304` com o token no `ETag` (antiga 4.6a). Enquanto isso não acontecer, a emissão continua bloqueada pelo gate de prova de contrato.
- Expor a leitura de mensagem da caixa postal só com consentimento explícito do Membro, que confirma que a leitura registra a ciência e abre o prazo (antiga 7.5).

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `monitoring`: a leitura de mensagem da caixa postal passa a exigir confirmação explícita da ciência.

## Impact

- Backend: `routes/api.php` (rota `POST serpro/monitoring/obligations/{o}/clients/{client}/messages/{id}`, com `ciencia: true` no corpo), `SerproMailboxReader`, `SerproMonitoringMessageController`. As fixtures e o contrato do termo foram para `serpro-provider-contract`.
- Frontend: `useSerpro.ts` manda o cliente e a confirmação; `MessageDetail.vue` só oferece a leitura a `admin` e `operador`.
- Provedor: exige credenciais do trial e do ambiente de demonstração.
