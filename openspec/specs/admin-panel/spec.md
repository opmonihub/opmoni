# admin-panel Specification

## Purpose
Oferece ao super_admin um ambiente global separado do operacional para gerir contas, planos, assinaturas, usuários e suporte.

## Requirements

### Requirement: Área exclusiva do super_admin
The system SHALL expose global admin routes and screens only to super_admins; regular users are redirected away and receive 403 from the underlying endpoints.

#### Scenario: Usuário comum acessa área global
- **WHEN** a regular user navigates to a global admin route
- **THEN** they are redirected to the operational area and the data endpoints respond 403

### Requirement: Gestão de contas
The system SHALL give super_admins screens to list accounts (with status, plan and member count), create accounts, rename them, and suspend or reactivate them. The effect of suspension is defined by the `accounts` spec and is not restated here.

#### Scenario: Suspensão de conta
- **WHEN** a super_admin suspends an account from the panel
- **THEN** the account status changes to suspended

### Requirement: Gestão financeira e usuários
The system SHALL give super_admins screens to change an account's plan, update its subscription status, edit plan limits, view all users globally, and open any account for support with access to the audit log. The rules behind those screens live in the `subscriptions` and `support-access` specs and are not restated here.

#### Scenario: Tela financeira aplica a regra de assinaturas
- **WHEN** a super_admin changes a plan or a subscription status from the panel
- **THEN** the change is applied as the `subscriptions` spec defines

#### Scenario: Log de suporte visível
- **WHEN** a super_admin opens the support log
- **THEN** the append-only entries defined by `support-access` are listed

### Requirement: Painel Global não cadastra o e-CNPJ do escritório
The system SHALL NOT offer the office e-CNPJ screen in the global panel. The platform credential screen SHALL remain there. The system SHALL NOT offer the office certificate form, the authorization-term screen or the integration switch in the monitoring module. Synchronization runs and the obligations panel SHALL remain in the monitoring module.

#### Scenario: Abas do Painel Global sem o certificado do escritório
- **WHEN** a super_admin opens the global panel
- **THEN** the navigation has no office-certificate entry and `/admin/certificado` is not a page of the panel

#### Scenario: Monitoramento não oferece o cadastro
- **WHEN** a member opens the monitoring module
- **THEN** the module shows no office-certificate form, no authorization-term screen and no integration switch, and `/monitoring/termos` is not a page of the module

### Requirement: Entrada do Painel Global no sidebar
The system SHALL show the global panel entry in the operational sidebar if and only if the current user is a super_admin.

#### Scenario: super_admin vê a entrada
- **WHEN** a super_admin loads the operational shell
- **THEN** the sidebar includes the global panel entry

#### Scenario: Admin da Account não vê a entrada
- **WHEN** a member whose role is `admin` and who is not a super_admin loads the operational shell
- **THEN** the sidebar does not include the global panel entry
