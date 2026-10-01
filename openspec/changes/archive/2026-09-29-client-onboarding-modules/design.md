## Context

Motivação em proposal.md (Why). Estado atual verificado no código:

- O cadastro é o modal `frontend/app/components/customers/ClientCreateModal.vue`, com duas etapas internas (consulta do CNPJ, `step = 1`, e dados, `step = 2`) e o evento `saved` ao fim. `ClientController::store` (`backend/app/Http/Controllers/Tenant/ClientController.php:145-158`) cria o cliente, audita com `SupportAudit::logWrite` e responde 201.
- A associação cliente × obrigação existe só pelo botão "Adicionar clientes": `POST serpro/monitoring/obligations/{obligation}/clients` (`routes/api.php:167`) → `SerproMonitoringAssociationController` → `SerproMonitoringReader::associate` (`app/Services/SerproMonitoringReader.php:168-207`), que grava `state = sem_dados` e `source_at = null` por `forceFill` com `account_id` explícito, recusa com 422 o que não é `direct`/`derived` e não chama o provedor.
- O catálogo é `config('integra-contador.obligations')`, lido por `SerproObligationCatalog`. Cada entrada tem `category`, `service`, `procuracao` (famílias, com `+` e `,`), `derived_from` e `sync_enabled`. Hoje só `simples-nacional`, `caixas-postais/e-cac` e `declaracoes/pgdas` têm `sync_enabled = true`; `mei` (PGMEI) está com `sync_enabled = false` e `procuracao = null`.
- `TaxRegime` tem `mei`, `simple_national`, `presumed_profit`, `actual_profit`, `other` e `not_applicable`.
- A elegibilidade (`app/Services/SerproEligibility.php:42-91`) confere pessoa jurídica, termo vigente, a exceção `PROCURACOES`, a linha de `serpro_client_authorizations` (estado `established` e `expires_on`) e, por último (linhas 80-89), o intervalo digitado em `ClientEcacPowerOfAttorney`.
- O oráculo (`app/Services/SerproPowerOracle.php`) tem `refresh()` (chama `OBTERPROCURACAO41` direto pelo `SerproClient`, sem `SerproCallRecorder`) e `persist()`, que grava as famílias e chama `refletirNaProcuracao` (linhas 113 e 203). Só `persist()` tem chamador: `SyncSerproClientJob.php:217`, que faz a chamada pelo `deliver` auditado. `refresh()` não é chamado em lugar nenhum.
- `OBTERPROCURACAO41` é `billable: true` (`config/integra-contador.php:37`). O config não tem preço unitário, só o flag.
- `serpro_calls.run_id` é nullable (a migration comenta o envio do termo como exemplo), então uma chamada fora de execução pode ser registrada.
- A captura agenda `fiscal:capture` de hora em hora (`routes/console.php`). `CaptureFiscalDocumentsJob` recebe só `clientId` e `source`, com `tries = 1` e `timeout = 85`, e busca `Client::query()->find($this->clientId)` sem `account_id` (em fila o escopo não filtra). O disparo sob demanda (`FiscalDocumentController::capture`, linhas 164-198) confere fonte habilitada (`recusaDeFonte`), bloqueio (`bloqueioDe`), despacha e audita.
- `FiscalCoverage` já calcula o motivo por cliente (`motivoDoCertificado`), e `FiscalSkipReason` tem `no_certificate`.
- A coluna de procuração em `/customers` lê `ClientResource.ecac_power_of_attorney` e `ecac_power_of_attorney_status`, derivados de `ClientEcacPowerOfAttorney.expires_at` por `DeadlineState`. Filtro e ordenação por procuração estão em `app/Models/Client.php:219, 234-239, 265-272, 360` e `app/Services/ClientPortfolio.php:49, 307, 320`. `DeadlineStatus` tem `missing`, `valid`, `expiring` e `expired`.

Fatos que divergem do briefing:

