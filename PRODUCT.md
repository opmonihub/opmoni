# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Público prioritário:** escritórios contábeis brasileiros. Administradores e integrantes da equipe trabalham com a carteira de clientes, documentos fiscais, obrigações, processos e tarefas do escritório.
- **Público secundário:** empresas clientes e seus usuários autorizados, que usam o ERP para a operação diária. O ERP é uma extensão do serviço oferecido pelo escritório, não uma prioridade equivalente à operação contábil quando houver conflito de decisões de produto.
- **Administração da plataforma:** Super Admin gerencia escritórios e recursos da plataforma. Essa função não representa o operador comum e não substitui vínculo real ou autorização para acessar dados de um escritório ou empresa.

A prioridade dos escritórios e o papel secundário das empresas foram confirmados pelo usuário durante o `impeccable init` em 2026-10-29.

## Product Purpose

Centralizar a operação do escritório contábil: carteira de clientes, documentos, obrigações e trabalho da equipe. Permitir que o escritório ofereça e administre o ERP dos clientes como extensão desse serviço.

O sucesso da experiência é permitir que a equipe encontre o cliente e a pendência corretos, compreenda o estado real da operação, execute a ação permitida e confirme seu resultado sem perder o contexto do escritório ou da empresa. Não há metas quantitativas de sucesso confirmadas neste registro.

## Positioning

O Opmoni é uma central de operação para escritórios contábeis, com ERP integrado à oferta de serviços aos clientes. A organização da carteira, o acompanhamento fiscal e de obrigações e a coordenação do trabalho são o núcleo; a gestão comercial e o uso empresarial do ERP complementam esse núcleo.

Esse é o posicionamento confirmado pelo usuário, não uma alegação de exclusividade no mercado. Comparações com concorrentes, ganhos de produtividade, economia e outras promessas comerciais exigem evidência própria.

## Operating Context

- O produto é uma aplicação web em português brasileiro, com operação completa em desktop e celular. Mobile web não implica aplicativo nativo.
- Uma identidade pode ter acesso a mais de um escritório e a empresas. O contexto selecionado e a autorização atual determinam o que pode ser consultado ou alterado.
- No acesso de usuários com escritórios ativos, o login entra no escritório padrão; a troca posterior é explícita e local à sessão. Usuários apenas de empresa têm acesso ao ambiente ERP autorizado.
- A rotina do escritório envolve cadastros e CNPJ, certificados A1, documentos e XML fiscais, acompanhamento de obrigações, processos, tarefas, responsáveis e calendário.
- O escritório administra sua oferta ERP, empresas clientes, planos, atribuições de assinatura, contratos, faturas e recebimentos. O uso operacional do ERP pertence ao contexto da empresa e às permissões concedidas.
- Integrações e rotinas agendadas dependem de configuração, credenciais, autorizações e disponibilidade dos respectivos provedores. A interface deve distinguir esses pré-requisitos dos resultados efetivamente confirmados.

## Capabilities and Constraints

### Capacidades representadas no projeto

- **Carteira de clientes:** cadastro e consulta, detalhes, busca e filtros, organização e gestão de certificados A1.
- **Fiscal:** acompanhamento de captura por cliente, documentos, detalhes e download de XML, com contratos e liberações próprios para cada fonte e operação fiscal.
- **Monitoramento:** obrigações e consultas via SERPRO, agendas e histórico de sincronizações; comunicação de guias por WhatsApp conforme os contratos e as liberações existentes.
- **Trabalho e equipe:** modelos, processos, tarefas, responsáveis, calendário, membros e departamentos.
- **ERP empresarial:** cadastros, vendas, compras, estoque, financeiro, caixa, fiscal, relatórios e projeções contábeis, sujeitos a permissões, módulos e liberações próprios.
- **Gestão comercial do ERP pelo escritório:** empresas da carteira, planos e revisões, atribuição de assinatura, ciclo de contrato, faturas, recebimentos e administração autorizada de acesso de membros.
- **Administração da plataforma:** escritórios, planos e assinaturas da plataforma, usuários, consultas de suporte e configuração SERPRO. Assinaturas da plataforma não são os contratos ERP das empresas.

Essas capacidades têm fontes, especificações e evidências locais no repositório. Sua presença não comprova disponibilidade em produção nem aceitação real por provedores externos.

### Limites que futuras mudanças devem preservar

