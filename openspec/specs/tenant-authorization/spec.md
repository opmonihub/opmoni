# tenant-authorization Specification

## Purpose
Consolidar quem pode executar cada capacidade sensível, separando o super_admin de plataforma (módulo Admin) dos papéis de Membro dentro de uma Account. As demais specs citam exceções; esta spec é a matriz de referência.

## Matriz de referência (formato C)

Legenda por célula: **L** = leitura permitida (tipicamente 200), **E** = escrita permitida, **—** = 403 ou superfície ausente. “super_admin (Admin)” = usuário com `is_super_admin` nas rotas `/admin/*`. “Membro” = vínculo na Account corrente. super_admin em acesso de suporte na Account B atua como Membro `admin` em B (ver `support-access`).

| Capability | super_admin (Admin `/admin/*`) | Membro `admin` | Membro `operador` | Membro `user` |
| --- | --- | --- | --- | --- |
| Credencial de plataforma Integra Contador (key, secret, cert. contratante) | E | — | — | — |
| Habilitação da integração SERPRO da Account corrente | E | — | — | — |
| e-CNPJ do escritório (upload/remoção na Account corrente) | E† | E | — | — |
| Metadados do e-CNPJ do escritório | L‡ | L | L | L |
| Termo de autorização (consulta de estado) | L‡ | L | L | L |
| Criação de Account (Painel Global / onboarding inicial) | E | — | — | — |
| Membros da Account (convite, papel, remoção) | E† | E | — | L |
| Assinatura/plano da Account | E† | E | — | L |
| Carteira, Work, departamentos (operacional) | E† | E | E | L |
| Filtros salvos de clientes | E† | E | E | E (próprios) |

† Na Account corrente quando o super_admin é Membro ou está em acesso de suporte com poderes de `admin`.  
‡ Leitura no tenant corrente; gravação da credencial de plataforma continua só no Admin.

## Requirements

### Requirement: Módulo Admin exclusivo do super_admin
The system SHALL expose every route and API under the global Admin module only to users with `is_super_admin`. Members with role `admin`, `operador` or `user` who are not super_admins SHALL NOT access Admin screens and SHALL receive 403 from the underlying Admin endpoints.

#### Scenario: Admin da Account tenta abrir Admin
- **WHEN** a member whose role is `admin` and who is not a super_admin navigates to `/admin` or any child route
- **THEN** the UI redirects away from Admin and the Admin APIs respond 403

#### Scenario: super_admin abre credencial de plataforma
- **WHEN** a super_admin opens `/admin/serpro` and saves platform credentials
- **THEN** the change is applied as defined in `serpro-connection`

### Requirement: Papéis de Membro seguem a matriz
The system SHALL enforce tenant capabilities according to the reference matrix above. Unless another spec states a narrower exception, `admin` SHALL have write access to every tenant resource except SERPRO enablement and platform credentials; `operador` SHALL lack write access to members, subscription, office e-CNPJ and SERPRO enablement; `user` SHALL read all tenant resources and SHALL write only its own saved client filters.

#### Scenario: Operador não envia e-CNPJ
- **WHEN** a member with role `operador` uploads or removes the office e-CNPJ for the current Account
- **THEN** the system responds 403 and the stored certificate is unchanged

#### Scenario: Admin envia e-CNPJ
- **WHEN** a member with role `admin` uploads a valid office e-CNPJ for the current Account
- **THEN** the certificate is stored for that Account and non-secret metadata is returned

#### Scenario: Admin não habilita integração
- **WHEN** a member with role `admin` who is not a super_admin attempts to enable or disable SERPRO integration for the current Account
- **THEN** the system responds 403 and the enablement flag is unchanged

### Requirement: Outras specs declaram exceções, não a matriz inteira
Domain specs SHALL reference this matrix for cross-cutting authorization and SHALL document only API-specific status codes, payloads or UI surfaces not implied by the matrix.

#### Scenario: Implementador consulta certificado
- **WHEN** an implementer needs write rules for the office e-CNPJ
- **THEN** they read this matrix and `serpro-connection` for storage and secret-handling details
