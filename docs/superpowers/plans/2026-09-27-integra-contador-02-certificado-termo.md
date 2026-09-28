# Integra Contador — Certificado e Termo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que cada Account entregue seu e-CNPJ uma vez e obtenha um termo assinado, armazenado e renovado pela plataforma, sem expor material de assinatura.

**Architecture:** Extrair do vault de cliente a leitura/higiene do PFX sem mudar seu comportamento; persistir o PFX do Account **cifrado no banco** (decisão aprovada nesta sessão: disco do container é efêmero em produção), com histórico de metadados e sem path. Isolar a assinatura em um adaptador sobre componente oficial verificado, guardar o XML assinado verbatim e renovar por reenvio do mesmo documento.

**Tech Stack:** Laravel ^13.17/PHP ^8.3, OpenSSL e DOM nativos, PHPUnit ^12.5, Nuxt ^4.5, pnpm 12.5.1; nenhuma dependência Composer/npm nova.

**Spec:** `openspec/changes/complete-serpro-integration/specs/serpro-connection/spec.md` (certificado/termo); `openspec/changes/complete-serpro-integration/design.md` D2–D3; tarefas 4.1–4.11. **Desvio deliberado aprovado:** certificado cifrado no banco em vez de disco; antes de executar a migration, atualizar a redação de design/spec do change para esta decisão, sem alterar a semântica de upload único.

## Global Constraints

- Termo **por Account**, assinado com e-CNPJ do escritório, `contratante` da plataforma; nunca pedir assinatura manual ao Membro. XML assinado e certificado não saem do backend nem são registrados em logs.
- Arquivo `.pfx|.p12` de até 2048 KiB; `Crypt::encryptString(base64_encode($bytes))` para certificado, `Crypt::encryptString($password)` para senha; usar `base64_decode(Crypt::decryptString(...), true)` na leitura. Nunca salvar PFX em disco duradouro.
- Conservação de metadados após substituir/remover; conteúdo/senha cifrados apagados. Nenhuma rotação de `APP_KEY`; não alterar `ClientCertificateVault` além da extração comum, nem `composer.json`.
- Renovação diariamente com fuso `America/Sao_Paulo`; `304` recupera token de `ETag`, sem re-assinar. Sem certificado ou termo vencido => ação do Account, nunca erro inventado de cliente.
- Termo é um documento jurídico: não inventar implementação XMLDSig, não alegar interoperabilidade provada sem contrato real. **O modelo oficial não passa `php -l` em sua forma distribuída** e imprime XML em `echo`; não executá-lo/copiar o script global sem isolar e corrigir o exemplo de forma auditável.
- Regras gerais de `CONTEXT.md`, `backend/AGENTS.md`; testes em português, PHP formatado via `vendor/bin/pint --dirty --format agent`, frontend `pnpm lint && pnpm typecheck && pnpm test`.

## Mapa de arquivos e interfaces

| Arquivo | Responsabilidade |
| --- | --- |
| `backend/app/Services/CertificatePkcs12.php`, `backend/app/Services/ClientCertificateVault.php` | Extração de metadados/OpenSSL/senha compartilhada; vault existente mantém arquivos dos clientes intactos. |
| `backend/database/migrations/*_create_account_certificates_table.php`, `backend/app/Models/AccountCertificate.php`, `backend/database/factories/AccountCertificateFactory.php` | Histórico de certificado do Account e PFX/senha cifrados em colunas `longText`/`text`; `BelongsToAccount`. |
| `backend/app/Services/AccountCertificateVault.php`, `backend/app/Http/Controllers/Tenant/AccountCertificateController.php`, `backend/app/Http/Resources/AccountCertificateResource.php`, `backend/app/Policies/AccountCertificatePolicy.php` | Upload, substituição e remoção, acessíveis a `admin|operador`; serialização só de metadados. |
| `backend/app/Support/SerproSigner.php`, `backend/app/Services/SerproTermSigner.php` | Rotina de assinatura isolada do modelo oficial corrigido; XML termo + assinatura enveloped RSA-SHA256 e normalização pré-assinatura; nunca usar `echo` do script exemplo. |
| `backend/database/migrations/*_create_serpro_authorization_terms_table.php`, `backend/app/Models/SerproAuthorizationTerm.php`, `backend/database/factories/SerproAuthorizationTermFactory.php` | Termo único por Account, XML verbatim cifrado, token cifrado, validade do documento e do token separadas. |
| `backend/app/Services/SerproTermManager.php`, `backend/app/Jobs/RenewSerproTermsJob.php`, `backend/app/Console/Commands/RenewSerproTerms.php`, `backend/routes/console.php` | Emissão, reenvio `304`/`ETag` e renovação diária com Account explícita. |
| `backend/app/Http/Controllers/Tenant/SerproAuthorizationTermController.php`, `backend/app/Http/Resources/SerproAuthorizationTermResource.php`, `backend/routes/api.php` | GET `/api/serpro/authorization-terms` (`state`, `expires_on`, `signed_at`, `document_present`). |
| `frontend/app/composables/useSerpro.ts`, `frontend/app/pages/monitoring/termos.vue`, `frontend/tests/monitoringTermGuidance.test.ts` | Formulário de certificado com gate preventivo e estado do termo; nenhum XML mostrado. |

