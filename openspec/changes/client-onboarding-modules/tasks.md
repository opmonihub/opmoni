## 1. Baseline

- [x] 1.1 Confirmar o git limpo fora de `openspec/changes/` e anotar o commit de partida; verificar com `git status --short`
- [x] 1.2 Rodar a suíte do backend antes de qualquer mudança; verificar com `cd backend && composer test` verde
- [x] 1.3 Rodar a suíte do frontend antes de qualquer mudança; verificar com `cd frontend && pnpm lint && pnpm typecheck && pnpm test` verdes

## 2. Testes RED do backend

- [ ] 2.1 Escrever `tests/Feature/Serpro/ClientMonitoringModulesTest.php` cobrindo GET/POST `clients/{client}/monitoring-modules` (sugestão por regime, 200 com `associated`/`already`, 403 para user, 404 entre Accounts, 422 para slug desconhecido, `unavailable`/`extinct` e pessoa física, nenhuma chamada ao provedor, auditoria em suporte); verificar que falha com `php artisan test --compact --filter=ClientMonitoringModulesTest`
- [ ] 2.2 Reescrever `tests/Feature/SerproEligibilityTest.php` sem a procuração manual e com o cenário "Dado digitado não altera a elegibilidade"; verificar que falha com `php artisan test --compact --filter=SerproEligibilityTest`
- [ ] 2.3 Escrever `tests/Feature/Serpro/RefreshSerproPowersJobTest.php` (job com `account_id` explícito, tenant residual de outra Account, pula sem habilitação/termo/certificado, pula pessoa física, pula verificado há menos de 20 horas, chamada registrada em `serpro_calls` com `run_id` nulo) usando `Http::fake`; verificar que falha com `php artisan test --compact --filter=RefreshSerproPowersJobTest`
- [ ] 2.4 Escrever testes dos gatilhos do oráculo em `ClientController` (criação e troca de CPF/CNPJ despacham o job com `Queue::fake`, falha do provedor não desfaz o cadastro) e do comando `serpro:refresh-powers` (só Accounts habilitadas); verificar que falham com `php artisan test --compact --filter=SerproPowerTriggers`
- [ ] 2.5 Escrever testes da procuração derivada em `ClientResource`, filtro de pendências e ordenação (`missing`/`valid`/`expiring`/`expired`, precedência, fallback sem módulo, nenhum campo de código, notas ou caminho); verificar que falham com `php artisan test --compact --filter=ClientDerivedPowerOfAttorney`
- [ ] 2.6 Escrever testes da captura imediata no upload do A1 (despacho por fonte habilitada, `meta.capture` `queued`/`blocked`/`not_capturable`, 403 para user, auditoria em suporte, job com `account_id` explícito ignorando tenant residual); verificar que falham com `php artisan test --compact --filter=CaptureOnCertificateUpload`
- [ ] 2.7 Escrever testes do `client_certificate_status` em `GET /api/fiscal/documents` (valores `missing`/`expired`/`password_missing`/`expiring`/`valid`, sem segredos, 404 entre Accounts); verificar que falham com `php artisan test --compact --filter=FiscalDocumentCertificateStatus`
- [ ] 2.8 Escrever o teste de ausência de rota para `PUT/DELETE clients/{client}/ecac-power-of-attorney` (404/405); verificar que falha com `php artisan test --compact --filter=EcacPowerOfAttorneyRemoved`

## 3. Backend: mapa regime → obrigações e associação no cadastro

- [ ] 3.1 Adicionar `regime_suggestions` em `config/integra-contador.php` e `SerproObligationCatalog::suggestedFor(TaxRegime)`, rejeitando no teste do catálogo slug inexistente ou não servido; verificar com `php artisan test --compact --filter=SerproObligationCatalogTest`
- [ ] 3.2 Criar `ClientMonitoringModuleController` (index/store), a request com `Gate::authorize('update', $client)` e as rotas em `routes/api.php`, reusando `SerproMonitoringReader::associate`; verificar com `php artisan test --compact --filter=ClientMonitoringModulesTest`
- [ ] 3.3 Adicionar `SupportAudit::logWrite` em `SerproMonitoringAssociationController::store` e no novo `store`; verificar com `php artisan test --compact --filter=ClientMonitoringModulesTest` e `--filter=SerproMonitoringAssociation`

