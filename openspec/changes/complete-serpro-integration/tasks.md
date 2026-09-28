# Tarefas

Continuation de `archive/2026-09-27-add-integra-contador-sync`, que entregou a camada de
transporte (itens 1.1, 1.3, 2.1, 2.2, 2.3, 2.4, 2.6, 3.1, 3.2, 3.5) e o frontend de
monitoramento (seções 8 e 9) e foi arquivado com `--skip-specs` justamente porque as
seções 4 a 7 nunca foram implementadas.

A numeração original foi preservada de propósito: `plan-01-transporte.md` e
`plan-02-monitoramento.md` referenciam estas seções, e renumerar quebraria essa
rastreabilidade. Não existem as seções 8 e 9 aqui porque foram concluídas.

**A ordem importa.** A 6.2 é destrutiva — remove o CRUD placeholder de `monitorings`, a
policy e a chave de plano, o que quebra três arquivos de teste que hoje afirmam o
comportamento antigo. E a 7.1 depende de `serpro_monitorings` já ter `client_id` e estado,
então 6.2 vem antes de 7.x. A 6.13 e a 7.3 são o mesmo trabalho: a classificação
`direct`/`derived`/`unavailable`/`extinct` precisa existir no mapa de configuração para ser
exposta na resposta.

## 1. Configuração e segredos de plataforma

- [ ] 1.2 Verificar o tipo da coluna `tax_id` em `clients` e a regra `ValidCnpj`, e ajustar para aceitar CNPJ alfanumérico como texto em todas as camadas, conforme exigido pelo SERPRO; verificar com teste que um CNPJ alfanumérico válido é aceito e um inválido recusado

- [ ] 1.4 Gerar migration, model, factory, policy e resource de `SerproConnection`, com segredo e certificado passando por `Crypt::encryptString`; verificar com teste que a coluna gravada não contém o valor em claro

- [ ] 1.5 Implementar no model um accessor que devolve apenas metadados não secretos e validar que o documento configurado bate com o do certificado antes de qualquer chamada; verificar com teste para documento divergente

## 2. Cliente HTTP, envelope e autenticação

- [ ] 2.5 Implementar a decodificação da resposta com `dados` decodificado duas vezes, tolerando `tipo` como inteiro ou string e envelope nulo em erro; verificar com testes para sucesso, erro de aplicação e erro de gateway

- [ ] 2.7 Definir os enums de conexão, execução, item e procuração em `app/Enums/` com chaves TitleCase; verificar que são usados por models e resources

- [ ] 2.8 Implementar o teste de conectividade que exercita a autenticação sem tocar em cliente, distinguindo credencial ausente, credencial inválida e provedor indisponível; verificar com `Http::fake` para os quatro desfechos

## 3. Fixtures e contrato contra o trial

- [ ] 3.3 Gravar como fixture, a partir das chamadas reais ao trial, as respostas de `REGIMEAPURACAO`, `PGDASD`, `DTE` e `SITFIS`, incluindo o envelope completo e o `dados` decodificado; verificar que as fixtures contêm o payload documentado

- [ ] 3.4 Rodar a suíte determinística apenas com as fixtures, sem rede, e verificar que ela cobre envelope, decodificação dupla, `mensagens` e os dois formatos de erro

## 4. Certificado do escritório e termo de autorização

- [ ] 4.1 Extrair de `ClientCertificateVault` a parte de leitura, cifragem e higiene de senha para uma unidade compartilhada, deixando o vault de cliente como chamador enxuto, e verificar que os testes existentes de certificado de cliente continuam passando sem alteração de comportamento

- [ ] 4.2 Gerar migration, model, factory, policy e resource de `account_certificates`, com `certificate_encrypted` e `password_encrypted` em colunas do banco, metadados não secretos e **nenhuma coluna de caminho** — o disco do container é efêmero em produção; verificar com `php artisan migrate --force` e `migrate:rollback` que nada existente quebra

