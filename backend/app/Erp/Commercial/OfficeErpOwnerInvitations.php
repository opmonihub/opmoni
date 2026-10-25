<?php

namespace App\Erp\Commercial;

use App\Enums\ClientStatus;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCommercialSetting;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Support\LiteralSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class OfficeErpOwnerInvitations
{
    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpLicenceAllocator $allocator,
        private readonly OfficeErpOperations $operations,
    ) {}

    /** @return array{data: list<array{id: int, name: string, trade_name: ?string, tax_id: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}} */
    public function clients(OfficeErpExecutionContext $ctx, ?string $search = null, int $page = 1, int $perPage = 25): array
    {
        $this->assertOfficeAdministrator($ctx);
        abort_unless($page > 0 && in_array($perPage, [25, 50, 100], true), 422);

        return $ctx->run(function () use ($ctx, $search, $page, $perPage): array {
            $this->expirePendingInvitations($ctx);
            $this->admission->capabilities($ctx);
            $query = Client::query()
                ->where('status', ClientStatus::Active)
                ->orderBy('name')
                ->orderBy('id');
            $term = trim((string) $search);
            if ($term !== '') {
                $query->where(function ($searchQuery) use ($term): void {
                    LiteralSearch::whereContains($searchQuery, 'name', $term);
                    $searchQuery->orWhere(function ($taxIdQuery) use ($term): void {
                        LiteralSearch::whereContains($taxIdQuery, 'tax_id', $term);
                    });
                });
            }
            $paginator = $query->paginate($perPage, ['id', 'name', 'trade_name', 'tax_id'], 'page', $page);

            return $this->clientPage($paginator);
        });
    }

    /** @param array<string, mixed> $owner @return array<string, mixed> */
    public function preview(OfficeErpExecutionContext $ctx, int $clientId, int $revisionId, array $owner): array
    {
        $owner = $this->normalizeOwner($owner);
        $this->assertOfficeAdministrator($ctx);

        return $ctx->run(fn (): array => DB::transaction(function () use ($ctx, $clientId, $revisionId, $owner): array {
            $this->admission->lockCommercialAggregate($ctx);
            $setting = $this->commercialSetting($ctx, true);
            $this->activeClient($ctx, $clientId, false);
            $this->publishedRevision($ctx, $revisionId, false);
            $this->expirePendingInvitations($ctx);
            $this->admission->prepareAdmissionLocks($ctx);
            $client = $this->activeClient($ctx, $clientId, true);
            [$plan, $revision] = $this->publishedRevision($ctx, $revisionId, true);
            $capabilities = $this->admission->capabilities($ctx);

            return [
                'client' => $this->clientData($client),
                'seller' => $this->sellerSnapshot($ctx),
                'owner' => $owner,
                'offer' => $this->revisionData($plan, $revision),
                'service' => [
                    'start_mode' => 'activation_date',
                    'billing_timezone' => $setting?->billing_timezone ?? 'America/Sao_Paulo',
                    'due_in_days' => (int) $revision->due_in_days,
                ],
                'capacity' => $capabilities['usage'] + [
                    'available' => $capabilities['available'],
                    'can_issue' => $capabilities['can_sell_new'],
                ],
            ];
        }, 3));
    }

    /** @param array<string, mixed> $owner @return array{invitation_id: int, expires_at: string, status: string, url?: string} */
    public function issue(OfficeErpExecutionContext $ctx, int $clientId, int $revisionId, array $owner, string $key): array
    {
        $owner = $this->normalizeOwner($owner);
        $payload = [
            'intent' => 'create',
            'client_id' => $clientId,
            'sales_plan_revision_id' => $revisionId,
            'owner' => $owner,
        ];
        $initialUrl = null;
        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'invitation.issue',
            $payload,
            fn (string $action, array $operationPayload): mixed => $this->authorizeOperation($ctx, $this->admission, $action, $operationPayload, $key),
            function () use ($ctx, $clientId, $revisionId, $owner, &$initialUrl): array {
                $client = $this->activeClient($ctx, $clientId, true);
                [$plan, $salesRevision] = $this->publishedRevision($ctx, $revisionId, true);
                $setting = $this->commercialSetting($ctx, false);
                $contract = $this->createContractDraft($ctx, $client, $plan, $salesRevision, $setting?->billing_timezone ?? 'America/Sao_Paulo');
                $expiresAt = now()->toImmutable()->addDays(7);
                $secret = bin2hex(random_bytes(32));
                $invitation = $this->newInvitation($ctx, $contract, $owner, $expiresAt, $secret, $this->sellerSnapshot($ctx));
                $contract->forceFill(['agreement_status' => 'awaiting_owner']);
                OfficeErpRecords::persist($ctx, $contract);
                $this->allocator->reserve($ctx, $contract->fresh(), $expiresAt);
                $initialUrl = $this->fragmentUrl($secret);

                return $this->outcome($invitation);
            },
        );

        return $initialUrl === null ? $outcome : $outcome + ['url' => $initialUrl];
    }

    public function revoke(OfficeErpExecutionContext $ctx, int $id, string $reason, string $key): void
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:1000', 'not_regex:/[\x00-\x1F\x7F]/']])->validate();
        abort_if(preg_match('/(?:https?:\/\/|\b(?:bearer|token|password|secret|credential)\s*[:=]|\b[a-f0-9]{40,}\b)/i', $reason) === 1, 422, 'unsafe_revocation_reason');
        $this->operations->execute(
            $ctx,
            $key,
            'invitation.revoke',
            ['invitation_id' => $id, 'reason' => $reason],
            fn (string $action, array $payload): mixed => $this->authorizeOperation($ctx, $this->admission, $action, $payload, $key),
            function () use ($ctx, $id, $reason): array {
                $invitation = $this->invitation($ctx, $id, true);
                $contract = $this->contract($ctx, (int) $invitation->contract_id, true);
                if ($invitation->status === 'pending' && $invitation->expires_at->isFuture()) {
                    $this->allocator->release($ctx, $contract, 'invitation_revoked');
                    $invitation->forceFill([
                        'status' => 'revoked',
                        'revoked_at' => now(),
                        'revocation_reason' => $reason,
                    ]);
                    OfficeErpRecords::persist($ctx, $invitation);
                } elseif ($invitation->status === 'pending') {
                    $invitation->forceFill(['status' => 'expired']);
                    OfficeErpRecords::persist($ctx, $invitation);
                }

                return $this->outcome($invitation->refresh());
            },
        );
    }

    /** @return array{invitation_id: int, expires_at: string, status: string, url?: string} */
    public function reissue(OfficeErpExecutionContext $ctx, int $id, string $key): array
    {
        $initialUrl = null;
        $old = $this->invitation($ctx, $id, false);
        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'invitation.issue',
            [
                'intent' => 'reissue',
                'invitation_id' => $id,
                'client_id' => (int) $old->contract->client_id,
                'sales_plan_revision_id' => (int) $old->contract->currentRevision->sales_plan_revision_id,
                'owner' => ['full_name' => $old->owner_name, 'email' => $old->owner_email],
            ],
            fn (string $action, array $payload): mixed => $this->authorizeOperation($ctx, $this->admission, $action, $payload, $key),
            function () use ($ctx, $id, &$initialUrl): array {
                $oldInvitation = $this->invitation($ctx, $id, true);
                $contract = $this->contract($ctx, (int) $oldInvitation->contract_id, true);
                $now = now()->toImmutable();
                $activeInvitations = OfficeErpRecords::query($ctx, OfficeErpOwnerInvitation::class)
                    ->where('contract_id', $contract->id)
                    ->where('status', 'pending')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($activeInvitations as $activeInvitation) {
                    if ($activeInvitation->expires_at->isPast()) {
                        $activeInvitation->forceFill(['status' => 'expired']);
                    } else {
                        $this->allocator->release($ctx, $contract, 'invitation_revoked');
                        $activeInvitation->forceFill([
                            'status' => 'reissued',
                            'revoked_at' => $now,
                            'revocation_reason' => 'Replaced by an explicitly requested invitation reissue.',
                        ]);
                    }
                    OfficeErpRecords::persist($ctx, $activeInvitation);
                }
                abort_unless($oldInvitation->status === 'pending'
                    || in_array($oldInvitation->status, ['revoked', 'expired', 'reissued'], true), 409, 'invitation_not_reissuable');

                $expiresAt = $now->addDays(7);
                $secret = bin2hex(random_bytes(32));
                $newInvitation = $this->newInvitation(
                    $ctx,
                    $contract,
                    ['full_name' => $oldInvitation->owner_name, 'email' => $oldInvitation->owner_email],
                    $expiresAt,
                    $secret,
                    $oldInvitation->seller_snapshot,
                );
                $this->allocator->reserve($ctx, $contract->fresh(), $expiresAt);
                $initialUrl = $this->fragmentUrl($secret);

                return $this->outcome($newInvitation);
            },
        );

        return $initialUrl === null ? $outcome : $outcome + ['url' => $initialUrl];
    }

    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, OfficeErpAdmission $admission, string $action, array $payload, ?string $key = null): void
    {
        abort_unless(in_array($action, ['invitation.issue', 'invitation.revoke'], true), 422);
        $this->assertOfficeAdministrator($ctx);
        if ($action === 'invitation.revoke') {
            $targetId = $payload['invitation_id'] ?? null;
            abort_unless(is_int($targetId) && $targetId > 0, 409);
            $target = $this->invitation($ctx, $targetId, false);
            $contract = $this->contract($ctx, (int) $target->contract_id, false);
            $admission->lockCommercialAggregate($ctx);
            $this->commercialSetting($ctx, true);
            $this->expirePendingInvitations($ctx, $contract);
            $target = $this->invitation($ctx, $targetId, false);
            $contract = $this->contract($ctx, (int) $target->contract_id, false);
            $admission->prepareAdmissionLocks($ctx, $contract);
            $contract = $this->contract($ctx, (int) $target->contract_id, true);
            $target = $this->invitation($ctx, $targetId, true);
            OfficeErpRecords::query($ctx, OfficeErpOwnerInvitation::class)
                ->where('contract_id', $contract->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            abort_unless($target !== null && $contract !== null, 404);
            if ($target->status === 'pending' && $target->expires_at->isFuture()) {
                $allocation = $this->allocation($ctx, $contract, false);
                $admission->assertCommand($ctx, 'invitation.revoke', $contract, $contract->currentRevision->module_keys, $allocation);
            } else {
                abort_unless($admission->capabilities($ctx)['can_maintain'], 403);
            }

            return;
        }

        $intent = $payload['intent'] ?? null;
        abort_unless(in_array($intent, ['create', 'reissue'], true), 409);
        $clientId = $payload['client_id'] ?? null;
        $revisionId = $payload['sales_plan_revision_id'] ?? null;
        abort_unless(is_int($clientId) && $clientId > 0 && is_int($revisionId) && $revisionId > 0, 409);
        $owner = $this->normalizeOwner($payload['owner'] ?? []);
        $operation = null;
        if ($key !== null) {
            $operation = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
                ->where('actor_kind', $ctx->actorKind)
                ->where('actor_identity', $ctx->actorIdentity)
                ->where('idempotency_key', $key)
                ->where('action', $action)
                ->first(['outcome']);
        }
        $targetId = $intent === 'reissue'
            ? ($payload['invitation_id'] ?? null)
            : ($operation?->outcome['invitation_id'] ?? null);
        abort_unless($targetId === null || (is_int($targetId) && $targetId > 0), 409);
        $target = $targetId === null ? null : $this->invitation($ctx, $targetId, false);
        $contract = $target === null ? null : $this->contract($ctx, (int) $target->contract_id, false);
        if ($operation === null) {
            $this->activeClient($ctx, $clientId, false);
        }
        [$plan, $revision] = $this->publishedRevision($ctx, $revisionId, false, $operation !== null);

        $admission->lockCommercialAggregate($ctx);
        $setting = $this->commercialSetting($ctx, true);
        $this->expirePendingInvitations($ctx, $contract);
        if ($targetId !== null) {
            $target = $this->invitation($ctx, $targetId, false);
            $contract = $this->contract($ctx, (int) $target->contract_id, false);
        }
        $admission->prepareAdmissionLocks($ctx, $contract);
        if ($operation === null) {
            $this->activeClient($ctx, $clientId, true);
        }
        [$plan, $revision] = $this->publishedRevision($ctx, $revisionId, true, $operation !== null);
        if ($contract !== null) {
            $contract = $this->contract($ctx, (int) $target->contract_id, true);
            $target = $this->invitation($ctx, $targetId, true);
            OfficeErpRecords::query($ctx, OfficeErpOwnerInvitation::class)
                ->where('contract_id', $contract->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
        abort_unless($revision->sales_plan_id === $plan->id, 404);

        if ($operation !== null) {
            abort_unless($target !== null && $contract !== null, 404);
            abort_unless((int) $contract->client_id === $clientId
                && (int) $contract->currentRevision?->sales_plan_revision_id === $revisionId
                && $target->owner_name === $owner['full_name']
                && $target->owner_email === $owner['email'], 409);
            abort_unless($admission->capabilities($ctx)['can_maintain'], 403);

            return;
        }

        if ($intent === 'create' && $target === null) {
            abort_unless(($payload['invitation_id'] ?? null) === null, 409);
            $admission->assertCommand($ctx, 'contract.create', modules: $revision->module_keys);

            return;
        }

        abort_unless($target !== null && $contract !== null, 404);
        abort_unless((int) $contract->client_id === $clientId
            && (int) $contract->currentRevision?->sales_plan_revision_id === $revisionId
            && $target->owner_name === $owner['full_name']
            && $target->owner_email === $owner['email'], 409);
        abort_unless($contract->agreement_status === 'awaiting_owner', 409, 'contract_not_invitable');
        $allocation = $this->allocation($ctx, $contract, false);
        $admission->assertCommand($ctx, 'invitation.issue', $contract, $revision->module_keys, $allocation);
    }

    private function createContractDraft(OfficeErpExecutionContext $ctx, Client $client, OfficeErpSalesPlan $plan, OfficeErpSalesPlanRevision $salesRevision, string $billingTimezone): OfficeErpContract
    {
        $contract = new OfficeErpContract;
        $contract->forceFill([
            'client_id' => $client->id,
            'sales_plan_id' => $plan->id,
            'erp_company_id' => null,
            'agreement_status' => 'draft',
            'current_revision_id' => null,
            'pending_revision_id' => null,
            'service_anchor_on' => null,
            'billing_timezone' => $billingTimezone,
            'next_period_index' => null,
            'next_period_month_offset' => null,
            'manual_hold' => false,
        ]);
        /** @var OfficeErpContract $contract */
        $contract = OfficeErpRecords::persist($ctx, $contract);

        $revision = new OfficeErpContractRevision;
        $revision->forceFill([
            'contract_id' => $contract->id,
            'revision_number' => 1,
            'sales_plan_revision_id' => $salesRevision->id,
            'price' => (string) $salesRevision->price,
            'currency' => 'BRL',
            'interval_months' => $salesRevision->interval_months,
            'due_in_days' => $salesRevision->due_in_days,
            'module_keys' => $salesRevision->module_keys,
            'service_period_start' => null,
            'service_period_end' => null,
            'reason' => 'Initial owner invitation terms.',
            'created_by_user_id' => $ctx->actor?->getKey(),
        ]);
        /** @var OfficeErpContractRevision $revision */
        $revision = OfficeErpRecords::persist($ctx, $revision);
        $contract->forceFill(['current_revision_id' => $revision->id]);
        /** @var OfficeErpContract $contract */

        return OfficeErpRecords::persist($ctx, $contract);
    }

    /** @param array{full_name: string, email: string} $owner @param array<string, mixed> $sellerSnapshot */
    private function newInvitation(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, array $owner, \DateTimeInterface $expiresAt, string $secret, array $sellerSnapshot): OfficeErpOwnerInvitation
    {
        $invitation = new OfficeErpOwnerInvitation;
        $invitation->forceFill([
            'contract_id' => $contract->id,
            'owner_name' => $owner['full_name'],
            'owner_email' => $owner['email'],
            'seller_snapshot' => $sellerSnapshot,
            'token_hash' => hash('sha256', $secret),
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
            'created_by_user_id' => $ctx->actor?->getKey(),
        ]);
        /** @var OfficeErpOwnerInvitation $invitation */

        return OfficeErpRecords::persist($ctx, $invitation);
    }

    private function activeClient(OfficeErpExecutionContext $ctx, int $id, bool $lock): Client
    {
        $client = $ctx->run(function () use ($id, $lock): Client {
            $query = Client::query()->whereKey($id);
            if ($lock) {
                $query->lockForUpdate();
            }

            $client = $query->first();
            abort_unless($client, 404);

            return $client;
        });
        abort_unless($client->status === ClientStatus::Active, 422, 'client_inactive');

        return $client;
    }

    /** @return array{OfficeErpSalesPlan, OfficeErpSalesPlanRevision} */
    private function publishedRevision(OfficeErpExecutionContext $ctx, int $revisionId, bool $lock, bool $allowArchived = false): array
    {
        $revision = OfficeErpRecords::query($ctx, OfficeErpSalesPlanRevision::class)
            ->whereKey($revisionId)
            ->first();
        abort_unless($revision, 404);
        $planQuery = OfficeErpRecords::query($ctx, OfficeErpSalesPlan::class)->whereKey($revision->sales_plan_id);
        if ($lock) {
            $planQuery->lockForUpdate();
        }
        $plan = $planQuery->firstOrFail();
        $revisionQuery = OfficeErpRecords::query($ctx, OfficeErpSalesPlanRevision::class)
            ->whereKey($revisionId)
            ->where('sales_plan_id', $plan->id);
        if ($lock) {
            $revisionQuery->lockForUpdate();
        }
        $revision = $revisionQuery->firstOrFail();
        abort_unless($revision->published_at !== null, 422, 'offer_revision_unpublished');
        if (! $allowArchived) {
            abort_if($plan->status === 'archived' || $plan->archived_at !== null, 409, 'offer_archived');
        }

        return [$plan, $revision];
    }

    private function invitation(OfficeErpExecutionContext $ctx, int $id, bool $lock): OfficeErpOwnerInvitation
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpOwnerInvitation::class)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function contract(OfficeErpExecutionContext $ctx, int $id, bool $lock): OfficeErpContract
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($id)->with('currentRevision');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function allocation(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, bool $lock): ?OfficeErpLicenceAllocation
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)->where('contract_id', $contract->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function commercialSetting(OfficeErpExecutionContext $ctx, bool $lock): ?OfficeErpCommercialSetting
    {
        $query = OfficeErpRecords::query($ctx, OfficeErpCommercialSetting::class);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function expirePendingInvitations(OfficeErpExecutionContext $ctx, ?OfficeErpContract $targetContract = null): void
    {
        $ctx->run(function () use ($ctx, $targetContract): void {
            DB::transaction(function () use ($ctx, $targetContract): void {
                $this->admission->prepareAdmissionLocks($ctx, $targetContract);
                $expired = OfficeErpRecords::query($ctx, OfficeErpOwnerInvitation::class)
                    ->where('status', 'pending')
                    ->where('expires_at', '<=', now())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($expired as $invitation) {
                    $systemContext = OfficeErpExecutionContext::system($ctx->account, 'office-erp-invitation-expiry:'.$invitation->id);
                    $this->operations->execute(
                        $systemContext,
                        'invitation-expire-'.$invitation->id,
                        'invitation.expire',
                        ['invitation_id' => (int) $invitation->id],
                        function () use ($systemContext, $invitation): void {
                            $current = $this->invitation($systemContext, (int) $invitation->id, true);
                            abort_unless($current->status === 'expired'
                                || $current->status === 'pending' && $current->expires_at->isPast(), 409, 'invitation_not_expired');
                            $contract = $this->contract($systemContext, (int) $current->contract_id, false);
                            $allocation = $this->allocation($systemContext, $contract, false);
                            abort_unless($allocation === null || $allocation->state === 'released', 409, 'expired_reservation_not_released');
                        },
                        function () use ($systemContext, $invitation): array {
                            $current = $this->invitation($systemContext, (int) $invitation->id, true);
                            if ($current->status === 'pending') {
                                $current->forceFill(['status' => 'expired']);
                                OfficeErpRecords::persist($systemContext, $current);
                            }

                            return ['invitation_id' => (int) $current->id, 'status' => 'expired'];
                        },
                    );
                }
            }, 3);
        });
    }

    /** @param array<string, mixed> $owner @return array{full_name: string, email: string} */
    private function normalizeOwner(array $owner): array
    {
        Validator::make(['owner' => $owner], [
            'owner' => ['required', 'array:full_name,email'],
            'owner.full_name' => ['required', 'string', 'min:2', 'max:255', 'not_regex:/^\s*$/u'],
            'owner.email' => ['required', 'string', 'email:rfc', 'max:255'],
        ])->validate();
        $fullName = preg_replace('/\s+/u', ' ', trim($owner['full_name']));
        $email = mb_strtolower(trim($owner['email']));
        if (! is_string($fullName) || $fullName === '' || $email === '') {
            throw ValidationException::withMessages(['owner' => ['Informe o nome e o e-mail do titular.']]);
        }

        return ['full_name' => $fullName, 'email' => $email];
    }

    private function assertOfficeAdministrator(OfficeErpExecutionContext $ctx): void
    {
        $ctx->assertVerified();
        abort_unless($ctx->actorKind === 'office_admin' && $ctx->actor !== null, 403);
    }

    /** @return array{account_id: int, name: string} */
    private function sellerSnapshot(OfficeErpExecutionContext $ctx): array
    {
        $account = Account::query()->whereKey($ctx->accountId)->firstOrFail();

        return ['account_id' => (int) $account->id, 'name' => (string) $account->name];
    }

    /** @return array<string, mixed> */
    private function clientData(Client $client): array
    {
        return [
            'id' => (int) $client->id,
            'name' => (string) $client->name,
            'trade_name' => $client->trade_name,
            'tax_id' => (string) $client->tax_id,
        ];
    }

    /** @return array<string, mixed> */
    private function revisionData(OfficeErpSalesPlan $plan, OfficeErpSalesPlanRevision $revision): array
    {
        return [
            'sales_plan_id' => (int) $plan->id,
            'revision_id' => (int) $revision->id,
            'name' => (string) $revision->name,
            'description' => $revision->description,
            'price' => (string) $revision->price,
            'currency' => 'BRL',
            'interval_months' => (int) $revision->interval_months,
            'due_in_days' => (int) $revision->due_in_days,
            'module_keys' => $revision->module_keys,
            'published' => $revision->published_at !== null,
            'published_at' => $revision->published_at?->toISOString(),
        ];
    }

    /** @param LengthAwarePaginator<int, Client> $paginator @return array<string, mixed> */
    private function clientPage(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(fn (Client $client): array => $this->clientData($client), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array{invitation_id: int, expires_at: string, status: string} */
    private function outcome(OfficeErpOwnerInvitation $invitation): array
    {
        return [
            'invitation_id' => (int) $invitation->id,
            'expires_at' => $invitation->expires_at->toDateTimeString(),
            'status' => (string) $invitation->status,
        ];
    }

    private function fragmentUrl(string $secret): string
    {
        $origin = (string) config('access.origin');
        abort_unless(filter_var($origin, FILTER_VALIDATE_URL) && parse_url($origin, PHP_URL_SCHEME) === 'https'
            || app()->environment('local', 'testing') && filter_var($origin, FILTER_VALIDATE_URL), 500);

        return rtrim($origin, '/').'/erp/ativar#token='.$secret;
    }
}
