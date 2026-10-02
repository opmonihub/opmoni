# Certificado do escritório em Configurações

## Contexto

A plataforma é a conta 1. Ela tem o contrato com o Integra Contador. Cada outro escritório é uma Account com o próprio e-CNPJ. O termo de autorização não é uma tela: o sistema emite e mantém ativo. A procuração dos clientes da carteira continua vindo do e-CAC.

O login da conta 1 é uma pessoa só. No seed local esse login é `admin@example.com`: super admin e Membro `admin` da conta 1. `super_admin@example.com` não existe. Nas demais contas os papéis são `admin`, `operador` e `user`, e nenhum deles cadastra e-CNPJ.

## Onde cada coisa aparece

Configurações (`/settings`) da conta corrente mostra só o e-CNPJ daquele escritório. A aba existe apenas para o super admin. A tela pede o arquivo e a senha que o abre. Não mostra o termo e não mostra o interruptor da integração.

O Painel Global não tem aba de certificado do escritório. O Monitoramento também não. Execuções de sincronização e obrigações continuam no Monitoramento.

`/admin/serpro` continua sendo a credencial da plataforma: chave, segredo e o certificado com que a integração autentica. Nesse certificado há duas saídas, e as duas apontam para um registro só:

- escolher o e-CNPJ já gravado em Configurações da conta 1;
- enviar outro arquivo, só quando o certificado da integração for diferente desse.

## Acesso de suporte

O banner não aparece na conta 1, porque `admin@example.com` é Membro dela. O banner aparece quando o super admin entra numa Account da qual não é Membro, e some ao sair.

## Fora deste desenho

Não há seletor de Accounts dentro de Configurações. Não há certificado do cliente no termo. Não há segunda cópia do mesmo arquivo. O interruptor de habilitação não ganha aba nova aqui; o flag que o sistema já guarda permanece sem tela nesta etapa.
