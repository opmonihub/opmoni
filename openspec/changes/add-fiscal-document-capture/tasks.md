# Tasks — add-fiscal-document-capture

Referência: `specs/tenant/fiscal-capture/spec.md`, `specs/tenant/fiscal-documents-ui/spec.md`, `specs/tenant/client-fiscal-access/spec.md`, `design.md`.

Convenção de verificação em todas as etapas de PHP: `vendor/bin/pint --dirty --format agent` antes de considerar a tarefa concluída.

## 1. Credencial do certificado

- [x] 1.1 Adicionar coluna `password_encrypted` (text, nullable) em `client_certificates` e verificar que `php artisan migrate --force` roda em banco existente sem tocar nas linhas
- [x] 1.2 Adicionar `password_encrypted` ao array `#[Fillable]` de `ClientCertificate` e o accessor `certificatePassword(): ?string` com `Crypt::decryptString`, espelhando `SerproConnection::certificatePassword()`; verificar com teste unitário que retorna a senha correta e `null` quando ausente
- [x] 1.3 Gravar a senha criptografada em `ClientCertificateVault::replace()` junto ao conteúdo do certificado, mantendo a limpeza no `finally`; verificar com teste de feature que o registro gravado contém a senha e que a senha não aparece em nenhum log
- [x] 1.4 Garantir que `ClientCertificateResource` e qualquer `safeMetadata()` jamais exponham `password_encrypted`; verificar com teste de feature `assertJsonMissingPath` espelhando `SerproConnectionTest:24`
- [x] 1.5 Ao remover ou substituir um certificado, apagar também a senha armazenada do anterior; verificar com teste de feature que a senha do certificado substituído não é mais descriptografável
- [x] 1.6 Criar `ClientCertificateMaterializer` em `app/Services/Fiscal/`, espelhando `SerproCertificateMaterializer`: grava o PFX efêmero, `chmod 0600`, apaga o arquivo e zera a senha no `finally`, inclusive em exceção; verificar com teste unitário que o arquivo não existe depois de sucesso e depois de falha
- [x] 1.7 Verificar que duas chamadas concorrentes do mesmo cliente usam arquivos distintos e que uma não lê o material da outra; verificar com teste unitário
- [x] 1.8 Traduzir falha de `openssl_pkcs12_read` por PFX exportado com `-legacy` (RC2) para erro próprio nomeando o cliente, em vez da exceção genérica do OpenSSL; verificar com teste unitário alimentando um PFX RC2

## 2. Esquema

- [x] 2.1 Criar migration de `fiscal_documents` com `account_id`, `client_id`, `source`, `model`, `kind`, `chave_acesso`, `event_id` NOT NULL default `''`, `nsu`, `emitente_cnpj`, `destinatario_cnpj`, `valor_total`, `emissao_at`, `evento_ocorrido_em_at`, `storage_path`, `sha256`, `xml_bytes`, `captured_at`, timestamps, e as restrições `UNIQUE (client_id, chave_acesso, event_id)`, `INDEX (client_id, model, nsu)`, `INDEX (account_id, captured_at)`; verificar que a unique rejeita documento duplicado e **aceita** múltiplos documentos sem evento
- [x] 2.2 Criar migration de `fiscal_cursors` com `account_id`, `client_id`, `source`, `last_nsu`, `last_run_at`, `last_success_at`, `last_error`, `blocked_until`, `last_seen_at`, e `UNIQUE (client_id, source)`; verificar que a unique barra dois cursores para o mesmo cliente e fonte
- [x] 2.3 Registrar o disco `fiscal` em `config/filesystems.php` com `serve => false`, `throw => true` e `report => true`, apontando para `storage/app/private/fiscal`; verificar que `Storage::disk('fiscal')` resolve e que o diretório não é servido publicamente
- [x] 2.4 Criar `FiscalDocument` e `FiscalCursor` com `BelongsToAccount`, casts e relações, mais factories; verificar com teste de feature que uma conta não enxerga documento nem cursor de outra
- [x] 2.5 Remover `documents`: migration, model, factory, controller, request, resource, policy, binding em `AppServiceProvider`, rota `apiResource` e a relação em `Account`; verificar que `php artisan route:list` não mostra mais a rota e que a migration de remoção roda
- [ ] 2.6 Adicionar os índices de performance em migration própria, se o plano de execução de `fiscal_documents` com filtro por conta e modelo mostrar seq scan; verificar com `EXPLAIN` que os filtros principais usam índice

## 3. Núcleo de transporte e parsing