- A tabela é `client_ecac_powers_of_attorney` (migration `2026_09_22_100003_create_client_ecac_powers_of_attorney_table.php:14`), não `client_ecac_power_of_attorneys`.
- `ClientCertificateController::store`/`destroy`, `ClientEcacPowerOfAttorneyController` e `SerproMonitoringAssociationController` não chamam `SupportAudit::logWrite`. Hoje o upload de certificado e a associação em modo suporte não são auditados.
- `CaptureFiscalDocumentsJob` não carrega `account_id`.
- `SerproPowerOracle::refresh` existe mas não é usado, e não passa pelo `SerproCallRecorder`.

## Goals / Non-Goals

**Goals:**
- Um único fluxo de cadastro que termina com o cliente associado às Obrigações certas, sem gastar cota.
- Uma única fonte de verdade para a procuração: a Família de serviço autorizada.
- A captura de XML começando assim que o A1 existe.
- Todo ato novo em modo suporte auditado, e nenhum job dependendo do `CurrentTenant`.

**Non-Goals:**
- Ligar `sync_enabled` de obrigações que ainda não têm serviço conferido (PGMEI, DCTFWeb etc.). Elas podem ser associadas, e ficam `sem_dados` até a change que ligar o serviço.
- Tornar o mapa regime → obrigações editável por Account.
- Remover associações pela etapa de módulos. Desassociar continua fora de escopo.
- Mudar o bloqueio de uma hora, a trava por cliente e fonte ou a janela de continuidade da captura.
- Mexer nos requisitos que `consolidate-specs` reorganiza (isolamento genérico, CRUD por nível, auditoria de suporte, duplicata de substituição de certificado).

## Decisions

### 1. O mapa regime → obrigações fica no catálogo, em `config/integra-contador.php`

Uma chave `regime_suggestions` ao lado de `obligations`, lida por um método novo de `SerproObligationCatalog` (`suggestedFor(TaxRegime)`). Valor inicial:

| Regime | Obrigações sugeridas |
|---|---|
| `simple_national` | `simples-nacional`, `declaracoes/pgdas`, `declaracoes/defis`, `parcelamentos/simples-nacional`, `caixas-postais/e-cac`, `situacao-fiscal/relatorio-fiscal` |
| `mei` | `mei`, `caixas-postais/e-cac`, `situacao-fiscal/relatorio-fiscal` |
| `presumed_profit`, `actual_profit` | `dctfweb`, `declaracoes/dctfweb`, `fgts-digital`, `caixas-postais/e-cac`, `situacao-fiscal/relatorio-fiscal` |
| `other` | `caixas-postais/e-cac`, `situacao-fiscal/relatorio-fiscal` |
| `not_applicable` | nenhuma |

Um teste garante que toda obrigação sugerida existe no mapa `obligations` e é `direct` ou `derived`.

Por quê: o catálogo já é a fonte de categoria e família de cada Obrigação, e a regra "igual para todas as Accounts" é exatamente configuração de plataforma. Descartadas: tabela por Account (contradiz a decisão do usuário e cria migração de dados sem necessidade); regra no frontend (duplicaria o catálogo e deixaria a API sem a sugestão).

### 2. A etapa de módulos é uma terceira etapa do `ClientCreateModal`, alimentada por um endpoint do cliente

Depois do 201 do `POST /clients`, o modal não fecha: carrega `GET /api/clients/{client}/monitoring-modules` e mostra a lista com as sugestões marcadas. Confirmar chama `POST /api/clients/{client}/monitoring-modules` com `obligations: string[]`, e só então emite `saved`. Fechar sem confirmar também emite `saved` (o cliente já existe). Pessoa física não vê a etapa.

O controller novo (`ClientMonitoringModuleController`) usa `Gate::authorize('view', $client)` no GET e `Gate::authorize('update', $client)` no POST (a `ClientPolicy` já nega `update` ao `user`), e delega a escrita a `SerproMonitoringReader::associate`, uma chamada por slug. Contrato:

- `GET` → 200 `{ data: { regime, obligations: [{ slug, label, category, suggested, associated }] } }`, só `direct`/`derived`.
- `POST` → 200 `{ data: { associated, already } }`; 403 para `user`; 404 para cliente de outra Account (route model binding com escopo); 422 para slug desconhecido, `unavailable`/`extinct`, lista vazia ou pessoa física.
- Tipos no frontend em `app/types/serpro.ts` (`ClientMonitoringModules`).

