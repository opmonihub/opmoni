# monitoring Specification

## Purpose
Substitui o painel de Monitoramento, hoje alimentado por dados fictícios, pelos dados realmente sincronizados com o Integra Contador. O painel passa a expor por obrigação os contadores que subdividem a carteira do escritório, a situação de cada cliente e a causa nomeada por trás de cada agregação, e passa a distinguir as obrigações que o provedor atende das que ele não atende e das que deixaram de existir.

## Requirements

### Requirement: Clientes derivados de registros sincronizados
The system SHALL build the monitoring lists and the portfolio count from clients that have synchronized records, and SHALL NOT present hardcoded, sample or arithmetically-derived companies as real companies.

#### Scenario: Painel com clientes sincronizados
- **WHEN** an Account has clients with synchronized records
- **THEN** the portfolio count equals the number of those clients and each listed row corresponds to a real client of the Account

#### Scenario: Painel sem nenhum dado sincronizado
- **WHEN** an Account has no synchronized record at all
- **THEN** the portfolio count is zero and every list presents an explicit empty state instead of example rows

#### Scenario: Contribuinte pessoa física fora do alcance da integração
- **WHEN** a client of the Account is a natural person
- **THEN** that client is excluded from the total and from every list, because the integration only acts for company clients, and its exclusion is not reported as a failure of the integration

### Requirement: Obrigação classificada pela fonte que o provedor tem
The system SHALL classify every monitored obligation according to what the Integra Contador catalogue actually serves, into exactly one of four categories, and SHALL NOT treat an obligation it cannot serve as if the data were merely pending.

#### Scenario: Obrigação com leitura estruturada
- **WHEN** an obligation maps to a provider service that returns structured data
- **THEN** the obligation is classified as `direct` and presents counters and client rows

#### Scenario: Obrigação derivada de outra leitura
- **WHEN** an obligation is served by the same provider call as another obligation, or by a filter over the content of a returned message rather than by a dedicated service
- **THEN** the obligation is classified as `derived` and the panel names the call or the filter it derives from, so that the office knows it is a projection and not an independent source

#### Scenario: Obrigação sem serviço no provedor
- **WHEN** an obligation exists as a Brazilian tax obligation but the Integra Contador catalogue publishes no service serving it
- **THEN** the obligation is classified as `unavailable`, the panel states that the integration does not serve it, and the panel presents no counter and no client row for it, which is distinct from an obligation whose counter is zero

#### Scenario: Obrigação extinta
- **WHEN** an obligation ceased to be due under Brazilian law and its reporting content moved to systems the integration does not expose
- **THEN** the obligation is classified as `extinct`, the panel says the obligation itself no longer exists rather than that the integration is failing, and the panel presents no counter and no client row for it

#### Scenario: Obrigação sem fonte não é apresentada como pendência do cliente
- **WHEN** an obligation is classified as `unavailable` or `extinct`
- **THEN** no client of the Account is reported as pending, expiring, expired or requiring attention for that obligation, because the absence is a property of the obligation and not of any client

### Requirement: Contadores que subdividem o total
The system SHALL present, per monitored obligation that is classified as `direct` or `derived`, the total number of clients in scope together with the number in each of the states that subdivide it: `em_dia`, `processando`, `pendencias` and `atencao`. The system SHALL report the total as the sum of those four, and SHALL report it as zero rather than omitting it when nothing is to be counted. A state outside the partition, `encerrado`, SHALL be presented on the row and SHALL NOT be folded into any of the four counters.

#### Scenario: Total é a soma dos quatro
- **WHEN** an obligation presents its counters
- **THEN** the total equals the sum of `em_dia`, `processando`, `pendencias` and `atencao`, and no client is counted in two of them

#### Scenario: Nada a atender
- **WHEN** no client of an Account requires attention for an obligation
- **THEN** the `atencao` counter is reported as zero and is displayed, rather than omitted

#### Scenario: Obrigação encerrada fica fora da partição
- **WHEN** a client of an obligation has been closed
- **THEN** the client is shown as `encerrado` on the row and is excluded from the four counters, so that a closed obligation never inflates a state that requires action

#### Scenario: Contador de progresso é eixo separado
- **WHEN** an obligation reports how many clients have been transmitted or emitted out of how many were requested
- **THEN** that progress is presented beside the counters as a separate reading of the synchronization rather than as a state of any client