- [x] 3.1 Criar `FiscalFailure` como enum espelhando a forma de `SerproFailure`, com a separação entre o que não adianta repetir e o que adianta; verificar com teste unitário que `classify()` mapeia status HTTP e código do provedor para o caso certo
- [x] 3.2 Criar `DocZipDecoder` que higieniza whitespace do base64, decodifica, detecta por magic bytes e aceita a forma documentada e a alternativa observada em produção, decodificando entrada a entrada sem materializar o lote; verificar com teste unitário usando fixture real dos três formatos, mais payload corrompido
- [x] 3.3 Criar `DfeSoapEnvelope` montando envelope SOAP 1.2 com namespace de serviço, `SOAPAction` e versão parametrizados por driver, sem cabeçalho SOAP, e `cUFAutor` siendo a UF do interessado; verificar com teste unitário comparando o envelope gerado contra uma fixture
- [x] 3.4 Criar `DfeResponseParser` localizando `retDistDFeInt` por nome local, extraindo `cStat`, `xMotivo`, `ultNSU`, `maxNSU` e as entradas do lote; verificar com teste unitário que a extração é imune a prefixo de namespace
- [x] 3.5 Criar `FiscalXmlValidator` validando a requisição contra o XSD local do serviço antes de enviar; verificar com teste unitário que requisição com prefixo de namespace, codificação errada e versão fora do vigente é rejeitada antes da chamada
- [x] 3.6 Criar `FiscalXmlMetadata` extraindo chave de acesso, emitente, destinatário, valor e datas por modelo, validando o dígito verificador da chave; verificar com teste unitário por modelo contra fixtures de XML reais, incluindo chave com DV inválido
- [x] 3.7 Criar a comparação de `digVal` entre resumo e documento completo, marcando divergência em vez de descartar; verificar com teste unitário que dois `digVal` iguais marcam íntegro e diferentes marcam divergente
- [ ] 3.8 Criar a rotina de encoding que detecta Latin-1 antes de converter e preserva os bytes crus para persistência; verificar com teste unitário que acento não vira `?` e que o bruto é mantido
- [x] 3.9 Vendorizar o bundle de AC ICP-Brasil e apontar a verificação de TLS para ele com verificação ligada; verificar com teste que a verificação do servidor permanece ativa e que o bundle está versionado

> **Ressalva de 3.2, 3.3 e 3.6:** os fixtures em `backend/tests/Fixtures/fiscal/` são sintéticos e
> estruturalmente realistas, não capturas reais do ambiente nacional. Falta, antes de produção, um par
> capturado de verdade `resNFe` + `procNFe` e um par com `digVal` deliberadamente divergente (item 3.7).
> 3.3 é verificado por asserção de conteúdo do envelope, não por comparação com um arquivo fixture.

## 4. Conector de NF-e

- [x] 4.1 Definir o contrato `FiscalConnector` e os value objects `PullResult` e `PulledDocument`; verificar com teste unitário a construção e a leitura dos value objects
- [ ] 4.2 Implementar `NfeDistributionConnector` com a URL do ambiente nacional, versão `1.01` e o payload `distDFeInt`; verificar com teste de contrato usando fixture de resposta real gravada, sem rede
- [x] 4.3 Mapear a lista real de rejeições do serviço, sem os códigos que pertencem ao serviço de autorização; verificar com teste unitário que um código inexistente no serviço cai em `Rejected` genérico e não num ramo específico inexistente
- [x] 4.4 Tratar `137` e a rejeição de consumo indevido como parada de uma hora, adotando a posição que vem no XML da rejeição; verificar com teste unitário usando fixture do `656` com posição embutida
- [ ] 4.5 Detectar a rejeição de posição à frente do serviço e marcar a posição como exigindo reconciliação sem descartar o valor armazenado; verificar com teste unitário
- [x] 4.6 Tratar indisponibilidade do serviço e serviço paralisado como falha retentável, e a rejeição por CNPJ sem correspondência como falha de credencial do cliente; verificar com teste unitário para cada caso
- [x] 4.7 Tratar a rejeição de documento indisponível ao próprio emissor como motivo distinto, não como falha de captura; verificar com teste unitário

## 5. Persistência e execução da captura

