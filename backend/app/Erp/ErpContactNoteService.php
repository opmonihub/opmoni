<?php

namespace App\Erp;

use App\Models\Erp\ErpContactAttachment;
use App\Models\Erp\ErpContactNote;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ErpContactNoteService
{
    public const DISK = 'erp-contact-attachments';

    public const MAX_ATTACHMENT_BYTES = 1_000_000;

    /** @var array<string, list<string>> */
    private const MIME_TYPES = [
        'csv' => ['text/csv', 'text/plain'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'jpeg' => ['image/jpeg'],
        'jpg' => ['image/jpeg'],
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'zip' => ['application/zip'],
    ];

    /** @param array{heading?: ?string, description?: ?string, is_private?: bool} $data */
    public function createNote(int $companyId, int $contactId, User $actor, array $data): ErpContactNote
    {
        $this->assertTextContent($data['heading'] ?? null, $data['description'] ?? null);

        return ErpContactNote::query()->create([
            'erp_company_id' => $companyId,
            'erp_contact_id' => $contactId,
            'created_by' => $actor->getKey(),
            'heading' => $data['heading'] ?? null,
            'description' => $data['description'] ?? null,
            'is_private' => $data['is_private'] ?? false,
            'state' => 'active',
        ]);
    }

    /** @param array{heading?: ?string, description?: ?string, is_private?: bool} $data */
    public function updateNote(ErpContactNote $note, User $actor, array $data): ErpContactNote
    {
        if ($note->state !== 'active') {
            abort(response()->json(['code' => 'deletion_pending', 'message' => 'A anotação está aguardando remoção.'], 409));
        }

        $heading = array_key_exists('heading', $data) ? $data['heading'] : $note->heading;
        $description = array_key_exists('description', $data) ? $data['description'] : $note->description;
        $this->assertTextContent($heading, $description);

        if (array_key_exists('is_private', $data)
            && $data['is_private'] !== $note->is_private
            && (int) $note->created_by !== (int) $actor->getKey()) {
            abort(403, 'Somente o autor pode alterar a privacidade da anotação.');
        }

        $note->fill($data);
        $note->save();

        return $note->fresh();
    }

    public function archiveNote(int $companyId, ErpContactNote $note): void
    {
        $noteId = (int) $note->getKey();
        ErpContactNote::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($noteId)
            ->where('state', 'active')
            ->update(['state' => 'deletion_pending', 'updated_at' => now()]);

        ErpContactAttachment::query()
            ->where('erp_company_id', $companyId)
            ->where('erp_contact_note_id', $noteId)
            ->whereIn('status', ['ready', 'unavailable'])
            ->update(['status' => 'deletion_pending', 'updated_at' => now()]);

        DB::afterCommit(static function () use ($companyId, $noteId): void {
            self::finishPendingNoteRemoval($companyId, $noteId);
        });
    }

    /** @return array{attachment: ErpContactAttachment, available: bool} */
    public function storeAttachment(int $companyId, ErpContactNote $note, UploadedFile $file): array
    {
        if ($note->state !== 'active') {
            abort(response()->json(['code' => 'deletion_pending', 'message' => 'A anotação está aguardando remoção.'], 409));
        }

        $fileData = $this->validatedFileData($file);
        $storageKey = bin2hex(random_bytes(32));
        $this->registerRollbackCleanup($storageKey);

        $available = false;
        try {
            $disk = Storage::disk(self::DISK);
            if ($disk->put($storageKey, $fileData['contents']) === true) {
                $stored = $this->readDiskFile($disk, $storageKey);
                $available = is_string($stored)
                    && strlen($stored) === $fileData['byte_length']
                    && hash_equals($fileData['sha256'], hash('sha256', $stored));
            }
        } catch (Throwable) {
            $available = false;
        }

        $attachment = ErpContactAttachment::query()->create([
            'erp_company_id' => $companyId,
            'erp_contact_note_id' => $note->getKey(),
            'original_name' => $fileData['original_name'],
            'mime_type' => $fileData['mime_type'],
            'byte_length' => $fileData['byte_length'],
            'sha256' => $fileData['sha256'],
            'storage_key' => $storageKey,
            'status' => $available ? 'ready' : 'unavailable',
        ]);

        if (! $available) {
            try {
                Storage::disk(self::DISK)->delete($storageKey);
            } catch (Throwable) {
            }
        }

        return ['attachment' => $attachment, 'available' => $available];
    }

    public function archiveAttachment(int $companyId, ErpContactNote $note, ErpContactAttachment $attachment): void
    {
        $noteId = (int) $note->getKey();
        $attachmentId = (int) $attachment->getKey();
        $storageKey = (string) $attachment->storage_key;
        ErpContactAttachment::query()
            ->where('erp_company_id', $companyId)
            ->where('erp_contact_note_id', $noteId)
            ->whereKey($attachmentId)
            ->whereIn('status', ['ready', 'unavailable'])
            ->update(['status' => 'deletion_pending', 'updated_at' => now()]);

        DB::afterCommit(static function () use ($companyId, $noteId, $attachmentId, $storageKey): void {
            self::finishPendingAttachmentRemoval(
                $companyId,
                $noteId,
                $attachmentId,
                $storageKey,
            );
        });
    }

    public function verifiedAttachmentBytes(int $companyId, ErpContactNote $note, ErpContactAttachment $attachment): ?string
    {
        if ((int) $attachment->byte_length < 0 || (int) $attachment->byte_length > self::MAX_ATTACHMENT_BYTES) {
            $this->markUnavailable($companyId, $note, $attachment);

            return null;
        }

        try {
            $bytes = $this->readDiskFile(Storage::disk(self::DISK), (string) $attachment->storage_key);
        } catch (Throwable) {
            $bytes = null;
        }

        if (! is_string($bytes)
            || strlen($bytes) !== (int) $attachment->byte_length
            || ! hash_equals((string) $attachment->sha256, hash('sha256', $bytes))) {
            $this->markUnavailable($companyId, $note, $attachment);

            return null;
        }

        return $bytes;
    }

    /** @param array{original_name: string, mime_type: string, byte_length: int, sha256: string, contents: string} $fileData */
    private function registerRollbackCleanup(string $storageKey): void
    {
        DB::afterRollBack(static function () use ($storageKey): void {
            try {
                Storage::disk(self::DISK)->delete($storageKey);
            } catch (Throwable) {
            }
        });
    }

    /** @return array{original_name: string, mime_type: string, byte_length: int, sha256: string, contents: string} */
    private function validatedFileData(UploadedFile $file): array
    {
        $name = $file->getClientOriginalName();
        $clientPath = $file->getClientOriginalPath();
        if ($name === ''
            || ! mb_check_encoding($name, 'UTF-8')
            || ! mb_check_encoding($clientPath, 'UTF-8')
            || mb_strlen($name, 'UTF-8') > 255
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $clientPath) === 1
            || str_contains($clientPath, '/')
            || str_contains($clientPath, '\\')) {
            throw ValidationException::withMessages(['file' => 'O nome do arquivo não é válido.']);
        }

        $path = $file->getRealPath();
        $stream = is_string($path) ? @fopen($path, 'rb') : false;
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o arquivo enviado.']);
        }

        try {
            $contents = stream_get_contents($stream, self::MAX_ATTACHMENT_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($contents)) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o arquivo enviado.']);
        }

        $byteLength = strlen($contents);
        if ($byteLength > self::MAX_ATTACHMENT_BYTES) {
            throw ValidationException::withMessages(['file' => 'O arquivo deve ter no máximo 1.000.000 bytes.']);
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! is_string($detectedMime)
            || ! in_array($detectedMime, self::MIME_TYPES[$extension] ?? [], true)
            || ($extension === 'csv' && ! $this->isReadableCsv($contents))) {
            throw ValidationException::withMessages(['file' => 'O formato real do arquivo não corresponde a uma extensão aceita.']);
        }

        return [
            'original_name' => $name,
            'mime_type' => $detectedMime,
            'byte_length' => $byteLength,
            'sha256' => hash('sha256', $contents),
            'contents' => $contents,
        ];
    }

    private function readDiskFile(FilesystemAdapter $disk, string $storageKey): ?string
    {
        $stream = $disk->readStream($storageKey);
        if (! is_resource($stream)) {
            return null;
        }

        try {
            $contents = stream_get_contents($stream, self::MAX_ATTACHMENT_BYTES + 1);
        } finally {
            fclose($stream);
        }

        if (! is_string($contents) || strlen($contents) > self::MAX_ATTACHMENT_BYTES) {
            return null;
        }

        return $contents;
    }

    private function markUnavailable(int $companyId, ErpContactNote $note, ErpContactAttachment $attachment): void
    {
        try {
            ErpContactAttachment::query()
                ->where('erp_company_id', $companyId)
                ->where('erp_contact_note_id', $note->getKey())
                ->whereKey($attachment->getKey())
                ->where('status', 'ready')
                ->update(['status' => 'unavailable', 'updated_at' => now()]);
        } catch (Throwable) {
        }
    }

    private function isReadableCsv(string $contents): bool
    {
        if (trim($contents) === ''
            || str_contains($contents, "\0")
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents) === 1) {
            return false;
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return false;
        }

        try {
            if (fwrite($stream, $contents) !== strlen($contents)) {
                return false;
            }
            rewind($stream);

            return is_array(fgetcsv($stream, null, ',', '"', ''));
        } finally {
            fclose($stream);
        }
    }

    private function assertTextContent(?string $heading, ?string $description): void
    {
        if (trim((string) $heading) === '' && trim((string) $description) === '') {
            throw ValidationException::withMessages(['heading' => 'Informe um título ou uma anotação.']);
        }
    }

    private static function finishPendingAttachmentRemoval(int $companyId, int $noteId, int $attachmentId, string $storageKey): void
    {
        self::deletePendingAttachment($companyId, $noteId, $attachmentId, $storageKey);

        try {
            if (ErpContactNote::query()
                ->where('erp_company_id', $companyId)
                ->whereKey($noteId)
                ->where('state', 'deletion_pending')
                ->exists()) {
                self::finishPendingNoteRemoval($companyId, $noteId);
            }
        } catch (Throwable) {
        }
    }

    private static function finishPendingNoteRemoval(int $companyId, int $noteId): void
    {
        try {
            $pending = ErpContactAttachment::query()
                ->where('erp_company_id', $companyId)
                ->where('erp_contact_note_id', $noteId)
                ->where('status', 'deletion_pending')
                ->get(['id', 'storage_key']);

            foreach ($pending as $attachment) {
                self::deletePendingAttachment($companyId, $noteId, (int) $attachment->getKey(), (string) $attachment->storage_key);
            }

            $hasAttachments = ErpContactAttachment::query()
                ->where('erp_company_id', $companyId)
                ->where('erp_contact_note_id', $noteId)
                ->exists();
            if (! $hasAttachments) {
                ErpContactNote::query()
                    ->where('erp_company_id', $companyId)
                    ->whereKey($noteId)
                    ->where('state', 'deletion_pending')
                    ->delete();
            }
        } catch (Throwable) {
        }
    }

    private static function deletePendingAttachment(int $companyId, int $noteId, int $attachmentId, string $storageKey): bool
    {
        try {
            $row = ErpContactAttachment::query()
                ->where('erp_company_id', $companyId)
                ->where('erp_contact_note_id', $noteId)
                ->whereKey($attachmentId)
                ->where('storage_key', $storageKey)
                ->where('status', 'deletion_pending')
                ->first();
            if ($row === null) {
                return ! ErpContactAttachment::query()
                    ->where('erp_company_id', $companyId)
                    ->where('erp_contact_note_id', $noteId)
                    ->whereKey($attachmentId)
                    ->exists();
            }

            if (preg_match('/\A[a-f0-9]{64}\z/', $storageKey) !== 1) {
                return false;
            }

            $diskConfiguration = config('filesystems.disks.'.self::DISK);
            if (! is_array($diskConfiguration) || ($diskConfiguration['driver'] ?? null) !== 'local') {
                return false;
            }

            $disk = Storage::disk(self::DISK);
            if (! $disk instanceof FilesystemAdapter || app(Filesystem::class)->delete($disk->path($storageKey)) !== true) {
                return false;
            }

            return DB::transaction(function () use ($companyId, $noteId, $attachmentId, $storageKey): bool {
                $query = ErpContactAttachment::query()
                    ->where('erp_company_id', $companyId)
                    ->where('erp_contact_note_id', $noteId)
                    ->whereKey($attachmentId)
                    ->where('storage_key', $storageKey)
                    ->where('status', 'deletion_pending');
                $deleted = $query->delete();

                return $deleted === 1 || ! ErpContactAttachment::query()
                    ->where('erp_company_id', $companyId)
                    ->where('erp_contact_note_id', $noteId)
                    ->whereKey($attachmentId)
                    ->exists();
            });
        } catch (Throwable) {
            return false;
        }
    }
}
