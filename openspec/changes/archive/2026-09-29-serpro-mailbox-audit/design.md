## Context

A leitura de mensagem da caixa postal (`CAIXAPOSTAL/MSGDETALHAMENTO62`) caracteriza ciência da intimação e abre prazo legal (D19 de `add-integra-contador-sync`). A rota, a confirmação e a tela foram entregues pela `serpro-contract-and-mailbox`, já arquivada. Esta change registra as decisões da grelha de 2026-09-29 que ficaram de fora daquela: os status HTTP na spec, o autor da ciência e a auditoria de suporte nos controllers que ainda não a fazem.

## Goals / Non-Goals

**Goals**
- Ler a mensagem só com consentimento explícito, e recusar tudo o que pode ser recusado antes da chamada.
- Saber quem registrou a ciência.
- Auditar em Acesso de suporte todo ato no tenant ou no provedor.

**Non-Goals**
- Guardar o corpo da mensagem no banco.
- Mudar a listagem da caixa postal, que continua sem ler mensagem nenhuma.

## Decisions

### 1. A leitura é `POST` com `ciencia: true` no corpo

A rota é `POST serpro/monitoring/obligations/{obligation}/clients/{client}/messages/{message}`. Um GET pode vir de prefetch, de link ou de retry automático, e nenhum desses é consentimento. O corpo sem `ciencia: true` responde 422 antes de qualquer chamada.

Descartado: `GET .../messages/{id}` com cabeçalho de confirmação (fácil de repetir sem querer) e ausência de rota (deixava a mensagem inacessível).

### 2. O cliente entra na rota e o `isn` precisa estar na caixa sincronizada

O provedor identifica a mensagem pelo `isn` dentro da caixa de um contribuinte. O `isn` só é aceito se estiver entre os stubs que a sincronização gravou para aquele cliente naquela obrigação. Assim, um `isn` digitado não registra ciência numa mensagem que a tela nunca mostrou. Fora disso, a resposta é 404.

### 3. Toda recusa acontece antes da chamada

Obrigação sem caixa postal, mensagem fora da caixa, cliente de outra Account (404), `user` (403), sem termo ou sem e-CNPJ (409): tudo é conferido antes de `MSGDETALHAMENTO62` sair. Uma recusa depois da chamada não desfaz a ciência.

### 4. Leem `admin`, `operador` e o suporte, sem exceção

Registrar ciência é ato em nome do cliente, não leitura do painel, então fica com quem escreve. O super_admin em Acesso de suporte tem os mesmos poderes do `admin`, inclusive aqui, e o ato vai para o log de auditoria. Uma decisão anterior de bloquear o suporte foi revertida na grelha.

### 5. `user_id` em `serpro_calls`

Hoje a chamada registra quando a ciência correu, mas não quem a registrou. A coluna `user_id` (nullable, porque execuções agendadas não têm usuário) passa a ser gravada pelo `SerproCallRecorder` quando há usuário na requisição.

### 6. Reabrir a mensagem sempre chama o provedor

O corpo não é guardado. Reabrir chama `MSGDETALHAMENTO62` de novo e cobra de novo. As datas de ciência e prazo não mudam, porque são do provedor. Guardar o corpo foi descartado para não manter conteúdo de intimação no banco.

### 7. Falha do provedor responde o rótulo, não o texto

A resposta 502 traz o rótulo da falha (`SerproFailure::label()`), nunca o texto do provedor, e o stub continua como não lido.

## Tenancy, papéis, suporte e auditoria

- O leitor recebe o `account_id` do `CurrentTenant` e filtra `SerproMonitoring` e `Client` por ele de forma explícita, sem depender do escopo global.
- Papéis: `admin` e `operador` leem; `user` recebe 403 e a tela esconde o botão.
- Suporte: igual ao `admin`, com `SupportAudit::logWrite` no controller.
- Os nove controllers sem auditoria (certificado do cliente, procuração e-CAC, refresh de CNPJ, filtros salvos, seleção, habilitação SERPRO, sync e resync, associação de monitoramento, leitura de mensagem) ganham `logWrite`.

## Segredos

- O corpo da mensagem não vai para log nem para banco.
- O token do termo, o e-CNPJ e a Credencial de plataforma continuam só no `SerproClient`.
- O texto do provedor não vai para a resposta.

## Risks / Trade-offs

- **O provedor responde 200 e o `save` do stub falha.** A ciência já correu e a lista continua mostrando a mensagem como não lida. Aceito: a próxima sincronização traz o estado real, e a chamada fica em `serpro_calls`.
- **O `path` do `MSGDETALHAMENTO62` não foi conferido.** O config usa `Consultar`, o do serviço irmão. A conferência já está com a `serpro-provider-contract` e não é repetida aqui.
- **Custo repetido ao reabrir.** Consciente (decisão 6).

## Migration Plan

- Migration nova adicionando `user_id` nullable em `serpro_calls`, com FK para `users` e `nullOnDelete`. Sem backfill.
- Migrations de produção só com autorização explícita.

## Open Questions

- Nenhuma.