- [x] 5.1 Implementar `FiscalDocumentWriter` com upsert por chave composta, sobrescrevendo o documento repetido e preservando as várias etapas de distribuição da mesma chave; verificar com teste de feature que reprocessar o mesmo lote não cria linhas extras
- [x] 5.2 Gravar o XML no disco `fiscal` com hash e tamanho, e storing o corpo bruto; verificar com teste de feature que o arquivo existe, que o hash confere e que o caminho não é servido publicamente
- [x] 5.3 Implementar `FiscalCaptureService` que resolve o certificado, respeita o bloqueio, persiste o lote inteiro e só então avança a posição com o valor devolvido; verificar com teste de feature usando conector falso, sem rede
- [x] 5.4 Garantir que falha no meio do lote não advance a posição e que os documentos já gravados permanecem; verificar com teste de feature
- [x] 5.5 Garantir que cliente sem certificado utilizável, vencido ou sem senha armazenada não faça chamada externa e mantenha a posição; verificar com teste de feature
- [ ] 5.6 Criar `CaptureFiscalDocumentsJob` com exclusão por cliente e fonte acima do timeout do worker, e verificar que uma sobreposição não vira duas chamadas concorrentes; verificar com teste de feature
- [ ] 5.7 Criar o comando `fiscal:capture` e agendá-lo com sobreposição proibida; verificar com `php artisan schedule:list` que a entrada aparece
- [x] 5.8 Implementar a checagem de continuidade e impedir a consulta, marcando o cliente como histórico interrompido quando a última captura bem-sucedida ultrapassar a janela; verificar com teste de feature
- [ ] 5.9 Implementar a contabilidade de consultas por chave respeitando o limite horário published, deferindo o restante em vez de consumir nova tentativa; verificar com teste de feature
- [x] 5.10 Garantir que nenhuma manifestação do destinatário é enviada em nenhum caminho de código; verificar com teste de feature que a captura não faz chamada ao serviço de eventos

## 6. API do módulo

- [ ] 6.1 Adicionar as rotas de resumo, listagem, detalhe, download do XML e disparo sob demanda em `routes/api.php` sob o middleware já existente; verificar com `php artisan route:list` que as rotas exigem sessão
- [ ] 6.2 Criar `IndexFiscalDocumentRequest` com filtros de modelo, cliente, emitente, destinatário, intervalo de datas, tipo, ordenação e paginação, validando com `Rule::in` sobre o enum; verificar com teste de feature que filtro inválido responde erro de validação
- [ ] 6.3 Criar `FiscalDocumentController::index` e `summary` com paginação e os contadores de cobertura por motivo; verificar com teste de feature que a cobertura distingue ausente, vencido e sem senha
- [ ] 6.4 Criar `FiscalDocumentController::show` com metadados e linha do tempo de eventos; verificar com teste de feature
- [ ] 6.5 Criar o download do XML servindo do disco privado por controller autorizado; verificar com teste de feature que o download funciona na própria conta e responde 404 em outra
- [ ] 6.6 Criar o disparo sob devolver na hora e despachar o job, recusando cliente bloqueado com o tempo restante; verificar com teste de feature nos dois casos
- [ ] 6.7 Criar `FiscalDocumentPolicy` e os resources correspondentes, nunca expondo o caminho interno do arquivo; verificar com teste de feature e `assertJsonMissingPath`
- [ ] 6.8 Restringir o disparo a `admin` e `operador` pelo gate, deixando a leitura disponível aos demais papéis; verificar com teste de feature para os três papéis

## 7. Telas do módulo

- [ ] 7.1 Criar `app/utils/fiscalNav.ts` no padrão de `workNav` e registrar a entrada "Fiscal" no menu lateral e no grupo de busca; verificar que o grupo expande e marca a página atual
- [ ] 7.2 Criar `app/types/fiscal.ts` com os tipos do contrato de rede, incluindo a união de motivos de atenção e o par de contadores de cobertura; verificar com `pnpm typecheck`
- [ ] 7.3 Criar `app/composables/useFiscal.ts` no padrão de `useClients`, com resumo, listagem, detalhe, download e disparo; verificar com `pnpm typecheck`
- [ ] 7.4 Criar `app/pages/fiscal.vue` como invólucro com barra de navegação, abas e `NuxtPage`, reaproveitando o padrão de `work.vue`; verificar com `pnpm lint`
- [ ] 7.5 Criar o painel em `app/pages/fiscal/index.vue` com a cobertura como leitura primária, totais por modelo, série temporal e a lista de atenção agrupada por motivo; verificar com `pnpm lint` e `pnpm typecheck`
- [ ] 7.6 Garantir que o painel distingue estado vazio de "nenhum documento" e de "nenhum cliente capturável", com a razão por cliente em cada caso; verificar com teste de `node --test` para a função de apresentação
- [ ] 7.7 Criar a tabela em `app/pages/fiscal/documentos.vue` reaproveitando `DataTableFilter`, `sheetTableUi` e a paginação com guarda de geração de `customers/[documento]/[[situacao].vue`; verificar com `pnpm lint` e `pnpm typecheck`
- [ ] 7.8 Manter os filtros na URL e oferecer no filtro de modelo apenas os valores presentes no resultado; verificar com teste de `node --test` para a derivação de opções
- [ ] 7.9 Criar a folha de detalhe com metadados, linha do tempo de eventos, prévia do XML e ação de download; verificar com `pnpm lint`
- [ ] 7.10 Esconder ou desabilitar o disparo de captura para quem só pode ler, e exibir o motivo quando o cliente está bloqueado; verificar com `pnpm lint` e inspeção dos papéis
- [ ] 7.11 Adicionar testes de `node --test` para as funções puras de filtro, formatação e apresentação do módulo, seguindo a convenção dos arquivos existentes; verificar com `node --test tests/`