**Interfaces produzidas:** `AccountCertificateVault::replace(Account $account, UploadedFile $file, string $password): AccountCertificate`; `::remove(Account $account): void`; `SerproTermManager::issue(int $accountId): SerproAuthorizationTerm`; `::refresh(int $accountId): SerproAuthorizationTerm`; `::validToken(int $accountId): ?string`. Plano 3 usa `validToken`, plano 4 depende de sua recusa em termo vencido. `SerproClient::call` recebe o token somente do backend.

---

### Task 1: Revisar proveniência da assinatura antes de acoplar criptografia

**Files:** Modify `openspec/changes/complete-serpro-integration/design.md`, `openspec/changes/complete-serpro-integration/specs/serpro-connection/spec.md`; Create `backend/tests/Unit/SerproSignerProvenanceTest.php` **somente após** verificar a origem; vendor source final `backend/app/Support/SerproSigner.php`.

**Interfaces:** O adaptador do Task 4 consumirá `SerproSigner::sign(string $xml, string $certificateBytes, string $password): string`. A entrada é XML sem assinatura, a saída XML assinado; nenhuma função global ou variável `$GLOBALS` escapa para a aplicação.

- [ ] **Step 1: Fixar proveniência.** Fonte oficial: [modelo PHP publicado pelo SERPRO](https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/modelos/modelo_de_assinador_digital_php/), ZIP v1.0.0 em `https://serprodrive.serpro.gov.br/s/prii8sBZbgs9ddw/download`, SHA-256 observado em 2026-09-27 `6e139b207527047e9e66e9228c7c1ea6ea444b1b1a936f5f02f4d936a9e0c72b`, `LICENSE` MIT no ZIP. Reconfirmar checksum/licença no dia da implementação: se mudou, interromper e revisar o código de origem antes de integrar.
- [ ] **Step 2: Gate verificável.** `unzip -p serpro-assinador-1.0.0.zip 'Serpro.Componentes.AssinadorDigital.php/Serpro.Componentes.AssinadorDigital.php' | php -l` reproduziu `Parse error: Unclosed '{' ... line 50`; o arquivo também define globais, usa `echo` com XML/base64 e tem `addChild('finalidade ')` com espaço no nome. **Não** vendorizar o script de aplicação tal qual: isolar somente a rotina de assinatura, corrigir sintaxe e formato do documento em commits rastreáveis, testar estrutura XMLDSig e assinatura criptográfica com certificado de teste. Se o comportamento corrigido divergir do exemplo/documentação oficial, parar e pedir decisão antes de emitir termos.
- [ ] **Step 3: Reconciliar artefatos.** Atualizar D2 e os requisitos de certificado para `certificate_encrypted` no banco em vez de `storage_path`, registrar esta URL/versão/SHA/licença e a diferença entre modelo de referência e rotina isolada, e a ressalva de que `304` e papéis do termo exigem teste real de contrato. `git diff backend/composer.json` deve permanecer vazio.
- [ ] **Step 4: Commit da decisão.** `git add openspec/changes/complete-serpro-integration && git commit -m "docs(serpro): registrar persistência durável e proveniência da assinatura"`.

### Task 2: Reusar parsing de PFX sem alterar certificados de clientes

**Files:** Create `backend/app/Services/CertificatePkcs12.php`; Modify `backend/app/Services/ClientCertificateVault.php`; Test `backend/tests/Feature/Tenancy/ClientCertificateTest.php`, `backend/tests/Feature/Tenancy/ClientCertificatePasswordVaultTest.php`, `backend/tests/Unit/ClientCertificateVaultLegacyPfxTest.php`.

