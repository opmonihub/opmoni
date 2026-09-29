## Context

As 21 specs vigentes em `openspec/specs/` acumularam cópias das mesmas regras, contradições com o código e justificativa de design escrita como norma. Uma grelha em 2026-09-29 levantou os problemas, e um explorer conferiu no código cada contradição que dependia dele. Esta change só mexe em texto: specs, `openspec/config.yaml` e `CONTEXT.md`. Nenhum comportamento do sistema muda.

## Goals / Non-Goals

**Goals**
- Uma regra transversal vive num lugar só: isolamento em `isolation`, suporte em `support-access`, papéis em `accounts`.
- Toda frase normativa bate com o código conferido.
- Spec só tem norma. Justificativa fica neste design.

**Non-Goals**
- Mudar código. As lacunas de código encontradas viram tasks de outras changes.
- A elegibilidade por procuração e a procuração manual. Ficam com `client-onboarding-modules`.
- O departamento como FK. Fica com `departments-fk`.
- O requisito "CRUD conforme nível do membro" de `client-portfolio`. Ele traz a regra própria da exclusão lógica e fica como está.

## Decisions

### 1. As regras transversais vivem numa spec só, e as demais citam só a exceção

Isolamento, auditoria em modo suporte e permissão por nível estavam copiados em até oito specs, e o silêncio de uma spec não dizia se a regra valia ali. A regra geral fica em `isolation`, `support-access` e `accounts`. Uma spec de domínio repete a regra só quando tem exceção.

Descartado: manter as cópias e completar as que faltam. Cada cópia nova seria mais um lugar para divergir.

### 2. O acesso de suporte tem os poderes do `admin` sem exceção, e tudo é auditado

Decidido na grelha: o super_admin em modo suporte faz tudo o que um `admin` faz, inclusive registrar a ciência da intimação. Em troca, toda escrita e todo ato com efeito no provedor (sync, habilitação, ciência) vão para o log de auditoria. Essa decisão reverte a de bloquear a ciência em modo suporte, tomada antes na mesma sessão.

Descartado: bloquear atos jurídicos em modo suporte. O suporte existe para operar a conta como o escritório operaria.

### 3. A matriz de papéis descreve o que o código faz

O explorer conferiu as policies: o `user` lê tudo no tenant e escreve só os próprios filtros salvos. O `config.yaml` dizia "o user só lê Work", e isso estava errado. A matriz em `accounts` segue as policies.

### 4. Suspensão bloqueia tudo, assinatura inativa bloqueia só a escrita

`ResolveTenant.php:32-42`: Account suspensa responde 403 a qualquer método, inclusive para o super_admin. Assinatura com status diferente de `active`, ou Account sem assinatura, bloqueia só métodos não seguros. A spec de `subscriptions` dizia o contrário.

### 5. O registro abre só com a base sem users e sem accounts

`AuthController.php:130`. As frases com "ou" em `auth` estavam erradas.

### 6. Credencial de plataforma e e-CNPJ do escritório são duas coisas

A Credencial de plataforma autentica a plataforma no provedor e só o super_admin a grava. O e-CNPJ do escritório (`AccountCertificate`) é por Account, `admin` e `operador` o enviam, e ele assina o Termo de autorização. A spec proibia "credencial por escritório" de um jeito que parecia proibir também o e-CNPJ.

### 7. Justificativas do termo de autorização, retiradas da spec

Guardadas aqui para não se perderem:

- **`finalidade` sem espaço.** O modelo de referência escreve `finalidade `. `SimpleXMLElement::addChild` aceita, mas o nome sai sem o espaço. `DOMDocument::createElement` recusa com `DOMException`. O `loadXML`/`saveXML` da assinatura remove o espaço. O layout do provedor, lido em 2026-09-28, não tem espaço. Restaurar o espaço quebraria o documento.
- **Canonicalização.** O provedor declara c14n inclusivo. O digest do modelo usa o exclusivo. Os dois coincidem enquanto nenhum elemento declara namespace, e um teste sobre o documento real garante isso.
- **Vigência de 30 dias, não confirmada.** O provedor só declara o formato `AAAAMMDD`. Os dois exemplos dele duram 200 dias (`20220614` a `20221231`) e 145 dias (`20220808` a `20221231`). A data final comum exclui uma constante de N dias e é compatível com "31 de dezembro", mas nenhum dos dois números é inferível. Os 30 dias vêm da única conta de período no material do provedor e são o valor de menor risco: um termo curto demais tende a ser aceito e renova todo dia, um termo longo demais é recusado. O teste de contrato decide, antes de qualquer prova gravada.
- **Por que as constantes entram no digest.** O período, a normalização de Unicode e o fuso não aparecem nos bytes do template. Um digest só do template continuaria liberando um documento que ninguém testou.
- **O termo não tem rota de escrita para Membro.** Ele é assinado com o e-CNPJ do escritório, e o escritório nunca é chamado a assinar. Uma rota de escrita só aceitaria um documento que a própria plataforma não teria produzido. A spec anterior dizia que `admin` e `operador` gravavam o termo, e a versão vigente já tinha removido isso.

### 8. O vocabulário do sync fica como a API expõe

Estados de item em português (`sincronizado`, `ignorado`...), chaves de contagem em inglês (`synchronized`, `skipped`...). É o contrato atual da API, e a spec passa a dizer isso em vez de parecer uma inconsistência.

Descartado: unificar os nomes. Seria uma mudança de contrato, fora de uma change só de texto.

## Risks / Trade-offs

- **Conflito de delta com as outras changes.** `client-onboarding-modules` e `departments-fk` também mexem em `client-fiscal-access`, `serpro-connection`, `monitoring` e nas specs de Work. Esta change evita os requisitos que elas modificam. Mesmo assim, esta change precisa ser arquivada primeiro, e as outras conferem os títulos antes de arquivar.
- **A regra geral de suporte promete mais do que o código entrega.** Nove controllers ainda não chamam `SupportAudit::logWrite`. A lacuna vira tasks em `serpro-contract-and-mailbox`. Até lá, a spec está à frente do código.

## Tenancy, papéis, suporte e segredos

Nenhuma mudança de comportamento. O texto passa a descrever o `account_id` e o escopo global como regra de `isolation`, a matriz de papéis em `accounts` e a auditoria de suporte em `support-access`. Nenhum segredo é tocado.

## Migration Plan

Arquivar esta change antes de `serpro-contract-and-mailbox`, `client-onboarding-modules` e `departments-fk`. Não há migração de dados.

## Open Questions

Nenhuma.
