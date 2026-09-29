# subscriptions Specification

## Purpose
Sustenta o modelo comercial com três planos, assinatura automática no Básico e bloqueio no estouro de limites.

## Requirements

### Requirement: Três planos com limites
The system SHALL provide exactly three plans — Básico (5 users, 50 clients, 100 monitorings), Profissional (20 users, 500 clients, 1000 monitorings) and Empresarial (unlimited) — with editable limits managed through the global panel.

#### Scenario: Listagem de planos
- **WHEN** a super_admin requests the plan catalog
- **THEN** the three plans with their current limits are returned

### Requirement: Assinatura automática no Básico
The system SHALL create an `ativa` subscription on the Básico plan automatically whenever an account is created.

#### Scenario: Conta criada
- **WHEN** a new account is created by any means
- **THEN** it has exactly one `ativa` subscription on the Básico plan without manual action

### Requirement: Bloqueio no estouro de limite
The system SHALL reject with 422 any creation that would exceed the account's active plan limit, creating nothing.

#### Scenario: Limite de clientes atingido
- **WHEN** an account on the Basic plan with 50 clients attempts to create one more
- **THEN** the system responds 422 and the client count remains 50

### Requirement: Gestão financeira global
The system SHALL allow only super_admins to change an account's plan and to set its subscription status to `ativa`, `inadimplente` or `cancelada`. While the account has no subscription, or its subscription is in any status other than `ativa`, every tenant write SHALL respond 403 and every tenant read SHALL keep responding. This block SHALL be distinct from account suspension, which blocks reads and writes alike.

#### Scenario: Assinatura inadimplente bloqueia escrita
- **WHEN** a subscription is `inadimplente` or `cancelada` and a member attempts a write
- **THEN** the system responds 403 and nothing changes until the subscription is `ativa` again

#### Scenario: Assinatura inadimplente mantém leitura
- **WHEN** a subscription is `inadimplente` or `cancelada` and a member reads a tenant resource
- **THEN** the system responds 200

#### Scenario: Conta sem assinatura
- **WHEN** an account has no subscription and a member attempts a write
- **THEN** the system responds 403

#### Scenario: Troca de plano
- **WHEN** a super_admin moves an account from Básico to Profissional
- **THEN** the new limits apply immediately to subsequent creations
