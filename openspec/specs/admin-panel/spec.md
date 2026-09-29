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
