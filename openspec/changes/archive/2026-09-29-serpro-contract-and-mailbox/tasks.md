## 1. Fixtures reais

- [ ] 1.1 Gravar como fixture, a partir de uma chamada real ao trial, a resposta do `SITFIS` com o envelope completo e o `dados` decodificado, no lugar de `sitfis-relatorio.json`; verificar que a suíte determinística continua passando só com fixtures (antiga 3.3 de `complete-serpro-integration`)

  > Movida (2026-09-29) para `serpro-provider-contract` 1.1, porque depende do trial. Não foi feita aqui.

## 2. Contrato do termo de autorização

- [ ] 2.1 Confirmar com o ambiente de demonstração que o termo é aceito, que os papéis do documento são os que o gateway espera e que o reenvio de um termo válido responde `304` com o token no `ETag`; registrar a vigência aceita, que hoje é `+30 days` e não está confirmada, antes de gravar qualquer prova de contrato (antiga 4.6a; o raciocínio completo está no `tasks.md` arquivado)

  > Movida (2026-09-29) para `serpro-provider-contract` 2.1. Não foi feita aqui, e a emissão continua bloqueada pelo gate.

- [ ] 2.2 Cobrir o item 2.1 com teste de contrato no grupo `serpro-trial`, ao lado de `SerproTrialContractTest`

  > Movida (2026-09-29) para `serpro-provider-contract` 2.2. Não foi feita aqui.

## 3. Leitura de mensagem da caixa postal

- [x] 3.1 Registrar `serpro/monitoring/obligations/{o}/messages/{id}` em `routes/api.php`, no grupo `['auth:sanctum', 'tenant']`, recusando a chamada sem a confirmação explícita da ciência; verificar com teste de feature e com `php artisan route:list` (antiga 7.5)

  > Feita (2026-09-29) como `POST serpro/monitoring/obligations/{o}/clients/{client}/messages/{isn}`: o `isn` só é único dentro da caixa do contribuinte, então a rota nomeia o cliente. `ReadSerproMessageRequest` exige `ciencia: true` (`422` sem ela) e a policy `readMessage` limita a `admin`/`operador`. `SerproMailboxReader` confere obrigação de caixa postal, `isn` entre os stubs sincronizados do cliente, termo e certificado antes de chamar `MSGDETALHAMENTO62`; grava a chamada em `serpro_calls` sem execução e atualiza o stub. Falha do provedor responde `502` com o rótulo, sem o texto dele. `SerproMailboxReadTest` (8 testes) e `route:list` conferidos. O `path` `Consultar` foi deduzido; a conferência está em `serpro-provider-contract` 3.1.

- [x] 3.2 Pedir a confirmação no frontend antes de chamar a rota, dizendo que abrir a mensagem registra a ciência e abre o prazo

  > Feita (2026-09-29): `MessageDetail.vue` já pedia a confirmação; agora recebe o `clientId`, manda `ciencia: true` por `POST` e, para o papel `user`, mostra o aviso sem o botão de abrir.