Por quê: o endpoint por cliente é o formato natural da etapa (uma lista de obrigações para um cliente), e reusar `associate` mantém a propriedade verificada de não chamar o provedor. Descartadas: enviar os módulos junto no `POST /clients` (acopla a validação do cliente à do catálogo, e um slug errado derrubaria o cadastro inteiro); chamar N vezes a rota por obrigação que já existe (N requisições e N auditorias para um só ato).

### 3. A procuração manual sai por inteiro, com migration de drop

Somem rota, controller, request, resource, model, factory, a relação `Client::ecacPowerOfAttorney`, o modal e os métodos de `useClients.ts`. Uma migration nova derruba `client_ecac_powers_of_attorney`, e o `down()` recria a estrutura vazia (sem dados). Em `SerproEligibility` sai o bloco das linhas 80-89 e a menção a "intervalo que o Membro registrou" no docblock; em `SerproPowerOracle` saem `refletirNaProcuracao` e o `use ClientEcacPowerOfAttorney`.

Por quê: o provedor é a única autoridade sobre a outorga, e manter a tabela "desligada" deixaria código lendo uma fonte que ninguém mais escreve. Descartadas: esconder só a UI (a segunda fonte de verdade continuaria na elegibilidade); migrar as datas digitadas para `serpro_client_authorizations` (seria gravar como resposta do provedor algo que o provedor não disse).

### 4. A procuração exibida é a menor validade entre as famílias exigidas pelos módulos do cliente

Um serviço novo (`ClientPowerOfAttorneySummary`) calcula, para um ou vários clientes de uma Account, as famílias exigidas pela união de `procuracao` das obrigações associadas (`serpro_monitorings` do cliente). Para uma entrada com alternativas (`,`), vale a alternativa com a maior menor-validade; para uma conjunção (`+`), todas as famílias entram. Sem obrigação que exija família, o resumo usa todas as famílias já gravadas do cliente. Precedência do estado, na mesma escala de `DeadlineStatus`:

1. `expired` se alguma família considerada venceu (`expires_on < hoje` ou estado `expired`);
2. `missing` se alguma família exigida não está `established`, ou se nenhuma família existe;
3. `expiring` se a menor validade cai em até 30 dias;
4. `valid` nos outros casos.

`ClientResource.ecac_power_of_attorney` passa a ser `{ status, expires_on, families: [{ family, state, expires_on }] }` e `ecac_power_of_attorney_status` continua existindo com o mesmo significado (`missing|valid|expiring|expired`), para que `DocumentStatus`, KPIs e painéis de prazo sigam funcionando. O filtro e a ordenação em `Client.php` e `ClientPortfolio.php` passam a usar uma subquery sobre `serpro_client_authorizations` (menor `expires_on` das linhas `established`), e o cálculo por lista é feito em lote para evitar N+1.

Por quê: é o que o usuário pediu, e manter `ecac_power_of_attorney_status` reduz a quebra ao formato do objeto. Uma família recusada é tratada como `missing`, não como quinto estado, porque o conserto é o mesmo (outorgar no e-CAC). Descartadas: menor validade de todas as famílias sempre (mostraria "válida" para um cliente que não autorizou a família do módulo que ele usa); um estado `rejected` visível (quebraria a escala de quatro estados que a carteira inteira usa).

Limitação aceita: o filtro por procuração na listagem considera a menor validade das famílias `established` do cliente, sem cruzar com os módulos, porque fazer o cruzamento em SQL exigiria expandir o formato `+`/`,` do catálogo dentro da query. A coluna e o recurso usam a regra completa. O teste fixa o caso comum (todas as famílias exigidas confirmadas) em que os dois coincidem.

### 5. O oráculo roda em job próprio, com `account_id` explícito, ao salvar e numa rotina diária

`RefreshSerproPowersJob(int $accountId, int $clientId)` chama um `SerproPowerOracle::refresh` refeito: passa pelo `SerproCallRecorder` com `run_id = null` (a chamada é cobrável e precisa entrar na auditoria de cobrança como as da execução), usa `SerproClientLock` para não correr junto com o `SyncSerproClientJob` do mesmo cliente, busca o cliente por `where('account_id', $accountId)` e grava por `persist()`.

