## Context

Ver [proposal.md](proposal.md). O módulo fiscal fala SOAP com Distribuição DF-e (`DfeTransport` fixa o payload `distDFeInt` e declara no docbloco que **não existe XMLDSig em nenhum arquivo do módulo** — a distribuição não assina; a autenticação é o A1 no transporte). A consulta pontual por chave (`consChNFe`) já existe com orçamento `FiscalLookupBudget` (20/h por CNPJ, chave por CNPJ e não por conta, janela expirando no topo da hora) e bloqueio de 1h após cStat 137/656. O change arquivado `add-fiscal-document-capture` decidiu **não** adicionar xmlseclibs/sped-common justamente porque nada assinava; a manifestação é o primeiro caso que exige assinatura. Gates de módulo seguem o padrão `cte_enabled`/`nfse_enabled` em `backend/config/fiscal.php`.

## Goals / Non-Goals

**Goals:**

- Assinar o evento de manifestação (XMLDSig) com o A1 do cliente e enviá-lo por `nfeRecepcaoEvento` no AN.
- Ciência da emissão (210210) automática após gravação de resumo, com deduplicação e prazo de 90 dias.
- Tratar 573 (já manifestado por terceiro) como estado conhecido e seguir para `consChNFe`.
- Gate default false, auditoria completa, tenancy explícita em jobs.
- Reuso integral do orçamento e do transporte existentes para a recuperação do XML.

**Non-Goals:**

- Confirmação da operação (210200) automática — efeito fiscal bloqueante de cancelamento; só decisão de produto explícita mudaria isso.
- Baixa/desmanifestação de evento; manifestação de CT-e; `NfeDownloadNF` (desativado, nunca usar).
- UI de disparo manual de manifestação — o fluxo é automático; a UI só expõe estado.

## Decisions

1. **Assinatura com a biblioteca xmlseclibs canônica — pacote `robrichards/xmlseclibs` ^3.1 (DOMDocument nativo), não implementação própria.** O evento precisa de Exclusive C14N + RSA-SHA com `SignedInfo`/`SignatureValue`/`X509Certificate` no padrão que o AN valida — o mesmo que sped-common/nfephp usam (o sped-common depende exatamente de `robrichards/xmlseclibs`). Reimplementar canonicalização e montagem do envelope via `openssl_sign` + DOM é o maior risco do change e não há motivo: a biblioteca é pequena, mantida e especializada em NFE. Alternativas descartadas: (a) implementação própria de C14N/RSA (risco de rejeição enigmática do AN e de regressão silenciosa), (b) trazer o `sped-common` inteiro (arrasta dependências que o change arquivado recusou de propósito), (c) o pacote `xmlseclibs/xmlseclibs` do Packagist (fork obscuro, não é a biblioteca canônica). **Esta decisão adiciona a dependência `robrichards/xmlseclibs` ao `composer.json` — aprovação concedida pelo usuário (03/10/2026).**

2. **Conector e transporte próprios para `nfeRecepcaoEvento`, não esticar `DfeTransport`.** `DfeTransport` fixa o elemento do payload (`distDFeInt`) por decisão interna do módulo de distribuição e documentou regras que o evento quebra (corpo assinado; cStat 215 não se aplica). O novo serviço vive em `app/Services/Fiscal/Manifestacao/`, valida contra o XSD local do serviço de eventos, e reutiliza `HttpPkcs12ClientOptions`, o bundle ICP-Brasil e as mesmas regras de segredo/fault. Alternativa descartada: parametrizar payload/assinatura em `DfeTransport` (diluiria a classe de distribuição e misturaria dois contratos com validação de assinatura diferente).

3. **Manifestação enfileirada após gravação de resumo, nunca inline na captura.** O job novo carrega `account_id` e o id do cliente, checa gate, deduplicação, prazo e janela de bloqueio **no momento da execução** (não no enqueue), porque o estado pode mudar entre os dois. Alternativas descartadas: assinar dentro do job de captura (atrasa o lote e acopla dois serviços que falham por motivos diferentes), e consolidar em command agendado (atraso de até 24h destrava tarde o NSU).

4. **Estado da manifestação em tabela própria, não em `fiscal_events`.** Os eventos capturados registram fatos recebidos do AN; a manifestação enviada por nós é fato próprio, com autor (`requested_by` — job ou membro), resultado, data e unique `(account_id, client_id, chave_acesso, event_type, event_seq)`. A tabela é o registro de auditoria exigido pela spec (quem/operação/data) e cobre deduplicação. Modelo com `BelongsToAccount`; jobs gravam `account_id` explícito. Alternativa descartada: inferir "já manifestado" pela presença de evento no AN via `consChNFe` (custa orçamento e confunde causa).