**Interfaces:** `CertificatePkcs12::inspect(string $bytes, string $password): array{cert:string,pkey:string,subject:string,serial:string,valid_from:Carbon,valid_until:Carbon,sha256:string}`; não retorna o password.

- [ ] **Step 1: Baseline.** Rodar `cd backend && php artisan test --compact --filter=ClientCertificate`; guardar saída antes da refatoração para comparar.
- [ ] **Step 2: Teste vermelho.** Novo `backend/tests/Unit/CertificatePkcs12Test.php` usa um PFX gerado em runtime; acerta senha ⇒ `subject`, `serial`, datas e `sha256`; erra senha ⇒ `ValidationException` com chave `password`; PFX RC2 legado ⇒ chave `certificate` e mensagem distinta.

```php
$inspected = (new CertificatePkcs12)->inspect($pfxGeneratedInTest, 'senha-de-teste');
$this->assertSame(hash('sha256', $pfxGeneratedInTest), $inspected['sha256']);
$this->expectException(ValidationException::class);
(new CertificatePkcs12)->inspect($pfxGeneratedInTest, 'incorreta');
```
- [ ] **Step 3: Implementar.** Extrair `openssl_pkcs12_read`, limpeza da fila de erro, detecção de RC2, `openssl_x509_parse`, subject/serial de `ClientCertificateVault` para `CertificatePkcs12::inspect`; envolver bytes/senha em `try/finally` com zeragem local, retornar apenas os campos do contrato. O vault original usa `inspect`, guarda exatamente `Crypt::encryptString(base64_encode($contents))`, mantém `storage_path`, transação e limpeza anteriores.

```php
public function inspect(string $bytes, string $password): array
{
    $parsed = [];
    try {
        // Copiar a distinção de RC2 da rotina existente, antes de modificar o vault.
        if (! openssl_pkcs12_read($bytes, $parsed, $password)) {
            throw ValidationException::withMessages(['password' => 'Não foi possível abrir o certificado com a senha informada.']);
        }
        $metadata = openssl_x509_parse($parsed['cert']);
        // Converter os campos nos nomes declarados em Interfaces.
        return ['cert' => $parsed['cert'], 'pkey' => $parsed['pkey'], 'subject' => (string) $metadata['name'],
            'serial' => (string) $metadata['serialNumber'], 'valid_from' => Carbon::createFromTimestamp($metadata['validFrom_time_t']),
            'valid_until' => Carbon::createFromTimestamp($metadata['validTo_time_t']), 'sha256' => hash('sha256', $bytes)];
    } finally {
        $password = str_repeat("\0", strlen($password));
        unset($password, $parsed);
    }
}
```
- [ ] **Step 4: Verificar sem regressão.** Rodar teste novo e `php artisan test --compact --filter=ClientCertificate` (incluindo fixture legacy quando OpenSSL suportar); `git add backend/app/Services backend/tests && git commit -m "refactor(certificados): compartilhar leitura segura de PFX"`.

### Task 3: Certificado do Account durável e API de upload/remoção

**Files:** Create migration, `backend/app/Models/AccountCertificate.php`, `backend/database/factories/AccountCertificateFactory.php`, `backend/app/Services/AccountCertificateVault.php`, `backend/app/Policies/AccountCertificatePolicy.php`, `backend/app/Http/Requests/Tenant/UploadAccountCertificateRequest.php`, `backend/app/Http/Controllers/Tenant/AccountCertificateController.php`, `backend/app/Http/Resources/AccountCertificateResource.php`; Modify `backend/routes/api.php`, `backend/app/Models/Account.php`; Test `backend/tests/Feature/SerproAccountCertificateTest.php`.

**Interfaces:** `AccountCertificate::currentFor(int $accountId): ?self` busca `removed_at/replaced_at IS NULL` por `account_id` explícito; `certificateBytes(): string` faz `base64_decode(Crypt::decryptString($this->certificate_encrypted), true)`; `certificatePassword(): string` desencripta senha só no escopo de assinatura; não há coluna de caminho.

- [ ] **Step 1: Teste vermelho.** Testar `admin|operador` upload PFX, `user` 403, senha incorreta 422 sem registro, troca conserva metadados anteriores e apaga ciphertext/senha anteriores, remoção apaga conteúdo sem apagar histórico, response não contém `encrypted`, `password`, `path`; fixture com outro Account retorna 404. Testar que dados sobrevivem instância nova do model (sem `Storage::disk`), sem rede.