### Requirement: Situação nomeada por cliente e causa por trás do agregador
The system SHALL present each client's situation on the row, and SHALL name the cause behind an aggregate counter rather than presenting only the aggregate. The system SHALL return the list of causes with a code for each, and the presentation of every cause SHALL be derived from that list rather than hardcoded in the client.

#### Scenario: Atenção nomeia a causa
- **WHEN** the `atencao` counter of an obligation is greater than zero
- **THEN** each client counted in it shows a named cause rather than the bare aggregate, and the named causes include the absence of a power of attorney, an invalid power of attorney, and the absence of a declaration

#### Scenario: Lista de causas vem do backend
- **WHEN** a cause is presented
- **THEN** its label and colour are resolved from the code supplied by the system, and adding a cause does not require redeploying the client

#### Scenario: Pendência com e sem prazo
- **WHEN** a client has an obligation that is due or approaching its due date
- **THEN** the client is counted in `pendencias` with its due date shown, and a due date beyond thirty days does not by itself place the client in that counter

#### Scenario: Regular com carência
- **WHEN** an obligation's due date is beyond thirty days and it is not marked as pending upstream
- **THEN** the client is counted in `em_dia`

#### Scenario: Processando por obrigação
- **WHEN** a client has an item of the current synchronization still queued or running for the obligation being viewed
- **THEN** the client is counted in `processando` for that obligation, and a client synchronized for one obligation while still processing another may be counted in `em_dia` for the first and in `processando` for the second

### Requirement: Dado sincronizado desatualizado é sinalizado à parte
The system SHALL report whether a client's synchronized data is out of date as an attribute separate from the client's situation, so that a client whose power of attorney lapsed keeps its retained data without that data being presented as current, and SHALL NOT express staleness as a further situation.

#### Scenario: Procuração vencida com dado retido
- **WHEN** a client's power of attorney has expired and previously synchronized data is retained
- **THEN** the client is reported as no longer eligible to be acted for, the retained data is labelled as out of date, and the client's situation for the obligation is left intact

#### Scenario: Dado vigente não é sinalizado
- **WHEN** a client's power of attorney is in force
- **THEN** no out-of-date label is reported, whatever the client's situation is

### Requirement: Listagem por obrigação e situação
The system SHALL list the clients of the current Account for a selected obligation and situation, and SHALL resolve the selection from the requested route so that a situation can be shared as a link, and SHALL resolve it by navigating rather than by local state.

#### Scenario: Listagem filtrada
- **WHEN** a member opens a monitored obligation restricted to a situation
- **THEN** only clients in that situation for that obligation are returned, scoped to the current Account

#### Scenario: Situação desconhecida
- **WHEN** the requested obligation or situation does not exist
- **THEN** the system responds with a not-found result and no list of companies

#### Scenario: Situação preservada entre obrigações
- **WHEN** a member moves from one obligation to another while a situation is selected
- **THEN** the selected situation is kept in the resulting route

#### Scenario: Situação é um link
- **WHEN** a member shares the address of an obligation restricted to a situation
- **THEN** the address reproduces the same list for whoever follows it

### Requirement: Estados de carregamento, vazio, sem fonte e erro
The system SHALL present a distinct recoverable error state with a retry action, a loading state, an empty state, an empty-result-after-filtering state, and a distinct state for an obligation the integration does not serve, and SHALL NOT present a failed load as an empty list, nor an unserved obligation as an obligation with nothing to show.

#### Scenario: Falha ao carregar
- **WHEN** the monitoring data cannot be loaded
- **THEN** the page shows a recoverable error with a retry action and does not show an empty list

#### Scenario: Lista vazia sem filtros
- **WHEN** the list for an obligation is empty and no filter is applied
- **THEN** the page shows an empty state describing that the list has no clients

#### Scenario: Filtro sem resultado
- **WHEN** the list has clients but the applied filters exclude all of them
- **THEN** the page shows an empty-result state offering to clear the filters

#### Scenario: Obrigação não servida
- **WHEN** an obligation is classified as `unavailable` or `extinct`
- **THEN** the page states which of the two it is and why, and does not show the empty state used for an obligation that has no clients

