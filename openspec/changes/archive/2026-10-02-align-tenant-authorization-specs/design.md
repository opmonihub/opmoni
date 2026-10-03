## Context

O e-CNPJ do escritório assina o termo de autorização por Account. A credencial de plataforma e a habilitação da integração são decisões de plataforma. A change arquivada `move-office-certificate-to-admin` misturou essas três coisas nas specs e restringiu incorretamente o certificado ao super_admin, divergindo das policies e da UI em Configurações.

## Goals / Non-Goals

**Goals**

- Uma matriz legível (formato C) que responda “quem pode o quê” sem reler cinco specs.
- Texto normativo alinhado a `AccountCertificatePolicy`, `UpdateSerproEnablementRequest`, `admin-panel`, `settingsNav` e middleware `account-admin`.
- Vocabulário do `CONTEXT.md` coerente com Configurações.

**Non-Goals**

- Mover telas, policies ou testes de certificado/habilitação.
- Alterar quem acessa `/admin/*` (já super_admin).

## Decisions

### 1. Matriz central em `tenant-authorization`

A matriz formato C (linhas = capacidades, colunas = papéis) vive em `tenant-authorization`. Specs de domínio (`accounts`, `serpro-connection`, …) declaram só exceções ou detalhes de API, não repetem a matriz inteira.

### 2. e-CNPJ do escritório: admin da Account

Quem envia ou remove o e-CNPJ é o Membro `admin` da Account corrente. `operador` e `user` leem metadados. `is_super_admin` fora do suporte usa o módulo Admin; dentro do tenant (incluindo suporte) atua como `admin` para o certificado, conforme `support-access`.

### 3. Habilitação e credencial de plataforma: só super_admin

Nenhum Membro (`admin`, `operador`, `user`) habilita a integração nem grava consumer key/secret/certificado contratante. Isso permanece no Painel Global (`/admin/*`).

### 4. UI do certificado em Configurações

A aba “Certificado do escritório” aparece para quem passa em `account-admin` / equivalente a `canManageMembers` (papel `admin` ou super_admin no tenant). Não aparece no Painel Global nem no Monitoramento.

## Risks / Trade-offs

- **Duplicação parcial com `accounts`**: o bullet resumido em `accounts` permanece; a matriz completa fica em `tenant-authorization`. Implementadores devem ler os dois: resumo + matriz.

## Migration Plan

Sincronizar `openspec/specs/` com os deltas desta change e arquivar quando o produto confirmar. Sem migração de dados.

## Open Questions

Nenhuma — decisão de produto confirmada pelo usuário.
