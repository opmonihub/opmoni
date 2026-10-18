<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalDeliveryOrigin;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Read\FiscalProcessing;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\BusinessTestCase;

class FiscalProcessingApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
    }

    public function test_processing_list_excludes_ready_documents(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        FiscalDocument::factory()->summary()->create([
            'account_id' => $account->getKey(),
            'client_id' => $cliente->getKey(),
            'processing_state' => FiscalProcessingState::AwaitingScience,
        ]);

        FiscalDocument::factory()->withStoredXml()->create([
            'account_id' => $account->getKey(),
            'client_id' => $cliente->getKey(),
            'processing_state' => FiscalProcessingState::Complete,
        ]);

        $page = resolve(FiscalProcessing::class)->page($account->getKey(), []);
        $this->assertSame(1, $page['rows']->total());
        $this->assertSame(1, $page['summary']['active']);

        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/processing')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.active', 1);
    }

    public function test_processing_lists_available_erp_artifact_conflicts_once_and_preserves_the_accepted_xml(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $accepted = FiscalDocument::factory()->withStoredXml()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::ErpIssued,
            'delivery_origin' => FiscalDeliveryOrigin::ErpIssued,
            'processing_state' => FiscalProcessingState::Complete,
            'processing_reason' => FiscalProcessingReason::Ready,
        ]);
        $acceptedBytes = Storage::disk('fiscal')->get($accepted->storage_path);

        FiscalDocument::factory()->summary()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'chave_acesso' => $accepted->chave_acesso,
            'processing_state' => FiscalProcessingState::AwaitingScience,
        ]);

        $healthy = FiscalDocument::factory()->withStoredXml()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'processing_state' => FiscalProcessingState::Complete,
            'processing_reason' => FiscalProcessingReason::Ready,
        ]);

        resolve(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao, new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            stage: FiscalStage::Document,
            chave: $accepted->chave_acesso,
            eventId: '',
            emitenteCnpj: $accepted->emitente_cnpj,
            destinatarioCnpj: null,
            valorTotal: null,
            digVal: null,
            nsu: 902,
            schema: 'nfeProc_v4.00.xsd',
            emissaoAt: now()->toImmutable(),
            eventoOcorridoEmAt: null,
            xml: '<nfeProc>different incoming artifact</nfeProc>',
        ));

        $accepted = $accepted->fresh();
        $this->assertSame(FiscalStorageState::Available, $accepted->storage_state);
        $this->assertSame(FiscalProcessingState::Attention, $accepted->processing_state);
        $this->assertSame(FiscalProcessingReason::ArtifactConflict, $accepted->processing_reason);
        $this->assertSame(hash('sha256', $acceptedBytes), $accepted->sha256);
        $this->assertSame(strlen($acceptedBytes), $accepted->xml_bytes);
        $this->assertSame($acceptedBytes, Storage::disk('fiscal')->get($accepted->storage_path));

        $reader = $this->membroDe($account, 'user');

        $this->actingAs($reader, 'web')
            ->getJson('/api/v1/fiscal/processing')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $accepted->getKey())
            ->assertJsonPath('data.0.stage', 'document')
            ->assertJsonPath('data.0.storage_state', 'available')
            ->assertJsonPath('data.0.processing_state', 'attention')
            ->assertJsonPath('data.0.processing_reason', 'artifact_conflict')
            ->assertJsonPath('summary.attention', 1);

        $this->actingAs($reader, 'web')
            ->getJson('/api/v1/fiscal/processing?state=attention')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $accepted->getKey())
            ->assertJsonPath('summary.attention', 1);

        $this->actingAs($reader, 'web')
            ->getJson('/api/v1/fiscal/processing/summary')
            ->assertOk()
            ->assertJsonPath('data.attention', 1);

        $this->actingAs($reader, 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => $accepted->getKey()])
            ->assertJsonFragment(['id' => $healthy->getKey()]);

        $accepted = $accepted->fresh();
        $this->assertSame(FiscalStorageState::Available, $accepted->storage_state);
        $this->assertSame(FiscalProcessingState::Attention, $accepted->processing_state);
        $this->assertSame(FiscalProcessingReason::ArtifactConflict, $accepted->processing_reason);
        $this->assertSame(hash('sha256', $acceptedBytes), $accepted->sha256);
        $this->assertSame(strlen($acceptedBytes), $accepted->xml_bytes);
        $this->assertSame($acceptedBytes, Storage::disk('fiscal')->get($accepted->storage_path));
    }

    public function test_processing_filters_and_authorization_do_not_disclose_another_accounts_rows(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $ownSummary = FiscalDocument::factory()->summary()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'processing_state' => FiscalProcessingState::AwaitingScience,
        ]);

        $otherAccount = Account::factory()->create();
        $foreignClient = Client::factory()->company()->create([
            'account_id' => $otherAccount->getKey(),
            'name' => 'Foreign processing client',
        ]);
        $foreignSummary = FiscalDocument::factory()->summary()->create([
            'account_id' => $otherAccount->getKey(),
            'client_id' => $foreignClient->getKey(),
            'processing_state' => FiscalProcessingState::Attention,
        ]);

        $reader = $this->membroDe($account, 'user');

        $this->actingAs($reader, 'web')
            ->getJson('/api/v1/fiscal/processing?client_id='.$foreignClient->getKey())
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data')
            ->assertDontSee('Foreign processing client', false);

        $otherAccountReader = $this->membroDe($otherAccount, 'user');

        $otherAccountResponse = $this->actingAs($otherAccountReader, 'web')
            ->getJson('/api/v1/fiscal/processing')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $foreignSummary->getKey());
        $this->assertSame([$foreignSummary->getKey()], array_column($otherAccountResponse->json('data'), 'id'));
        $this->assertNotSame($ownSummary->getKey(), $otherAccountResponse->json('data.0.id'));

        $outsider = User::factory()->create();
        $this->selectBusinessAccount($outsider, $account->getKey());

        $this->actingAs($outsider, 'web')
            ->getJson('/api/v1/fiscal/processing')
            ->assertForbidden()
            ->assertDontSee('Foreign processing client', false);
    }

    private function membroDe(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
