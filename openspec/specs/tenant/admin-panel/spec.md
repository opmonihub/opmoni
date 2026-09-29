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
The system SHALL allow super_admins to list accounts (with status, plan and member count), create accounts, rename them, and suspend or reactivate them.

#### Scenario: Suspensão de conta
- **WHEN** a super_admin suspends an account from the panel
- **THEN** the account status changes and its members lose tenant access until reactivation

### Requirement: Gestão financeira e usuários
The system SHALL allow super_admins to change an account's plan, update subscription status, edit plan limits, view all users globally, and open any account for support with access to the audit log.

#### Scenario: Troca de plano
- **WHEN** a super_admin moves an account from Basic to Profissional
- **THEN** the new limits apply immediately to subsequent creations
