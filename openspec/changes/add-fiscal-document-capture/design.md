## Context

Ver `proposal.md` para a motivação. O que segue é o estado atual que obriga as decisões abaixo.

**Credencial inutilizável.** `ClientCertificateVault::replace()` valida o PFX com `openssl_pkcs12_read` e descarta a senha no `finally`; o controller também. `client_certificates` tem o arquivo criptografado mas nenhuma senha. `SerproConnection` faz o contrário — guarda `certificate_password_encrypted` e expõe `certificatePassword()`. Esse é o precedente a espelhar.

**Infraestrutura de certificado já pronta.** `SerproCertificateMaterializer` grava o PKCS#12 num arquivo efêmero, aplica `chmod 0600`, e apaga tudo no `finally` zerando a senha com `str_repeat("\0", …)`. A limitação real do `libcurl` — `CURLOPT_SSLCERT` só aceita caminho — já foi resolvida e o padrão está no repo.

**Fila com janelas conflitantes.** `docker/queue-entrypoint.sh:63` roda `queue:work --sleep=3 --tries=3 --timeout=120 --max-time=3600` contra `QUEUE_CONNECTION=redis` com `retry_after=90`. O change `add-integra-contador-sync` já registra esse descompasso como risco conhecido.

**Taxonomia de falha estabelecida.** `SerproFailure` com `SerproException::classify(status, providerCode)` e `DoNotRetry`/`Upstream` já dão a forma. `SerproClient` faz 401 → `tokens->forget()` → uma retry.

**Frontend com padrões maduros.** `app/pages/work/clientes.vue` e `customers/[documento]/[[situacao].vue` já resolvem paginação com guarda de geração, filtros facetados, seleção em massa e export. `app/components/data-table/` tem o motor de filtro puro. Reaproveitar, não reescrever.

**Scaffolding a remover.** `documents` tem `Route::apiResource`, controller, request, resource, policy, factory e binding em `AppServiceProvider:72`, mas nenhum consumidor no frontend. É resíduo do template.

**Achados de pesquisa que anulam premissas** (ver `specs/tenant/fiscal-capture/spec.md` para o comportamento resultante):

- A requisição de Distribuição DF-e **não é assinada**. O XSD tem sequência fechada e rejeita assinatura injetada com `215`. O WS não tem grupo de regras de assinatura. Autenticação é mTLS.
- NFC-e (modelo 65) **não chega** por esse serviço — regra H12 rejeita `mod` ≠ 55.
- O Integra Contador do SERPRO **não tem** serviço de documento fiscal: 119 serviços enumerados, zero.
- A rejeição de consumo indevido **devolve a posição correta** dentro do XML desde a versão 1.14 da NT.
- Parar de sincronizar por mais de 60 dias **interrompe a geração de posições sem retroativa**.
- Documentos emitidos pelo próprio cliente nunca são distribuídos.

## Goals / Non-Goals

**Goals:**

- Um cliente e uma fonte por job, com posição persistida e avançada de forma que uma interrupção só perca trabalho, nunca documento.
- Reprocessamento seguro por construção: a mesma posição capturada duas vezes sobrescreve, não duplica.
- Rejeição do fisco como estado consultável, não como exceção.
- Cobertura da carteira legível antes de existir um único documento.
- Nenhuma dependência de runtime nova.

**Non-Goals:**

- Emissão de qualquer documento fiscal.
- Manifestação do destinatário, em qualquer modalidade.
- Geração de DANFE ou DANFSe.
- NFC-e e NFS-e padrão nacional — ver Open Questions.
- Validar a assinatura digital dos documentos recebidos.

## Decisions

### 1. Nenhuma assinatura digital, nenhuma dependência nova

A requisição `distDFeInt` não é assinada e o XSD a rejeita se for. A autenticação é o certificado no transporte. Portanto não existe XMLDSig a implementar.

**Alternativas:** `nfephp-org/sped-common` traz `Signer`, mas também `SoapCurl` que **desliga** `CURLOPT_SSL_VERIFYPEER` e `CURLOPT_SSL_VERIFYHOST` por padrão — replicar isso seria introduzir uma falha de segurança junto com a biblioteca. `robrichards/xmlseclibs` tem **CVE-2025-66578** (bypass de validação de assinatura por erro de canonicalização, corrigido em 3.1.4) e a 4.0.0, de agosto/2026, é uma reescrita com breaking changes em toda a superfície. `xmlsec1` exigiria um pacote de sistema e um template embutido no documento.