Gatilhos:
- `ClientController::store` e `update` despacham o job depois do commit (`afterCommit`), só para pessoa jurídica e, no `update`, só quando o `tax_id` mudou.
- O comando `serpro:refresh-powers`, agendado `dailyAt('02:00')` em `America/Sao_Paulo` com `withoutOverlapping`, percorre as Accounts habilitadas (`SerproAccountEnablement::enabled`) e despacha um job por cliente pessoa jurídica ativo.
- O job sai sem chamar nada quando a Account não está habilitada, não tem termo vigente ou não tem certificado do escritório, e quando `verified_at` mais recente do cliente tem menos de 20 horas (exceto no gatilho de troca de `tax_id`, que passa `force: true`).

Custo: cada consulta é uma chamada cobrável. No pior caso são uma chamada por cliente pessoa jurídica por dia pela rotina, mais uma por cadastro ou troca de CNPJ. A janela de 20 horas impede que a rotina pague de novo um cliente que acabou de sincronizar ou de ser cadastrado. O config não traz preço unitário; o valor em reais depende do contrato da plataforma e fica como Open Question.

Por quê: o salvamento não pode esperar o provedor nem falhar por ele, e a rotina diária mantém a coluna de `/customers` viva para quem não sincroniza todo dia. Descartadas: chamar o oráculo dentro do request (acopla o 201 a um timeout de 25 s do provedor); rodar a rotina de hora em hora (multiplica o custo por 24 para um dado que muda por outorga no e-CAC); pular a janela de 20 horas (paga duas vezes no dia do cadastro).

### 6. O upload do A1 enfileira a captura na hora, pelo mesmo caminho do disparo sob demanda

A regra de "fonte habilitada" e "cliente em bloqueio" hoje privada em `FiscalDocumentController` (`recusaDeFonte`, `bloqueioDe`) vai para um serviço (`FiscalCaptureDispatcher`) usado pelos dois controllers. Depois de `ClientCertificateVault::replace`, `ClientCertificateController::store` pergunta ao dispatcher, por fonte habilitada, se o cliente é capturável (mesmo critério de `FiscalCoverage::motivoDoCertificado`) e não está bloqueado, e despacha `CaptureFiscalDocumentsJob`. A resposta continua 200 com `ClientResource` e ganha `meta.capture = { status: queued|blocked|not_capturable, sources, blocked_until, reason }`.

`CaptureFiscalDocumentsJob` passa a receber `accountId` e a buscar o cliente com `where('account_id', $accountId)`. O `fiscal:capture` e o disparo sob demanda são ajustados para passar o `account_id` do cliente.

Por quê: um só lugar decide se uma captura pode ser enfileirada, e o bloqueio de uma hora continua valendo porque o job e o `FiscalCaptureService` já o conferem de novo ao rodar. Descartadas: disparar a captura por evento de model (`ClientCertificate::created`) (dispararia também em seeders e em testes, e esconderia a auditoria do request); esperar a próxima hora cheia (é o problema da proposta).

### 7. A coluna de certificado da tabela XML reusa o motivo da cobertura

`FiscalDocumentController::index` passa a carregar o certificado atual do cliente de cada linha em lote e devolve `client_certificate_status` (`missing|expired|password_missing|expiring|valid`), calculado pela mesma regra de `FiscalCoverage` extraída para um método público (`FiscalCoverage::certificateStatus`). O frontend mostra a coluna "Certificado" em `app/pages/fiscal/documentos.vue`, com `color` semântico (error para vencido e sem certificado, warning para sem senha e a vencer, success para válido), seguindo a Status Is Semantic Rule do DESIGN.md. O cliente sem documento continua visível só na lista de atenção de `/fiscal`, que já existe.

Por quê: a tabela é o lugar onde o operador percebe que um cliente parou de chegar, e a regra já existe na cobertura. Descartadas: nova tela por cliente (duplica a lista de atenção); status por documento (o certificado é do cliente, não do documento).

### 8. Tenancy, papéis, suporte, auditoria e segredos

