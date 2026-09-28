## Purpose

Executa e registra a sincronização inicial e o re-sync dos dados fiscais dos clientes de um escritório com o Integra Contador, com estado persistido por execução para que o progresso e as falhas sejam acompanháveis sem depender de log de servidor.

## ADDED Requirements

### Requirement: Disparo de sincronização por escritório
The system SHALL allow an `admin` or `operador` member to start a synchronization for the current Account, SHALL record it as a distinct run, and SHALL return a rejected response for a `user` member.

#### Scenario: Sincronização disparada
- **WHEN** an authorized member requests a synchronization and the platform connection is usable
- **THEN** a run is created in a queued state and its identifier is returned without waiting for the Serpro responses

#### Scenario: Contribuintes pessoa jurídica e pessoa física
- **WHEN** an Account has both company clients and individual clients
- **THEN** only company clients are dispatched and counted as considered, and individual clients are left out of the run entirely rather than counted as skipped

#### Scenario: Membro com papel=user
- **WHEN** a member whose role in the current Account is `user` requests a synchronization
- **THEN** the system responds 403 and no run is created

#### Scenario: Conexão ausente
- **WHEN** a synchronization is requested while the platform connection is missing or invalid
- **THEN** the system responds with a validation or service error and no run is created

### Requirement: Execução fora do ciclo de requisição
The system SHALL perform the Serpro calls from a queued job rather than inside the request that started the run, and SHALL accept the request before the results are known.

#### Scenario: Resposta imediata
- **WHEN** a synchronization is accepted
- **THEN** the response indicates the run was accepted for background processing and does not claim a completed result

#### Scenario: Execução pelos dados do escritório
- **WHEN** the queued job runs
- **THEN** it processes the clients of the Account that requested the run, and not the clients of any other Account

### Requirement: Estados persistidos por execução
The system SHALL persist each run through the states queued, running, completed, partial and failed, and SHALL record when the run entered each state.

#### Scenario: Sincronização concluída
- **WHEN** every eligible client is processed without an unrecovered failure
- **THEN** the run reaches the completed state and its timestamps are returned

#### Scenario: Sincronização parcialmente concluída
- **WHEN** at least one eligible client fails and at least one succeeds
- **THEN** the run reaches the partial state, retaining the counts of both outcomes and the reason of the failures

#### Scenario: Sincronização falhou
- **WHEN** the run cannot process any client because the connection is rejected or the provider is unavailable
- **THEN** the run reaches the failed state with a readable reason and no run is left in a non-terminal state

#### Scenario: Execução abandonada
- **WHEN** the worker terminates while a run is in the running state
- **THEN** the run is eventually moved to a terminal failed state rather than remaining running indefinitely

### Requirement: Estados por item de execução
The system SHALL report each client's item of a run as one of `sincronizado`, `ignorado`, `falhou`, `indeterminado` or `nao_processado`, SHALL record the reason for every `ignorado` item, and SHALL NOT reuse the vocabulary of an obligation's situation for an item.

#### Scenario: Item não processado
- **WHEN** a run has not yet reached a client
- **THEN** that client's item is reported as `nao_processado`, which is distinct from an item that was processed and skipped

#### Scenario: Motivo do item ignorado
- **WHEN** an item is `ignorado`
- **THEN** the reason is reported, and a client with no valid power of attorney is reported as not eligible rather than as a failure

#### Scenario: Item não elegível
- **WHEN** a client has no valid power of attorney
- **THEN** the item is `ignorado` with the ineligibility as its reason, and is counted neither as synchronized nor as failed

### Requirement: Contagens por execução
The system SHALL report, for each run, the total number of clients considered, the number synchronized, the number skipped and the number failed.

#### Scenario: Consulta de uma execução
- **WHEN** an authorized member requests a completed run
- **THEN** the response includes total, synchronized, skipped and failed counts that sum to the total considered

#### Scenario: Cliente sem procuração é contado como ignorado
- **WHEN** a client has no valid procuração
- **THEN** it is counted as skipped and is not counted as synchronized or failed

### Requirement: Falha legível por execução
The system SHALL map a Serpro return code or provider failure to a readable message for the member, and SHALL NOT expose the raw provider payload, the consumer secret or certificate contents.

#### Scenario: Código de retorno do provedor
- **WHEN** the Serpro service answers with a documented return code
- **THEN** the run records the corresponding readable reason and the code, and no raw payload

#### Scenario: Limite de requisições do provedor
- **WHEN** the Serpro gateway rejects a request because a provider limit was reached
- **THEN** the run records a recoverable provider-limit reason and the affected clients are retried by a later run rather than reported as permanent failures

#### Scenario: Erro de transporte
- **WHEN** the gateway times out or the connection is refused
- **THEN** the run records a recoverable provider failure and does not mark the client data as up to date

### Requirement: Re-sync manual
The system SHALL allow an authorized member to start a new run over clients already synchronized, and SHALL refresh the stored data rather than accumulating duplicate records.

#### Scenario: Re-sync concluído
- **WHEN** a member requests a re-sync after a completed run
- **THEN** the stored records for the affected clients are updated in place and a new run is recorded separately from the previous one

