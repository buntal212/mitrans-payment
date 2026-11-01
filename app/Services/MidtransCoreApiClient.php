<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MidtransCoreApiClient
{
    private const SANDBOX_BASE_URL = 'https://api.sandbox.midtrans.com/v2';

    private const PRODUCTION_BASE_URL = 'https://api.midtrans.com/v2';

    /**
     * Create a transaction charge via Midtrans Core API.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function charge(string $serverKey, array $payload, string $environment = 'sandbox'): array
    {
        $baseUrl = $this->getBaseUrl($environment);
        $endpoint = "{$baseUrl}/charge";

        try {
            $response = Http::withBasicAuth(trim($serverKey), '')
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout((int) config('services.midtrans.timeout', 10))
                ->connectTimeout((int) config('services.midtrans.connect_timeout', 5))
                ->post($endpoint, $payload);

            return [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->json() ?? [],
            ];
        } catch (ConnectionException $e) {
            return [
                'status_code' => 504,
                'successful' => false,
                'body' => [
                    'status_code' => '504',
                    'status_message' => 'Gagal terhubung ke Midtrans API: '.$e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Check transaction status by Order ID or Transaction ID.
     *
     * @return array<string, mixed>
     */
    public function checkStatus(string $serverKey, string $orderIdOrTransactionId, string $environment = 'sandbox'): array
    {
        $baseUrl = $this->getBaseUrl($environment);
        $endpoint = "{$baseUrl}/".urlencode($orderIdOrTransactionId).'/status';

        try {
            $response = Http::withBasicAuth(trim($serverKey), '')
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout((int) config('services.midtrans.timeout', 10))
                ->connectTimeout((int) config('services.midtrans.connect_timeout', 5))
                ->get($endpoint);

            return [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->json() ?? [],
            ];
        } catch (ConnectionException $e) {
            return [
                'status_code' => 504,
                'successful' => false,
                'body' => [
                    'status_code' => '504',
                    'status_message' => 'Gagal terhubung ke Midtrans API: '.$e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Cancel an active transaction.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $serverKey, string $orderIdOrTransactionId, string $environment = 'sandbox'): array
    {
        $baseUrl = $this->getBaseUrl($environment);
        $endpoint = "{$baseUrl}/".urlencode($orderIdOrTransactionId).'/cancel';

        try {
            $response = Http::withBasicAuth(trim($serverKey), '')
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout((int) config('services.midtrans.timeout', 10))
                ->connectTimeout((int) config('services.midtrans.connect_timeout', 5))
                ->post($endpoint);

            return [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->json() ?? [],
            ];
        } catch (ConnectionException $e) {
            return [
                'status_code' => 504,
                'successful' => false,
                'body' => [
                    'status_code' => '504',
                    'status_message' => 'Gagal terhubung ke Midtrans API: '.$e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Verify Midtrans notification signature:
     * SHA512(order_id + status_code + gross_amount + ServerKey)
     */
    public function verifySignature(string $orderId, string $statusCode, string|float|int $grossAmount, string $serverKey, string $signatureKey): bool
    {
        // Midtrans gross_amount may be provided with or without decimals
        // We try both exact string and 2-decimal formatted string
        $grossString = (string) $grossAmount;
        $hash1 = hash('sha512', $orderId.$statusCode.$grossString.trim($serverKey));
        if (hash_equals(strtolower($signatureKey), strtolower($hash1))) {
            return true;
        }

        $grossFormatted = number_format((float) $grossAmount, 2, '.', '');
        $hash2 = hash('sha512', $orderId.$statusCode.$grossFormatted.trim($serverKey));

        return hash_equals(strtolower($signatureKey), strtolower($hash2));
    }

    private function getBaseUrl(string $environment): string
    {
        if ($environment === 'production') {
            return config('services.midtrans.production_base_url', self::PRODUCTION_BASE_URL);
        }

        return config('services.midtrans.sandbox_base_url', self::SANDBOX_BASE_URL);
    }
}
