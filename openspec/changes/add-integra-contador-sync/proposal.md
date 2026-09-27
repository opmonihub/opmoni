## Why

O módulo de Monitoramento é hoje inteiramente fictício: `frontend/app/utils/monitoringNav.ts` carrega dez empresas escritas à mão e deriva o estado de cada uma por aritmética (`statusCycle[(company.id + pageIndex) % 4]`), sem nenhuma chamada de rede. Sem uma fonte real, o escritório não consegue saber o que está pendente, a vencer ou vencido em nenhum dos 17 painéis — o produto mostra número plausível e nada mais.

A fonte real já existe e é pública: o **Integra Contador**, a plataforma de APIs do SERPRO que dá ao mercado contábil acesso programático aos sistemas da Receita Federal. Enquanto o produto não fala com ela, o Monitoramento é ficção; enquanto não falar, o escritório continua operando a planilha no e-CAC.

Como o opmoni é uma software house, a comunicação com o SERPRO é responsabilidade nossa e não do escritório: **uma única credencial de plataforma** (consumer key/secret), com o opmoni na posição de `contratante` e de `autorPedidoDados`. O escritório contribui apenas com a relação de procurações entre os seus clientes e o opmoni — o que ele já registra hoje em `client_ecac_powers_of_attorney`. Essa divisão é o que torna a integração implantável sem renegociar contrato Serpro por carteira.

## What Changes

- **Credencial única de plataforma.** Uma conexão Integra Contador no nível da plataforma, com consumer key/secret do SERPRO e o e-CNPJ do próprio opmoni (o certificado que assinou a contratação, exigência do SERPRO), protegida fora de `Account.settings` e fora dos certificados por cliente que já existem. O token e o `jwt_token` de execução passam a ser derivados e cacheados, não digitados.
- **Procuração como pré-requisito de ação.** O Monitoramento passa a carregar o estado da procuração de cada cliente junto do status fiscal, porque é a procuração que autoriza o opmoni a falar pelo contribuinte. O estado é **observado**, lido do SERPRO pelo serviço de consulta de procuração: o escritório continua estabelecendo a procuração no e-CAC, como já faz, e o produto apenas reflete se ela vale e avisa quando está vencendo. Clientes sem procuração válida ficam visivelmente bloqueados em vez de aparecerem como "regular".
- **Habilitação por escritório.** Cada escritório entra explicitamente na integração, com um `admin` ligando e desligando. Nenhum cliente é sincronizado antes disso, o que evita que uma credencial de plataforma revogada ou uma indisponibilidade do SERPRO afete todos os escritórios do cluster de uma vez.
- **Sincronização inicial com histórico.** Uma execução de sincronização por cliente, disparada sob demanda, persistida com estado (`queued`/`running`/`completed`/`partial`/`failed`), contagens por cliente, erro legível e carimbo de tempo. Re-sync manual pelo mesmo caminho, com as requisições aos serviços do SERPRO saindo de uma fila e não do ciclo de request.
- **Monitoramento passa a ler dados reais.** As dez empresas fictícias e o status por aritmética saem: o painel de Monitoramento, as listagens por obrigação e situação e os contadores de atenção passam a derivar de registros sincronizados, preservando o vocabulário de estado já exposto (`pending`, `expiring`, `expired`, `regular`).
- **Superfície nova em `/monitoring`.** Grupo de navegação para a conexão e as execuções de sincronização, reaproveitando o invólucro `UDashboardPanel` já existente em `monitoring.vue`, e telas de configuração, listagem de execuções com estado e detalhe por cliente.
- `SerproMonitoring` deixa de ser um marcador sem uso e passa a ser a entidade de vínculo entre um cliente do escritório e seu estado sincronizado, com factory, spec e policy próprios.

