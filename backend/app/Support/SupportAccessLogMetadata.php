<?php

namespace App\Support;

class SupportAccessLogMetadata
{
    /** @var list<string> Resource identifiers emitted by SupportAudit writers and WhatsAppDispatchService. */
    private const RESOURCES = [
        'account_certificates',
        'client_dispatch_contacts',
        'client_saved_filters',
        'client_selections',
        'clients',
        'departments',
        'fiscal',
        'members',
        'process_templates',
        'processes',
        'serpro_enablement',
        'serpro_manual_searches',
        'serpro_messages',
        'serpro_monitorings',
        'serpro_obligation_schedules',
        'serpro_sync_runs',
        'tags',
        'tasks',
        'whatsapp_channels',
        'whatsapp_dispatches',
    ];

    /**
     * Only flat descriptors emitted by SupportAudit tenant writers are public:
     * resource is a known writer identifier and resource_id a positive integer; name/email/document
     * describe it; role/status/person_type/action describe its public state;
     * counts, related IDs and boolean flags describe the performed operation.
     * Unknown fields and all nested containers are intentionally omitted.
     * This is a read projection: stored historical metadata is never changed.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    public static function sanitize(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $sanitized = [];
        foreach ($metadata as $key => $value) {
            $allowed = match ($key) {
                'resource' => is_string($value) && in_array($value, self::RESOURCES, true),
                'resource_id' => is_int($value) && $value > 0,
                'name', 'email', 'document', 'role', 'status', 'person_type', 'action' => is_string($value),
                'client_id', 'certificate_id', 'previous_run_id', 'count', 'clients', 'tags' => is_int($value) && $value >= 0,
                'enabled', 'power_refresh_queued' => is_bool($value),
                default => false,
            };
            if ($allowed || ($value === null && in_array($key, ['certificate_id', 'document', 'person_type'], true))) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