Verificado empiricamente que a canonicalização do lado do PHP é uma chamada de método que a libxml2 já faz, e que implementações manual, `xmlseclibs` e `xmlsec1` produzem digest byte-idêntico — ou seja, a superfície realmente necessária é pequena caso venha a ser preciso assinar.

**Se surgir a necessidade de assinar** (emissão, manifestação, inutilização): o XSD `xmldsig-core-schema_v1.01.xsd` **fixa** `rsa-sha1`, `sha1` e canonicalização inclusiva sem comentários; SHA-256 é rejeitado pelo schema. `Reference/@URI` tem `minLength=2`, então assinatura de documento inteiro com URI vazia é inválida. `KeyInfo/X509Data` só aceita um `X509Certificate`. `preserveWhiteSpace = false` é obrigatório ou o digest muda.

### 2. Verificação de TLS permanece ligada, com bundle de AC versionado

`sped-common` desliga a verificação do servidor por padrão porque many SEFAZ environments use roots que não estão no trust store. Não replicar isso.

**Decisão:** vendorizar o bundle ICP-Brasil e apontar `CURLOPT_CAINFO` para ele, com `verify => true` explícito. Sem isso a integração aceita certificado de qualquer autoridade — e o certificado do cliente é o que autentica a chamada, o que faz uma verificação frouxa ser imediatamente perigosa e não apenas antipática.

### 3. Uma interface de conector, uma tabela de documentos

```php
interface FiscalConnector
{
    public function source(): FiscalSource;
    public function pull(Client $client, int $fromNsu, int $limit): PullResult;
    public function fetchByChave(Client $client, string $chave): ?PulledDocument;
}
```

Um conector fala com **um** serviço e faz o parse do envelope **dele**. Não escreve no banco. `FiscalDocumentWriter` é o único caminho de escrita, o que faz painel e tabela serem escritos uma vez.

**Alternativas:** uma tabela por tipo foi descartada — três migrations quase idênticas, três conjuntos de relações, e a consulta da tabela vira uma união que degrada com filtro e paginação. Um tipo primeiro foi descartado porque deixa o schema adivinhado e a reescrita depois do CT-e seria trabalho real.

**O CT-e quase não custa.** O payload é o mesmo `distDFeInt` (mesmo nome, sem renomear), a resposta é `retDistDFeInt` com a mesma estrutura, e `docZip` é idêntico. O que muda: método SOAP `cteDistDFeInteresse`, versão `1.00` em vez de `1.01`, ausência de `docEvento` e de consulta por chave, e **cinco** valores de `schema` em vez de dois. O envelope, o parser e o writer são compartilhados.

⚠️ `sped-cte` monta um `SOAPAction` divergente do aceito pelo servidor — bug conhecido da biblioteca. Usar o valor verificado em produção por terceiros, não o do pacote.

### 4. Identidade por chave de acesso, com `event_id` não-nulo defaultando a string vazia

Restrição única em `(client_id, chave_acesso, event_id)`.

`event_id` **não pode** ser nullable. No Postgres `NULL != NULL`, então uma coluna nullable deixaria passar quantas duplicatas de documento comum quisesse — silenciosamente, porque todos os testes de unicidade com valor presente continuariam passando. Documento comum tem zero eventos; `'0'` é o valor neutro.

A identidade do documento é a chave de 44 dígitos, não série e número, e o dígito verificador é módulo 11. Validar o DV na entrada é o parse mais barato que detecta a classe inteira de corrupção de identidade.

Um mesmo documento chega em posições diferentes conforme o estágio: resumo na posição A, documento completo na B, evento na C. São três registros de distribuição, um documento. A chave composta preserva os três.

### 5. Posição só avança depois do lote persistido, e nunca é incrementada

`ultNSU` é sempre o valor devolvido pela resposta. Nunca `+= 1` — existe issue real do NFePHP em que o exemplo oficial tinha um laço que não avançava.

