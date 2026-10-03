## ADDED Requirements

### Requirement: Módulo Admin exclusivo do super_admin
The system SHALL expose every route and API under the global Admin module only to users with `is_super_admin`. Members with role `admin`, `operador` or `user` who are not super_admins SHALL NOT access Admin screens and SHALL receive 403 from the underlying Admin endpoints.

#### Scenario: Admin da Account tenta abrir Admin
- **WHEN** a member whose role is `admin` and who is not a super_admin navigates to `/admin` or any child route
- **THEN** the UI redirects away from Admin and the Admin APIs respond 403

#### Scenario: super_admin abre credencial de plataforma
- **WHEN** a super_admin opens `/admin/serpro` and saves platform credentials
- **THEN** the change is applied as defined in `serpro-connection`

### Requirement: Papéis de Membro seguem a matriz
The system SHALL enforce tenant capabilities according to the reference matrix in the Purpose section of this spec. Unless another spec states a narrower exception, `admin` SHALL have write access to every tenant resource except SERPRO enablement and platform credentials; `operador` SHALL lack write access to members, subscription, office e-CNPJ and SERPRO enablement; `user` SHALL read all tenant resources and SHALL write only its own saved client filters.

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
