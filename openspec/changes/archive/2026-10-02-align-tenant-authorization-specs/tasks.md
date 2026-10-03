## 1. Baseline

- [x] 1.1 Confirmar que `AccountCertificatePolicy` concede escrita ao `admin` e que `UpdateSerproEnablementRequest` restringe habilitação ao super_admin; verificar leitura em `backend/app/Policies/AccountCertificatePolicy.php` e testes `SerproAccountCertificate` / `SerproAccountEnablement`.

## 2. OpenSpec

- [x] 2.1 Criar `tenant-authorization` com matriz formato C e requisitos do módulo Admin; verificar `openspec validate --specs`.
- [x] 2.2 Alinhar `accounts`, `serpro-connection` e `equipe-members-ui` ao código; verificar `openspec validate --change align-tenant-authorization-specs`.
- [x] 2.3 Atualizar `CONTEXT.md` e comentário em `frontend/app/pages/admin/serpro.vue`; verificar busca por "Painel Global" no parágrafo do certificado.

## 3. Verificação

- [x] 3.1 Rodar `openspec validate --all` e confirmar zero falhas.
