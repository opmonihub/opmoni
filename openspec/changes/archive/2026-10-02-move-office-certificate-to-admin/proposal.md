## Why

O e-CNPJ do escritório estava no Monitoramento e depois numa aba do Painel Global. Ele é de cada Account, então mora em Configurações. Quem cadastra é o super_admin. Na conta 1 essa pessoa é o próprio admin do escritório, um login só. O termo não é tela: o sistema emite e mantém ativo.

## What Changes

- **BREAKING**: gravar ou remover o e-CNPJ da Account responde 403 para `admin`, `operador` e `user` que não são super_admin. Só o super_admin escreve. A leitura dos metadados continua de qualquer Membro da Account corrente.
- A tela sai do Monitoramento e do Painel Global. Execuções de sincronização e obrigações ficam no Monitoramento.
- Configurações (`/settings/certificado`) mostra só o arquivo e a senha do e-CNPJ da Account corrente. Não mostra o termo e não mostra o interruptor da integração. A aba só aparece para o super_admin. Não há seletor de Accounts nem certificado do cliente.
- Em `/admin/serpro` a chave e o segredo continuam da plataforma. O certificado da integração ou é o e-CNPJ já gravado em Configurações da conta 1, sem segunda cópia, ou um arquivo diferente enviado ali.
- O item Admin do sidebar continua só para super_admin.
- No ambiente `local`, `admin@example.com` é o único login da conta 1: `is_super_admin` e Membro `admin`. `super_admin@example.com` deixa de existir. O banner de acesso de suporte não aparece na conta 1. Ele aparece quando o super_admin entra numa Account da qual não é Membro.

## Capabilities

### New Capabilities

Nenhuma

### Modified Capabilities

- `serpro-connection`: a escrita do e-CNPJ da Account fica restrita ao super_admin, e o certificado da integração reusa o da conta 1 ou grava outro arquivo.
- `accounts`: a matriz de papéis deixa de conceder ao `admin` da Account a escrita da habilitação e do e-CNPJ.
- `admin-panel`: o Painel Global não oferece o certificado do escritório. A credencial de plataforma continua em Serpro. O Monitoramento também não oferece essa tela.
- `equipe-members-ui`: Configurações oferece só o e-CNPJ da Account corrente, e só para o super_admin.

## Impact

- Backend: `AccountCertificatePolicy`, `UpdateSerproEnablementRequest` e os testes de certificado e habilitação. `AccountPolicy::update` não muda, porque também cobre a edição da Account pelo `admin`.
- Frontend: a tela sai de `frontend/app/pages/admin/certificado.vue` e vai para `frontend/app/pages/settings/certificado.vue`, só com o arquivo. `/admin/serpro` passa a escolher o e-CNPJ da conta 1 ou receber outro arquivo.
- Provedor: nenhum contrato novo. O termo continua um por Account, assinado com o e-CNPJ do escritório; `destinatario` é a plataforma e `assinadoPor` é o escritório. A carteira entra pela procuração e-CAC, não por outro certificado.
- Seed local: `backend/database/seeders/DevAdminSeeder.php`. Os arquivos em `.ref/data/` ficam fora do git e não entram no seed.