#### Scenario: Re-sync sem dado novo
- **WHEN** a re-sync returns data identical to what is already stored
- **THEN** the stored state is preserved, the source timestamp is refreshed and the client is still counted as synchronized

### Requirement: Uma execução em andamento por escritório
The system SHALL refuse to start a new run for an Account while that Account already has a run in the queued or running state.

#### Scenario: Sincronização concorrente
- **WHEN** a member requests a synchronization while the same Account has a run queued or running
- **THEN** the system refuses the new request, reports the identifier of the run in progress and creates no additional run

#### Scenario: Nova execução após término
- **WHEN** a member requests a synchronization after the previous run reached a terminal state
- **THEN** the new run is accepted

### Requirement: Isolamento entre escritórios
The system SHALL scope runs and synchronized records to the Account that requested them, and SHALL return no run and no record belonging to another Account.

#### Scenario: Consulta entre contas
- **WHEN** a member of one Account requests a run created by a member of another Account
- **THEN** the system responds 404 and reveals no run detail

#### Scenario: Processo sem escritório definido
- **WHEN** background processing runs without a resolved Account context
- **THEN** the system still restricts reads and writes to the Account explicitly carried by the run and never falls back to an unscoped query

### Requirement: Dado trazido fica vinculado ao cliente
The system SHALL attach synchronized data to the client it belongs to, SHALL record when the source reported that data, and SHALL keep the most recent successful synchronization per client.

#### Scenario: Registro trazido do provedor
- **WHEN** a client is synchronized successfully
- **THEN** the returned values are stored against that client with the source timestamp reported by the Serpro service

#### Scenario: Cliente sem dado anterior
- **WHEN** a client is synchronized for the first time
- **THEN** the stored state is created for it and the run counts it as synchronized

### Requirement: Chamadas serializadas por cliente
The system SHALL issue at most one in-flight provider request per client, SHALL not process the same client concurrently, and SHALL surface a client whose previous call has not finished as still running rather than starting a second call.

#### Scenario: Duas execuções para o mesmo cliente
- **WHEN** two runs both include the same client
- **THEN** only one of them issues provider calls for that client at a time and the other waits, because the provider requires one request at a time per document

#### Scenario: Cliente já em processamento
- **WHEN** a run reaches a client whose call has not completed
- **THEN** the run remains in progress and does not report that client as synchronized or failed prematurely

### Requirement: Timeout é estado indeterminado
The system SHALL record a provider timeout as a distinct indeterminate outcome, SHALL NOT retry it within the same run, SHALL NOT count it as a client failure, and SHALL leave the remaining clients of the run to be processed.

#### Scenario: Provedor excede o tempo
- **WHEN** a provider call exceeds the gateway response limit
- **THEN** the run item is recorded as an indeterminate outcome with the provider's response identifier, the run continues with the other clients, and no immediate second call is made

#### Scenario: Reenvio por conta própria
- **WHEN** the office starts a later run covering a client whose previous call timed out
- **THEN** the call is attempted again, and the indeterminate outcome from the previous run remains visible in the history

#### Scenario: Reenvio imediato é vedado
- **WHEN** a run is processing clients
- **THEN** no client that already produced an indeterminate outcome is called again within that same run

### Requirement: Falhas de configuração e permissão não são repetidas
The system SHALL NOT retry a provider rejection that indicates a data, permission, authorization-term or configuration fault, and SHALL present it as a cause to be corrected rather than as a transient failure.

#### Scenario: Falta de procuração e-CAC
- **WHEN** the provider reports that the requesting party holds no procuração for the contributor
- **THEN** the client is reported as not eligible, the run records the reason as a business condition, and the call is not repeated

#### Scenario: Termo de autorização exigido ou recusado
- **WHEN** the provider reports that an authorization term is required, is missing, is expired or was signed by another party
- **THEN** the run records the reason as a non-retryable term problem, identifies the client, and does not repeat the call

#### Scenario: Divergência de documento do contratante
- **WHEN** the provider reports that the contracting document does not match the certificate
- **THEN** the run fails with a configuration reason naming the mismatch, and the call is not repeated

#### Scenario: Limite do provedor
- **WHEN** the provider rejects a request because a provider quota was reached
- **THEN** the run records a recoverable reason and the affected clients are left to a later run rather than being reported as permanent failures

### Requirement: Rastreabilidade de custo por cliente
The system SHALL attach a request tag identifying the requesting party, the subject and the service to every provider call, SHALL persist that tag together with the provider's response identifier, and SHALL be able to report the calls of a run so they can be reconciled against the provider's consumption report.

#### Scenario: Chamada identificada
- **WHEN** the system issues a provider call for a client and a service
- **THEN** the call carries a tag of exactly thirty-two characters in the documented shape and the tag is stored on the call record

#### Scenario: Identificador de resposta preservado
- **WHEN** the provider answers with a response identifier
- **THEN** it is stored on the call record so the call can be quoted to provider support

#### Scenario: Reconciliação por cliente
- **WHEN** an authorized member inspects a completed run
- **THEN** the calls of that run are listed with their client, service and tag, and a client with no call is distinguishable from a client whose call failed

#### Scenario: Chamada gratuita é identificada
- **WHEN** the system issues a call to a provider path that is not charged
- **THEN** the call record states that it was not billable, so cost expectations are not overstated
