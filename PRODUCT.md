# Opmoni

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Brazilian accounting offices manage their client portfolio and commercial ERP relationships. Office operators register clients, activate ERP subscriptions and operate billing. ERP company users work in a separate company context.

## Product Purpose

Connect an accounting office's portfolio with the ERP subscriptions it manages. The confirmed priority for ERP management is registering clients and activating their ERP; mobile users need complete registration, editing and operation.

## Operating Context

The existing application uses Nuxt 4, Nuxt UI 4 and Laravel 13. Commercial management includes ERP plans, portfolio clients, enrollment, subscriptions, invoices, receipts and commercial configuration.

## Capabilities and Constraints

An Account is an accounting office, a Client belongs to its portfolio, and a Company is an ERP tenant. Platform administration does not grant tenant access. Preserve account authorization, exact monetary values, command recovery and existing enrollment and billing behavior. Production fiscal gates and local environment configuration are outside the redesign scope.

## Brand Commitments

Keep the Opmoni name and Brazilian Portuguese product copy. The user selected Nuxt UI components and the established application as the basis for this redesign.

## Evidence on Hand

Canonical requirements live in openspec/specs; accepted product decisions live in docs/architecture/product-decisions.md. The installed application and .dashboard/app provide component and layout evidence. reference/sistema describes another product and is not product authority.

## Product Principles

- Make client registration and ERP activation easy to find and complete.
- Support the complete workflow on mobile as well as desktop.
- Preserve commercial and financial behavior while improving presentation.
- Keep office and company contexts explicit and isolated.