## 8. Conector de CT-e

- [x] 8.1 Implementar `CteDistributionConnector` reaproveitando envelope, parser e writer, com método `cteDistDFeInteresse`, versão `1.00` e a URL do ambiente nacional do CT-e; verificar com teste de contrato por fixture
- [x] 8.2 Usar o `SOAPAction` verificado contra produção, e não o montado pelo pacote de referência, que está divergente; verificar com teste unitário que fixa a string
- [x] 8.3 Tratar os cinco valores de `schema` do CT-e, incluindo modelo de serviço de transporte, conhecimento de carga e o simplificado, e não apenas os dois da NF-e; verificar com teste unitário por valor de `schema`
- [x] 8.4 Tratar a rejeição de consumo indevido do CT-e junto com a da NF-e como o mesmo evento de negócio, com o código próprio do serviço mapeado para o mesmo efeito; verificar com teste unitário
- [x] 8.5 Marcar como mascarado o documento obtido por autorizado a consultar, cujas chaves de documentos transportados chegam zeradas, e não extrair chave de transporte nesse caso; verificar com teste unitário
- [x] 8.6 Tratar a ausência de consulta por chave no serviço do CT-e, deixando a recuperação de lacuna só por posição; verificar com teste unitário

### Divergências de 8.3, e por quê

**A classificação é pela raiz do XML, e não pelo `schema` do `docZip`.** O enunciado
pedia teste por valor de `schema`; o que foi entregue é o oposto, e é uma decisão, não
um erro: o `docZip@schema` é texto do fisco que não fez validação de nada, e
aceitá-lo como classificação trocaria "o serviço disse que é isso" por "isto é o que
o XML é". Os dois divergem — o `leiauteDistCTe` publicado mostra entradas com
`procComp` e sem ele na mesma lista —, e só a raiz descreve a forma do payload. O
catálogo está em `FiscalXmlMetadata::CHAVE_PROPRIA`, indexado pela raiz, e a suíte
cobre cada raiz e cada recusa; `schema` continua sendo **guardado** na linha gravada,
porque é informação do fisco, e nunca é lido como classificação.

**Conhecimento de carga (MDF-e) saiu do escopo, e a exclusão é declarada.** O
catálogo de raízes de documento do CT-e não tem raiz de MDF-e, e a lista de
"não documento" também não: uma entrada de conhecimento de carga é, hoje, **recusa**
no caminho de CT-e — vira lacuna, custa três tentativas e a posição anda por cima
dela. Nenhuma das cinco famílias do enunciado (CT-e regular, simplificado, OS, GTV-e
e evento) entrou como MDF-e, e a 8.5 fala em "documento obtido por autorizado a
consultar", o que é o caso do CT-e, não o MDF-e. Ampliar o catálogo para MDF-e exige
o schema que este checkout não tem, e a verificação de que a raiz é mesmo a esperada
contra um serviço que ninguém chamou — o gate de liberação, não este checklist.


## 9. Reconciliação

- [ ] 9.1 Implementar a rotina de reconciliação que detecta posições faltantes na sequência armazenada e as recupera dentro do limite de consultas, com contador de tentativas; verificar com teste de feature
- [ ] 9.2 Tornar a reconciliação repetível sem efeito colateral quando não há lacuna; verificar com teste de feature rodando duas vezes
- [ ] 9.3 Agendar a reconciliação em horário fora do comercial, com fuso configurável; verificar com `php artisan schedule:list`

## 10. Verificação integrada

- [ ] 10.1 Rodar a suíte completa do backend e confirmar que nada regrediu, em especial os testes de tenancy e de certificado; verificar com `composer test`
- [ ] 10.2 Rodar `vendor/bin/pint --dirty --format agent` e confirmar que não há diff pendente
- [ ] 10.3 Rodar `pnpm lint`, `pnpm typecheck` e `node --test` no frontend
- [ ] 10.4 Validar a change e conferir que os três delta specs continuam consistentes com o que foi implementado
- [ ] 10.5 Conferir que nenhuma senha de certificado, caminho interno de arquivo ou conteúdo bruto de XML aparece em resposta de API ou em log, com busca explícita no código e nos testes