- **Contextos distintos:** `Account` é o escritório contábil; `Client` é o registro da carteira do escritório; `Company` é a empresa que opera no ERP; estabelecimentos pertencem à empresa. Correspondência de CNPJ ou email não concede acesso nem funde essas entidades.
- **Autoridade explícita:** dados isolados por escritório e empresa; vínculo real, permissões atuais e contexto selecionado são necessários. Delegação do escritório para operar uma empresa é explícita e revogável. Super Admin não recebe acesso operacional implícito.
- **Etapas comerciais distintas:** cadastrar uma empresa não atribui licença, cria contrato ou emite fatura. A atribuição registra termos explícitos, reserva capacidade e mantém contrato pendente. A ativação inicia o serviço conforme a data planejada e gera a primeira fatura de forma idempotente. O fluxo composto de adesão existente não elimina essa distinção.
- **Identidades distintas:** o proprietário da empresa e o administrador do escritório têm autoridades diferentes. Preparação e aceite de acesso do proprietário não substituem administração autorizada de membros pelo escritório.
- **Trial explícito:** há contrato para teste ERP de sete dias atribuído ao escritório, sujeito à liberação correspondente. Não implica fatura de trial, cobrança automática ou conversão paga automática. Expiração preserva dados e consultas autorizadas, bloqueando escritas operacionais conforme o contrato.
- **Exatidão e histórico:** valores monetários e quantidades fracionárias devem preservar representação decimal exata. Termos, documentos e histórico financeiro não podem ser reescritos para simplificar a interface.
- **Recuperação segura:** operações com efeito comercial, financeiro ou fiscal preservam idempotência, identidade de operação e consulta de resultado. Resultado incerto não autoriza repetição cega. Recuperação de acesso de membros ERP permanece vinculada ao destinatário, à autoridade e à configuração segura de entrega.
- **Manutenção não é nova venda:** a manutenção autorizada de contratos já aceitos e de seu histórico tem limites próprios quando direitos comerciais do escritório ficam inativos. Isso não restaura capacidade para novas vendas nem cria permissão geral de escrita.
- **Estados verdadeiros:** carregando, vazio confirmado, ausente, negado, desatualizado, falha e resultado incerto são estados diferentes. Dados demonstrativos ou zeros fabricados não substituem respostas reais.
- **Liberações independentes:** cadastro público, trials, escritas ERP, transmissão fiscal, provedores, pagamentos, WhatsApp e produção mantêm seus próprios gates. Implementação, testes locais ou arquivamento de uma mudança não os habilitam.
- **Arquitetura atual:** Nuxt apresenta a interface; Laravel possui autenticação, autorização, dados e rotinas de negócio, com API versionada e proxy privado. A captura fiscal atualmente reside no Laravel; a extração para `services/fiscal/` é planejada, não um serviço já entregue.
- **Escopo honesto:** widgets de demonstração, Inbox e remanescentes do template não devem ser apresentados como funcionalidades de negócio completas apenas por existirem na navegação.

### Decisões em aberto

- Metas quantitativas de sucesso, segmento de porte dos escritórios e empresas, volumes típicos e compromissos de desempenho não foram definidos neste init.
- Não foi assumido compromisso com aplicativo nativo, operação offline ou suporte específico a aparelhos de baixa capacidade e conexões instáveis.
- Preços públicos, garantias de disponibilidade, datas de lançamento e diferenciais comparativos não foram aprovados neste registro. Termos comerciais existentes continuam nos contratos e configurações próprios.

## Brand Commitments

- Nome do produto: **Opmoni**. A identificação nominal existente aparece, por exemplo, em `frontend/app/pages/login.vue` e `frontend/app/app.vue`.
- Idioma da experiência: **português brasileiro**.
- Voz confirmada: **direta, clara e profissional**. Usar termos reconhecíveis pelo escritório e explicar efeitos e restrições sem expor jargão interno de implementação como orientação de negócio.
- Este init não aprova mudança de marca ou substituição da identidade existente. Não define paleta, tipografia, componentes ou conceitos de página.

## Evidence on Hand

- `docs/architecture/product-decisions.md`: decisões aceitas e sua evolução. Trechos históricos ou superados não devem prevalecer sobre decisões posteriores e contratos canônicos.
- `openspec/specs/`: contratos canônicos de acesso, isolamento, carteira, fiscal, monitoramento, trabalho, equipe, ERP, gestão comercial e padrões de interface.
- `docs/architecture/system-spec-reconciliation.md`: mapa de capacidades, implementação, registros datados de verificação e limites de evidência.
- `README.md`: estrutura atual, configuração, operação, distinção entre recursos implementados e liberações pendentes.
- `frontend/app/pages/`, `frontend/app/components/`, `backend/routes/` e testes correspondentes: implementação e conteúdo existentes. Relatórios históricos não substituem inspeção e verificação do checkout atual.
- Há registros de aceitação local e cenários de navegador em `docs/reviews/` e mudanças arquivadas em `openspec/changes/archive/`. Eles são evidência datada do escopo declarado, não comprovação automática de produção, entrega real de email ou integração ao vivo.
- Nenhum depoimento, cliente de referência, resultado comercial mensurado, certificação ou autorização de uso de marcas de terceiros foi fornecido neste init. Não inventar prova social ou promessas a partir de fixtures e telas de exemplo.

Este arquivo registra contexto durável do produto. Não substitui as especificações de domínio, os gates operacionais ou os registros de aceitação vinculados às fontes acima.

## Product Principles

1. **O escritório vem primeiro.** Priorizar a operação contábil; o ERP amplia o serviço sem deslocar esse núcleo.
2. **Contexto e autoridade antes de conveniência.** Tornar o trabalho mais simples sem misturar escritórios, empresas, identidades ou permissões.
3. **A verdade da operação deve ser visível.** Diferenciar intenção, pré-requisito, execução e resultado confirmado; não esconder falhas nem inventar sucesso.
4. **Preservar confiança financeira e fiscal.** Manter valores exatos, histórico, efeitos explícitos e recuperação segura de operações.
5. **A tarefa precisa poder ser concluída.** Manter consulta, ações permitidas, validação e recuperação disponíveis em desktop e celular, sem reduzir o escopo funcional para caber na tela.

## Accessibility & Inclusion

Preservar os requisitos já registrados em `openspec/specs/frontend-ui-patterns/spec.md`: nomes acessíveis, operação por teclado, foco efetivo, estados de expansão e contenção e retorno de foco em modais; detalhes, ações e caminhos de recuperação devem permanecer alcançáveis nos dispositivos suportados e nos temas existentes.

O usuário confirmou os limites existentes, sem acrescentar requisito específico de público ou padrão normativo neste init. Não há certificação de acessibilidade ou meta formal WCAG adicional estabelecida por este registro.
