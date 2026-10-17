<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use JsonSerializable;

/** A frozen generation; secret material is accessible only through explicit methods. */
final readonly class SerproCredentialSnapshot implements JsonSerializable
{
    /** @param array{key: ?string, secret: ?string, certificate: ?string, password: ?string} $encrypted */
    public function __construct(
        public int $connectionId,
        public int $connectionRevision,
        public string $source,
        public ?int $accountId,
        public ?string $accountStatus,
        public ?int $sourceId,
        public ?int $sourceRevision,
        public string $document,
        public ?CarbonImmutable $notBefore,
        public ?CarbonImmutable $notAfter,
        public ?string $state,
        private array $encrypted,
        private string $generation,
    ) {}

    public function consumerKey(): string
    {
        return Crypt::decryptString($this->encrypted['key']);
    }

    public function consumerSecret(): string
    {
        return Crypt::decryptString($this->encrypted['secret']);
    }

    public function certificateBytes(): ?string
    {
        if ($this->encrypted['certificate'] === null) {
            return null;
        }
        $bytes = Crypt::decryptString($this->encrypted['certificate']);

        return $this->source === 'legacy_account' ? (base64_decode($bytes, true) ?: null) : $bytes;
    }

    public function certificatePassword(): ?string
    {
        return $this->encrypted['password'] === null ? null : Crypt::decryptString($this->encrypted['password']);
    }

    public function isConfigured(): bool
    {
        return $this->encrypted['key'] !== null && $this->encrypted['secret'] !== null && $this->consumerKey() !== '';
    }

    public function hasCertificate(): bool
    {
        return $this->encrypted['certificate'] !== null && $this->encrypted['password'] !== null;
    }

    public function hasUsableCertificate(): bool
    {
        return $this->hasCertificate()
            && ($this->accountId === null || $this->accountStatus === 'active')
            && $this->notBefore !== null && ! $this->notBefore->isFuture()
            && $this->notAfter !== null && ! $this->notAfter->isPast();
    }

    public function sameGeneration(self $other): bool
    {
        return hash_equals($this->generation, $other->generation);
    }

    /** Safe cache-generation identifier; no encrypted or plaintext material. */
    public function generation(): string
    {
        return $this->generation;
    }

    public function jsonSerialize(): array
    {
        return [
            'connection_id' => $this->connectionId, 'connection_revision' => $this->connectionRevision,
            'source' => $this->source, 'account_id' => $this->accountId, 'account_status' => $this->accountStatus,
            'source_id' => $this->sourceId, 'source_revision' => $this->sourceRevision,
            'document' => $this->document, 'not_before' => $this->notBefore?->toISOString(),
            'not_after' => $this->notAfter?->toISOString(), 'state' => $this->state,
        ];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Credential snapshots must be captured by the lifecycle coordinator.');
    }
}