Fora deste change: webhooks e o serviço `EVENTOSATUALIZACAO` (push de eventos), serviços de escrita que emitem ou transmitem declaração (DCTFWeb, MIT, PGDAS-D, SICALC), o estabelecimento de procuração pelo produto via `AUTENTICAPROCURADOR` (que exige XML assinado digitalmente — o escritório continua fazendo isso no e-CAC e o opmoni apenas lê o resultado), o sync de contribuintes pessoa física no v1, upload de arquivo de procuração e qualquer resultado da API que dependa de contratação já feita com o SERPRO.

## Capabilities

### New Capabilities

- `serpro-connection`: conexão de plataforma com o Integra Contador — credencial e certificado do SERPRO, ciclo de token e `jwt_token` derivado, teste de conectividade, e o estado da procuração de cada cliente como condição para agir em nome dele.
- `serpro-sync`: execuções de sincronização inicial e re-sync — disparo, execução em fila, estado persistido por execução, contagens por cliente, erro legível por execução, e o registro do que foi trazido do SERPRO.
- `monitoring`: o Monitoramento do escritório sobre dados reais — clientes do escritório derivados de registros sincronizados, estado por obrigação (pendente, a vencer, vencido, regular), listagem e contagem de atenção, e uma empresa explicitamente marcada como não atendida quando não há dado sincronizado.

### Modified Capabilities

- `client-fiscal-access`: a procuração e-CAC deixa de ser apenas datas e notas e passa a carregar o código emitido pelo SERPRO junto do estado de estabelecimento da integração, sem passar a exigir o arquivo de procuração.

## Impact

- **Backend (Laravel 13).** `SerproMonitoring` e sua migration (`serpro_monitorings` ganha colunas de vínculo e de estado), com factory e policy; novos models para conexão de plataforma e para execução de sincronização; `config/integra-contador.php` no lugar do padrão de `config/clients.php`; a primeira integração do projeto com autenticação real — hoje `CnpjWsLookup` é o único cliente HTTP de saída e ele não tem token, base URL em config, retry nem mTLS; um job novo ao lado de `DeleteClientsJob`, que hoje é o único da fila; endpoints em `routes/api.php` sob o middleware `['auth:sanctum', 'tenant']` já existente.
- **Segredos.** A credencial do SERPRO e o certificado do opmoni não podem ir para `Account.settings` (cast `array` puro, sem criptografia), nem para `client_certificates` (escopo por cliente). `Crypt::encryptString` sobre disco privado é o precedente do próprio repo, mas para uso de plataforma. Consistente com `APP_KEY` ser invariante de produção.
- **Fila.** `QUEUE_CONNECTION=redis` com `retry_after` default de 90s contra `timeout` de 300s do job existente é um descompasso conhecido; um job de sincronização por cliente precisa de timeout e backoff coerentes com o consumidor real, ou execução duplicada.
- **Frontend (Nuxt 4 + Nuxt UI v4).** `app/utils/monitoringNav.ts` perde os dados fictícios e ganha contagem de atenção vinda do backend; `MonitoringSheet.vue` deixa de filtrar um array local; entram páginas novas no grupo `/monitoring` e um composable `useSerpro` no padrão de `useWork`/`useClients`; tipos em `app/types/`. O padrão de estado vazio/carregando/erro e o `DataTableFilter` já existem em `work/processos.vue` e são a referência a copiar. A habilitação por escritório aparece na tela de conexão, com o reflexo de que desligar impede novas execuções sem apagar o histórico.
- **Infraestrutura.** A integração é server-side only: o navegador nunca fala com o SERPRO (mesma premissa de `cnpj-lookup`). Nada muda em `docker/nginx/*.conf` nem no roteamento; a única variável nova é a URL base da API, que precisa ser distinta entre trial e produção.
- **Sem dependência nova.** O cliente HTTP é o `Http` do Laravel, já presente. Não há gerador OpenAPI nem SDK PHP-SERPRO no repositório, e a comparação de alternativas fica em `design.md`.
