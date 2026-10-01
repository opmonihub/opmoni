## Context

Ver `proposal.md` para o motivo. Hoje `frontend/app/pages/monitoring/termos.vue` cadastra o e-CNPJ da Account, mostra o termo e liga a integração. A escrita do certificado passa por `AccountCertificatePolicy` (`admin` e `operador`). A habilitação passa por `UpdateSerproEnablementRequest`, que consulta `AccountPolicy::update` e por isso aceita o `admin` da Account. A credencial de plataforma já está em `/admin/serpro`, fora do grupo `tenant`, só para super_admin. O item Admin do sidebar em `frontend/app/layouts/default.vue` já entra quando `isSuperAdmin` é verdadeiro. No banco local, `admin@example.com` tem `is_super_admin` falso.

As rotas do e-CNPJ, do termo e da habilitação continuam no grupo `tenant` e endereçam a Account corrente. Não há linha endereçada: é um certificado e um flag por Account.

## Goals / Non-Goals

**Goals:**

- Fechar a escrita do e-CNPJ e da habilitação no super_admin, sem abrir a leitura.
- Mover a tela para o Painel Global, na Account corrente, e tirá-la do Monitoramento.
- Garantir, só em `local`, um login de Membro `admin` e um login de super_admin.

**Non-Goals:**

- Seletor de Accounts na tela do certificado, busca de todas as carteiras, ou certificado do cliente no termo.
- Mudar o layout do XML, o papel `destinatario`/`assinadoPor`, ou `AccountPolicy::update`.
- Colocar os `.pfx` de `.ref/data/` no git ou no seed.

## Decisions

### 1. A escrita olha `is_super_admin`, e as rotas continuam no tenant

`AccountCertificatePolicy::create` e `delete` passam a exigir `$user->isSuperAdmin()`. `UpdateSerproEnablementRequest::authorize` passa a exigir o mesmo, em vez de `AccountPolicy::update`. A leitura (`viewAny` do certificado, GET do termo, GET da habilitação) permanece para qualquer Membro da Account corrente.

A Account corrente continua sendo o endereço. O super_admin alcança o próximo escritório trocando a Account no menu ou pelo acesso de suporte. Não nasce rota em `/api/admin/accounts/{account}/certificate`.

Alternativa descartada: rotas globais com `{account}` no caminho. Exige outro conjunto de policies e um seletor que a spec não pede.

### 2. A tela muda de módulo, o formulário não muda de contrato

`monitoring/termos.vue` vira `frontend/app/pages/admin/certificado.vue` e entra em `adminNav.ts` com o rótulo "Certificado do escritório". O shell `admin.vue` já aplica `middleware: ['auth', 'super-admin']`. Saem o link de `monitoringIntegrationLinks` e o título em `monitoring.vue`. `/monitoring/termos` deixa de ser página; o catch-all responde 404.

A credencial de plataforma fica em `/admin/serpro`. São partes diferentes do envelope: contratante e autor do pedido.

Alternativa descartada: fundir as duas telas. Mistura o e-CNPJ da plataforma com o e-CNPJ da Account.

### 3. O acesso de suporte continua podendo gravar, e a auditoria permanece

Quem está em acesso de suporte é super_admin, então a nova guarda deixa a escrita passar. `SupportAudit` na habilitação não muda. Um Membro `admin` que não é super_admin recebe 403, inclusive o `admin@example.com` do seed.

`AccountPolicy::update` não é estreitada. Ela também autoriza a edição da Account pelo `admin`.

### 4. O seed local separa os dois papéis

`DevAdminSeeder` continua só em `local` e idempotente. Ele reafirma `admin@example.com` como Membro `admin` com `is_super_admin` falso, e cria `super_admin@example.com` com `is_super_admin` verdadeiro e `current_account_id` na mesma Account de desenvolvimento. A senha é a que o seeder já publica para o login de desenvolvimento. Nenhum dos dois recebe o material de `.ref/data/`.

Alternativa descartada: promover `admin@example.com` a super_admin. Os dois papéis deixariam de ser distinguíveis no login de desenvolvimento.

## Risks / Trade-offs

- [Um `admin` da Account que hoje entrega o e-CNPJ passa a receber 403] → A tela some do Monitoramento junto com a rota de escrita. O super_admin cadastra na Account corrente.
- [Super_admin sem Account corrente não consegue gravar o e-CNPJ] → O seed aponta `super_admin@example.com` para a Account de desenvolvimento. Fora do seed, o acesso de suporte ou o menu de Accounts define a corrente. Não há seletor novo.
- [Senha, PKCS#12 e XML do termo vazarem em log ou no git] → A change não adiciona log desses materiais. `*.pfx` já está no `.gitignore`. `.ref/data/` fica fora do seed e do commit.
- [O cenário antigo de emissão ainda nomeia o escritório como destinatário, e o requisito do documento nomeia a plataforma] → Esta change não reabre o XML. O delta copia o cenário de emissão como está e só muda quem age quando o certificado falta ou o termo vence.

## Migration Plan

Não há migration de banco. O certificado já gravado continua válido. O deploy é de policy e de tela: depois dele, `admin` e `operador` recebem 403 na escrita. Rollback é reverter a policy e devolver a página ao Monitoramento; os registros não precisam de migração inversa.

Em desenvolvimento, rodar o seed local depois da change para criar `super_admin@example.com`.

## Open Questions

Nenhuma.