Gravar a posição antes dos documentos inverte a ordem e perde documento silenciosamente numa interrupção. Com a gravação primeiro, a interrupção só repete trabalho — e a repetição é inofensiva por causa da decisão 4.

O `656` devolve a posição correta dentro do XML desde a v1.14 da NT. Adotá-la é a alavanca de recuperação que o fisco oferece; zerar o cursor é perder a informação que ele está entregando.

### 5b. Uma posição que esgotou as tentativas é abandonada, e abandonada em voz alta

`fiscal_reconcile_max_attempts` tentativas separadas por uma hora encerram a reconciliação daquela posição: passar disso seria uma consulta por noite para o fisco responder "não há documento nesta posição", e a resposta não muda porque a posição é imutável e cresce. A linha continua em `fiscal_gaps` com as tentativas e a última consulta — é o histórico do que o fisco respondeu — e a captura volta a andar, porque parar de consultar não pode virar parar de capturar.

A consequência é que existe um ponto do módulo em que um documento que o fisco entregou é descartado de vez. Sem marca, o cliente volta a parecer saudável no dia seguinte: cursor normal, `last_error` nulo, documento chegando todo dia. A prova ficaria numa linha de aviso e numa linha de `fiscal_gaps` que nada consulta — e nenhum dos dois é estado que um painel consiga mostrar.

Por isso a liberação grava o token fixo `gap_abandoned` em `last_error`, no mesmo vocabulário de `certificate_reupload` e `blocked_consumption`. A coluna vira o vocabulário de classificação do cursor: quatro tokens estáveis, e nenhum texto de terceiro dentro dela. **`fiscal_gaps` não ganha leitor neste change** — leitor por conta é da API e da tela, e até lá o token é o que torna o estado nomeável. Quem construir a lista de atenção vai precisar conhecer este terceiro token, e é nele que a lista se apoia.

### 6. Bloqueio como coluna, com semântica de "recomeçar a contagem"

`blocked_until` em `fiscal_cursors`.

A NT é explícita: retomar antes de completar uma hora **zera o relógio e a contagem recomeça**. Backoff de cinco minutos não desbloqueia nunca — é um laço eterno documentado em fóruns. A única saída é parada absoluta, e a parada precisa sobreviver entre execuções, o que significa coluna e não variável de processo.

### 7. Uma execução por cliente e fonte

`docker/queue-entrypoint.sh:63` dá `--timeout=120` contra `retry_after=90` do redis. Um lote de 50 documentos é de 1 a 3 MB, com mTLS e latência de rede. Um job por carteira estoura a janela duas vezes.

Além disso, consultas paralelas ao mesmo CNPJ são classificadas como uso indevido pela NT. A serialização por cliente é um requisito do serviço, não uma preferência de implementação.

A chave está em `Cache::add()` por `client:fiscal:{id}:{source}`, com expiração acima do timeout, para que uma sobreposição não vire duas chamadas concorrentes.

### 8. Integridade por comparação de `digVal`, sem cripto

O resumo traz `digVal`. O documento completo traz `protNFe/infProt/digVal`. Dois SHA-1 base64 batendo provam que o XML completo é o que o ambiente nacional catalogou.

Custo zero de código de cripto, e cobre a classe real de corrupção — resposta truncada, payload misturado, documento trocado. A verificação de assinatura completa fica como verificação pontual, sob demanda, e não no caminho quente.

### 9. Disco privado para o XML, não coluna no banco

Disco `fiscal` em `storage/app/private/fiscal`, `serve => false`, espelhando `certificates`.

XML fiscal carrega dado de terceiros e `infCpl` historicamente carrega dado sensível. Estático é unacceptable. O banco guarda metadados, hash e ponteiro.

**Alternativa:** `bytea` comprimido no Postgres seria mais simples de desenvolver e aceitável em volume baixo. Perde backup trivial, e a separação metadado/payload é o que permite reindexar sem reescrever tabela.

### 10. `DOMDocument` para todo parse, nunca `simplexml_load_string`

`simplexml_load_string` não achata CDATA — o elemento vira objeto, não string, e `print_r` mostra vazio. Também engole erros de parse devolvendo um `SimpleXMLElement` vazio que é falsy mas não `false`, e não tem `LIBXML_NOBLANKS`.

