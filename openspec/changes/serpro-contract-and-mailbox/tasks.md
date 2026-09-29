## 1. Fixtures reais

- [ ] 1.1 Gravar como fixture, a partir de uma chamada real ao trial, a resposta do `SITFIS` com o envelope completo e o `dados` decodificado, no lugar de `sitfis-relatorio.json`; verificar que a suíte determinística continua passando só com fixtures (antiga 3.3 de `complete-serpro-integration`)

## 2. Contrato do termo de autorização

- [ ] 2.1 Confirmar com o ambiente de demonstração que o termo é aceito, que os papéis do documento são os que o gateway espera e que o reenvio de um termo válido responde `304` com o token no `ETag`; registrar a vigência aceita, que hoje é `+30 days` e não está confirmada, antes de gravar qualquer prova de contrato (antiga 4.6a; o raciocínio completo está no `tasks.md` arquivado)
- [ ] 2.2 Cobrir o item 2.1 com teste de contrato no grupo `serpro-trial`, ao lado de `SerproTrialContractTest`

## 3. Leitura de mensagem da caixa postal

- [ ] 3.1 Registrar `serpro/monitoring/obligations/{o}/messages/{id}` em `routes/api.php`, no grupo `['auth:sanctum', 'tenant']`, recusando a chamada sem a confirmação explícita da ciência; verificar com teste de feature e com `php artisan route:list` (antiga 7.5)
- [ ] 3.2 Pedir a confirmação no frontend antes de chamar a rota, dizendo que abrir a mensagem registra a ciência e abre o prazo
