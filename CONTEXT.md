# opmoni

Vocabulário da plataforma multi-tenant opmoni, que organiza escritórios em accounts isoladas sob um painel global.

## Language

### Núcleo

**Account**:
A unidade operacional de um escritório: carteira, monitoramentos, documentos, processos e membros próprios.
_Avoid_: Conta, escritório

**Usuário**:
A identidade global de login (email e senha), que pode pertencer a um ou mais accounts.
_Avoid_: Membro

**Membro**:
O vínculo entre um usuário e um account, com um nível (admin, operador ou user).
_Avoid_: Usuário da conta, participante

**Super Admin**:
O usuário com acesso ao painel global e a todos os accounts para suporte.
_Avoid_:

**Admin**:
O nível máximo dentro de um account: gere tudo na conta, incluindo membros e assinatura.
_Avoid_:

**Operador**:
O nível que opera os recursos do account (criar, ler, atualizar, excluir), sem gerenciar membros.
_Avoid_:

**User**:
O nível que lê os recursos do account e executa apenas as próprias ações.
_Avoid_:

### Comercial

**Plano**:
O item do catálogo com limites (Básico, Profissional, Empresarial).
_Avoid_:

**Assinatura**:
O vínculo entre um account e um plano, com status (ativa, inadimplente, cancelada).
_Avoid_:

### Plataforma

**Acesso de suporte**:
A entrada do super_admin em um account alheio com poder de admin, mantendo a própria identidade e registrando as ações.
_Avoid_: Impersonação

**Painel Global**:
O ambiente separado do operacional onde o super_admin gere accounts, planos, assinaturas, usuários e suporte.
_Avoid_: Painel administrativo

### Integra Contador

**Integra Contador**:
A plataforma de APIs do SERPRO que dá ao mercado contábil acesso programático aos sistemas da Receita Federal.
_Avoid_: Serpro — que é o órgão e o fornecedor, não o produto

**Credencial de plataforma**:
A chave, o segredo e o certificado de uma única conta contratada com o provedor, guardados uma vez e compartilhados por todas as Account.
_Avoid_: credencial do escritório, credencial da conta

**Contratante**:
Quem contrata o produto junto ao provedor e aparece como `contratante` no envelope de toda requisição. Aqui, a plataforma — nunca a Account que opera o sistema.
_Avoid_: escritório, cliente

**Procuração e-CAC**:
O vínculo entre um cliente e o escritório, registrado pelo próprio escritório no e-CAC, com data de início e de fim. Diz o que o escritório cadastrou, e nada sobre o que o provedor reconheceu.
_Avoid_: procuração, sem qualificação

**Família de serviço autorizada**:
O que o provedor confirma que um cliente autorizou, por família de serviço, com o código emitido pelo e-CAC e um estado de integração. Um cliente pode ter uma família e não outra, e a mesma concessão pode cobrir duas famílias de uma vez.
_Avoid_: procuração — a palavra já é do registro do escritório

**Termo de autorização**:
O documento único **por Account** que autoriza a plataforma a agir em nome daquele escritório. Não é por cliente, e o escritório não o assina: a plataforma monta, assina e renova sozinha.
_Avoid_: termo do cliente, termo por cliente

**Obrigação**:
Um dever tributário que o Monitoramento acompanha por cliente — PGDAS, DCTFWeb, situação fiscal, parcelamento.
_Avoid_: painel — que é a tela, não a obrigação

**Fonte da obrigação**:
O que o provedor de fato entrega para aquela obrigação, em uma de quatro situações: **direta** (leitura estruturada), **derivada** (a mesma chamada com outra projeção, ou um filtro sobre o conteúdo retornado), **indisponível** (a obrigação existe e ninguém a serve) ou **extinta** (a obrigação deixou de existir). As duas últimas não mostram contador nem linha, e é essa distinção que separa "ninguém serve" de "o cliente não tem dado".
_Avoid_: sem dados — que descreve o cliente, não a obrigação

**Contadores**:
Os cinco números que subdividem o total de clientes de uma obrigação: total, em dia, processando, pendências e atenção. O total é a soma dos quatro últimos.
_Avoid_: métricas, KPIs

**Encerrado**:
A situação de um cliente cuja obrigação foi quitada. Fica **fora** dos quatro contadores, porque uma obrigação quitada não infla um balde que exige ação.
_Avoid_: concluído — que é situação de execução

**Causa**:
O motivo nomeado por trás de um contador de atenção: sem declaração, sem procuração, procuração inválida, contém débitos. O contador agrega; a linha nomeia.
_Avoid_: pendência — que é o balde, não a causa

**Ciência da intimação**:
O ato de abrir uma mensagem da caixa postal de um cliente, que inicia um prazo legal. Listar mensagens é livre de consequência; abri-las não é.
_Avoid_: ler mensagem, ver mensagem

**Execução**:
Um disparo de sincronização, com estado próprio, histórico e contagens. Uma por Account em andamento.
_Avoid_: job, tarefa, processo

**Item de execução**:
O trabalho de um cliente dentro de uma execução, em um de cinco estados: sincronizado, ignorado com motivo, falhou, indeterminado e não processado.
_Avoid_: situação do cliente — que pertence à obrigação, não ao item
