<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpReceiptProofs;
use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpReceiptController;
use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpReceiptProofController;
use App\Http\Middleware\ResolveOfficeErpCommercial;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptProof;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\OfficeErpCommercialTestCase;

class ReceiptProofTest extends OfficeErpCommercialTestCase
{
    /** @var list<string> */
    private array $storageRoots = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerReceiptTestRoutes();
        $this->usePrivateLocalDisk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        try {
            parent::tearDown();
        } finally {
            $filesystem = new Filesystem;
            foreach ($this->storageRoots as $root) {
                $filesystem->deleteDirectory($root);
            }
        }
    }

    public function test_actual_pdf_png_and_jpeg_content_is_staged_in_private_storage(): void
    {
        $ctx = $this->officeContext();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true);
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9k=', true);
        self::assertIsString($png);
        self::assertIsString($jpeg);
        $formats = [
            ['evidencia.pdf', "%PDF-1.4\nComprovante válido\n%%EOF\n", 'application/pdf'],
            ['evidencia.png', $png, 'image/png'],
            ['evidencia.jpg', $jpeg, 'image/jpeg'],
        ];

        foreach ($formats as [$name, $bytes, $mime]) {
            $response = $this->asOffice($ctx)->post('/api/v1/erp-commercial/receipt-proofs', [
                'file' => UploadedFile::fake()->createWithContent($name, $bytes),
            ])->assertCreated();

            self::assertArrayNotHasKey('path', $response->json('data'));
            $proof = OfficeErpReceiptProof::query()->findOrFail($response->json('data.id'));
            self::assertSame($ctx->accountId, (int) $proof->account_id);
            self::assertSame((int) $ctx->actor->id, (int) $proof->actor_user_id);
            self::assertSame($mime, $proof->mime_type);
            self::assertNull($proof->receipt_id);
            self::assertStringStartsWith('office-erp/receipt-proofs/', $proof->path);
            Storage::disk('local')->assertExists($proof->path);
            self::assertSame(strlen($bytes), (int) $proof->byte_length);
            self::assertSame(hash('sha256', $bytes), $proof->sha256);
        }
    }

    public function test_oversized_and_disguised_proof_content_is_rejected(): void
    {
        $ctx = $this->officeContext();
        $path = '/api/v1/erp-commercial/receipt-proofs';

        $this->asOffice($ctx)->post($path, [
            'file' => UploadedFile::fake()->createWithContent('grande.pdf', "%PDF-1.4\n".str_repeat('x', 5 * 1024 * 1024)),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->asOffice($ctx)->post($path, [
            'file' => UploadedFile::fake()->createWithContent('disfarce.pdf', 'texto simples sem assinatura PDF'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        self::assertSame(0, OfficeErpReceiptProof::query()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_proof_is_bound_to_the_staging_actor_and_download_requires_claimed_seller_receipt(): void
    {
        $ctx = $this->officeContext();
        $invoice = $this->makeIssuedInvoice($ctx, '100.00');
        $proof = $this->stagePdf($ctx);
        $this->asOffice($ctx)->get('/api/v1/erp-commercial/receipt-proofs/'.$proof['id'])->assertNotFound();

        $otherActor = $this->officeContextFor($ctx->account);
        $this->asOffice($otherActor)->postJson('/api/v1/erp-commercial/receipts', $this->receiptInput($invoice, $proof['id']), [
            'Idempotency-Key' => 'proof-other-actor-claim',
        ])->assertUnprocessable();
        self::assertNull(OfficeErpReceiptProof::query()->findOrFail($proof['id'])->receipt_id);

        $receipt = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $this->receiptInput($invoice, $proof['id']), [
            'Idempotency-Key' => 'proof-owner-claim',
        ])->assertCreated()->json('data');
        $storedProof = OfficeErpReceiptProof::query()->findOrFail($proof['id']);
        self::assertSame((int) $receipt['id'], (int) $storedProof->receipt_id);

        $foreignOffice = $this->officeContext();
        $this->asOffice($foreignOffice)->get('/api/v1/erp-commercial/receipt-proofs/'.$proof['id'])->assertNotFound();

        $download = $this->asOffice($ctx)->get('/api/v1/erp-commercial/receipt-proofs/'.$proof['id'])->assertOk();
        $download->assertStreamedContent("%PDF-1.4\nComprovante privado\n%%EOF\n");
        self::assertSame('application/pdf', $download->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        self::assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
    }

    public function test_failed_receipt_commit_keeps_staged_private_proof_unclaimed(): void
    {
        $ctx = $this->officeContext();
        $invoice = $this->makeIssuedInvoice($ctx, '100.00');
        $proof = $this->stagePdf($ctx);
        $beforeReceiptCount = OfficeErpReceiptProof::query()->whereNotNull('receipt_id')->count();
        DB::unprepared("CREATE TEMP TRIGGER fail_test_receipt_allocation BEFORE INSERT ON office_erp_receipt_allocations BEGIN SELECT RAISE(ABORT, 'receipt allocation failure fixture'); END");

        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $this->receiptInput($invoice, $proof['id']), [
            'Idempotency-Key' => 'proof-failed-receipt-commit',
        ])->assertServerError();

        self::assertSame(0, OfficeErpReceipt::query()->count());
        self::assertSame($beforeReceiptCount, OfficeErpReceiptProof::query()->whereNotNull('receipt_id')->count());
        self::assertNull(OfficeErpReceiptProof::query()->findOrFail($proof['id'])->receipt_id);
        Storage::disk('local')->assertExists($proof['path']);
    }

    public function test_expiry_cleanup_deletes_only_verified_unclaimed_proof_objects(): void
    {
        $ctx = $this->officeContext();
        $invoice = $this->makeIssuedInvoice($ctx, '100.00');
        $start = CarbonImmutable::parse('2026-10-25 12:00:00', 'America/Sao_Paulo');
        Carbon::setTestNow($start);
        $expired = $this->stagePdf($ctx);
        $corrupt = $this->stagePdf($ctx);
        $claimed = $this->stagePdf($ctx);
        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $this->receiptInput($invoice, $claimed['id']), [
            'Idempotency-Key' => 'proof-cleanup-claimed',
        ])->assertCreated();
        Storage::disk('local')->put($corrupt['path'], "%PDF-1.4\ncorrompido\n%%EOF\n");

        Carbon::setTestNow($start->addHours(25));
        $unexpired = $this->stagePdf($ctx);
        Storage::disk('local')->put('unrelated/private-sentinel.dat', 'preservar');
        $deletedCount = app(OfficeErpReceiptProofs::class)->cleanExpired(CarbonImmutable::now());

        self::assertSame(1, $deletedCount);
        Storage::disk('local')->assertMissing($expired['path']);
        Storage::disk('local')->assertExists($corrupt['path']);
        Storage::disk('local')->assertExists($claimed['path']);
        Storage::disk('local')->assertExists($unexpired['path']);
        Storage::disk('local')->assertExists('unrelated/private-sentinel.dat');
        self::assertNull(OfficeErpReceiptProof::query()->find($expired['id']));
        self::assertNotNull(OfficeErpReceiptProof::query()->findOrFail($corrupt['id']));
    }

    private function registerReceiptTestRoutes(): void
    {
        Route::middleware(['web', 'auth', ResolveOfficeErpCommercial::class])
            ->prefix('api/v1/erp-commercial')
            ->group(function (): void {
                Route::get('/receipts', [OfficeErpReceiptController::class, 'index'])->name('test.office-erp.receipts.index');
                Route::post('/receipts', [OfficeErpReceiptController::class, 'store'])->name('test.office-erp.receipts.store');
                Route::get('/receipts/{receipt}', [OfficeErpReceiptController::class, 'show'])->whereNumber('receipt')->name('test.office-erp.receipts.show');
                Route::post('/receipts/{receipt}/reverse', [OfficeErpReceiptController::class, 'reverse'])->whereNumber('receipt')->name('test.office-erp.receipts.reverse');
                Route::post('/receipt-proofs', [OfficeErpReceiptProofController::class, 'store'])->name('test.office-erp.receipt-proofs.store');
                Route::get('/receipt-proofs/{proofId}', [OfficeErpReceiptProofController::class, 'download'])->name('test.office-erp.receipt-proofs.download');
            });
    }

    private function usePrivateLocalDisk(): void
    {
        $root = sys_get_temp_dir().'/opmoni-office-erp-proofs-'.getmypid().'-'.bin2hex(random_bytes(12));
        if (! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new \RuntimeException('Unable to create the private office ERP proof test root.');
        }
        $this->storageRoots[] = $root;
        Storage::set('local', Storage::build([
            'driver' => 'local',
            'root' => $root,
            'serve' => false,
            'throw' => false,
            'report' => false,
        ]));
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return $this->withHeader('X-Account-Id', (string) $ctx->accountId);
    }

    /** @return array{id:string,expires_at:string,path:string} */
    private function stagePdf(OfficeErpExecutionContext $ctx): array
    {
        $response = $this->asOffice($ctx)->post('/api/v1/erp-commercial/receipt-proofs', [
            'file' => UploadedFile::fake()->createWithContent('comprovante.pdf', "%PDF-1.4\nComprovante privado\n%%EOF\n"),
        ])->assertCreated();
        $id = (string) $response->json('data.id');
        $proof = OfficeErpReceiptProof::query()->findOrFail($id);

        return ['id' => $id, 'expires_at' => (string) $response->json('data.expires_at'), 'path' => (string) $proof->path];
    }

    private function makeIssuedInvoice(OfficeErpExecutionContext $ctx, string $amount): OfficeErpInvoice
    {
        $contract = $this->withOfficeAccount($ctx, fn (): OfficeErpContract => $this->acceptedContract($ctx));

        return $this->issuedInvoice($ctx, $contract, $amount);
    }

    /** @return array{amount:string,received_on:string,proof_id:string,allocations:list<array{invoice_id:int,amount:string}>} */
    private function receiptInput(OfficeErpInvoice $invoice, string $proofId): array
    {
        return [
            'amount' => '10.00',
            'received_on' => now('America/Sao_Paulo')->toDateString(),
            'proof_id' => $proofId,
            'allocations' => [['invoice_id' => (int) $invoice->id, 'amount' => '10.00']],
        ];
    }
}