- [ ] 4.3 Implementar o envio do certificado do escritório reaproveitando a unidade compartilhada, com validação de senha, cifragem em `Crypt::encryptString(base64_encode($bytes))` e remoção em `finally`; verificar com teste que a senha errada não grava registro

- [ ] 4.4 Implementar a substituição e a remoção do certificado, preservando metadados históricos e apagando o conteúdo cifrado; verificar com teste

- [ ] 4.5 Isolar em `app/Support/` a rotina de assinatura do componente publicado pelo SERPRO, portando apenas a sequência XMLDSig de `assinar()` e registrando no arquivo a URL de origem, a versão, o SHA-256 do ZIP e a licença MIT, sem `include` do arquivo oficial e sem alterar `composer.json`; verificar com `git diff composer.json` que nenhuma dependência foi adicionada e com teste que nenhuma das funções globais do modelo existe na aplicação

- [ ] 4.6 Montar o documento de autorização em um único ponto de entrada, com o escritório como destinatário, normalizando caracteres Unicode invisíveis **antes** de assinar e passando os bytes à rotina isolada, que devolve o documento assinado sem reserializar; verificar com teste que a estrutura XMLDSig é a esperada e que a assinatura confere com a chave pública do certificado. **Produzir também `SerproTermSigner::formatDigest()`**, que pertence a este item e não ao 4.8 porque o construtor do documento é o que produz o modelo: ele devolve o SHA-256 do template canonicalizado, com todo valor por escritório e por termo trocado por placeholder fixo, **concatenado com as constantes de formato** — o comprimento do período de vigência, o algoritmo de canonicalização e a regra de normalização de Unicode —, em ordem estável, para que dois escritórios hasheiem igual. As constantes entram porque o template sozinho não as vê: o período não aparece no documento e a normalização é transformação do documento, não marca no template, então 30→60 dias e a remoção da normalização deixariam o digest intacto. Verificar com teste que mudar o template **ou** qualquer constante muda o digest

- [ ] 4.6a **Depende de teste de contrato real com o provedor, ainda não feito, e bloqueia a emissão.** Confirmar com o ambiente de demonstração que o termo é aceito, que os papéis do documento são os que o gateway espera, e que o reenvio de um termo válido responde `304` com o token no `ETag`. Dos três pontos que parecem erro no modelo de referência, **um é corrigido** — o espaço no nome `finalidade `, que **não sobrevive ao documento assinado** (o espaço existe na string que o modelo serializa; o que não acontece é ele chegar ao nome do elemento): `createElement('finalidade ')` lança `DOMException`, o `SimpleXMLElement` do modelo emite `<finalidade  texto=…/>` cujo `nodeName` já é `finalidade` sem o espaço, e o `loadXML`/`saveXML` da própria assinatura o apaga — **e dois são mantidos verbatim por decisão**: a vigência `+30 days` e a canonicalização exclusiva do digest contra a `Reference` inclusiva, porque a documentação do termo do provedor responde `500` e não publica XSD, então o modelo é a única autoridade. Nenhum teste local prova aceitação; emitir termo sem isso é emitir termo sem prova de que o provedor o aceita

- [ ] 4.7 Gerar migration, model, factory, policy e resource de `serpro_authorization_terms`, com o documento assinado guardado verbatim, o token, o vencimento e o estado; verificar com rollback. **As colunas `term_format_sha256` e `term_format_proven_at` não são deste item**: elas alteram `serpro_connections`, que já existe, e pertencem ao 4.8, que é quem implementa o gate que as lê — juntá-las à migration de uma tabela nova misturia um `ALTER` de tabela existente numa migration de criação e confundiria a ordem de rollback

