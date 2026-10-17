<?php

namespace App\Support;

class SupportAccessLogMetadata
{
    /**
     * Only flat descriptors emitted by SupportAudit tenant writers are public:
     * resource/resource_id identify the changed record; name/email/document
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
                'resource', 'name', 'email', 'document', 'role', 'status', 'person_type', 'action' => is_string($value),
                'resource_id', 'client_id', 'certificate_id', 'previous_run_id', 'count', 'clients', 'tags' => is_int($value) && $value >= 0,
                'enabled', 'power_refresh_queued' => is_bool($value),
                default => false,
            };
            if ($allowed || ($value === null && in_array($key, ['resource_id', 'certificate_id', 'document', 'person_type'], true))) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
