## Why

O Monitoramento deixou de ser fictício, mas **não funciona**. A camada de transporte existe e é
testada — `SerproClient`, `SerproTokenProvider`, `SerproEnvelope`, `SerproRequestTag`,
`SerproCertificateMaterializer`, `SerproFailure` — e o frontend inteiro de monitoramento foi
escrito contra um contrato de API que o Laravel ainda não serve.

O resultado em produção hoje é o pior dos dois: as telas **parecem** corretas e não têm nada
atrás. `useSerpro.ts` chama onze endpoints sob `/serpro/*` e nenhum existe, então toda tela cai
no ramo de `404` tratado como "sem dado" — o overview desenha zeros e travessões, a lista de
obrigações devolve envelope vazio, a de termos mostra "o escritório ainda não tem termo". É uma
tela vazia plausível, indistinguível do estado inerte que o produto deveria estar mostrando.

Faltam as quatro camadas que transformam o dado em decisão: o **certificado do escritório** e o
**termo de autorização** que a plataforma assina em nome dele, a **procuração** por cliente e
família de serviço, a **sincronização** em fila que persiste o que veio do provedor, e a **API de
leitura** que deriva contadores e situações. Sem elas o escritório continua na planilha.

Este change é a continuação de `add-integra-contador-sync`, que entregou o transporte (itens 1.1,
1.3, 2.1, 2.2, 2.3, 2.4, 2.6, 3.1, 3.2, 3.5) e o frontend (seções 8 e 9) e foi arquivado com
`--skip-specs` justamente porque as seções 4 a 7 nunca foram implementadas.

## What Changes

- **Fechar as lacunas do transporte já construído.** O `BrazilianTaxId` normaliza com
  `preg_replace('/\D+/')`, o que **descarta as letras** e rejeita sistematicamente o CNPJ
  alfanumérico que o SERPRO exige desde a RFB IN 2.119/2022 — a integração quebra ao salvar um
  escritório. Faltam também a policy e o resource da conexão, a validação de que o documento
  configurado bate com o do certificado antes de qualquer chamada, os enums de estado e o teste
  de conectividade com seus quatro desfechos, a tolerância de `tipo` como string e a fixture
  SITFIS.
- **Certificado do escritório e termo de autorização.** O escritório sobe o próprio e-CNPJ uma
  vez; a plataforma monta o `termoDeAutorizacao`, assina, submete ao serviço gratuito de apoio e
  renova todo dia reenviando o mesmo documento — o provedor responde `304` com o token no
  `ETag`, então o escritório não assina nada de novo. O componente de assinatura do SERPRO é
  vendorizado em `app/Support/`, sem tocar em `composer.json`, e o documento assinado nunca sai
  do backend.
- **Procuração por cliente e família de serviço.** O estado é **lido** de
  `PROCURACOES/OBTERPROCURACAO41`, nunca estabelecido pelo produto: o escritório continua
  outorgando no e-CAC e o opmoni apenas reflete se vale. A elegibilidade é por família, porque o
  catálogo usa códigos distintos por serviço — e `00146` é compartilhado por `PGDASD` e `DEFIS`,
  de modo que as duas não são autorizáveis de forma independente.
- **Sincronização durável.** Execuções e itens viram linhas de banco, não cache: um job de
  fan-out com `accountId` explícito, um job por cliente idempotente por `(run_id, client_id)`,
  lock por cliente, `timeout` abaixo do `retry_after`, e um registro de cada chamada com serviço,
  versão, caminho, marcação de cobrança, mensagens e duração. Um `504` é resultado indeterminado:
  não repete na mesma execução, não conta como falha do cliente e não interrompe os demais.
- **A API de leitura que o frontend já consome.** Listagem de clientes por obrigação, filtrada no
  servidor, com `total` igual à soma de `em_dia`, `processando`, `pendencias` e `atencao` mais
  `encerrado` fora da partição, e a lista de causas com código e contagem. A classificação de
  cada uma das 19 obrigações em `direct`, `derived`, `unavailable` ou `extinct` passa a vir do
  backend, versionada com o catálogo de onde foi lida, para que a tela não apresente obrigação
  sem serviço como pendência de cliente.
