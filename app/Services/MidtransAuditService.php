<?php

namespace App\Services;

use App\Models\GatewayAudit;
use App\Models\Merchant;

class MidtransAuditService
{
    /**
     * Record a security / operational audit entry with sanitized metadata.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $action,
        string $status,
        ?Merchant $merchant = null,
        ?string $merchantCode = null,
        ?string $responseCode = null,
        ?int $latencyMs = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
    ): GatewayAudit {
        $sanitizedMetadata = $metadata ? $this->sanitizeMetadata($metadata) : null;
        $code = $merchantCode ?? $merchant?->merchant_code;

        return GatewayAudit::create([
            'merchant_id' => $merchant?->id,
            'merchant_code' => $code,
            'action' => $action,
            'status' => $status,
            'response_code' => $responseCode,
            'latency_ms' => $latencyMs,
            'ip_address' => $ipAddress ?? request()?->ip(),
            'metadata' => $sanitizedMetadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Strict redaction of secrets, keys, and credentials from metadata.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(array $data): array
    {
        $sensitiveKeys = [
            'server_key',
            'client_key',
            'signature_key',
            'signature_secret_key',
            'encrypted_server_key',
            'encrypted_signature_key',
            'password',
            'token',
            'authorization',
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMetadata($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
