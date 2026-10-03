## MODIFIED Requirements

### Requirement: Filtros da tabela
The system SHALL allow filtering the documents table by model, client, issuer, recipient, date range and document kind, SHALL allow a free-text search filter (`q`) matching the document number exactly, the access key exactly when the search value is forty-four or fifty digits, and the client name by case-insensitive substring, SHALL combine the text search with the other filters by conjunction (AND), and SHALL keep every filter in the URL so the view is shareable and survives reload. A search value longer than the accepted maximum SHALL be rejected with HTTP 422, and a search value containing LIKE wildcards SHALL be treated as literal text, never as a wildcard.

#### Scenario: Filtro por modelo
- **WHEN** a member filters by one or more models
- **THEN** only documents of those models are listed and the model filter offers the values present in the filtered result plus the currently selected ones, so a filter that matches nothing can still be removed

#### Scenario: Filtro preservado
- **WHEN** a member reloads a filtered view or opens it in another tab
- **THEN** the same filters are applied

#### Scenario: Combinação sem resultado
- **WHEN** a filter combination matches no document
- **THEN** the table reports that no document matches rather than an error

#### Scenario: Busca pelo número da nota
- **WHEN** a member searches the documents table with the exact number of a note (`q`)
- **THEN** only the rows of that number within the account are listed, and the events of a matched document remain reachable through its detail as in any other filter

#### Scenario: Busca pela chave de acesso
- **WHEN** a member searches with the full access key of a document, using either forty-four digits for NF-e, NFC-e and CT-e family documents or fifty digits for NFS-e national documents
- **THEN** the rows of that access key within the account are listed, including the timeline rows of the same client and key

#### Scenario: Busca pelo nome do cliente
- **WHEN** a member searches with a fragment of a client name in a different letter case
- **THEN** the listing shows documents of clients whose name contains that fragment, compared case-insensitively

#### Scenario: Busca combinada com os outros filtros
- **WHEN** a member searches with `q` while other filters are active
- **THEN** only documents satisfying both the text search and the active filters are listed, and the pagination, ordering and available models reflect the combined result

#### Scenario: Busca sem resultado
- **WHEN** the search value matches no document
- **THEN** the empty state names the search as a possible cause and offers clearing the filters, and the response is 200 with no rows rather than an error

#### Scenario: Curinga da busca
- **WHEN** a member searches with a value containing LIKE wildcard characters (`%` or `_`)
- **THEN** the value is matched literally and returns only documents containing those characters, not every document

#### Scenario: Busca inválida
- **WHEN** a request carries a search value longer than the accepted maximum
- **THEN** the listing responds with HTTP 422 naming the `q` field, and no partial result is returned

#### Scenario: Busca entre contas
- **WHEN** a member searches a value that exists as a document number or access key in another Account
- **THEN** only documents of the current Account are returned, and no other account's document is reachable through the search

## ADDED Requirements

### Requirement: Tabela inclui NFS-e capturada
The documents table SHALL list captured NFS-e national documents alongside NF-e and CT-e when present, with model `nfse` shown per row and the same detail and XML download behavior as other captured documents.

#### Scenario: Linha de NFS-e na tabela
- **WHEN** an authorized member opens the documents table for an account that has captured NFS-e documents
- **THEN** those rows appear with model NFS-e and remain filterable by the NFS-e model filter