5. **573 é estado, não falha.** Classificador próprio mapeia cStat 573 para `AlreadyManifestedByOther` — distinto de `FiscalFailure` transitória —, grava o estado e libera a recuperação via `consChNFe`. Isso segue a issue #778 do sped-nfe (573 = já manifestado por terceiro, inclusive por sistema alheio). Alternativa descartada: tratar como `FiscalFailure` e retentar (rejeição definitiva; retry só queima janela).

6. **Manifestação não consome `FiscalLookupBudget`; recuperação do XML sim.** `nfeRecepcaoEvento` não é consulta e o fisco não publica teto de eventos por hora; debitar o orçamento de `consChNFe` na manifestação reduziria a capacidade de fechar lacunas. A recuperação do procNFe após ciência é consulta pontual e passa pelo caminho existente (budget, teto 20/h, bloqueio 1h), com ressincronização de resumos pendentes limitada por chave e segura a repetição — mesma disciplina da reconciliação (`reconcile_max_attempts`). Alternativa descartada: orçamento compartilhado (privaria a consulta urgente do escritório).

7. **Gate `manifestacao_enabled` (default false), espelhando `cte_enabled`.** Lido por dispatcher (enqueue pós-resumo) e pelo job (execução). Em produção, `FISCAL_ENVIRONMENT` já tem default produção — o gate evita tráfego de evento acidental no mesmo espírito dos gates anteriores. Alternativa descartada: ligar por default (evento assinado é ato perante o fisco; nunca deve sair sem decisão).

8. **UI mínima: código de completude na listagem de documentos.** O backend deriva `summary_awaiting_xml`/`complete` dos registros de distribuição da chave (resumo sem procNFe versus completo) e a coluna nova reusa os tokens de cor semântica do DESIGN.md. Nenhuma ação na linha dispara manifestação. Alternativa descartada: badge derivado no frontend a partir de campos crus (duplicaria a regra de estado em dois lugares).

**Tenancy, papéis, suporte e auditoria:** a tabela e o modelo usam `account_id` + `BelongsToAccount`; o job carrega `account_id` explícito e só age no cliente da sua Account (escopo global não filtra com `CurrentTenant` vazio). A manifestação automática não tem endpoint de membro: `user`, `operador` e `admin` não disparam evento; `super_admin` em acesso de suporte segue a mesma regra do upload de certificado — poder de admin com entrada de auditoria de suporte. Auditoria grava cliente, chave, tipo de evento, resultado e requisitante, sem material de certificado.

**Segredos:** a senha do A1 vive só na opção do transporte; o XML do evento (assim ou não), o envelope assinado e qualquer token não vão para log nem para o registro de auditoria — identificadores e resultado apenas. Reuso das regras de fault do transporte (texto condensado, sem eco do pedido).

## Risks / Trade-offs

- **[Assinatura rejeitada pelo AN com erro enigmático]** → Mitigação: validação local contra XSD do serviço de eventos antes do envio; canário em homologação (`FISCAL_ENVIRONMENT=homologacao`) antes de produção; fixture de resposta real anonimizada.
- **[Dependência nova (xmlseclibs) muda o perfil do módulo]** → Mitigação: dependência já aprovada pelo usuário; a biblioteca fica confinada ao serviço de assinatura, sem vazar para o resto do módulo.
- **[Manifestação automática lida como anuência]** → Mitigação: 210210 é ciência, não confirmação (210200 nunca automática — decisão registrada na proposal e na spec); textos de UI em português deixam isso claro.
- **[Rajada de manifestações num lote grande de resumos]** → Mitigação: fila com jobs por chave e deduplicação; evento não tem teto publicado, mas o cliente em bloqueio não recebe evento até a janela expirar.
- **[573 confundido com erro e travando o fluxo]** → Mitigação: classificador dedicado coberto por teste; estado "já manifestado" visível na tabela e navegável até a consulta.

## Migration Plan

1. Migration aditiva da tabela de manifestação (com `account_id`); nenhuma coluna existente muda.
2. Deploy com `manifestacao_enabled` false; registrar serviço e jobs.
3. Canário em homologação: assinar evento, enviar 210210, confirmar aceite (cStat do evento) e NSU com procNFe na distribuição seguinte.
4. Ligar o gate para a conta canária em produção; acompanhar a recuperação via `consChNFe` dentro do teto.

Rollback: desligar `manifestacao_enabled` — fila para de executar e nada novo é enfileirado; tabela aditiva pode ficar (compatível com NF-e e CT-e futuros). Migration de produção só com autorização explícita.

## Open Questions

- Algoritmo de digest/assinatura esperado pelo serviço de eventos na versão vigente (SHA-1 legado versus SHA-256 da NT 2020.001) — confirmado contra o schema local no início da implementação; não muda specs nem tarefas. A dependência `xmlseclibs` já está aprovada (decisão 1).