## Why

O e-CNPJ do escritório e a habilitação da integração estão no Monitoramento, onde o `admin` e o `operador` da Account gravam. Quem contrata o Integra Contador é a plataforma, e quem opera essa configuração é o super_admin. O escritório só entrega o e-CNPJ da própria Account; a procuração dos clientes da carteira continua sendo a que o provedor confirma.

## What Changes

- **BREAKING**: gravar ou remover o e-CNPJ da Account, e habilitar ou desabilitar a integração, passa a responder 403 para `admin`, `operador` e `user`. Só o super_admin escreve. A leitura do termo, dos metadados do certificado e da habilitação continua de qualquer Membro da Account corrente.
- A tela sai do Monitoramento (`/monitoring/termos`, aba e item do sidebar). Execuções de sincronização e o painel de obrigações ficam onde estão.
- O Painel Global ganha a tela Certificado do escritório, na Account corrente (a selecionada no menu, ou a do acesso de suporte). Ela cadastra o e-CNPJ, mostra o estado do termo que a plataforma monta e assina, e liga ou desliga a integração. Não há seletor de Accounts nem certificado do cliente nessa tela.
- A credencial de plataforma permanece em `/admin/serpro`. Contratante e autor do pedido continuam partes diferentes do envelope.
- O item Admin do sidebar do super_admin permanece como está: ele já depende de `is_super_admin`.
- No ambiente `local`, o seed passa a garantir dois logins: `admin@example.com` (Membro `admin`, sem flag de super_admin) e `super_admin@example.com` (super_admin, com a Account de desenvolvimento como corrente).

## Capabilities

### New Capabilities

Nenhuma

### Modified Capabilities

- `serpro-connection`: a escrita do e-CNPJ da Account e a habilitação da integração ficam restritas ao super_admin.
- `accounts`: a matriz de papéis deixa de conceder ao `admin` da Account a escrita da habilitação e do e-CNPJ.
- `admin-panel`: o Painel Global passa a cadastrar o e-CNPJ da Account corrente e a habilitação, e o Monitoramento deixa de oferecer essa tela.

## Impact

- Backend: `AccountCertificatePolicy`, `UpdateSerproEnablementRequest` e os testes de certificado e habilitação. `AccountPolicy::update` não muda, porque também cobre a edição da Account pelo `admin`.
- Frontend: `frontend/app/pages/monitoring/termos.vue` sai; entra `frontend/app/pages/admin/certificado.vue`; `monitoringNav.ts`, `monitoring.vue` e `adminNav.ts` acompanham. O shell `admin.vue` e o item do sidebar em `layouts/default.vue` já fecham o super_admin.
- Provedor: nenhum contrato novo. O termo continua um por Account, assinado com o e-CNPJ do escritório; `destinatario` é a plataforma e `assinadoPor` é o escritório. A carteira entra pela procuração e-CAC, não por outro certificado.
- Seed local: `backend/database/seeders/DevAdminSeeder.php`. Os arquivos em `.ref/data/` ficam fora do git e não entram no seed.
