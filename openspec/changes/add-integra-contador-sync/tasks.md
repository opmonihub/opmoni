## 1. Configuração e segredos de plataforma

- [ ] 1.1 Criar `config/integra-contador.php` no padrão de `config/clients.php`, com host de autenticação, gateway de trial e de produção separados, `contratante` fixo, e um mapa de serviços por `idServico` contendo `path`, `versaoSistema` e `billable`; verificar com `php artisan config:clear` e `php artisan tinker` que as chaves resolvem
- [ ] 1.2 Verificar o tipo da coluna `tax_id` em `clients` e a regra `ValidCnpj`, e ajustar para aceitar CNPJ alfanumérico como texto em todas as camadas, conforme exigido pelo SERPRO; verificar com teste que um CNPJ alfanumérico válido é aceito e um inválido recusado
- [ ] 1.3 Documentar as chaves em `backend/.env.example` com comentário em português e valores comentados; verificar que nenhum segredo real aparece no arquivo
- [ ] 1.4 Gerar migration, model, factory, policy e resource de `SerproConnection`, com segredo e certificado passando por `Crypt::encryptString`; verificar com teste que a coluna gravada não contém o valor em claro
- [ ] 1.5 Implementar no model um accessor que devolve apenas metadados não secretos e validar que o documento configurado bate com o do certificado antes de qualquer chamada; verificar com teste para documento divergente

## 2. Cliente HTTP, envelope e autenticação

- [ ] 2.1 Materializar o PFX em arquivo temporário `0600` e removê-lo em `finally`, zerando a senha; verificar que nenhum arquivo permanece após uma chamada que falha
- [ ] 2.2 Implementar a obtenção do par de tokens em uma única chamada, com `Content-Type` de formulário, cache com folga sobre a validade informada e renovação por `401` no máximo uma vez; verificar com `Http::fake` que duas chamadas seguidas disparam uma única aquisição
- [ ] 2.3 Implementar a montagem do envelope com `contratante`, `autorPedidoDados` e `contribuinte` como papéis distintos e `dados` codificado como string; verificar com teste que o corpo enviado tem exatamente essa forma
- [ ] 2.4 Implementar o `X-Request-Tag` de exatamente 32 caracteres em uma única função, e verificar com teste que o comprimento e o formato batem em todos os casos
- [ ] 2.5 Implementar a decodificação da resposta com `dados` decodificado duas vezes, tolerando `tipo` como inteiro ou string e envelope nulo em erro; verificar com testes para sucesso, erro de aplicação e erro de gateway
- [ ] 2.6 Mapear a tabela de códigos do provedor para classe de retentativa — reautenticar, reenviar termo, nunca repetir, repetir com backoff — e verificar com teste unitário para cada código mapeado
- [ ] 2.7 Definir os enums de conexão, execução, item e procuração em `app/Enums/` com chaves TitleCase; verificar que são usados por models e resources
- [ ] 2.8 Implementar o teste de conectividade que exercita a autenticação sem tocar em cliente, distinguindo credencial ausente, credencial inválida e provedor indisponível; verificar com `Http::fake` para os quatro desfechos

## 3. Fixtures e contrato contra o trial

- [ ] 3.1 Obter o token público do trial e gravá-lo apenas em variável de ambiente de teste, jamais em arquivo versionado; verificar com `git grep` que nenhum token está no repositório
- [ ] 3.2 Escrever testes de contrato que são pulados por padrão e só rodam com a variável de token presente, tratando limitação de cota como skip e não como falha; verificar rodando com e sem a variável
- [ ] 3.3 Gravar como fixture, a partir das chamadas reais ao trial, as respostas de `REGIMEAPURACAO`, `PGDASD`, `DTE` e `SITFIS`, incluindo o envelope completo e o `dados` decodificado; verificar que as fixtures contêm o payload documentado
- [ ] 3.4 Rodar a suíte determinística apenas com as fixtures, sem rede, e verificar que ela cobre envelope, decodificação dupla, `mensagens` e os dois formatos de erro
- [ ] 3.5 Documentar em comentário no teste de contrato o que o trial não prova: asserção de token, máquina de estados de espera e semântica real de procuração

## 4. Certificado do escritório e termo de autorização

