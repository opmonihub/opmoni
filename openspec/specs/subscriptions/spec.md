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
The system SHALL create an active subscription on the Basic plan automatically whenever an account is created.

#### Scenario: Conta criada
- **WHEN** a new account is created by any means
- **THEN** it has exactly one active subscription on the Basic plan without manual action

### Requirement: Bloqueio no estouro de limite
The system SHALL reject with 422 any creation that would exceed the account's active plan limit, creating nothing.

#### Scenario: Limite de clientes atingido
- **WHEN** an account on the Basic plan with 50 clients attempts to create one more
- **THEN** the system responds 422 and the client count remains 50

### Requirement: Gestão financeira global
The system SHALL allow super_admins to change an account's plan and mark subscriptions as past_due or canceled; accounts with past_due or canceled subscriptions are blocked from writes like suspended accounts.

#### Scenario: Assinatura inadimplente
- **WHEN** a subscription is marked past_due and a member attempts a write
- **THEN** the write is rejected until the subscription is active again