- [ ] 4.8 Implementar a emissão automática do termo quando o certificado é armazenado **e** o teste de contrato do 4.6a tiver provado que o provedor aceita o documento, submeter ao serviço gratuito de apoio e persistir token e vencimento. O predicado do gate **não é uma flag deste task** e já está decidido: vive em `serpro_connections.term_format_sha256` e `term_format_proven_at`, e a emissão só acontece se `term_format_proven_at` não for nulo **e** `term_format_sha256` for igual a `SerproTermSigner::formatDigest()` (4.6). **Esta task produz as duas colunas**, em migration aditiva de `serpro_connections`, fora do `#[Fillable]` do modelo, e o comando `serpro:record-term-proof`, que é o único escritor sancionado e a exceção declarada à regra da spec — regra que é "nenhuma request, nenhum job e nenhum scheduler escreve essas colunas", e não "nenhum código": um comando artisan **é** código de aplicação, então uma regra mais larga obrigaria a exceptuar de dentro dela justamente a coisa que a regra proíbe. O comando grava as duas colunas juntas, com o digest que `formatDigest()` calcula e não com um valor digitado pelo operador, e registra entrada de auditoria com esse digest. Verificar com `Http::fake` que a submissão ocorreu e o token foi salvo, e com teste de negação que nada é submetido sem a prova — inclusive quando o digest gravado deixou de bater com o modelo atual, e inclusive quando o período, o algoritmo ou a regra de normalização mudaram

- [ ] 4.9 Implementar a renovação diária por reenvio do mesmo documento, tratando a resposta de não-modificado com o token no `ETag`; verificar com teste que o documento armazenado não é re-assinado nem alterado

- [ ] 4.10 Garantir que o documento assinado e o material de assinatura nunca apareçam em resposta de API nem em log, e verificar com teste de negação

- [ ] 4.11 Reportar certificado ausente e termo com validade vencida como ações pertencentes ao escritório, sem pedir que ele assine nada quando o termo está válido; verificar com teste para cada estado

## 5. Procuração e habilitação

- [ ] 5.1 Gerar migration adicionando à `client_ecac_powers_of_attorney` o código de procuração e o estado de integração, ambos nullable, e a tabela de autorização por cliente e família de serviço; registrar que o código `00146` é compartilhado por `PGDASD` e `DEFIS`, de modo que as duas famílias não são autorizáveis de forma independente; verificar rollback

- [ ] 5.2 Atualizar os resources de cliente e de procuração para expor código e estado, sem segredo nem caminho de arquivo; verificar com teste de negação

- [ ] 5.3 Implementar a leitura do estado de procuração a partir do serviço de consulta do provedor, por cliente, sem estabelecer procuração pelo produto; verificar com `Http::fake` que os estados são persistidos

- [ ] 5.4 Implementar a elegibilidade por família de serviço, de modo que procuração de uma família não habilite outra; verificar com teste para procuração válida, expirada e nunca cadastrada

- [ ] 5.5 Implementar a habilitação por Account gravada em `Account.settings`, com escrita restrita a `admin`, preservando execuções e dados ao desligar; verificar com teste para `operador` e `user` recebendo 403

## 6. Sincronização

- [ ] 6.1 Gerar migration, models, factories e resources de `serpro_sync_runs` e `serpro_sync_run_items`, com unicidade em `(run_id, client_id)` e colunas para estado, código do provedor, identificador de resposta, tag e resultado indeterminado; verificar que o índice é criado

- [ ] 6.2 Remover as linhas placeholder de `serpro_monitorings` que só carregam `name`, ligar a tabela a `client_id` com estado e carimbo de origem, e remover junto o CRUD, a policy e a chave `'monitorings'` do `PlanLimits`, que passam a contar "cliente × obrigação" e não têm consumidor — o frontend nunca chamou `/api/monitorings`; verificar com `assertDatabaseCount` e com rota:list que nada mais referencia a chave

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