- [ ] 4.1 Extrair de `ClientCertificateVault` a parte de leitura, cifragem e higiene de senha para uma unidade compartilhada, deixando o vault de cliente como chamador enxuto, e verificar que os testes existentes de certificado de cliente continuam passando sem alteração de comportamento
- [ ] 4.2 Gerar migration, model, factory, policy e resource de `account_certificates`, com caminho por `account_id` e metadados não secretos; verificar com `php artisan migrate --force` e `migrate:rollback` que nada existente quebra
- [ ] 4.3 Implementar o envio do certificado do escritório reaproveitando a unidade compartilhada, com validação de senha, cifragem e remoção em `finally`; verificar com teste que a senha errada não grava registro nem arquivo
- [ ] 4.4 Implementar a substituição e a remoção do certificado, preservando metadados históricos e apagando o conteúdo cifrado; verificar com teste
- [ ] 4.5 Vendorizar o componente de assinatura do SERPRO em `app/Support/`, sem alterar `composer.json`, e verificar com `git diff composer.json` que nenhuma dependência foi adicionada
- [ ] 4.6 Envolver o componente em um único ponto de entrada que monta o documento de autorização com o escritório como destinatário, normaliza caracteres Unicode invisíveis antes de assinar e devolve o documento assinado; verificar com teste que a estrutura do XML gerado é a esperada
- [ ] 4.7 Gerar migration, model, factory, policy e resource de `serpro_authorization_terms`, com o documento assinado guardado verbatim, o token, o vencimento e o estado; verificar com rollback
- [ ] 4.8 Implementar a emissão automática do termo quando o certificado é armazenado, submeter ao serviço gratuito de apoio e persistir token e vencimento; verificar com `Http::fake` que a submissão ocorreu e o token foi salvo
- [ ] 4.9 Implementar a renovação diária por reenvio do mesmo documento, tratando a resposta de não-modificado com o token no `ETag`; verificar com teste que o documento armazenado não é re-assinado nem alterado
- [ ] 4.10 Garantir que o documento assinado e o material de assinatura nunca apareçam em resposta de API nem em log, e verificar com teste de negação
- [ ] 4.11 Reportar certificado ausente e termo com validade vencida como ações pertencentes ao escritório, sem pedir que ele assine nada quando o termo está válido; verificar com teste para cada estado

## 5. Procuração e habilitação

- [ ] 5.1 Gerar migration adicionando à `client_ecac_powers_of_attorney` o código de procuração e o estado de integração, ambos nullable, e a tabela de autorização por cliente e família de serviço; verificar rollback
- [ ] 5.2 Atualizar os resources de cliente e de procuração para expor código e estado, sem segredo nem caminho de arquivo; verificar com teste de negação
- [ ] 5.3 Implementar a leitura do estado de procuração a partir do serviço de consulta do provedor, por cliente, sem estabelecer procuração pelo produto; verificar com `Http::fake` que os estados são persistidos
- [ ] 5.4 Implementar a elegibilidade por família de serviço, de modo que procuração de uma família não habilite outra; verificar com teste para procuração válida, expirada e nunca cadastrada
- [ ] 5.5 Implementar a habilitação por Account gravada em `Account.settings`, com escrita restrita a `admin`, preservando execuções e dados ao desligar; verificar com teste para `operador` e `user` recebendo 403

## 6. Sincronização

- [ ] 6.1 Gerar migration, models, factories e resources de `serpro_sync_runs` e `serpro_sync_run_items`, com unicidade em `(run_id, client_id)` e colunas para estado, código do provedor, identificador de resposta, tag e resultado indeterminado; verificar que o índice é criado
- [ ] 6.2 Remover as linhas placeholder de `serpro_monitorings` que só carregam `name` e ligar a tabela a `client_id` com estado e carimbo de origem; verificar com `assertDatabaseCount`
- [ ] 6.3 Criar a tabela e o model de registro de chamadas, com colunas para serviço, versão, caminho, cliente, marcação de cobrança, mensagens e duração; verificar que uma chamada sem `client_id` é aceitável
- [ ] 6.4 Implementar o endpoint de disparo, que cria a execução em `queued` e recusa quando a conexão é inválida, o escritório está desabilitado, o papel não permite ou já existe execução `queued`/`running`; verificar com teste para cada recusa
- [ ] 6.5 Implementar o job de fan-out com `afterCommit`, carrying `accountId` explícito; verificar com `Queue::fake` que um job por cliente elegível foi despachado e nenhum pertence a outra conta
- [ ] 6.6 Implementar o lock por cliente no job, de modo que duas execuções não chamem o mesmo cliente ao mesmo tempo; verificar com teste que a segunda chamada aguarda
- [ ] 6.7 Implementar o job por cliente, idempotente, com `timeout` abaixo do `retry_after`, backoff, re-hidratação de `CurrentTenant` a partir de `accountId` e re-checagem de elegibilidade e de termo dentro de `handle()`; verificar que reentrega atualiza o item em vez de duplicar
- [ ] 6.8 Implementar o timeout como resultado indeterminado, sem retentativa na mesma execução, sem contar como falha do cliente e sem interromper os demais clientes; verificar com teste de fixture simulando o limite do gateway
- [ ] 6.9 Implementar a proibição de retentativa para as classes de falha de dados, permissão, termo e configuração, mantendo-as visíveis como causa a corrigir; verificar com teste para cada classe
- [ ] 6.10 Implementar as transições de estado `queued` → `running` → `completed`/`partial`/`failed` com carimbo de tempo, incluindo o abandono de execução presa em `running`; verificar com teste cada estado terminal
- [ ] 6.11 Implementar as contagens por execução `total`, `synchronized`, `skipped`, `failed` e garantir que somem ao total considerado, com pessoa física fora do total em vez de contada como ignorada; verificar com teste a soma e o caso misto
- [ ] 6.12 Implementar o re-sync que atualiza registros no lugar, preserva estado idêntico e renova o carimbo de origem; verificar com teste que não duplica
- [ ] 6.13 Mapear o primeiro conjunto de serviços — consulta de procuração, anos-calendários de regime, relatório de situação fiscal, mensagens de caixa postal e declarações transmitidas — com versão e caminho vindos da configuração; verificar com teste que cada serviço é chamado com a versão correta