### Requirement: Colunas declaradas por obrigação
The system SHALL declare the columns each obligation displays in the obligation's own registry entry, and SHALL NOT derive the column set from a family shared with other obligations, so that an obligation the integration does not serve is not obliged to display a column about that data.

#### Scenario: Obrigação sem coluna própria
- **WHEN** an obligation serves no data of its own
- **THEN** it presents no obligation-specific column, rather than repeating a column that belongs to another obligation

#### Scenario: Colunas descrevem o que a fonte entrega
- **WHEN** an obligation is displayed
- **THEN** each of its columns corresponds to a value its source actually returns

### Requirement: Painel coerente com a listagem
The system SHALL make the overview counters, the group summaries and the list contents consistent with each other, so that navigating from a counter to a list yields the number of clients the counter announced.

#### Scenario: Contador e listagem concordam
- **WHEN** a member navigates from a counter to the corresponding list and situation
- **THEN** the list contains exactly the clients counted by that counter

#### Scenario: Atualização após sincronização
- **WHEN** a synchronization completes and changes the state of clients
- **THEN** a subsequent load of the overview reflects the new counters without requiring a manual recalculation

### Requirement: Status de guia derivado dos dados sincronizados
The system SHALL derive collection-slip status from the synchronized declaration data, presenting whether a slip was issued, its issue date, its due date and whether it was paid, and SHALL NOT require a separate provider call to present it.

#### Scenario: Guia emitida e paga
- **WHEN** the synchronized data reports a slip number, its issue timestamp and a paid flag for an assessment period
- **THEN** the client is presented as having that slip issued and paid, with both dates

#### Scenario: Guia emitida e não paga
- **WHEN** the synchronized data reports a slip number with a false paid flag
- **THEN** the client is presented as having that slip issued and unpaid, and it is surfaced as requiring attention

#### Scenario: Guia ainda não emitida
- **WHEN** the synchronized data reports an assessment period with a declaration but no slip
- **THEN** the client is presented as owing that period, and the situation is not reported as regular

#### Scenario: Declaração retificadora
- **WHEN** the synchronized data reports a rectified declaration for a period that already had an original one
- **THEN** the most recent transmission is the one presented, and the earlier one is not shown as current

#### Scenario: Confirmação de pagamento não é exigida para exibir
- **WHEN** the status view is loaded
- **THEN** the slip status is presented from already synchronized data without issuing an additional provider call for that client

### Requirement: Leitura de mensagem exige confirmação da ciência

The system SHALL read a mailbox message from the provider only when the member explicitly confirms that reading it registers the ciência da intimação and starts the legal deadline. The system SHALL NOT read a message as a side effect of listing or opening the monitoring screen. The read SHALL be a `POST` to `serpro/monitoring/obligations/{obligation}/clients/{client}/messages/{message}` carrying `ciencia: true` in the body, and SHALL be allowed to `admin` and `operador` members and to a super_admin in support mode, like any other `admin` act. Every refusal SHALL happen before the provider is called, because the call itself is the legal act and a refusal after it cannot undo the ciência. The system SHALL accept a message id only when it is among the messages already synchronized for that client and that obligation. The system SHALL record who read the message and when. A provider failure SHALL answer with the readable failure label and SHALL NOT expose the provider's own text. Reopening a message already read SHALL call the provider again.

#### Scenario: Leitura sem confirmação

- **WHEN** a member requests a mailbox message without `ciencia: true` in the body
- **THEN** the system responds 422 and does not call the provider

#### Scenario: Leitura com confirmação

- **WHEN** an `admin` or `operador` member confirms the ciência and requests a message synchronized for that client
- **THEN** the system reads the message from the provider, responds 200 with its subject, plain-text body, reading date, ciência date and deadline, marks the message as read in the list, and records the member who read it

#### Scenario: Listagem não lê mensagens

- **WHEN** a member opens the monitoring screen or lists the mailbox
- **THEN** the system does not read any message from the provider

#### Scenario: User não registra ciência

- **WHEN** a `user` member requests a message with `ciencia: true`
- **THEN** the system responds 403 and does not call the provider

#### Scenario: Suporte registra ciência com auditoria

- **WHEN** a super_admin in support mode confirms the ciência and requests a message
- **THEN** the system reads the message as it would for an `admin` and records the act in the support audit log