`DOMDocument` com `preserveWhiteSpace = false` e erros internos capturados dá o detalhe que um payload de fisco exige. Em encoding: o serviço de NFS-e nacional já devolveu Latin-1adekando o próprio manual exige UTF-8, e o serviço de NF-e às vezes emite byte inválido porque muitas SEFAZ rodam Windows. A regra é **guardar os bytes crus e só normalizar na leitura**, nunca antes de persistir.

### 11. Rejeição como estado, espelhando a forma de `SerproFailure`

`FiscalFailure` com as mesmas duas families: algo que não adianta repetir (`NoCertificate`, `NotInterested`, `Rejected`, `CursorAhead`, `Unauthorized`) e algo que adianta (`Upstream`, `Blocked`).

O código importa duas vezes aqui: a lista de rejeições do WS de distribuição é **bem menor** que a tabela geral de NF-e, e códigos como `297` (assinatura) e `539` (duplicidade) **nunca** aparecem — pertencem ao serviço de autorização. Implementar a tabela geral seria escrever ramos inalcançáveis.

A consequência de UI: indisponibilidade do fisco não é toast, é linha na lista de atenção do painel. Reutiliza a vocabulação de causa que `serpro_monitorings` já tem.

### 12. Remover `documents`

Scaffolding do template sem consumidor. `fiscal_documents` a substitui. Manter as duas criaria um nome que já significa outra coisa no produto — em `/customers/[documento]`, "documento" é certificado-ou-procuração.

### 13. Habilitação implícita no certificado

Captura roda para quem tem certificado utilizável. Não há interruptor por escritório.

**Alternativa:** um interruptor explícito, como o `add-integra-contador-sync` faz para o Integra Contador. Descartada porque ali a habilitação protege contra uma credencial de plataforma única revogada afetar todos os escritórios do cluster — risco que não existe aqui, onde a credencial é por cliente. O certificado já é o opt-in; um interruptor seria um segundo lugar onde o usuário pode errar.

## Risks / Trade-offs

**Interrupção de 60 dias destrói histórico** → Sem como recuperar: o serviço não gera posições retroativas. Mitigação: `last_seen_at` no cursor, alerta antes de 45 dias, e o cliente marcado como histórico interrompido — uma verdade que precisa ser dita, não suavizada. O efeito colateral de um job que falha por dois meses é pior que o de não existir: dá aparência de funcionando.

**Outro sistema captura o mesmo CNPJ primeiro** → A entrega de cada posição é única por CNPJ; o documento some e a consulta por posição devolve "nenhum localizado". Mitigação: documentar a regra de uma captura por CNPJ, e usar a posição que vem no `656` para reconciliar. Não há defesa técnica, apenas operacional — e isso precisa estar visível no painel, porque é a causa número um de buraco inexplicado.

**Resposta grande estoura memória** → Lote de 50 documentos de ~10 KB, comprimido a cerca de 40% e re-expandido em base64, dá de 270 KB a 3 MB por chamada, e o `gzdecode` de 50 payloads materializa tudo. Mitigação: `memory_limit` do PHP-FPM em 256M ou mais, `Http::timeout(60)`, decodificar entrada a entrada em vez de materializar o lote, e nunca registrar o payload bruto.

**Interrupção entre gravar documento e gravar posição** → Ordem invertida perde documento em silêncio. Mitigação: documentos primeiro; a repetição é segura pela decisão 4.

**Rejeição de consumo indevido em laço** → Backoff curto nunca desbloqueia, porque a contagem recomeça. Mitigação: parada absoluta com `blocked_until`.

**Senha do PFX agora persistida** → Um dump do banco passa a expor PFX e senha juntos, para todo cliente da carteira. Mitigação: `APP_KEY` fora do banco, nunca retornado por API, nunca em log, scrutinized por teste. É a decisão que mais muda o perfil de risco do módulo e precisa estar escrita, não implícita.

**XML de terceiro em disco** → Dado pessoal de cliente, nome, endereço epayer, e `infCpl`. Mitigação: disco privado sem servir, download só por controller autorizado, teste de tenancy, `infCpl` nunca indexado nem logado.

**Certificado vence no meio da operação** → Captura para silenciosamente. Mitigação: `Client::scopeWithCertificateStatus` já calcula vencimento em 30 dias; o painel reusa e lista como atenção.

