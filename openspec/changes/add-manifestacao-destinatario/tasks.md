## 1. Baseline

- [ ] 1.1 Confirmar estado limpo do repositório e suítes verdes antes de qualquer mudança, verificar com `cd backend && composer test` e `cd frontend && pnpm test`
- [ ] 1.2 Revisar as specs vigentes de `fiscal-capture`, `fiscal-documents-ui` e o change arquivado `add-fiscal-document-capture` para não contradizer decisões antigas, verificar com `openspec show fiscal-capture --type spec`

## 2. Dependência e assinatura (TDD RED primeiro)

- [ ] 2.1 Adicionar a dependência `robrichards/xmlseclibs` ao `composer.json` (decisão 1 da design, aprovada pelo usuário em 03/10/2026 — pacote canônico da biblioteca xmlseclibs; o pacote `xmlseclibs/xmlseclibs` do Packagist é fork obscuro e NÃO usar), verificar com `cd backend && composer require robrichards/xmlseclibs && composer show robrichards/xmlseclibs`
- [ ] 2.2 Escrever teste de unidade que monta um evento de manifestação e falha porque a assinatura XMLDSig ainda não existe (Exclusive C14N + `SignedInfo`/`SignatureValue`/`X509Certificate`), verificar com `cd backend && php artisan test --compact --filter=AssinaturaEvento`
- [ ] 2.3 Implementar o serviço de assinatura em `backend/app/Services/Fiscal/Manifestacao/` usando o certificado A1 do cliente e falhando sem enviar nada quando a assinatura não puder ser produzida, verificar com o teste de 2.2 passando
- [ ] 2.4 Validar o evento assinado contra o XSD local do serviço de eventos antes de qualquer envio, verificar com teste que rejeita um envelope malformado

## 3. Estado, gate e deduplicação

- [ ] 3.1 Criar migration aditiva da tabela de manifestação com `account_id`, `client_id`, `chave_acesso`, tipo e sequência do evento, `requested_by`, resultado e datas, com unique por (account_id, client_id, chave_acesso, evento), verificar com `cd backend && php artisan test --compact --filter=ManifestacaoMigration` ou teste de schema
- [ ] 3.2 Criar o modelo com a trait `BelongsToAccount` e factory, e testes de tenancy (escopo por Account, job com `account_id` explícito, job de outra Account não age no cliente alheio), verificar com `php artisan test --compact --filter=ManifestacaoTest`
- [ ] 3.3 Adicionar `manifestacao_enabled` (default false, padrão `filter_var`) em `backend/config/fiscal.php` e fazer dispatcher e job consultarem o gate, verificar com teste que com o gate desligado nada é enfileirado nem enviado
- [ ] 3.4 Escrever teste que enfileira duas vezes a ciência da mesma chave e prova que só um evento existe (overwrite, não duplicata), verificar com `php artisan test --compact --filter=ManifestacaoDuplicidade`

## 4. Conector e classificação do cStat

- [ ] 4.1 Escrever teste que monta o evento 210210 a partir do resumo capturado e rejeita documentos fora do prazo de 90 dias, verificar com `php artisan test --compact --filter=CienciaPrazo`
- [ ] 4.2 Escrever teste que falha porque o conector `nfeRecepcaoEvento` não existe, cobrindo aceite (cStat do evento), rejeição transitória e rejeição 573 mapeada para estado conhecido "já manifestado" (não `FiscalFailure`), verificar com `php artisan test --compact --filter=RecepcaoEvento`
- [ ] 4.3 Implementar o conector em `backend/app/Services/Fiscal/Manifestacao/` reutilizando `HttpPkcs12ClientOptions`, bundle ICP-Brasil e regras de fault condensado, sem esticar `DfeTransport`, verificar com o teste de 4.2 passando
- [ ] 4.4 Garantir que nenhum log ou registro de auditoria contém senha do certificado, XML do evento (assim ou não), envelope assinado ou tokens, verificar com teste que inspeciona mensagens registradas em cenários de falha

## 5. Job e integração com a captura

