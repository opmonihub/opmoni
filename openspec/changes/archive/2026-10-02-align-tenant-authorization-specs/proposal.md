## Why

A change arquivada `2026-10-02-move-office-certificate-to-admin` restringiu nas specs o e-CNPJ do escritório ao super_admin, mas o produto e o código mantêm a escrita no `admin` da Account (`AccountCertificatePolicy`, Configurações → Certificado do escritório, middleware `account-admin`). Isso confunde implementadores e testes de contrato. Falta também uma matriz única que separe claramente o módulo Admin da plataforma (`/admin/*`, só `is_super_admin`) das capacidades dentro do tenant.

## What Changes

- **Documentação only**: nenhuma alteração de comportamento em backend ou frontend além de um comentário enganoso em `admin/serpro.vue`.
- Nova capability `tenant-authorization`: matriz formato C (capability × papel) para super_admin de plataforma, Membro `admin`, `operador` e `user`, com referência cruzada nas outras specs.
- Corrigir `accounts`, `serpro-connection` e `equipe-members-ui`: e-CNPJ do escritório = leitura de qualquer Membro; escrita = `admin` da Account (super_admin em suporte atua como `admin`, conforme `support-access`). Habilitação SERPRO e credencial de plataforma permanecem só super_admin.
- Atualizar `CONTEXT.md`: certificado cadastrado em Configurações pelo admin do escritório, não no Painel Global.
- Esclarecer comentário em `/admin/serpro`: habilitação por escritório não é desta tela e não é papel do admin da Account.

## Capabilities

### New Capabilities

- `tenant-authorization`: matriz de autorização por papel e requisitos do módulo Admin.

### Modified Capabilities

- `accounts`: matriz de Membro alinhada ao código (admin grava e-CNPJ; ninguém exceto super_admin grava habilitação).
- `serpro-connection`: requisito de certificado do escritório e cenários de escrita/leitura coerentes com `AccountCertificatePolicy`.
- `equipe-members-ui`: aba Certificado do escritório visível ao admin da Account, não restrita a super_admin.

## Impact

- OpenSpec: `openspec/specs/tenant-authorization/spec.md` (novo) e edições em `accounts`, `serpro-connection`, `equipe-members-ui`.
- Docs: `CONTEXT.md`.
- Frontend: comentário em `frontend/app/pages/admin/serpro.vue` apenas.