- **`SerproMonitoring` deixa de ser placeholder.** As linhas que só carregam `name` são removidas,
  a tabela passa a ligar `client_id` com estado e carimbo de origem, e o CRUD, a policy e a chave
  `'monitorings'` do `PlanLimits` saem junto — o frontend nunca chamou `/api/monitorings`.
- **O controle de habilitação do escritório.** Fica em `Account.settings`, escrito só por `admin`,
  preservando execuções e dados ao desligar. É o que permite que uma credencial revogada ou uma
  indisponibilidade do SERPRO não alcance todos os escritórios do cluster de uma vez.

Fora deste change: webhooks e `EVENTOSATUALIZACAO`; qualquer serviço de escrita que emita ou
transmita declaração; o estabelecimento de procuração pelo produto; a sincronização de
contribuintes pessoa física; e a leitura do corpo de uma intimação da caixa postal, que é ato
jurídico e por isso não entra junto com a listagem.

## Capabilities

### New Capabilities

Nenhuma. As quatro capacidades continuam as mesmas, com os mesmos identificadores: `serpro-connection`,
`serpro-sync`, `monitoring` e a modificação de `client-fiscal-access`. Elas entram em
`openspec/specs/` quando este change for arquivado — nada de SERPRO foi sincronizado ainda,
porque o change anterior foi arquivado com `--skip-specs`.

### Modified Capabilities

- `client-fiscal-access`: a procuração e-CAC passa a carregar o código emitido pelo SERPRO junto
  do estado de estabelecimento da integração, sem passar a exigir o arquivo de procuração.

## Impact

- **Backend (Laravel 13).** Cinco migrations aditivas (`account_certificates`,
  `serpro_authorization_terms`, `serpro_sync_runs`, `serpro_sync_run_items` e a autorização por
  cliente × família), extensão de `serpro_monitorings` e de `client_ecac_powers_of_attorney` com
  colunas nullable, e uma refatoração: a leitura, a cifragem e a higiene de senha saem de
  `ClientCertificateVault` para uma unidade compartilhada, deixando o vault de cliente como
  chamador enxuto. É o único arquivo pré-existente que este change reestrutura.
- **Remoção.** O CRUD de `serpro_monitorings`, a `SerproMonitoringPolicy`, a chave `'monitorings'`
  do `PlanLimits` e as referências nos seeders saem. Isso **quebra três arquivos de teste** que
  hoje afirmam o comportamento antigo (`ConsistencyRefactorTest`, `SubscriptionsTest`,
  `SecurityRefactorTest`) — a remoção e a atualização desses testes são o mesmo trabalho.
- **Fila.** `QUEUE_CONNECTION=redis` com `retry_after` de 90s contra `timeout: 300` do
  `CaptureFiscalDocumentsJob` é um descompasso conhecido. O job novo tem `timeout` abaixo do
  `retry_after`; o desalinhamento do job fiscal existente é sinalizado, não corrigido aqui.
- **Frontend.** Praticamente nada: as telas, o composable, os tipos e o registro de apresentação
  já existem e já passam em `pnpm lint`, `pnpm typecheck` e `pnpm test`. Entra o controle de
  habilitação, que acompanha o endpoint da 5.5, e nenhum outro ajuste é previsto.
- **Sem dependência nova.** O cliente HTTP é o `Http` do Laravel; a assinatura é o componente
  oficial do SERPRO vendorizado em `app/Support/`, sem mover nada em `composer.json`.
- **Segredos.** Nenhum segredo novo sai do backend. A conexão de plataforma já é cifrada; o
  certificado do escritório usa a mesma disciplina do vault, e o documento assinado e o material
  de assinatura nunca aparecem em resposta de API nem em log.
