<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptProof;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class OfficeErpReceiptProofs
{
    public const MAX_PROOF_BYTES = 5 * 1024 * 1024;

    private const DISK = 'local';

    private const STORAGE_PREFIX = 'office-erp/receipt-proofs/';

    public function __construct(
        private readonly OfficeErpAdmission $admission,
    ) {}

    /** @return array{id:string,expires_at:string} */
    public function stage(OfficeErpExecutionContext $ctx, UploadedFile $file): array
    {
        $ctx->assertVerified();
        abort_unless($ctx->actorKind === 'office_admin' && $ctx->actor !== null, 403);
        $capabilities = $this->admission->capabilities($ctx);
        abort_unless($capabilities['can_maintain'], 403);

        $fileData = $this->validatedFileData($file);
        $id = (string) Str::uuid();
        $path = self::STORAGE_PREFIX.$ctx->accountId.'/'.$id.'.'.$fileData['extension'];
        $disk = Storage::disk(self::DISK);

        try {
            if ($disk->put($path, $fileData['contents']) !== true) {
                abort(503, 'Não foi possível armazenar a evidência privada.');
            }
            $stored = $this->readStoredPath($path);
            if ($stored === null
                || strlen($stored) !== $fileData['byte_length']
                || ! hash_equals($fileData['sha256'], hash('sha256', $stored))) {
                abort(503, 'A evidência privada não passou na verificação de integridade.');
            }

            $expiresAt = CarbonImmutable::now()->addHours(24);
            DB::afterRollBack(static function () use ($path): void {
                try {
                    Storage::disk(self::DISK)->delete($path);
                } catch (Throwable) {
                }
            });

            OfficeErpRecords::persist($ctx, new OfficeErpReceiptProof([
                'id' => $id,
                'account_id' => $ctx->accountId,
                'actor_user_id' => $ctx->actor->getKey(),
                'receipt_id' => null,
                'original_name' => $fileData['original_name'],
                'mime_type' => $fileData['mime_type'],
                'byte_length' => $fileData['byte_length'],
                'sha256' => $fileData['sha256'],
                'path' => $path,
                'expires_at' => $expiresAt,
                'claimed_at' => null,
            ]));
        } catch (Throwable $exception) {
            try {
                $disk->delete($path);
            } catch (Throwable) {
            }

            throw $exception;
        }

        return ['id' => $id, 'expires_at' => $expiresAt->toISOString()];
    }

    public function attach(OfficeErpExecutionContext $ctx, OfficeErpReceipt $receipt, string $proofId): void
    {
        $ctx->assertVerified();
        abort_unless(DB::transactionLevel() > 0, 409, 'commercial_transaction_required');
        abort_unless((int) $receipt->account_id === $ctx->accountId && $receipt->exists, 404);

        $proof = OfficeErpRecords::query($ctx, OfficeErpReceiptProof::class)
            ->whereKey($proofId)
            ->lockForUpdate()
            ->first();
        if ($proof === null
            || (int) $proof->actor_user_id !== (int) $ctx->actor?->getKey()
            || $proof->receipt_id !== null
            || $proof->claimed_at !== null
            || $proof->expires_at === null
            || ! $proof->expires_at->isFuture()
            || $this->verifiedBytes($proof) === null) {
            throw ValidationException::withMessages(['proof_id' => ['A evidência não está disponível para este operador.']]);
        }

        $proof->forceFill(['receipt_id' => $receipt->getKey(), 'claimed_at' => now()]);
        OfficeErpRecords::persist($ctx, $proof);
        $receipt->forceFill(['proof_path' => $proof->path]);
        OfficeErpRecords::persist($ctx, $receipt);
    }

    public function download(OfficeErpExecutionContext $ctx, string $proofId): StreamedResponse
    {
        $ctx->assertVerified();
        $proof = OfficeErpRecords::query($ctx, OfficeErpReceiptProof::class)
            ->whereKey($proofId)
            ->firstOrFail();
        abort_if($proof->receipt_id === null || $proof->claimed_at === null, 404);

        $receipt = OfficeErpRecords::query($ctx, OfficeErpReceipt::class)
            ->whereKey($proof->receipt_id)
            ->firstOrFail();
        $allocationRows = OfficeErpRecords::query($ctx, OfficeErpReceiptAllocation::class)
            ->where('receipt_id', $receipt->getKey())
            ->get(['invoice_id']);
        abort_if($allocationRows->isEmpty(), 404);
        $contractIds = [];
        foreach ($allocationRows as $allocation) {
            $invoice = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
                ->whereKey($allocation->invoice_id)
                ->firstOrFail();
            $contractIds[] = (int) $invoice->contract_id;
        }
        foreach (array_values(array_unique($contractIds)) as $contractId) {
            $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                ->whereKey($contractId)
                ->firstOrFail();
            $this->admission->assertCommand($ctx, 'receipt.read', $contract);
        }

        $bytes = $this->verifiedBytes($proof);
        abort_unless($bytes !== null, 503, 'A evidência privada não está disponível ou falhou na verificação de integridade.');

        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $proof->original_name) ?: 'comprovante';
        $disposition = HeaderUtils::makeDisposition('attachment', $proof->original_name, $fallbackName);

        return new StreamedResponse(
            static function () use ($bytes): void {
                echo $bytes;
            },
            200,
            [
                'Content-Type' => (string) $proof->mime_type,
                'Content-Disposition' => $disposition,
                'Content-Length' => (string) strlen($bytes),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function cleanExpired(CarbonImmutable $now): int
    {
        $accountIds = OfficeErpReceiptProof::query()
            ->whereNull('receipt_id')
            ->whereNull('claimed_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('account_id')
            ->distinct()
            ->pluck('account_id');
        $deleted = 0;

        foreach ($accountIds as $accountId) {
            $account = Account::query()->whereKey($accountId)->where('status', 'active')->first();
            if ($account === null) {
                continue;
            }
            $ctx = OfficeErpExecutionContext::system($account, 'billing:receipt-proof-cleanup');
            $deleted += $ctx->run(function () use ($ctx, $now): int {
                return DB::transaction(function () use ($ctx, $now): int {
                    $proofs = OfficeErpRecords::query($ctx, OfficeErpReceiptProof::class)
                        ->whereNull('receipt_id')
                        ->whereNull('claimed_at')
                        ->where('expires_at', '<=', $now)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $removed = 0;
                    $disk = Storage::disk(self::DISK);

                    foreach ($proofs as $proof) {
                        if ($this->verifiedBytes($proof) === null) {
                            continue;
                        }
                        if ($disk->delete($proof->path) !== true) {
                            continue;
                        }
                        if ($proof->delete()) {
                            $removed++;
                        }
                    }

                    return $removed;
                }, 3);
            });
        }

        return $deleted;
    }

    /** @return array{original_name:string,extension:string,mime_type:string,byte_length:int,sha256:string,contents:string} */
    private function validatedFileData(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => ['O arquivo enviado não é válido.']]);
        }

        $name = $file->getClientOriginalName();
        $clientPath = $file->getClientOriginalPath();
        if ($name === ''
            || ! mb_check_encoding($name, 'UTF-8')
            || ! mb_check_encoding($clientPath, 'UTF-8')
            || mb_strlen($name, 'UTF-8') > 191
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $clientPath) === 1
            || str_contains($clientPath, '/')
            || str_contains($clientPath, '\\')) {
            throw ValidationException::withMessages(['file' => ['O nome do arquivo não é válido.']]);
        }

        $filePath = $file->getRealPath();
        $stream = is_string($filePath) ? @fopen($filePath, 'rb') : false;
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['file' => ['Não foi possível ler o arquivo enviado.']]);
        }

        try {
            $contents = stream_get_contents($stream, self::MAX_PROOF_BYTES + 1);
        } finally {
            fclose($stream);
        }

        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['file' => ['O arquivo enviado está vazio ou não pôde ser lido.']]);
        }
        $byteLength = strlen($contents);
        if ($byteLength > self::MAX_PROOF_BYTES) {
            throw ValidationException::withMessages(['file' => ['A evidência deve ter no máximo 5 MiB.']]);
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $expectedMime = match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
        if (! is_string($mimeType) || $expectedMime === null || $mimeType !== $expectedMime || ! $this->hasActualFormat($contents, $expectedMime)) {
            throw ValidationException::withMessages(['file' => ['O conteúdo real não corresponde a um PDF, PNG ou JPEG válido.']]);
        }

        return [
            'original_name' => $name,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'byte_length' => $byteLength,
            'sha256' => hash('sha256', $contents),
            'contents' => $contents,
        ];
    }

    private function hasActualFormat(string $contents, string $mimeType): bool
    {
        return match ($mimeType) {
            'application/pdf' => preg_match('/\A%PDF-[1-2]\.\d(?:\r\n|\r|\n)/', $contents) === 1
                && preg_match('/%%EOF[\x00-\x20]*\z/', $contents) === 1,
            'image/png' => ($image = @getimagesizefromstring($contents)) !== false && $image[2] === IMAGETYPE_PNG,
            'image/jpeg' => ($image = @getimagesizefromstring($contents)) !== false && $image[2] === IMAGETYPE_JPEG,
            default => false,
        };
    }

    private function verifiedBytes(OfficeErpReceiptProof $proof): ?string
    {
        $extension = match ($proof->mime_type) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/jpeg' => in_array(pathinfo((string) $proof->path, PATHINFO_EXTENSION), ['jpg', 'jpeg'], true)
                ? pathinfo((string) $proof->path, PATHINFO_EXTENSION)
                : null,
            default => null,
        };
        $expectedPath = self::STORAGE_PREFIX.(int) $proof->account_id.'/'.(string) $proof->id.'.'.$extension;
        if ((int) $proof->byte_length <= 0
            || (int) $proof->byte_length > self::MAX_PROOF_BYTES
            || ! preg_match('/^[a-f0-9]{64}$/', (string) $proof->sha256)
            || ! Str::isUuid((string) $proof->id)
            || $extension === null
            || (string) $proof->path !== $expectedPath) {
            return null;
        }

        $bytes = $this->readStoredPath((string) $proof->path);
        if (! is_string($bytes)
            || strlen($bytes) !== (int) $proof->byte_length
            || ! hash_equals((string) $proof->sha256, hash('sha256', $bytes))) {
            return null;
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($mimeType)
            && $mimeType === $proof->mime_type
            && $this->hasActualFormat($bytes, $mimeType)
            ? $bytes
            : null;
    }

    private function readStoredPath(string $path): ?string
    {
        try {
            $disk = Storage::disk(self::DISK);
            if (! $disk->exists($path)) {
                return null;
            }
            $bytes = $disk->get($path);

            return is_string($bytes) ? $bytes : null;
        } catch (Throwable) {
            return null;
        }
    }
}