## 7. API de leitura para o Monitoramento

- [ ] 7.1 Implementar a listagem de clientes por obrigação e situação, filtrada no servidor, com escopo de Account e `404` para obrigação ou situação inexistente; verificar com teste de isolamento entre contas
- [ ] 7.2 Implementar a derivação de estado `pending`, `expiring`, `expired` e `regular` a partir do dado sincronizado, com o limite de trinta dias e o caso sem dado suficiente para derivar; verificar com teste unitário para cada estado
- [ ] 7.3 Implementar a marcação de cliente não atendido quando falta registro sincronizado, distinta de `regular` e excluída da contagem de atenção; verificar com teste de resposta
- [ ] 7.4 Implementar a carga de contagem de atenção por obrigação, com zero quando não há o que atender; verificar com teste
- [ ] 7.5 Registrar as rotas novas em `routes/api.php` dentro do grupo `['auth:sanctum', 'tenant']`, sem versionamento, e verificar com `php artisan route:list`
- [ ] 7.6 Cobrir a suíte com `php artisan test --compact` e formatar com `vendor/bin/pint --dirty --format agent`, confirmando que nenhum teste novo e nenhum arquivo existente quebrou

## 8. Frontend — base e navegação

- [ ] 8.1 Remover de `app/utils/monitoringNav.ts` as dez empresas de exemplo e a derivação por aritmética do estado, mantendo o registro de grupos e páginas; verificar com `pnpm typecheck` que nada referencia mais os dados fictícios
- [ ] 8.2 Criar `app/types/serpro.ts` com os tipos de conexão, termo, execução, item, chamada e estado, e `app/composables/useSerpro.ts` no padrão de `useWork`/`useClients`, usando `queryOf`; verificar com `pnpm typecheck`
- [ ] 8.3 Trocar a contagem de atenção por valor do backend e atualizar `app/pages/monitoring/index.vue` para buscar dados reais com estados de carregamento e erro; verificar com `pnpm lint` e `pnpm typecheck`
- [ ] 8.4 Adicionar o grupo de navegação da integração em `monitoringGroups` e conferir os filhos da sidebar em `app/layouts/default.vue`; verificar que o grupo aparece e que a rota resolve sem 404
- [ ] 8.5 Criar as páginas estáticas de conexão, termos e execuções, confirmando que vencem o catch-all `[...slug].vue`; verificar navegando até elas e pelo sidebar

## 9. Frontend — telas

- [ ] 9.1 Construir a tela de conexão com estado da credencial, metadados não secretos, documento configurado, botão de teste de conectividade e controle de habilitação do escritório, com `UForm` e zod no padrão de `settings/index.vue`; verificar `pnpm lint` e `pnpm typecheck`
- [ ] 9.2 Construir a tela de termos por cliente, com envio do documento assinado, estado, vencimento e indicação explícita de que a assinatura é do escritório, sem exibir o documento armazenado; verificar `pnpm lint` e `pnpm typecheck`
- [ ] 9.3 Construir a listagem de execuções com estado, contagens e carimbo de tempo, seguindo a escada de carregamento, vazio e erro de `work/processos.vue`; verificar `pnpm lint` e `pnpm typecheck`
- [ ] 9.4 Construir o detalhe da execução com os itens por cliente, distinguindo não elegível, falhou, pendente e resultado indeterminado, com a ação de re-sincronizar e 403 tratado como aviso degradado; verificar `pnpm lint` e `pnpm typecheck`
- [ ] 9.5 Converter `MonitoringSheet.vue` em lista paginada e filtrada no servidor, usando `DataTableFilter` e `sheetTableUi`, removendo o filtro sobre array local; verificar `pnpm lint` e `pnpm typecheck`
- [ ] 9.6 Tratar carregamento, vazio, vazio após filtro e erro recuperável com ação de tentar novamente, sem apresentar falha como lista vazia; verificar `pnpm lint` e `pnpm typecheck`
- [ ] 9.7 Adicionar testes em `frontend/tests/` para a derivação de estado, o parsing de rota da integração e a formatação de contagem, rodando `node --test tests/`; verificar que todos passam

## 10. Verificação final

- [ ] 10.1 Rodar a suíte completa do backend com `composer test` e `pnpm lint` e `pnpm typecheck` do frontend, a partir do estado limpo
- [ ] 10.2 Confirmar que nenhum segredo, conteúdo de certificado, senha ou documento assinado aparece em resposta de API, log ou teste, revisando as asserções de negação
- [ ] 10.3 Confirmar que o módulo permanece inerte sem credencial configurada, com o teste de conectividade reportando não configurado, o disparo recusando e as telas de monitoramento em estado vazio
- [ ] 10.4 Confirmar que nenhuma chamada é repetida dentro da mesma execução para o mesmo cliente, e que resultado indeterminado não é contado como falha, revisando o log de chamadas de uma execução de teste
