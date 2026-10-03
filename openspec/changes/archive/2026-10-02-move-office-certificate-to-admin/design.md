## Context

Ver `proposal.md` para o motivo. Hoje `frontend/app/pages/monitoring/termos.vue` cadastra o e-CNPJ da Account, mostra o termo e liga a integração. A escrita do certificado passa por `AccountCertificatePolicy` (`admin` e `operador`). A habilitação passa por `UpdateSerproEnablementRequest`, que consulta `AccountPolicy::update` e por isso aceita o `admin` da Account. A credencial de plataforma já está em `/admin/serpro`, fora do grupo `tenant`, só para super_admin. O item Admin do sidebar em `frontend/app/layouts/default.vue` já entra quando `isSuperAdmin` é verdadeiro. No banco local, `admin@example.com` tem `is_super_admin` falso.

As rotas do e-CNPJ, do termo e da habilitação continuam no grupo `tenant` e endereçam a Account corrente. Não há linha endereçada: é um certificado e um flag por Account.

## Goals / Non-Goals

**Goals:**

- Fechar a escrita do e-CNPJ no super_admin, sem abrir a leitura.
- Levar para Configurações só o arquivo do e-CNPJ da Account corrente, e tirar termo e interruptor dessa tela.
- Fazer a credencial em `/admin/serpro` usar o e-CNPJ da conta 1 ou um arquivo diferente, sem copiar o mesmo.
- Garantir, só em `local`, um login que é super_admin e Membro `admin` da conta 1.

**Non-Goals:**

- Seletor de Accounts na tela do certificado, busca de todas as carteiras, ou certificado do cliente no termo.
- Mudar o layout do XML, o papel `destinatario`/`assinadoPor`, ou `AccountPolicy::update`.
- Colocar os `.pfx` de `.ref/data/` no git ou no seed.

## Decisions

### 1. A escrita olha `is_super_admin`, e as rotas continuam no tenant

`AccountCertificatePolicy::create` e `delete` passam a exigir `$user->isSuperAdmin()`. `UpdateSerproEnablementRequest::authorize` passa a exigir o mesmo, em vez de `AccountPolicy::update`. A leitura (`viewAny` do certificado, GET do termo, GET da habilitação) permanece para qualquer Membro da Account corrente.

A Account corrente continua sendo o endereço. O super_admin alcança o próximo escritório trocando a Account no menu ou pelo acesso de suporte. Não nasce rota em `/api/admin/accounts/{account}/certificate`.

Alternativa descartada: rotas globais com `{account}` no caminho. Exige outro conjunto de policies e um seletor que a spec não pede.

### 2. O e-CNPJ da Account fica em Configurações, não no Painel Global

A tela sai de `frontend/app/pages/admin/certificado.vue` e vai para `frontend/app/pages/settings/certificado.vue`. A aba entra na toolbar de `settings.vue` e nos filhos de Configurações do sidebar em `layouts/default.vue`, só quando `isSuperAdmin` é verdadeiro. A página declara `middleware: ['auth', 'super-admin']` porque o shell de Configurações é só `auth`. Sai a entrada de `adminNav.ts`. `/admin/certificado` deixa de existir.

A página pede arquivo e senha. Não renderiza o estado do termo nem o interruptor da integração. O termo continua sendo emitido e renovado pelo sistema.

Alternativa descartada: manter a aba no Painel Global. O certificado é um por escritório.

### 2b. `/admin/serpro` aponta para o e-CNPJ da conta 1 ou grava outro

A chave e o segredo continuam na credencial única. O certificado da integração tem duas saídas na mesma tela: usar o e-CNPJ já gravado em Configurações da conta 1, sem segundo arquivo, ou enviar outro arquivo quando for diferente. Escolher o da conta 1 não copia os bytes para outra linha.

Alternativa descartada: sempre gravar um certificado próprio em `/admin/serpro`. Duplica o arquivo quando ele é o mesmo da conta 1.

### 3. O acesso de suporte continua podendo gravar, e a auditoria permanece

Quem está em acesso de suporte é super_admin, então a nova guarda deixa a escrita passar. Um Membro `admin` que não é super_admin recebe 403. `admin@example.com` é super_admin, então na conta 1 ele grava.

`AccountPolicy::update` não é estreitada. Ela também autoriza a edição da Account pelo `admin`.

### 4. O super_admin da primeira Account também é Membro admin dela

O registro inicial já cria o primeiro usuário como `is_super_admin` e Membro `admin` da Account que ele abre. Na conta própria ele tem os poderes de admin do escritório mais os da plataforma, e o indicador de acesso de suporte fica oculto: `useSupportMode` só é verdadeiro quando a Account corrente não está na lista de vínculos.

`DevAdminSeeder` segue só em `local` e idempotente. `admin@example.com` fica `is_super_admin` e Membro `admin` da primeira Account, com `current_account_id` nela. `super_admin@example.com` é removido se existir. Sem o vínculo, a conta própria parece alheia e o banner acende. A senha é a que o seeder já publica. O seed não copia `.ref/data/`.

Alternativa descartada: dois e-mails, um admin e um super_admin. A conta 1 tem uma pessoa só.

## Risks / Trade-offs

- [Um `admin` da Account que hoje entrega o e-CNPJ passa a receber 403] → A tela some do Monitoramento junto com a rota de escrita. O super_admin cadastra na Account corrente.
- [Super_admin sem Account corrente não consegue gravar o e-CNPJ] → O seed torna `admin@example.com` Membro `admin` da primeira Account e aponta `current_account_id` para ela. O banner não aparece nessa Account. Fora dela, o acesso de suporte continua valendo.
- [Senha, PKCS#12 e XML do termo vazarem em log ou no git] → A change não adiciona log desses materiais. `*.pfx` já está no `.gitignore`. `.ref/data/` fica fora do seed e do commit.
- [O cenário antigo de emissão ainda nomeia o escritório como destinatário, e o requisito do documento nomeia a plataforma] → Esta change não reabre o XML. O delta copia o cenário de emissão como está e só muda quem age quando o certificado falta ou o termo vence.

## Migration Plan

Não há migration de banco. O certificado já gravado continua válido. O deploy é de policy e de tela: depois dele, `admin` e `operador` recebem 403 na escrita. Rollback é reverter a policy e devolver a página ao Monitoramento; os registros não precisam de migração inversa.

Em desenvolvimento, rodar o seed local depois da change para promover `admin@example.com` e apagar `super_admin@example.com`.

## Open Questions

Nenhuma.