## 4. Backend: remoção da procuração manual (**BREAKING**)

- [ ] 4.1 Remover o passo das datas manuais de `SerproEligibility` e `refletirNaProcuracao` de `SerproPowerOracle`; verificar com `php artisan test --compact --filter='SerproEligibilityTest|SerproPowerOracleTest'`
- [ ] 4.2 Remover rotas, `ClientEcacPowerOfAttorneyController`, `UpsertClientEcacPowerOfAttorneyRequest`, `ClientEcacPowerOfAttorneyResource`, model, factory e a relação `Client::ecacPowerOfAttorney`, e apagar `tests/Feature/Tenancy/ClientEcacPowerOfAttorneyTest.php`; verificar com `php artisan test --compact --filter=EcacPowerOfAttorneyRemoved` e `grep -rn EcacPowerOfAttorney app routes database tests` sem resultado fora da migration histórica
- [ ] 4.3 Criar a migration que derruba `client_ecac_powers_of_attorney` com `down()` que recria a estrutura vazia; verificar com `php artisan test --compact --filter=SerproPowerSchemaTest` e `php artisan migrate:fresh` local em sqlite

## 5. Backend: procuração derivada na carteira

- [ ] 5.1 Implementar o cálculo da procuração derivada (famílias exigidas pelos módulos associados, alternativas, precedência `expired > missing > expiring > valid`, fallback para as famílias sincronizadas) num serviço consultável em lote; verificar com `php artisan test --compact --filter=ClientDerivedPowerOfAttorney`
- [ ] 5.2 Trocar em `ClientResource` e `ClientSheetResource` o campo `ecac_power_of_attorney` pelo novo formato e ajustar filtro de pendências e ordenação em `Client` e `ClientPortfolio`, sem N+1; verificar com `php artisan test --compact --filter='ClientDerivedPowerOfAttorney|ClientPortfolioAnalyticsTest|SecurityRefactorTest'`
- [ ] 5.3 Ajustar `DevClientPortfolioSeeder` e o teste dele para não criar procuração manual; verificar com `php artisan test --compact --filter=DevClientPortfolioSeederTest`

## 6. Backend: oráculo ao salvar e na rotina diária

- [ ] 6.1 Criar `RefreshSerproPowersJob(accountId, clientId)` que usa `SerproClientLock`, `SerproCallRecorder` (sem `run_id`) e `SerproPowerOracle::persist`, com a janela de 20 horas; verificar com `php artisan test --compact --filter=RefreshSerproPowersJobTest`
- [ ] 6.2 Despachar o job em `ClientController::store` e `update` (troca de CPF/CNPJ) após o commit, só para pessoa jurídica de Account habilitada; verificar com `php artisan test --compact --filter=SerproPowerTriggers`
- [ ] 6.3 Criar o comando `serpro:refresh-powers` e agendá-lo em `routes/console.php` (`dailyAt('02:00')`, `America/Sao_Paulo`, `withoutOverlapping`); verificar com `php artisan test --compact --filter=SerproPowerTriggers` e `php artisan schedule:list`

## 7. Backend: captura imediata e status do certificado

- [ ] 7.1 Extrair `recusaDeFonte`/`bloqueioDe` de `FiscalDocumentController` para `FiscalCaptureDispatcher` sem mudar o comportamento do disparo sob demanda; verificar com `php artisan test --compact --filter=FiscalDocumentController`
- [ ] 7.2 Adicionar `accountId` ao `CaptureFiscalDocumentsJob` e filtrar o cliente por ele, atualizando os despachos do comando `fiscal:capture` e do controller; verificar com `php artisan test --compact --filter='CaptureOnCertificateUpload|FiscalCapture'`
- [ ] 7.3 Despachar a captura em `ClientCertificateController::store` pelo dispatcher, devolver `meta.capture` e registrar `SupportAudit::logWrite` no upload e na remoção; verificar com `php artisan test --compact --filter=CaptureOnCertificateUpload`
- [ ] 7.4 Adicionar `client_certificate_status` às linhas de `GET /api/fiscal/documents` reusando o critério de `FiscalCoverage`, sem consulta por linha; verificar com `php artisan test --compact --filter=FiscalDocumentCertificateStatus`

