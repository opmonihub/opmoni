## 0. Baseline

- [x] 0.1 Confirmar working tree limpo e suítes verdes (`cd backend && composer test`, `cd frontend && pnpm test`) — verificar exit 0 antes de alterar código

## 1. Schema e domínio

- [x] 1.1 Criar migration alargando `fiscal_documents.chave_acesso` para 50 caracteres e rodar migration em dev — verificar `php artisan migrate` e teste de schema com chave de 50 dígitos passando
- [x] 1.2 Estender validação de chave (44 vs 50) e metadados NFS-e em `FiscalXmlMetadata` com testes unitários RED→GREEN — verificar `php artisan test --compact --filter=FiscalXmlMetadata`

## 2. Fonte, config e gate

- [x] 2.1 Adicionar `FiscalSource::NfseAdn`, bloco `endpoints` ADN em `config/fiscal.php` e `nfse_enabled` — verificar `php artisan test --compact --filter=FiscalEnums`
- [x] 2.2 Integrar gate em `FiscalCaptureDispatcher` e reconciliação — verificar teste de dispatcher recusando fonte com gate desligado

## 3. Probe e transporte (loop real)

- [x] 3.1 Implementar comando `fiscal:nfse-probe` (mTLS, saída segura, `--client`, `--nsu`, `--save-fixture`) — verificar comando lista ajuda e recusa cliente sem certificado
- [ ] 3.2 Inventariar cliente Auto Center no banco (id, CNPJ, certificado) — verificar saída tinker com metadados sem segredos
- [ ] 3.3 Executar probe em produção para Auto Center com `FISCAL_NFSE_ENABLED=true` — verificar resposta classificável (HTTP + JSON resumido) registrada nas notas do canário
- [ ] 3.4 Iterar transport/leitor JSON até probe parsear lote ou “vazio” sem exceção genérica — verificar probe estável em 3 execuções seguidas com mesmo NSU

## 4. Conector e captura

- [x] 4.1 Implementar `NfseAdnConnector` + registry + collector (pull, fetchByNsu, fetchByChave) — verificar testes feature com `Http::fake()` usando fixture anonimizada
- [ ] 4.2 Rodar `php artisan fiscal:capture --client={auto_center} --source=nfse_adn` em produção autorizada — verificar ≥1 linha `fiscal_documents` model `nfse` ou cursor/`blocked_until` coerente com ADN
- [ ] 4.3 Repetir loop capture até checklist do design (doc gravado + XML no disco `fiscal`) — verificar UI/API listam NFS-e filtrável

## 5. Busca e frontend

- [x] 5.1 Aceitar `q` com 44 ou 50 dígitos em `FiscalDocuments` — verificar `php artisan test --compact --filter=FiscalDocumentSearch`
- [x] 5.2 Ajustar comentário/tipo em `frontend/app/types/fiscal.ts` — verificar `pnpm typecheck`

## 6. Reconciliação e testes

- [x] 6.1 Garantir reconciliação e point lookup para `nfse_adn` — verificar `php artisan test --compact --filter=FiscalPointLookup` ou teste dedicado NFS-e
- [x] 6.2 Exportar fixture anonimizada do probe e cobrir cenários principais com `Http::fake()` — verificar `php artisan test --compact --filter=Nfse`

## 7. Live (opt-in)

- [x] 7.1 Opcional: teste grupo `nfse-live` para probe real (fora do CI padrão) — verificar `php artisan test --group=nfse-live` só quando credencial local existir

## 8. Verificação final

- [x] 8.1 Rodar `cd backend && composer test`, `vendor/bin/pint --dirty --format agent`, `cd frontend && pnpm lint && pnpm typecheck && pnpm test` — verificar exit 0
- [x] 8.2 Rodar `openspec validate add-nfse-adn-capture --strict` — verificar change válida
- [ ] 8.3 Revisar logs/respostas do canário para ausência de senha, PFX, XML bruto ou tokens — verificar checklist manual documentado no PR