- [ ] 6.13 Mapear o primeiro conjunto de serviços de leitura, com a versão e o caminho vindos da configuração e cada entrada classificada como `direct`, `derived`, `unavailable` ou `extinct` conforme o catálogo do SERPRO: `REGIMEAPURACAO/CONSULTAROPCAOREGIME103` (`00060`, traz `dataHoraOpcao`, ao contrário do `102`), `PGDASD/CONSDECLARACAO13` (`00146`), `DEFIS/CONSDECLARACAO142` (`00146`, compartilhado), `DCTFWEB/CONSXMLDECLARACAO38` (`00103`, única leitura estruturada de FGTS do catálogo), `SITFIS/RELATORIOSITFIS92` (`00002`, base das duas projeções Relatório e Certidões), `PAGTOWEB/PAGAMENTOS71` (`00004`), `CAIXAPOSTAL/MSGCONTRIBUINTE61` (`00006`, base do filtro de assunto para DET e FGTS), `DTE/CONSULTASITUACAODTE111` (`00050`) e `PROCURACOES/OBTERPROCURACAO41` (sem procuração); registrar `PGFN` e `Declarações › FGTS` como `unavailable` e `DIRF` como `extinct`, pelos fatos a partir de 1º/1/2025; verificar com teste que as dezenove entradas do registro resolvem

## 7. API de leitura para o Monitoramento

- [ ] 7.1 Implementar a listagem de clientes por obrigação, filtrada no servidor, com escopo de Account e `404` para obrigação inexistente; a resposta traz `total` igual à soma de `em_dia`, `processando`, `pendencias` e `atencao`, mais `encerrado` fora da partição, e a lista de causas com código e contagem; verificar com teste de isolamento entre contas e de que a soma fecha em todas as obrigações

- [ ] 7.2 Implementar a derivação dos contadores e das situações de linha a partir do dado sincronizado, com o limite de trinta dias, o marcador de pendência do provedor e o `encerrado` fora dos quatro; eliminar pessoa física do total e das listas, porque a integração só age para pessoa jurídica; verificar com teste unitário para cada balde

- [ ] 7.3 Implementar a classificação de cada obrigação em `direct`, `derived`, `unavailable` ou `extinct` a partir do mapa de configuração versionado, expondo-a na resposta para que a tela não apresente obrigação sem serviço como pendência de cliente; `unavailable` e `extinct` não produzem contador nem linha; verificar com teste de que a soma dos contadores fecha e de que nenhuma obrigação não servida gera linha

- [ ] 7.4 Implementar a associação de clientes a uma obrigação — somente pessoa jurídica e somente quem ainda não está associado, respondendo quantos foram associados e quantos já estavam —, recusada para `user` e **sem disparar execução**; verificar com teste que a associação não cria execução e que `user` recebe 403

- [ ] 7.5 Registrar as rotas novas em `routes/api.php` dentro do grupo `['auth:sanctum', 'tenant']`, sem versionamento, e verificar com `php artisan route:list`

- [ ] 7.6 Cobrir a suíte com `php artisan test --compact` e formatar com `vendor/bin/pint --dirty --format agent`, confirmando que nenhum teste novo e nenhum arquivo existente quebrou

## 10. Verificação final

- [ ] 10.1 Rodar a suíte completa do backend com `composer test` e `pnpm lint` e `pnpm typecheck` do frontend, a partir do estado limpo

- [ ] 10.2 Confirmar que nenhum segredo, conteúdo de certificado, senha ou documento assinado aparece em resposta de API, log ou teste, revisando as asserções de negação

- [ ] 10.3 Confirmar que o módulo permanece inerte sem credencial configurada, com o teste de conectividade reportando não configurado, o disparo recusando e as telas de monitoramento em estado vazio

- [ ] 10.4 Confirmar que nenhuma chamada é repetida dentro da mesma execução para o mesmo cliente, e que resultado indeterminado não é contado como falha, revisando o log de chamadas de uma execução de teste

- [ ] 10.5 Confirmar que as dezenove obrigações do registro têm fonte classificada e que nenhuma se apresenta como pendência de cliente quando é `unavailable` ou `extinct`, e que a aritmética dos contadores fecha em todas elas
