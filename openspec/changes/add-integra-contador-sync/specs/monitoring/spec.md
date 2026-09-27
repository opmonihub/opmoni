## Purpose

Substitui o painel de Monitoramento, hoje alimentado por dados fictícios, pelos dados realmente sincronizados com o Integra Contador, expondo por obrigação o estado de cada cliente do escritório e explicitando quem ainda não tem dado.

## ADDED Requirements

### Requirement: Clientes derivados de registros sincronizados
The system SHALL build the monitoring lists and the portfolio count from clients that have synchronized records, and SHALL NOT present hardcoded, sample or arithmetically-derived companies as real companies.

#### Scenario: Painel com clientes sincronizados
- **WHEN** an Account has clients with synchronized records
- **THEN** the portfolio count equals the number of those clients and each listed row corresponds to a real client of the Account

#### Scenario: Painel sem nenhum dado sincronizado
- **WHEN** an Account has no synchronized record at all
- **THEN** the portfolio count is zero and every list presents an explicit empty state instead of example rows

#### Scenario: Empresa não atendida é identificada
- **WHEN** a client of the Account has no synchronized record
- **THEN** it is presented as not covered by the integration, with a distinct label, and is never counted as regular

### Requirement: Estado derivado por obrigação
The system SHALL derive each client's state per monitored obligation as pending, expiring, expired or regular from the synchronized data, and SHALL preserve this state vocabulary for existing clients of the panel.

#### Scenario: Obrigação vencida
- **WHEN** the synchronized data reports an obligation whose due date is earlier than the current date
- **THEN** the client is returned in the expired state for that obligation

#### Scenario: Obrigação a vencer
- **WHEN** the synchronized data reports an obligation whose due date falls between the current date and thirty calendar days from it inclusive
- **THEN** the client is returned in the expiring state with the due date

#### Scenario: Obrigação regular
- **WHEN** the synchronized data reports an obligation whose due date is beyond thirty calendar days and which is not marked as pending upstream
- **THEN** the client is returned in the regular state

#### Scenario: Obrigação pendente
- **WHEN** the synchronized data marks the obligation as pending upstream
- **THEN** the client is returned in the pending state even if its due date is beyond thirty calendar days

#### Scenario: Sem dado suficiente para derivar
- **WHEN** the synchronized record exists but carries no due date and no upstream pending marker for an obligation
- **THEN** the state for that obligation is not reported as regular and the client is surfaced as requiring attention

### Requirement: Contagem de atenção
The system SHALL report, per monitored obligation, the number of clients in a state that requires attention, and SHALL compute that count from synchronized records only.

#### Scenario: Contagem reflete o estado real
- **WHEN** an Account has clients in the expired, expiring or pending state for an obligation
- **THEN** the count for that obligation equals the number of those clients and excludes the regular ones

#### Scenario: Painel de contagem
- **WHEN** an authorized member opens the monitoring overview
- **THEN** each card shows the attention count for its obligation, including zero when there is nothing to attend

### Requirement: Listagem por obrigação e situação
The system SHALL list the clients of the current Account for a selected obligation and situation, and SHALL resolve the selection from the requested route so that a situation can be shared as a link.

#### Scenario: Listagem filtrada
- **WHEN** a member opens a monitored obligation restricted to the expired situation
- **THEN** only clients in the expired state for that obligation are returned, scoped to the current Account

#### Scenario: Situação desconhecida
- **WHEN** the requested obligation or situation does not exist
- **THEN** the system responds with a not-found result and no list of companies

#### Scenario: Situação preservada entre obrigações
- **WHEN** a member moves from one obligation to another while a situation is selected
- **THEN** the selected situation is kept in the resulting route

### Requirement: Estados de carregamento, vazio e erro
The system SHALL present a distinct recoverable error state with a retry action, a loading state, an empty state, and an empty-result-after-filtering state, and SHALL NOT present a failed load as an empty list.

#### Scenario: Falha ao carregar
- **WHEN** the monitoring data cannot be loaded
- **THEN** the page shows a recoverable error with a retry action and does not show an empty list

#### Scenario: Lista vazia sem filtros
- **WHEN** the list for an obligation is empty and no filter is applied
- **THEN** the page shows an empty state describing that the list has no clients

#### Scenario: Filtro sem resultado
- **WHEN** the list has clients but the applied filters exclude all of them
- **THEN** the page shows an empty-result state offering to clear the filters

### Requirement: Painel coerente com a listagem
The system SHALL make the overview counts, the group summaries and the list contents consistent with each other, so that navigating from a count to a list yields the number of clients the count announced.

#### Scenario: Contagem e listagem concordam
- **WHEN** a member navigates from an attention count card to the corresponding list and situation
- **THEN** the list contains exactly the clients counted by that card

#### Scenario: Atualização após sincronização
- **WHEN** a synchronization completes and changes the state of clients
- **THEN** a subsequent load of the overview reflects the new counts without requiring a manual recalculation

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
- **THEN** the client is presented as owing that period, and the state is not reported as regular

#### Scenario: Declaração retificadora
- **WHEN** the synchronized data reports a rectified declaration for a period that already had an original one
- **THEN** the most recent transmission is the one presented, and the earlier one is not shown as current

#### Scenario: Confirmação de pagamento não é exigida para exibir
- **WHEN** the status view is loaded
- **THEN** the slip status is presented from already synchronized data without issuing an additional provider call for that client
