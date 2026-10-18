<?php

namespace App\Enums;

enum FiscalProcessingReason: string
{
    case GateDisabled = 'gate_disabled';
    case DeliveryUnverified = 'delivery_unverified';
    case CertificateMissing = 'certificate_missing';
    case CertificateExpired = 'certificate_expired';
    case ClientInactive = 'client_inactive';
    case AccountSuspended = 'account_suspended';
    case ScienceDeadlineExpired = 'science_deadline_expired';
    case AuthorizationMissing = 'authorization_missing';
    case AuthorizationFuture = 'authorization_future';
    case NoteCancelled = 'note_cancelled';
    case NoteDenied = 'note_denied';
    case SelfIssued = 'self_issued';
    case ConclusiveEvent = 'conclusive_event';
    case ProviderTransient = 'provider_transient';
    case ProviderRejected = 'provider_rejected';
    case PublicationDelay = 'publication_delay';
    case BudgetCooldown = 'budget_cooldown';
    case LockOccupied = 'lock_occupied';
    case AttemptsExhausted = 'attempts_exhausted';
    case StorageMissing = 'storage_missing';
    case StorageCorrupt = 'storage_corrupt';
    case ArtifactConflict = 'artifact_conflict';
    case PilotScope = 'pilot_scope';
    case Ready = 'ready';
}