**PKCS#12 com RC2** → `openssl_pkcs12_read` falha em arquivo exportado com `-legacy`, e a exceção do OpenSSL não diz qual arquivo é. Mitigação: erro próprio, com o nome do cliente no contexto.

**`SOAPAction` do CT-e divergente no pacote de referência** → Rejeição opaca do servidor. Mitigação: valor verificado contra produção, com teste de contrato fixando a string.

**Chaves mascaradas por `autXML` no CT-e** → Quem consulta por `autXML` recebe as chaves das NF-e transportadas zeradas; extrair e indexar colide. Mitigação: marcar o documento como mascarado e não extrair chave de `infDoc/infNFe` nesse caso.

**Posição não correlaciona com tempo** → O ambiente nacional não sincroniza em tempo real, então um evento pode chegar antes da própria nota. Mitigação: `dhEmi` nunca é derivado da posição; ordenação é por emissão.

**Uma aplicação, um CNPJ** → A regra do fisco é que duas aplicações no mesmo CNPJ se prejudiquem. Mitigação: a chave de exclusão na execução impede concorrência interna, mas duas instalações do produto diferentes não se enxergam. Limitação conhecida e não resolvível no software.

## Migration Plan

Ordem de deploy, todas aditivas:

1. Migration da coluna `password_encrypted` em `client_certificates`, nullable. Nenhuma linha existente é afetada.
2. Migrations de `fiscal_documents` e `fiscal_cursors`.
3. Disco `fiscal` e o código de captura, inerte sem certificado com senha.
4. Remoção de `documents` e da rota da API. Nenhum consumidor no frontend, verificado.
5. Telas `/fiscal`, atrás da capability nova.

**Certificados existentes entram como "requer re-upload"** — a senha não é recuperável de um arquivo já armazenado. Não há backfill possível, e o produto precisa dizer isso em vez de falhar na captura. O primeiro increment útil para esses clientes depende do escritório reenviar o PFX.

**Rollback:** as tabelas e a coluna são aditivas e podem ser descartadas. A remoção de `documents` é reversível por rollback da migration, já que a tabela não é populada. As telas somem com o rollback do frontend. Nada de irreversível.

**A primeira execução em produção é por NF-e e por cliente, manualmente**, observando a posição avançando, antes de ligar o agendamento. Um erro de credencial ou de envelope descobre-se em um cliente, não em trezentos.

## Open Questions

- **NFC-e (modelo 65) entra no v1?** Não. Exige serviço por UF e credencial do contador, não do cliente: SC tem `DistribuicaoNfceDownload` com `op=nfceDownloadContab`, que usa e-CNPJ do contador com vínculo no S@T e procuração no DTEC; SP tem o SAE-NFC-e desde fevereiro de 2026, limitado ao CNPJ próprio. Decidir depois de ver a carteira real, porque a resposta muda o escopo de credencial — não o schema.
- **NFS-e padrão nacional entra no v1?** Não. Seria a API do `/contribuintes` do ambiente de dados nacional, com o mesmo A1 do cliente. Dois detalhes mudam o desenho: o `404` carrega corpo de negócio e o status HTTP mente, e não existe `UltimoNSU` no contrato published, então a posição sai de `max(NSU)`. A API de DANFSe foi suspensa em 03/08/2026 e a obrigatoriedade passou para 1º/11/2026 pela Resolução CGSN 191/2026.
- **Quem é notificado quando um histórico é interrompido, e o que o escritório faz por aquele cliente depois?** Não muda o que é construído — a spec já exige que o estado apareça e que a captura não retome em silêncio. O que está aberto é o processo operacional em volta: a recuperação só existe por consulta pontual, limitada por hora, e o período perdido não volta. Consequência para a tela: ela não pode sugerir que continuar a captura resolve. O combinado de notificação e o roteiro de atendimento ao cliente entram depois, com o escritório.
- **Fuso e horário da reconciliação.** A varredura diária precisa sair fora do horário comercial no Brasil. Ajustável em config; o padrão é decided na implementação.
- **Observabilidade de falha.** Como uma rejeição vira visível para o time de operação — log estruturado, métrica, ou ambos. Não muda spec, abordagem nem quebra de tarefas.