- **Tenancy**: `RefreshSerproPowersJob` e `CaptureFiscalDocumentsJob` carregam `account_id` e toda leitura e escrita neles filtra por ele (`withoutGlobalScope('account')` + `where('account_id', ...)`, como `persist()` já faz). O comando diário itera Accounts explicitamente e nunca depende de `CurrentTenant`. As associações seguem `forceFill` com `account_id` explícito em `associate`.
- **Papéis**: escrever módulos, enviar certificado e disparar o oráculo por cadastro são de `admin` e `operador` (`ClientPolicy::update`); `user` recebe 403 e lê os módulos pelo GET.
- **Acesso de suporte**: o super_admin tem os poderes de `admin`, sem exceção. `SupportAudit::logWrite` passa a ser chamado em: `ClientMonitoringModuleController::store` (`clients`, `monitoring-modules`, slugs associados), `ClientCertificateController::store` e `destroy` (`clients`, `certificate`/`certificate-remove`, fontes enfileiradas) e `SerproMonitoringAssociationController::store` (lacuna existente). O despacho do oráculo no cadastro entra no contexto do `logWrite` de `clients.create`/`update` (`power_refresh_queued: true`).
- **Segredos**: nenhuma resposta nova traz token, conteúdo ou senha de certificado, caminho de armazenamento ou XML bruto. O job do oráculo não registra o `dados` bruto da resposta; falhas vão para `serpro_calls` pelo recorder, que já aplica a disciplina de segredo. A resposta do upload traz só o `meta.capture` com códigos e datas.

## Risks / Trade-offs

- [Custo da rotina diária do oráculo em carteiras grandes] → janela de 20 horas, só pessoa jurídica ativa de Account habilitada, e o custo declarado no Impact da proposta.
- [Quebra do formato de `ecac_power_of_attorney` em consumidores externos] → `ecac_power_of_attorney_status` mantém o significado, e não há consumidor fora do frontend do monorepo (busca em `frontend/app`).
- [Clientes que hoje são elegíveis só porque o provedor ainda não foi consultado e o cadastro manual estava preenchido] → não existem: a elegibilidade já exige a linha `established` antes de olhar as datas manuais. Remover o passo 5 só pode tornar elegível quem as datas manuais bloqueavam, e esse é o comportamento desejado.
- [Filtro por procuração não cruza com os módulos (Decisão 4)] → limitação documentada e coberta por teste no caso comum.
- [Upload repetido de certificado em sequência dispara capturas próximas] → o bloqueio de uma hora e a trava por cliente e fonte já serializam; o dispatcher não enfileira em bloqueio.
- [Obrigação associada que não sincroniza (`sync_enabled = false`)] → a linha fica `sem_dados` e aparece no Monitoramento como hoje; a etapa mostra a obrigação sem promessa de dado imediato.

## Migration Plan

1. Deploy do backend com a migration de drop de `client_ecac_powers_of_attorney` e do frontend sem o modal, no mesmo release (o frontend antigo chamaria rotas removidas).
2. Rodar `php artisan migrate` em produção só com autorização explícita do usuário.
3. Depois do deploy, rodar `serpro:refresh-powers` uma vez manualmente (também com autorização, porque consome cota) para preencher as famílias antes da primeira rotina.
4. Rollback: reverter o release e rodar o `down()` da migration, que recria a tabela vazia. As procurações digitadas não voltam; a elegibilidade continua correta porque depende de `serpro_client_authorizations`.

## Open Questions

- Preço unitário de `OBTERPROCURACAO41` no contrato da plataforma: o config só tem `billable: true`. Não muda a abordagem (a janela de 20 horas e o escopo já estão fixados), só o custo mensal estimado.
- Horário exato da rotina diária (02:00 escolhido para não coincidir com `serpro:renew-terms` às 01:00). Pode mudar sem afetar spec nem tasks.
- Lista inicial exata do mapa regime → obrigações além dos casos fixados pelo usuário (PGDAS para Simples, PGMEI para MEI, nada do Simples em Lucro Real/Presumido). É dado de config e pode ser ajustado antes do apply.
- O Purpose de `openspec/specs/client-fiscal-access/spec.md` ainda diz que a spec "centraliza ... a procuração e-CAC". Um delta não altera Purpose; ajustar à mão no archive.