```php
$response = $this->actingAs($admin, 'sanctum')->post('/api/serpro/account-certificate', [
    'certificate' => UploadedFile::fake()->createWithContent('account.p12', $pfxGeneratedInTest),
    'password' => 'senha-de-teste',
]);
$response->assertOk()->assertJsonMissingPath('data.certificate_encrypted');
$this->assertNotSame($pfxGeneratedInTest, AccountCertificate::currentFor($account->id)->certificate_encrypted);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproAccountCertificateTest.php`; esperado: endpoint ausente.
- [ ] **Step 3: Schema.** Migration com `account_id` FK, `document string(14)` **extraído do certificado** (Account não tem coluna de CNPJ), `subject`, `serial_number`, `valid_from`, `valid_until`, `original_filename`, `sha256`, `certificate_encrypted longText nullable`, `password_encrypted text nullable`, `replaced_at`, `removed_at`, timestamps e índice `(account_id, replaced_at, removed_at)`; down reversível. Factory com estados corrente/removido. Model usa `BelongsToAccount`, casts de datas, método de descriptografia **nunca** em JSON.

```php
$table->foreignId('account_id')->constrained()->cascadeOnDelete();
$table->string('document', 14);
$table->longText('certificate_encrypted')->nullable();
$table->text('password_encrypted')->nullable();
$table->index(['account_id', 'replaced_at', 'removed_at']);
```

