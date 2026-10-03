<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Jobs\SendFiscalManifestationJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Capture\FiscalLookupBudget;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Manifestacao\RecepcaoEventoConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A ressincronização dos resumos manifestados: a ciência registrada (`Sent` ou
 * o `573` de terceiro) libera o `consChNFe`, que traz o XML completo pelo
 * caminho de consulta pontual já existente — orçamento de 20/h por CNPJ,
 * tentativas limitadas por chave e repetição segura.
 */
class ManifestacaoResyncTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35220499999999999999550010020000001240556600';

    private const OUTRA_CHAVE = '35220499999999999999550010020000001345678901';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fiscal');
        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();
        Cache::store('array')->clear();

        config(['fiscal.manifestacao_enabled' => true]);
    }

    public function test_o_573_grava_ja_manifestado_e_a_chave_vira_elegivel_ao_resync(): void
    {
        [$cliente] = $this->clienteComCertificado();

        // O transporte fake responde duas coisas, na ordem: o `retEnvEvento`
        // com o 573 para o evento, e o `retDistDFeInt` com o documento para o
        // `consChNFe` que a ressincronização dispara depois.
        Http::fakeSequence()
            ->push($this->retEnvEvento('128', 'Lote de Evento Processado', '573', 'Rejeicao: Duplicidade de Evento'), 200)
            ->push($this->fixture('retDistDFeInt_138_consChNFe.xml'), 200);

        // O resumo já gravado é o que a captura produziu antes da manifestação
        // — e a manifestação pedida é o estado do dispatcher pós-resumo.
        $this->resumoGravado($cliente, self::CHAVE);
        $this->pedidoDeCiencia($cliente, self::CHAVE);

        $job = new SendFiscalManifestationJob(
            accountId: (int) $cliente->account_id,
            clientId: (int) $cliente->getKey(),
            chaveAcesso: self::CHAVE,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        $job->handle(
            resolve(FiscalManifestationStore::class),
            resolve(RecepcaoEventoConnector::class),
        );

        $registro = FiscalManifestation::withoutGlobalScope('account')->sole();

        // O 573 não é rejeição transitória: é o fisco dizendo que a chave já
        // foi manifestada — por nós ou por terceiro — e o efeito é o mesmo de
        // uma ciência nossa registrada: seguir para o `consChNFe`, sem retry
        // do evento.
        $this->assertSame(FiscalManifestationOutcome::AlreadyManifested, $registro->outcome);
        $this->assertSame('573', $registro->result_code);

        Artisan::call('fiscal:resync-manifestacoes');

        // A consulta pontual trouxe o XML completo da chave já manifestada,
        // e a linha do documento convive com a do resumo.
        $this->assertTrue(FiscalDocument::query()
            ->where('client_id', $cliente->getKey())
            ->where('chave_acesso', self::CHAVE)
            ->where('stage', FiscalStage::Document)
            ->exists());

        $registro->refresh();
        $this->assertNotNull($registro->xml_recovered_at);
        // A consulta que deu certo não é tentativa gasta: `resync_attempts`
        // conta as que saíram sem documento, e a recuperada marca a coluna —
        // a repetição lê os dois e não refaz nenhum dos dois.
        $this->assertSame(0, $registro->resync_attempts);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<consChNFe><chNFe>'.self::CHAVE.'</chNFe></consChNFe>'));
    }

    public function test_resumo_com_ciencia_enviada_e_recuperado_pela_consulta_pontual(): void
    {
        [$cliente] = $this->clienteComCertificado();

        $this->resumoGravado($cliente, self::CHAVE);
        $this->pedidoDeCiencia($cliente, self::CHAVE, FiscalManifestationOutcome::Sent);

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consChNFe.xml'), 200)]);

        Artisan::call('fiscal:resync-manifestacoes');

        $documento = FiscalDocument::query()
            ->where('client_id', $cliente->getKey())
            ->where('chave_acesso', self::CHAVE)
            ->where('stage', FiscalStage::Document)
            ->sole();

        $this->assertSame(FiscalSource::NfeDistribuicao, $documento->source);
        $this->assertSame(FiscalModel::Nfe, $documento->model);
        $this->assertSame(FiscalKind::Document, $documento->kind);
        $this->assertSame((int) $cliente->account_id, (int) $documento->account_id);
        $this->assertNotNull($documento->storage_path);
        Storage::disk('fiscal')->assertExists($documento->storage_path);
    }

    public function test_resumo_sem_manifestacao_ou_pendente_nao_e_consultado(): void
    {
        [$cliente] = $this->clienteComCertificado();

        $this->resumoGravado($cliente, self::CHAVE);
        $this->pedidoDeCiencia($cliente, self::CHAVE, FiscalManifestationOutcome::Pending);

        $outroResumo = $this->resumoGravado($cliente, self::OUTRA_CHAVE);

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consChNFe.xml'), 200)]);

        Artisan::call('fiscal:resync-manifestacoes');

        // Nem o resumo ainda pendente nem o que nem manifestação tem saem
        // para a rede: a consulta pontual só existe porque a ciência já foi
        // registrada — sem ela, a `consChNFe` é vaga gasta à toa.
        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->where('stage', FiscalStage::Document)->count());
    }

    public function test_resumo_que_ja_tem_o_xml_completo_nao_volta_a_consultar(): void
    {
        [$cliente] = $this->clienteComCertificado();

        $this->resumoGravado($cliente, self::CHAVE);
        $this->pedidoDeCiencia($cliente, self::CHAVE, FiscalManifestationOutcome::Sent);

        FiscalDocument::factory()->create([
            'account_id' => $cliente->account_id,
            'client_id' => $cliente->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'stage' => FiscalStage::Document,
            'chave_acesso' => self::CHAVE,
        ]);

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consChNFe.xml'), 200)]);

        Artisan::call('fiscal:resync-manifestacoes');

        // O par summary/document fechado é a deduplicação da rotina: o
        // documento já gravado dispensa a consulta, e o `xml_recovered_at`
        // não precisa mover.
        Http::assertNothingSent();
    }

    public function test_o_orcamento_esgotado_para_a_varredura_sem_gastar_tentativa(): void
    {
        [$cliente] = $this->clienteComCertificado();

        $this->resumoGravado($cliente, self::CHAVE);
        $registro = $this->pedidoDeCiencia($cliente, self::CHAVE, FiscalManifestationOutcome::Sent);

        $this->esgotaOrcamento($cliente);

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consChNFe.xml'), 200)]);

        Artisan::call('fiscal:resync-manifestacoes');

        // O teto é por hora e por CNPJ: acabou, a varredura para e a chave
        // volta na passada seguinte — sem tentativa cobrada por uma consulta
        // que não saiu.
        Http::assertNothingSent();
        $this->assertSame(0, $registro->refresh()->resync_attempts);
        $this->assertNull($registro->xml_recovered_at);
    }

    public function test_as_tentativas_por_chave_sao_limitadas(): void
    {
        [$cliente] = $this->clienteComCertificado();

        $this->resumoGravado($cliente, self::CHAVE);
        $registro = $this->pedidoDeCiencia($cliente, self::CHAVE, FiscalManifestationOutcome::Sent);

        // O serviço não tem a chave: cada passada gasta uma vaga e uma
        // tentativa, até o teto configurado — depois disso a chave para de
        // ser consultada e o registro fica como histórico.
        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_137.xml'), 200)]);

        $maximo = (int) config('fiscal.manifestacao_resync_max_attempts', 3);

        for ($passada = 0; $passada < $maximo + 1; $passada++) {
            Artisan::call('fiscal:resync-manifestacoes');
        }

        $this->assertSame($maximo, $registro->refresh()->resync_attempts);
        Http::assertSentCount($maximo);
    }

    public function test_a_manifestacao_nao_debita_o_orcamento_de_consultas(): void
    {
        [$cliente] = $this->clienteComCertificado();

        $this->resumoGravado($cliente, self::CHAVE);
        $this->pedidoDeCiencia($cliente, self::CHAVE);

        Http::fake(['*' => Http::response($this->retEnvEvento('128', 'ok', '135', 'Evento registrado'), 200)]);

        $cnpj = (string) $cliente->tax_id;

        $job = new SendFiscalManifestationJob(
            accountId: (int) $cliente->account_id,
            clientId: (int) $cliente->getKey(),
            chaveAcesso: self::CHAVE,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        $job->handle(
            resolve(FiscalManifestationStore::class),
            resolve(RecepcaoEventoConnector::class),
        );

        // `nfeRecepcaoEvento` não é consulta e o fisco não publica teto de
        // eventos por hora: o contador da `consChNFe` tem de continuar zerado,
        // porque a vaga é da recuperação do XML, não do evento.
        $this->assertSame(0, (int) (Cache::store('array')->get('fiscal:consulta:hora:'.$cnpj) ?? 0));

        // E a vaga que a ressincronização usa continua inteira depois do
        // evento: vinte consultas ainda cabem na hora.
        $budget = resolve(FiscalLookupBudget::class);

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($budget->reserve($cliente), "A vaga {$i} deveria estar livre.");
        }

        $this->assertFalse($budget->reserve($cliente));
    }

    private function resumoGravado(Client $cliente, string $chave): FiscalDocument
    {
        return FiscalDocument::factory()->create([
            'account_id' => $cliente->account_id,
            'client_id' => $cliente->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'stage' => FiscalStage::Summary,
            'chave_acesso' => $chave,
            'emissao_at' => now()->subDays(5),
        ]);
    }

    private function pedidoDeCiencia(Client $cliente, string $chave, ?FiscalManifestationOutcome $outcome = null): FiscalManifestation
    {
        $registro = resolve(FiscalManifestationStore::class)->registrarPedido(
            accountId: (int) $cliente->account_id,
            clientId: (int) $cliente->getKey(),
            chaveAcesso: $chave,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        );

        if ($outcome !== null) {
            $registro->forceFill(['outcome' => $outcome])->save();
        }

        return $registro->refresh();
    }

    private function esgotaOrcamento(Client $cliente): void
    {
        $budget = resolve(FiscalLookupBudget::class);

        for ($i = 0; $i < 20; $i++) {
            $budget->reserve($cliente);
        }

        $this->assertFalse($budget->reserve($cliente));
    }

    /** @return array{0: Client} */
    private function clienteComCertificado(): array
    {
        $account = Account::factory()->create();

        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        ClientCertificate::factory()->withPassword('segredo-unico-9f2b')->create([
            'account_id' => $account->getKey(),
            'client_id' => $cliente->getKey(),
        ]);

        return [$cliente->refresh()];
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path("tests/Fixtures/fiscal/{$name}"));

        $this->assertIsString($contents, "Fixture ausente: {$name}.");

        return $contents;
    }

    private function retEnvEvento(string $cStatLote, string $xMotivoLote, ?string $cStatEvento = null, ?string $xMotivoEvento = null): string
    {
        $retEvento = $cStatEvento === null ? '' : '<retEvento versao="1.00">'
            .'<infEvento>'
            .'<tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            ."<cStat>{$cStatEvento}</cStat><xMotivo>{$xMotivoEvento}</xMotivo>"
            .'<dhRegEvento>2026-10-03T15:00:01-03:00</dhRegEvento>'
            .'</infEvento></retEvento>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeRecepcaoEventoResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/RecepcaoEvento">'
            .'<nfeRecepcaoEventoResult>'
            .'<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">'
            .'<idLote>1</idLote><tpAmb>1</tpAmb><verAplic>AN_1.00</verAplic><cOrgao>91</cOrgao>'
            ."<cStat>{$cStatLote}</cStat><xMotivo>{$xMotivoLote}</xMotivo>"
            .$retEvento
            .'</retEnvEvento></nfeRecepcaoEventoResult>'
            .'</nfeRecepcaoEventoResponse></soap:Body></soap:Envelope>';
    }
}