- [ ] 5.1 Escrever teste que falha porque gravar um resumo ainda não enfileira a ciência da emissão, cobrindo gate ligado, cliente dentro do prazo e bloqueio respeitado na execução (não só no enqueue), verificar com `php artisan test --compact --filter=DispatcherManifestacao`
- [ ] 5.2 Implementar o job `ManifestarCiencia` (carregando `account_id` e id do cliente, checando gate, deduplicação, prazo e janela de bloqueio no momento da execução) e o hook no dispatcher pós-resumo, verificar com o teste de 5.1 passando
- [ ] 5.3 Implementar o tratamento da 573 no job: gravar estado "já manifestado" e liberar a recuperação por `consChNFe` sem retry, verificar com teste do fluxo 573 ponta a ponta com transporte fake
- [ ] 5.4 Garantir que a manifestação não debita `FiscalLookupBudget` e que a recuperação do XML pós-ciência passa pelo caminho de consulta pontual existente com teto de 20/h e limite por chave na ressincronização, verificar com teste de orçamento após manifestação
- [ ] 5.5 Implementar a rotina agendada de ressincronização que recupera, via consulta pontual por chave, o XML completo dos resumos com ciência já registrada (spec `fiscal-manifestacao`), com teto de 20/h, limite por chave e deduplicação, verificar com teste do fluxo agendado ponta a ponta com transporte fake

## 6. Auditoria e acesso de suporte

- [ ] 6.1 Escrever teste que falha porque a manifestação ainda não registra quem/operação/data para job e para `super_admin` em acesso de suporte, verificar com `php artisan test --compact --filter=AuditoriaManifestacao`
- [ ] 6.2 Implementar o registro de auditoria (cliente, chave, tipo de evento, resultado, requisitante) no fluxo do job, com entrada de auditoria de suporte quando originada por suporte, verificar com o teste de 6.1 passando

## 7. Frontend: estado de completude

- [ ] 7.1 Adicionar o código de completude (`summary_awaiting_xml`/`complete`) derivado dos registros de distribuição da chave na resposta da listagem de documentos, com teste de feature que cobre isolamento entre Accounts e ausência de segredos, verificar com `cd backend && php artisan test --compact --filter=DocumentosCompletude`
- [ ] 7.2 Escrever teste em `frontend/tests/` que falha porque a coluna de estado ("resumo aguardando XML" versus "XML completo") não existe na tabela, verificar com `cd frontend && pnpm test`
- [ ] 7.3 Implementar a coluna na tabela de `/fiscal/documentos` com cores semânticas do DESIGN.md e textos em português, sem ação de disparo de manifestação na linha, verificar com o teste de 7.2 e `cd frontend && pnpm typecheck` sem erros novos (os 4 erros pré-existentes de `frontend/app/pages/admin/contas.vue` não contam — registrar antes e depois)

## 8. Canário no ambiente real (fora da suíte padrão)

- [ ] 8.1 Com `FISCAL_ENVIRONMENT=homologacao` e gate ligado para o canário, enviar ciência 210210 real com o A1 do cliente e confirmar aceite do AN, verificar com registro do cStat do evento na tabela de manifestação (não roda em CI)
- [ ] 8.2 Confirmar que a distribuição seguinte traz NSU próprio com o procNFe e que a recuperação por `consChNFe` devolve o XML completo dentro do teto, verificar com contagem de resumos pendentes antes/depois (não roda em CI)

## 9. Verificação final

- [ ] 9.1 Rodar a suíte completa do backend e o lint do PHP nos arquivos da change, verificar com `cd backend && composer test && vendor/bin/pint --format agent app/Services/Fiscal/Manifestacao tests` — NUNCA `--dirty`, que reformataria os ~150 arquivos sujos pré-existentes
- [ ] 9.2 Rodar lint, typecheck e testes do frontend, verificar com `cd frontend && pnpm lint && pnpm typecheck && pnpm test` — typecheck precisa fechar sem erros novos (os 4 erros pré-existentes de `frontend/app/pages/admin/contas.vue` são registrados no baseline e não contam)
- [ ] 9.3 Validar o change no OpenSpec em modo estrito, verificar com `openspec validate add-manifestacao-destinatario --strict`
- [ ] 9.4 Buscar por segredos em logs e respostas do novo código (senha de certificado, XML bruto, envelope assinado, tokens), verificar com `grep -RniE 'senha|password|SignatureValue|X509Certificate|docZip' backend/app/Services/Fiscal/Manifestacao/ backend/tests --include='*.php'` revisando cada ocorrência