## 8. Frontend: tipos e remoção do modal (**BREAKING**)

- [ ] 8.1 Atualizar `app/types/client.ts` com o novo `ecac_power_of_attorney` e `app/types/` com `MonitoringModule`, `meta.capture` e `client_certificate_status`; verificar com `pnpm typecheck`
- [ ] 8.2 Remover `EcacPowerOfAttorneyModal.vue`, seus usos em `pages/customers/[documento]/[[situacao]].vue` e `pages/customers/empresa/[id].vue` e os métodos PUT/DELETE de `useClients.ts`; verificar com `pnpm lint && pnpm typecheck` e `grep -rn EcacPowerOfAttorney app` sem resultado

## 9. Frontend: etapa de módulos e coluna de procuração

- [ ] 9.1 Escrever `tests/monitoringModules.test.ts` para o util que ordena e agrupa os módulos e calcula o payload marcado; verificar que falha e depois passa com `pnpm test`
- [ ] 9.2 Adicionar a etapa de módulos ao `ClientCreateModal.vue` (pré-marcados, pular, erro com `ErrorRetryAlert`, oculta para pessoa física e para user) e os métodos em `useClients.ts`; verificar com `pnpm lint && pnpm typecheck && pnpm test`
- [ ] 9.3 Ajustar a coluna procuração em `ClientPortfolioTable.vue`, `ClientPortfolioMobileList.vue`, `DeadlineAlertPanels.vue` e `PortfolioKpis.vue` para o estado derivado, somente leitura, com as famílias no detalhe; verificar com `pnpm lint && pnpm typecheck && pnpm test`

## 10. Frontend: módulo XML

- [ ] 10.1 Escrever teste do mapeamento `client_certificate_status` → rótulo e cor em `app/utils/fiscalPresentation.ts`; verificar que falha e depois passa com `pnpm test`
- [ ] 10.2 Adicionar a coluna "Certificado" em `pages/fiscal/documentos.vue` e o aviso de captura enfileirada/bloqueada após o upload do A1; verificar com `pnpm lint && pnpm typecheck && pnpm test`

## 11. serpro-trial (provedor real)

- [ ] 11.1 Gravar, no grupo `serpro-trial`, uma chamada real de `OBTERPROCURACAO41` pelo `RefreshSerproPowersJob` para um cliente de teste e conferir `serpro_client_authorizations` e `serpro_calls`; verificar com `php artisan test --group=serpro-trial --filter=RefreshSerproPowersTrial`
- [ ] 11.2 Disparar em homologação (`FISCAL_ENVIRONMENT=homologacao`) a captura imediata após upload de um A1 de teste e conferir o cursor; verificar com `php artisan test --group=serpro-trial --filter=CaptureOnCertificateUploadTrial`

## 12. Verificação final

- [ ] 12.1 Rodar a suíte do backend; verificar com `cd backend && composer test` verde
- [ ] 12.2 Formatar o PHP; verificar com `cd backend && vendor/bin/pint --dirty --format agent` sem pendências
- [ ] 12.3 Rodar a suíte do frontend; verificar com `cd frontend && pnpm lint && pnpm typecheck && pnpm test` verdes
- [ ] 12.4 Validar a change; verificar com `openspec validate client-onboarding-modules --strict`
- [ ] 12.5 Buscar segredos em logs e respostas; verificar com `grep -rniE 'password|senha|token|BEGIN (CERTIFICATE|PRIVATE)|<nfeProc|pfx' backend/storage/logs` sem ocorrência nova e com os testes de "Resposta sem segredos" verdes
