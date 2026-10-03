# design-system-ui Specification

## Purpose

Define requisitos transversais de consistência visual e de recuperação de erro nas superfícies Operate do frontend (Carteira, Work, Admin, Equipe, Monitoramento e home interna), alinhadas ao design system documentado em `DESIGN.md`.

## Requirements

### Requirement: Shell de página usa tokens centralizados
The Operate dashboard SHALL apply one of the documented page-shell layout tokens (scrolling column, table host with relative positioning for floating selection chrome, or centred record layout) on every dashboard page body, imported from the shared page-shell module rather than duplicating equivalent utility class strings inline.

#### Scenario: Página de scroll importa token
- **WHEN** a member opens a dashboard page whose body is a single scrolling column (for example Monitoramento, Work › Modelos list, or a record editor using the scroll variant)
- **THEN** the root page container uses the shared scrolling-column token from the page-shell module

#### Scenario: Página de tabela importa token
- **WHEN** a member opens a dashboard page whose body hosts a full-height table with a floating selection bar (for example Work › Clientes or Carteira › Clientes)
- **THEN** the root page container uses the shared table-host token that includes relative positioning

#### Scenario: Sem cópia inline do token de scroll
- **WHEN** the frontend codebase is searched for the scrolling-column utility string defined in the page-shell module
- **THEN** that exact string appears only in the page-shell module and not as a hard-coded duplicate on Vue pages

### Requirement: Tabelas Operate aplicam tokens de densidade compartilhados
The system SHALL render Operate data tables with the shared table `:ui` tokens appropriate to their context: compact Work tables use the Work table token; bordered panel-list tables use the panel table token; windowed sheet tables use the sheet table token.

#### Scenario: Lista de modelos Work
- **WHEN** a member opens Work › Modelos and templates are listed in a table
- **THEN** the table applies the Work table `:ui` token (compact header and cell padding, fixed layout) instead of Nuxt UI defaults alone

#### Scenario: Tabela em painel de lista Admin
- **WHEN** a super_admin opens an Admin list rendered inside the panel-list pattern
- **THEN** the table applies the panel table `:ui` token with hairline row rules consistent with other Admin lists

#### Scenario: Widget de tabela na home
- **WHEN** a member views the home dashboard sales table widget
- **THEN** the table applies the panel table `:ui` token (or the same bordered panel table styling) rather than a one-off inline `:ui` object

### Requirement: Falha fatal de carga inicial usa alerta com retry
When the initial load of a dashboard page's primary data fails with a non-recoverable-by-navigation error (excluding deliberate "not available" cases such as HTTP 404 treated as inert on monitoring placeholders), the system SHALL block the main content, show the shared fatal-error alert with Portuguese copy naming what failed and offering a single retry action, and SHALL NOT rely on toast alone as the only recovery path for that failure.

#### Scenario: Falha ao carregar lista principal
- **WHEN** the primary list or dashboard payload for Carteira, Work, or Admin fails on first load
- **THEN** the member sees the shared error alert with retry and does not see the main table or panel body as if data were empty

#### Scenario: Retry limpa falha e recarrega
- **WHEN** the member activates retry on that fatal-error alert
- **THEN** the failure state clears and the page attempts the load again before rendering success content

#### Scenario: Toast complementar em refresh manual
- **WHEN** a subsequent manual refresh fails while the page had already loaded successfully
- **THEN** the system MAY show a toast for that refresh failure while keeping the last good data visible, consistent with the retryable-load composable contract

### Requirement: Estados vazios seguem contrato documentado
The system SHALL present empty data using one of the documented empty patterns: panel-table empty slot for panel-list tables, table or list empty components for Operate tables, or dedicated empty components for whole-page absence; inline ad-hoc paragraphs SHALL NOT replace those patterns on list surfaces scoped by this change.

#### Scenario: Tabela de painel sem linhas
- **WHEN** an Admin or Equipe panel-list table has zero rows after a successful load
- **THEN** the table empty slot shows the shared panel-table empty body with Portuguese guidance

#### Scenario: Lista Work sem modelos
- **WHEN** Work › Modelos loads successfully with zero templates
- **THEN** the page shows the dedicated empty state with primary action to create a model (for authorized members) rather than an empty table frame

#### Scenario: Carteira sem clientes filtrados
- **WHEN** Carteira › Clientes loads successfully but filters exclude every row
- **THEN** the table or list communicates no matching clients without treating the situation as a load failure

### Requirement: Matriz de tokens publicada no design system
The project design system documentation SHALL include a page-to-token matrix listing, for each major Operate route group, which page-shell token, panel chrome imports, and table `:ui` token apply, so new screens do not reintroduce inline duplicates.

#### Scenario: Desenvolvedor consulta matriz
- **WHEN** a contributor adds a new Operate list page
- **THEN** they can read the matrix in `DESIGN.md` to choose the correct shell and table tokens without copying classes from an unrelated page