- [ ] **Step 4: Upload atômico.** `replace` lê arquivo, chama `CertificatePkcs12::inspect` e `SerproCertificateIdentity::document` do plano 01 para extrair CNPJ; persiste `document` como identidade do Account (não compara com campo inexistente em `accounts`); lock em `Account::whereKey($accountId)`, marca anterior `replaced_at` + apaga as duas colunas cifradas, cria novo com `account_id` explícito e `Crypt::encryptString(base64_encode($bytes))`; `remove` marca `removed_at` + zera colunas. `finally` zera bytes/senha. Controller delega ao vault; `max:2048`, `extensions:pfx,p12` (e **não** `mimes`: a regra `mimes` resolve pelo `guessExtension()`, que pergunta ao host o que ele adivinha do **conteúdo**, e num host cuja libmagic não conhece PKCS#12 a adivinhação dá `bin` e a regra recusa todo e-CNPJ real; `extensions` lê a extensão do nome enviado e é determinística), password obrigatório no upload; policy `['admin','operador']` para escrita, qualquer Membro para leitura.

```php
$current->forceFill(['replaced_at' => now(), 'certificate_encrypted' => null, 'password_encrypted' => null])->save();
AccountCertificate::create([
    'account_id' => $account->getKey(), 'document' => $this->identity->document($bytes, $password),
    'certificate_encrypted' => Crypt::encryptString(base64_encode($bytes)),
    'password_encrypted' => Crypt::encryptString($password),
    // Metadados de CertificatePkcs12::inspect conforme a migration.
]);
```
- [ ] **Step 5: Testar/commitar.** `cd backend && php artisan test --compact tests/Feature/SerproAccountCertificateTest.php`; `git add backend/app backend/database backend/routes/api.php backend/tests && git commit -m "feat(serpro): guardar e-CNPJ do Account cifrado no banco"`.

### Task 4: Fonte oficial vendorizada e termo assinado com bytes verificáveis

**Files:** Create `backend/app/Support/SerproSigner.php`, `backend/app/Services/SerproTermSigner.php`; Test `backend/tests/Unit/SerproTermSignerTest.php`. **Pré-condição obrigatória:** Task 1 reconfirmou checksum/licença e isolou mudanças necessárias para reparar o exemplo.

**Interfaces:** `SerproTermSigner::sign(Account $account, AccountCertificate $certificate, string $contractingDocument): string` devolve XML assinado, não loga XML/PFX; bytes do documento não mudam entre persistência e reenvio.

- [ ] **Step 1: Teste vermelho.** Gerar PFX em runtime; montar termo com `destinatario.numero = CNPJ do escritório`, `contratante.numero = documento da plataforma`; validar por DOM que há `Signature`, `SignedInfo`, `Reference URI=""`, transforms enveloped/c14n, `SignatureMethod` RSA-SHA256 e que verificar a assinatura com chave pública extraída do PFX retorna verdadeiro. Inserir U+200B no nome e afirmar ausência após normalização, antes de assinar.

```php
$signed = $signer->sign($account, $certificate, '12345678000195');
$xml = new DOMDocument;
$this->assertTrue($xml->loadXML($signed));
$xpath = new DOMXPath($xml);
$xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
$this->assertSame('', $xpath->evaluate('string(//ds:Reference/@URI)'));
$this->assertSame(1, $xpath->query('//ds:Signature')->length);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Unit/SerproTermSignerTest.php`; esperado: classe ausente.
- [ ] **Step 3: Isolar a implementação oficial corrigida.** Portar para `app/Support/SerproSigner.php` apenas a sequência XMLDSig da função `assinar()` do ZIP: canonicalização C14N, SHA-256 digest, `SignedInfo`, enveloped `Reference URI=""`, RSA-SHA256 e X509; adicionar licença e referência ao SHA do arquivo de origem. Remover `$GLOBALS`, `date_default_timezone_set`, carregamento PFX de path e os dois `echo`; corrigir sintaxe inválida e `finalidade ` do modelo no adapter, não na cópia cega. `SerproTermSigner` monta documento do exemplo com datas via Carbon `America/Sao_Paulo`, normaliza Unicode invisível ANTES de assinar, passa bytes/chave à rotina isolada. Não reserializar depois da assinatura nem alterar assinatura fiscal DF-e.

```php
// Pré-processamento único, ANTES da assinatura — sem executar o script global.
$normalized = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $documentXml);
$document->loadXML($normalized, LIBXML_NONET);
$signedXml = $serproSigner->sign($document->saveXML(), $certificate->certificateBytes(), $certificate->certificatePassword());
return $signedXml;
```
- [ ] **Step 4: Verificar.** Teste local de assinatura/validação, `git diff backend/composer.json` vazio, `git add backend/app/Support backend/app/Services/SerproTermSigner.php backend/tests && git commit -m "feat(serpro): assinar termo com componente oficial auditado"`.

### Task 5: Emissão, renovação e leitura segura do termo

**Files:** Create migration, `backend/app/Models/SerproAuthorizationTerm.php`, factory, `backend/app/Services/SerproTermManager.php`, `backend/app/Jobs/RenewSerproTermsJob.php`, `backend/app/Console/Commands/RenewSerproTerms.php`, resource/controller; Modify `backend/config/integra-contador.php`, `backend/routes/api.php`, `backend/routes/console.php`, `backend/app/Services/AccountCertificateVault.php`; Test `backend/tests/Feature/SerproAuthorizationTermTest.php`.

**Interfaces:** `issue(int): SerproAuthorizationTerm`, `refresh(int): SerproAuthorizationTerm`, `validToken(int): ?string`; `SerproAuthorizationTerm` tem `account_id`, `document_encrypted`, `token_encrypted`, `document_expires_on`, `token_expires_at`, `state`, `signed_at`, `last_submitted_at`. Guardar XML via `Crypt::encryptString($signedXml)` e devolver verbatim via método privado para envio; `GET` devolve somente `state`, `expires_on`, `signed_at`, `document_present`.

- [ ] **Step 1: Teste vermelho.** `Http::fake` captura `AUTENTICAPROCURADOR/ENVIOXMLASSINADO81` `/Apoiar`, confere `autorPedidoDados` do Account, token recebido e `document_encrypted` não contém XML; chamada de renovação `304` com `ETag` UUID atualiza token mas ciphertext do documento fica **igual**, assinatura invocada só na emissão; documento vencido retorna `Vencido` sem enviar; provedor recusa ⇒ `Recusado` sem logar payload.

```php
$before = $term->document_encrypted;
Http::fake(['*/Apoiar' => Http::response('', 304, ['ETag' => '8f68d948-1059-4f42-9aa6-2931670b0a80'])]);
$manager->refresh($account->id);
$this->assertSame($before, $term->fresh()->document_encrypted);
$this->assertSame('8f68d948-1059-4f42-9aa6-2931670b0a80', $term->fresh()->token());
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproAuthorizationTermTest.php`; esperado: endpoint/model ausentes.
- [ ] **Step 3: Persistir/emitir.** Migration `unique(account_id)` com colunas e `BelongsToAccount`; manager re-hidrata Account pelo ID, exige certificado corrente e conexão íntegra, chama signer uma vez, envia usando transporte com `AUTENTICAPROCURADOR/ENVIOXMLASSINADO81` registrado como `path=Apoiar`, `billable=false`, aceita token + validade `America/Sao_Paulo`; não tratar 304 como falha no transporte: criar `SerproClient::submitTerm(string $signedXml, string $author): array{status:int,etag:?string,dados:mixed}` usando a mesma autenticação/mTLS/envelope e retorno de headers, em vez de reutilizar `call()` que rejeita 304.

```php
// Ao criar o termo:
$term = SerproAuthorizationTerm::updateOrCreate(['account_id' => $accountId], [
    'document_encrypted' => Crypt::encryptString($signedXml),
    'state' => SerproAuthorizationTermState::Pendente,
    'signed_at' => now(),
]);
// No submitTerm, 304 devolve ['status' => 304, 'etag' => $response->header('ETag'), 'dados' => null].
```
- [ ] **Step 4: Renovar.** `refresh` desencripta **exatamente** o XML armazenado, sem chamar signer, envia ao `/Apoiar`, valida ETag/token para 304 e salva novo vencimento; no `routes/console.php`, `Schedule::command('serpro:renew-terms')->dailyAt('01:00')->timezone('America/Sao_Paulo')->withoutOverlapping()`; comando percorre Accounts explicitamente, job recebe `accountId`, sem confiar em `CurrentTenant` residual. O upload bem-sucedido agenda emissão `afterCommit()` e a leitura do termo retorna `Pendente` até conclusão; falha do job registra estado sem expor material.

```php
Schedule::command('serpro:renew-terms')->dailyAt('01:00')
    ->timezone('America/Sao_Paulo')->withoutOverlapping();
$xml = Crypt::decryptString($term->document_encrypted);
$result = $client->submitTerm($xml, $certificate->document);
// Em 304 validar UUID no ETag antes de sobrescrever token_encrypted.
```
- [ ] **Step 5: Verificar e commitar.** Rodar testes novos + ClientCertificate; `php artisan schedule:list` mostra renovação diária; `git add backend/app backend/config/integra-contador.php backend/database backend/routes backend/tests && git commit -m "feat(serpro): emitir e renovar termo por Account"`.

### Task 6: Permitir upload pelo Account sem pedir assinatura

**Files:** Modify `frontend/app/composables/useSerpro.ts`, `frontend/app/pages/monitoring/termos.vue`; Create `frontend/tests/monitoringTermGuidance.test.ts` (somente utilidade TypeScript pura testável); Modify `frontend/app/utils/monitoringPresentation.ts` se necessário.

**Interfaces:** `useSerpro().uploadAccountCertificate(file: File, password: string)` POST `/serpro/account-certificate` com `FormData`; `removeAccountCertificate()` DELETE; termo continua GET `/serpro/authorization-terms`.

- [ ] **Step 1: Teste vermelho.** Teste `node --test` para `serproTermGuidance`: `ausente` pede certificado, `vencido` pede ação do Account, `validado`/`autenticado` não pedem assinatura; não tentar importar `.vue` no Node.

```ts
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { serproTermGuidance } from '../app/utils/monitoringPresentation.ts'
test('termo válido não pede nova assinatura ao Account', () => {
  assert.doesNotMatch(serproTermGuidance.autenticado, /assine novamente/i)
})
```
- [ ] **Step 2: Rodar.** `cd frontend && pnpm test`; esperado: novo caso falha.
- [ ] **Step 3: Implementar UI.** Em `termos.vue`, `useAuth().canManageClients` protege upload/remoção; `UFileUpload accept=".pfx,.p12"`, `UInput type="password"`, confirmação antes da remoção; quando upload terminar atualizar leitura via `reload()`, mostrar `Pendente`; nunca renderizar XML, password ou conteúdo PFX. Gate backend continua obrigatório. Composable manda `FormData` para API sem `/api` duplicado.

```ts
async function uploadAccountCertificate(file: File, password: string) {
  const body = new FormData()
  body.append('certificate', file)
  body.append('password', password)
  return $api('/serpro/account-certificate', { method: 'POST', body })
}
```
- [ ] **Step 4: Provar/commitar.** `cd frontend && pnpm lint && pnpm typecheck && pnpm test`; `git add frontend/app frontend/tests && git commit -m "feat(serpro): configurar certificado do Account no termo"`.

**Saída verificável:** upload e remoção seguros, termo emitido/renovado sem nova assinatura, nenhum arquivo de certificado dependente de volume efêmero. `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact`; `cd frontend && pnpm lint && pnpm typecheck && pnpm test`. Se fonte oficial do signer não for verificável, **não** declarar esta saída concluída: entregar Tasks 1–3 e aguardar decisão explícita.
