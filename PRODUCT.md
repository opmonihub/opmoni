# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

O usuário principal é o operador do escritório contábil. No dia a dia ele (1) varre a validade do certificado A1 e da procuração e-CAC e atualiza os cadastros da carteira, e (2) executa as rotinas fiscais do mês em Work: ver o que vence, para qual cliente, em que etapa está, e avançar ou dispensar tarefas sem sair do dashboard.

O admin da conta governa o escritório: equipe, departamentos e conta, e também opera Work e a carteira. O papel `user` lê Work na conta atual sem alterar tarefas, processos ou modelos. Super admin existe para o painel global e para o modo suporte, em que opera dentro de uma conta alheia com auditoria.

## Product Purpose

O opmoni existe para o escritório administrar, no tenant atual e isolado por conta, duas frentes do trabalho fiscal:

1. **Carteira** — identificação (CPF/CNPJ), regime tributário, certificado A1 e procuração e-CAC, com alerta de validade.
2. **Work** — rotinas mensais por cliente: modelos com blueprint e regra de elegibilidade, geração de um processo por (modelo, cliente, competência), tarefas com ciclo de vida, responsável, prazo e cascata opcional.

Sucesso na carteira: o operador vê quem está a vencer, vencido ou sem cadastro e age no mesmo lugar. Sucesso em Work: o operador vê o que vence no mês, por cliente e por etapa, e conclui ou dispensa sem perder o contexto da competência.

## Positioning

A carteira fiscal e o trabalho mensal do escritório num só dashboard: CPF/CNPJ, regime, A1 e e-CAC; modelos → processos por competência → tarefas, isolados por conta, com vocabulário fiscal em português.

## Operating Context

Escritório contábil brasileiro, com várias contas isoladas. A interface e o vocabulário são em português. Stack em execução: monorepo API Laravel (`backend/`) + dashboard Nuxt UI (`frontend/`).

**Carteira.** O operador começa no painel e segue para Meus clientes. Lá escolhe Certificados ou Procuração e a situação: todos, a vencer, vencido, válido ou sem cadastro. Pessoa jurídica entra com consulta de CNPJ; pessoa física entra em cadastro manual. Certificado A1 e procuração e-CAC ficam no registro do cliente.

**Work (modo Operate).** Seção própria no sidebar com cinco visões sob competência (`reference_month` onde couber):

- **Calendário** — só tarefas com prazo.
- **Clientes** — tabela agrupada Cliente → Processo → Tarefa.
- **Processos** — tabela agrupada Processo → Cliente → Tarefa (domínio processo primeiro); detalhe do processo com etapas ordenadas.
- **Tarefas** — board Kanban por status (sem drag-and-drop na v1).
- **Modelos** — catálogo e editor de blueprints, regra de elegibilidade, preview e geração do mês.

Nas tabelas agrupadas (Clientes / Processos), a coluna principal é **Item**: grupos mostram o nome do cliente ou do processo; folhas mostram `N. Título` (ordem da etapa + título). Indentação progressiva por profundidade e faixa elevada na linha de grupo seguem o padrão TaskHub / Nuxt grouped rows. Expand/collapse de grupos permanece estável após refresh de dados (`autoResetExpanded: false`). Status de tarefa usa rótulos fixos; o menu omite o valor atual; o botão de status não usa spinner de loading (busy fica na barra em lote / modal de dispensa). Seleção em lote, avanço, atribuição e dispensa compartilham barra de seleção, modal de dispensa e o fluxo de ações de tarefa.

## Capabilities and Constraints

- Conta isolada. CPF/CNPJ é único dentro da conta e pode repetir em contas distintas.
- Papéis: admin, operador, user e super admin. Admin e operador gerem Work e carteira; user só lê Work. Modo suporte de super admin em conta alheia fica auditado.
- Certificado digital A1 fica criptografado em disco privado; a senha do arquivo é descartada depois do processamento.
- A procuração e-CAC tem estados derivados da data: ausência, validade, proximidade do vencimento e vencimento.
- **Work — modelo:** blueprint ordenado (título, departamento texto livre, responsável padrão membro da conta, dia fixo 1–31, prioridade, descrição), flag de cascata, recorrência mensal; elegibilidade por regimes + Tags da carteira com exceções `added`/`removed`; preview antes de gerar.
- **Work — processo:** no máximo um por (modelo, cliente, competência) por conta; snapshot congelado na geração; processos manuais só-nome continuam válidos sem template/cliente/mês.
- **Work — tarefa:** etapa do processo (cliente herdado; sem `client_id` próprio). Status: A fazer (`todo`), Em progresso (`doing`), Concluída (`done`), Dispensada (`dismissed`). Dispensa exige motivo. Cascata (quando o modelo ativa) bloqueia avanço além de A fazer enquanto etapas anteriores não estão Concluída ou Dispensada.
- Vocabulário estável a preservar: carteira, certificado A1, procuração e-CAC, regime tributário, Work, modelo, processo, tarefa, competência, cascata, A fazer / Em progresso / Concluída / Dispensada.
- Trabalho futuro preserva isolamento por conta, papéis, modo suporte, português e esse vocabulário.

## Brand Commitments

O nome do produto é opmoni. A voz da interface é português direto, com o vocabulário fiscal e de Work acima. A interface permanece um dashboard Nuxt UI (Operate: scanabilidade e consistência acima de expressão de marca).

## Evidence on Hand

- Specs vigentes: `openspec/specs/` (carteira, contas, equipe, work-templates / work-processes / work-tasks / work-calendar, etc.).
- Change arquivado de Work: `openspec/changes/archive/2026-09-24-work/` (+ redesenho de calendário e alinhamentos posteriores sob `openspec/changes/`).
- Produto em execução no monorepo (API Laravel 13 / PHP ^8.3, dashboard Nuxt 4 + Nuxt UI). Superfícies Work: `frontend/app/pages/work/`, componentes e utilitários em `frontend/app/components/work/` e `frontend/app/utils/workGroupedTable.ts`, ações compartilhadas em `frontend/app/composables/useWorkTaskActions.ts`.
- Referência visual somente-leitura TaskHub em `.ref/` (não importar às cegas).

Não há depoimentos, casos, imprensa nem página de preço. Trabalho futuro não inventa prova social, clientes nomeados nem números de mercado.

## Product Principles

- O trabalho diário do operador é o produto: tratar validade na carteira e executar rotinas do mês em Work.
- Um cliente é um registro fiscal da conta; um processo do mês é a rotina daquele cliente naquela competência; uma tarefa é uma etapa ordenada desse processo.
- Isolamento de conta, papéis e modo suporte não cedem à interface.
- O vocabulário fiscal e de Work em português permanece estável (incluindo **A fazer**, nunca “Aberto”, para `todo`).
- Em Work Operate, o operador age no lugar: tabelas agrupadas densas, board por status, seleção em lote e dispensa com motivo — sem reinventar o padrão por visão.
- O admin governa a conta; o operador opera carteira e Work.

## Accessibility & Inclusion

Nenhum requisito de acessibilidade além do padrão do dashboard Nuxt UI foi estabelecido como compromisso de produto. Rótulos e `aria-label` em português nas ações de Work (status, expandir/recolher, seleção) devem permanecer compreensíveis.