#### Scenario: Mensagem fora da caixa sincronizada

- **WHEN** the message id is not among the synchronized messages of that client for that obligation, the obligation has no mailbox, or the client belongs to another Account
- **THEN** the system responds 404 and does not call the provider

#### Scenario: Escritório sem termo ou sem e-CNPJ

- **WHEN** the Account has no valid authorization term or no stored e-CNPJ certificate
- **THEN** the system responds 409 naming what is missing and does not call the provider

#### Scenario: Falha do provedor

- **WHEN** the provider rejects the read
- **THEN** the system responds 502 with the readable failure label, the response contains no provider text, and the message stays unread in the list

### Requirement: Mapa fixo de obrigações sugeridas por regime
The system SHALL keep, in the obligation catalogue, one fixed map from tax regime to suggested monitoring obligations that is the same for every Account, SHALL include in it only obligations classified as `direct` or `derived`, and SHALL NOT let an Account edit the map. The map SHALL suggest PGDAS for Simples Nacional, PGMEI for MEI, no Simples Nacional routine for Lucro Presumido or Lucro Real, and no obligation for a natural person.

#### Scenario: Sugestão para Simples Nacional
- **WHEN** the suggested obligations for a Simples Nacional company are requested
- **THEN** PGDAS is suggested and no obligation classified as `unavailable` or `extinct` is suggested

#### Scenario: Sugestão para MEI
- **WHEN** the suggested obligations for a MEI company are requested
- **THEN** PGMEI is suggested and PGDAS is not

#### Scenario: Sugestão para Lucro Presumido ou Real
- **WHEN** the suggested obligations for a Lucro Presumido or Lucro Real company are requested
- **THEN** neither PGDAS, PGMEI nor any other Simples Nacional routine is suggested

#### Scenario: Mesma sugestão em todas as Accounts
- **WHEN** two Accounts request the suggestion for clients of the same tax regime
- **THEN** both receive the same list of obligations

### Requirement: Associação no cadastro do cliente
The system SHALL expose `GET /api/clients/{client}/monitoring-modules`, returning with 200 each obligation the integration serves with its slug, label, category, whether it is suggested for the client's tax regime and whether the client is already associated with it, and `POST /api/clients/{client}/monitoring-modules` with a list of obligation slugs, creating with 200 one client × obligation association per slug that does not exist yet and returning how many were associated and how many already existed. The association SHALL be created without source data, SHALL enter the next synchronization, SHALL NOT issue any provider call and SHALL NOT consume provider quota. The system SHALL respond 403 to a `user` member on the POST, 404 when the client belongs to another Account, and 422 for an unknown slug, a slug classified as `unavailable` or `extinct`, or a natural-person client. The system SHALL NOT remove an existing association through this endpoint.

#### Scenario: Confirmação dos módulos
- **WHEN** an `admin` or `operador` posts PGDAS and the e-CAC mailbox for a company client of the current Account
- **THEN** the system responds 200 with `associated` equal to 2, both associations exist without source data, and no provider call is recorded

#### Scenario: Associação repetida
- **WHEN** a member posts an obligation the client is already associated with
- **THEN** the system responds 200, counts it as already associated and creates no duplicate

#### Scenario: Obrigação não servida
- **WHEN** a member posts a slug classified as `unavailable` or `extinct`, or a slug that does not exist
- **THEN** the system responds 422 and creates no association

#### Scenario: User tenta associar
- **WHEN** a `user` member posts modules for a client
- **THEN** the system responds 403 and creates no association

#### Scenario: Cliente de outra Account
- **WHEN** a member addresses the modules endpoints with the id of a client of another Account
- **THEN** the system responds 404 and reveals no obligation or association of that client

#### Scenario: Leitura dos módulos
- **WHEN** any member of the current Account, including a `user`, requests the modules of a client
- **THEN** the system responds 200 with the served obligations, the suggestion for the client's regime and the current associations

#### Scenario: Associação entra na próxima execução
- **WHEN** a synchronization runs after the modules were confirmed and the client is eligible for the obligation's family
- **THEN** the client is synchronized for the associated obligations

#### Scenario: Acesso de suporte
- **WHEN** a super_admin in support access confirms the modules of a client
- **THEN** the associations are created as for an `admin`, and a support audit entry records the client id and the associated slugs
