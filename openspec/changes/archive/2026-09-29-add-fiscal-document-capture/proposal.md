## Why

O escritório já guarda o certificado A1 de cada cliente da carteira, mas o certificado é **inutilizável**: `ClientCertificateVault` usa a senha do PFX uma única vez, para validar o upload, e a descarta. A senha nunca é persistida. Não existe caminho no produto para abrir um certificado de cliente depois do upload — e sem ele não há como falar com nenhum serviço do fisco em nome do cliente.

O resultado é que o escritório não tem visibilidade nenhuma sobre os documentos fiscais que os clientes recebem. O Monitoramento mostra obrigações acessórias, que é outra coisa. NF-e e CT-e emitidas por terceiros contra o CNPJ do cliente são o insumo mais básico do trabalho contábil e hoje chegam por e-mail, portal do cliente ou planilha.

Enquanto isso o acessoDistribution DF-e da SEFAZ é gratuito, público e já pronto — desde que se tenha o A1 do CNPJ. O que falta é a credencial utilizável, a integração e a tela.

## What Changes

- **A senha do PFX passa a ser persistida, criptografada, por cliente.** É a mudança que destrava tudo: sem ela o certificado guardado é um arquivo morto. A spec atual de `client-fiscal-access` diz o oposto — *"SHALL never persist or log the supplied password"* — e essa requirement é alterada explicitamente neste change. A senha nunca sai por API, nunca vai para log, e é descriptografada em memória apenas durante uma chamada.
- **Certificados já existentes passam a exigir re-upload**, e a UI passa a dizer isso em vez de falhar em silêncio na captura.
- **Captura incremental de NF-e e CT-e** por Distribuição DF-e do Ambiente Nacional, um documento por vez em fila, com cursor de NSU por cliente e fonte. Escrever, consultar, cancelar e imprimir **não** entram neste change.
- **Duas telas novas** sob `/fiscal`: um painel de acompanhamento e uma tabela unificada de documentos, com filtro por modelo, cliente, emitente, destinatário, valor e data, e download do XML.
- **`documents` é removida.** É scaffolding do template com um `apiResource` completo e nenhum consumidor no frontend. `fiscal_documents` a substitui com significado fiscal.
- **Rejeições da SEFAZ viram estado de primeira classe, não erro.** Um cliente cujo certificado está vencido, cujo cursor está bloqueado por consumo indevido ou cuja sincronização está interrompida aparece na lista de atenção do painel. Não é exceção, é situação operacional conhecida.
- **Nenhuma manifestação do destinatário é enviada automaticamente.** `210200` é ato jurídico e trava o cancelamento pelo emitente.
- **NFC-e e NFS-e padrão nacional ficam fora deste change.** A NFC-e não chega pelo mesmo serviço — exige integração por UF, com credencial do contador, não do cliente. A NFS-e nacional exige a API do ADN, que é integração própria. Ambas estão descritas como não-objetivos com o mecanismo identificado.

## Capabilities

### New Capabilities
- `tenant/fiscal-capture`: captura incremental de NF-e e CT-e emitidas por terceiros contra o CNPJ do cliente — credencial A1 utilizável, cursor de NSU por cliente e fonte, idempotência por chave de acesso, decodificação de lote comprimido, limites de consumo e reconciliação de lacunas, e cobertura da carteira (quem é capturável e quem não é).
- `tenant/fiscal-documents-ui`: as duas telas de `/fiscal` — painel com cobertura, volume por modelo e lista de atenção; tabela unificada de documentos com filtros, detalhe e download do XML.

### Modified Capabilities
- `tenant/client-fiscal-access`: a requirement de upload seguro passa a persistir a senha do PFX de forma criptografada para permitir uso posterior do certificado, com exposição e log vedados; certificados preexistentes passam a ter estado de re-upload obrigatório; e a materialização efêmera do certificado para uma chamada externa passa a ser comportamento especificado.

## Impact

- **Backend (Laravel 13).** Nova coluna `password_encrypted` em `client_certificates` e accessor equivalente ao de `SerproConnection`. Tabelas `fiscal_documents` e `fiscal_cursors`, disco `fiscal` privado, e remoção de `documents` (migration, model, factory, controller, request, resource, policy, binding em `AppServiceProvider` e a rota da API). `app/Services/Fiscal/` — conector com uma interface e duas implementações (NF-e e CT-e), envelope SOAP, parser de resposta, decodificador de `docZip`, extrator de metadados, validador de XSD, writer idempotente, serviço de captura, job e dois comandos Artisan. Enum `FiscalFailure` espelhando a forma de `SerproFailure`, e `FiscalDocumentPolicy` com `BelongsToAccount`.
- **Sem dependência nova.** `composer.json` mantém as mesmas quatro dependências de runtime. O serviço de Distribuição **não assina** o XML da requisição — a autenticação é mTLS com o A1, e o XSD rejeita uma assinatura injetada. Não há necessidade de biblioteca de assinatura digital, de canonicalização, nem de `sped-common`/`xmlseclibs`.
- **Fila.** `QUEUE_CONNECTION=redis` com `retry_after` de 90s contra `--timeout=120` do worker em `docker/queue-entrypoint.sh`. Um job por cliente e por fonte, nunca um job por carteira. A idempotência por chave de acesso torna execução duplicada inofensiva, que é a propriedade necessária quando timeout e retry discordam.
- **Segredos.** A senha do PFX passa a ser dado sensível persistido. O precedente é `SerproConnection.certificate_password_encrypted`. Um dump do banco passa a expor PFX e senha juntos — a mitigação é a de sempre, `APP_KEY` fora do banco e nunca devolver o segredo por API. Precisa estar explícito no threat model, não implícito.
- **LGPD.** XMLs fiscais contêm dado de terceiros (CPF/CNPJ, nomes, endereços, e `infCpl`, que historicamente carrega dado sensível). Por isso disco privado sem `serve`, download só por controller autorizado, e nenhum XML servido estaticamente.
- **Frontend (Nuxt 4 + Nuxt UI v4).** `app/pages/fiscal.vue` como invólucro, `app/pages/fiscal/index.vue` como painel e `app/pages/fiscal/documentos.vue` como tabela, no padrão de `work.vue` + `work/*.vue`. Mais `app/utils/fiscalNav.ts`, `app/composables/useFiscal.ts`, `app/types/fiscal.ts` e componentes em `app/components/fiscal/`. Entrada "Fiscal" no menu lateral e no grupo de busca. Reaproveita-se `DataTableFilter`, `sheetTableUi`, `MetricCard` e os gráficos em `unovis` que já existem.
- **Infraestrutura.** Nada muda em `docker/nginx/*.conf` nem no roteamento. A integração é server-side only: o navegador nunca fala com a SEFAZ, mesma premissa do `cnpj-lookup` e do Integra Contador já existentes.
