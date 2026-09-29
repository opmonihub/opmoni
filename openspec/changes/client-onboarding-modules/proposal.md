## Why

Hoje, depois de cadastrar um cliente, o Membro ainda precisa fazer três coisas à mão e em telas diferentes: associar o cliente às obrigações do Monitoramento pelo botão "Adicionar clientes", preencher uma procuração e-CAC que o provedor já conhece, e esperar a próxima hora cheia para a captura de XML começar depois do upload do A1. A procuração manual ainda cria uma segunda fonte de verdade que a elegibilidade cruza com a Família de serviço autorizada e que diverge dela sem aviso.

## What Changes

- O cadastro do cliente ganha uma etapa de módulos depois do salvamento: as Obrigações de monitoramento vêm pré-marcadas por um mapa fixo "regime tributário → obrigações", igual para todas as Accounts, e o Membro (`admin` ou `operador`) pode marcar e desmarcar antes de confirmar. Confirmar cria as associações cliente × obrigação sem chamar o provedor e sem consumir cota.
- Todo cliente entra na captura de XML (NF-e e CT-e), sem opção no cadastro. A tabela do módulo XML ganha uma coluna com o status do certificado do cliente (sem certificado, vencido, sem senha armazenada, utilizável).
- O upload do A1 de um cliente enfileira uma captura imediata daquele cliente, respeitando o bloqueio de uma hora do serviço.
- O oráculo de procuração (`OBTERPROCURACAO41`) passa a rodar também ao salvar o cliente e numa rotina diária, além de dentro da sincronização.
- **BREAKING**: sai o cadastro manual da procuração e-CAC. Somem as rotas `PUT` e `DELETE /api/clients/{client}/ecac-power-of-attorney`, o modal do frontend e a tabela `client_ecac_powers_of_attorney`. A elegibilidade passa a depender só da Família de serviço autorizada lida do provedor.
- **BREAKING**: o campo `ecac_power_of_attorney` do recurso de cliente muda de forma. Deixa de trazer datas, código e notas digitados pelo Membro e passa a trazer a menor validade entre as famílias exigidas pelos módulos associados ao cliente, com o estado derivado (sem procuração, válida, a vencer em 30 dias, vencida) e as famílias que a compõem.
- Em Acesso de suporte, o super_admin tem os poderes de `admin` nessas telas, e toda escrita nova (confirmação de módulos, upload que dispara captura, consulta ao oráculo sob demanda) é auditada.

## Capabilities

### New Capabilities

Nenhuma.

### Modified Capabilities

- `client-portfolio`: a listagem e a página `/customers` passam a mostrar a procuração e-CAC derivada das famílias autorizadas dos módulos associados, e o cadastro ganha a etapa de módulos.
- `client-fiscal-access`: sai o controle manual da procuração e-CAC; os estados de validade da procuração passam a vir da Família de serviço autorizada.
- `serpro-connection`: a condição para agir pelo cliente passa a ser só a Família de serviço autorizada em vigor, e o oráculo ganha os gatilhos de cadastro e rotina diária.
- `monitoring`: associação do cliente às obrigações a partir do cadastro, pelo mapa regime → obrigações.
- `fiscal-capture`: captura imediata após o upload do certificado e inclusão automática de todo cliente.
- `fiscal-documents-ui`: coluna de status do certificado na tabela de documentos.

## Impact

**Backend**
- Removidos: `app/Http/Controllers/Tenant/ClientEcacPowerOfAttorneyController.php`, `app/Http/Requests/Tenant/UpsertClientEcacPowerOfAttorneyRequest.php`, `app/Http/Resources/ClientEcacPowerOfAttorneyResource.php`, `app/Models/ClientEcacPowerOfAttorney.php`, `database/factories/ClientEcacPowerOfAttorneyFactory.php`, as rotas em `routes/api.php:72-73` e a tabela `client_ecac_powers_of_attorney` (nova migration de drop).
- Alterados: `app/Services/SerproEligibility.php` (sai a conferência das datas manuais, linhas 80-89), `app/Services/SerproPowerOracle.php` (sai `refletirNaProcuracao`, linhas 113 e 203), `app/Models/Client.php` (relação, filtros e ordenação por procuração), `app/Services/ClientPortfolio.php`, `app/Http/Resources/ClientResource.php`, `app/Http/Resources/ClientSheetResource.php`, `app/Http/Controllers/Tenant/ClientController.php` e `ClientCertificateController.php`, `app/Services/SerproMonitoringReader.php` (`associate`), `app/Services/SerproObligationCatalog.php` e `config/integra-contador.php` (mapa regime → obrigações), `routes/console.php` (rotina diária do oráculo).
- Novos: job do oráculo por cliente, comando da rotina diária, endpoint de módulos do cliente.
- Testes afetados: `tests/Feature/Tenancy/ClientEcacPowerOfAttorneyTest.php`, `tests/Feature/SerproEligibilityTest.php`, `tests/Feature/SerproPowerOracleTest.php`, `tests/Feature/SerproPowerSchemaTest.php`, `tests/Feature/Tenancy/ClientPortfolioAnalyticsTest.php`, `tests/Feature/Tenancy/DevClientPortfolioSeederTest.php`, `tests/Feature/Tenancy/SecurityRefactorTest.php`.

**Frontend**
- Removido: `app/components/customers/EcacPowerOfAttorneyModal.vue` e os usos em `app/pages/customers/[documento]/[[situacao]].vue:535` e `app/pages/customers/empresa/[id].vue:1094`; métodos PUT/DELETE em `app/composables/useClients.ts:141-146`.
- Alterados: `app/components/customers/ClientCreateModal.vue` (etapa de módulos), `app/components/customers/ClientPortfolioTable.vue` e `ClientPortfolioMobileList.vue` (coluna procuração), `app/types/client.ts`, `app/pages/fiscal/documentos.vue` (coluna de certificado), `app/components/customers/dashboard/DeadlineAlertPanels.vue` e `PortfolioKpis.vue`.

**Provedor**
- `PROCURACOES/OBTERPROCURACAO41` é `billable: true` em `config/integra-contador.php:37`. Os novos gatilhos (cadastro e rotina diária) aumentam o consumo pago em até uma chamada por cliente pessoa jurídica por dia, mais uma por cadastro.
- A captura imediata usa o serviço de distribuição DF-e com o A1 do cliente e continua sob o bloqueio de uma hora e a trava por cliente e fonte.
