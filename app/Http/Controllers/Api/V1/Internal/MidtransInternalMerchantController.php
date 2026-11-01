<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Models\GatewayAudit;
use App\Models\Merchant;
use App\Models\MerchantConfiguration;
use App\Models\Payment;
use App\Services\MidtransAuditService;
use App\Services\MidtransCoreApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MidtransInternalMerchantController extends Controller
{
    public function __construct(
        private MidtransCoreApiClient $apiClient,
        private MidtransAuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $environment = (string) $request->query('environment', 'sandbox');

        $query = Merchant::query()->with('configuration')
            ->where('environment', $environment);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('merchant_code', 'like', "%{$search}%")
                    ->orWhere('merchant_name', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $merchants = $query->orderBy('id')->get();

        $data = $merchants->map(fn (Merchant $m) => $this->transformMerchant($m));

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/', 'unique:merchants,merchant_code'],
            'merchant_name' => ['required', 'string', 'max:100'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $merchant = Merchant::create([
            'merchant_code' => $validated['merchant_code'],
            'merchant_name' => $validated['merchant_name'],
            'environment' => $validated['environment'] ?? 'sandbox',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Auto-create blank configuration
        $merchant->configuration()->create([
            'is_active' => true,
        ]);

        $this->auditService->record(
            action: 'merchant.provisioned',
            status: 'success',
            merchant: $merchant,
            metadata: ['merchant_code' => $merchant->merchant_code, 'merchant_name' => $merchant->merchant_name]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant Midtrans berhasil dibuat.',
            'data' => $this->transformMerchant($merchant->fresh('configuration')),
        ], Response::HTTP_CREATED);
    }

    public function show(string $merchantCode): JsonResponse
    {
        $merchant = Merchant::query()->with('configuration')
            ->where('merchant_code', $merchantCode)
            ->firstOrFail();

        return response()->json(['data' => $this->transformMerchant($merchant)]);
    }

    public function update(Request $request, string $merchantCode): JsonResponse
    {
        $merchant = Merchant::query()->where('merchant_code', $merchantCode)->firstOrFail();

        $validated = $request->validate([
            'merchant_name' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $merchant->update($validated);

        $this->auditService->record(
            action: 'merchant.updated',
            status: 'success',
            merchant: $merchant,
            metadata: $validated
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Data merchant berhasil diperbarui.',
            'data' => $this->transformMerchant($merchant->fresh('configuration')),
        ]);
    }

    public function readiness(string $merchantCode): JsonResponse
    {
        $merchant = Merchant::query()->with('configuration')
            ->where('merchant_code', $merchantCode)
            ->firstOrFail();

        $config = $merchant->configuration;
        $hasServerKey = ! empty($config?->encrypted_server_key);
        $hasClientKey = ! empty($config?->client_key);
        $isConfigured = $hasServerKey;
        $isReady = $merchant->is_active && $isConfigured;

        return response()->json([
            'data' => [
                'merchant_code' => $merchant->merchant_code,
                'merchant_name' => $merchant->merchant_name,
                'environment' => $merchant->environment,
                'merchant_status' => $merchant->is_active ? 'active' : 'inactive',
                'is_configured' => $isConfigured,
                'is_ready' => $isReady,
                'readiness_status' => $isReady ? 'ready' : 'incomplete',
                'details' => [
                    'merchant_active' => (bool) $merchant->is_active,
                    'server_key_configured' => $hasServerKey,
                    'client_key_configured' => $hasClientKey,
                    'merchant_id_configured' => ! empty($config?->midtrans_merchant_id),
                ],
            ],
        ]);
    }

    public function getConfiguration(string $merchantCode): JsonResponse
    {
        $merchant = Merchant::query()->with('configuration')
            ->where('merchant_code', $merchantCode)
            ->firstOrFail();

        $config = $merchant->configuration;

        return response()->json([
            'data' => [
                'merchant_code' => $merchant->merchant_code,
                'merchant_name' => $merchant->merchant_name,
                'environment' => $merchant->environment,
                'midtrans_merchant_id' => $config?->midtrans_merchant_id ?? '',
                'client_key' => $config?->client_key ?? '',
                'masked_server_key' => $config?->masked_server_key ?? '',
                'masked_signature_key' => $config?->masked_signature_key ?? '',
                'has_server_key' => ! empty($config?->encrypted_server_key),
                'has_signature_key' => ! empty($config?->encrypted_signature_key),
                'is_active' => (bool) ($config?->is_active ?? true),
                'updated_at' => $config?->updated_at?->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function updateConfiguration(Request $request, string $merchantCode): JsonResponse
    {
        $merchant = Merchant::query()->where('merchant_code', $merchantCode)->firstOrFail();

        $validated = $request->validate([
            'midtrans_merchant_id' => ['nullable', 'string', 'max:100'],
            'client_key' => ['nullable', 'string', 'max:150'],
            'server_key' => ['nullable', 'string', 'max:255'],
            'signature_secret_key' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $config = $merchant->configuration ?: new MerchantConfiguration(['merchant_id' => $merchant->id]);

        if (array_key_exists('midtrans_merchant_id', $validated)) {
            $config->midtrans_merchant_id = $validated['midtrans_merchant_id'];
        }
        if (array_key_exists('client_key', $validated)) {
            $config->client_key = $validated['client_key'];
        }
        if (! empty($validated['server_key'])) {
            $config->setServerKeyAttribute($validated['server_key']);
        }
        if (! empty($validated['signature_secret_key'])) {
            $config->setSignatureKeyAttribute($validated['signature_secret_key']);
        }
        if (array_key_exists('is_active', $validated)) {
            $config->is_active = $validated['is_active'];
        }

        $config->save();

        $this->auditService->record(
            action: 'configuration.saved',
            status: 'success',
            merchant: $merchant,
            metadata: [
                'merchant_code' => $merchant->merchant_code,
                'has_server_key' => ! empty($config->encrypted_server_key),
                'has_client_key' => ! empty($config->client_key),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Konfigurasi API Midtrans berhasil disimpan terenkripsi.',
            'data' => [
                'merchant_code' => $merchant->merchant_code,
                'midtrans_merchant_id' => $config->midtrans_merchant_id,
                'client_key' => $config->client_key,
                'masked_server_key' => $config->masked_server_key,
                'masked_signature_key' => $config->masked_signature_key,
                'has_server_key' => ! empty($config->encrypted_server_key),
                'has_signature_key' => ! empty($config->encrypted_signature_key),
                'is_active' => (bool) $config->is_active,
            ],
        ]);
    }

    public function testConnection(Request $request, string $merchantCode): JsonResponse
    {
        $merchant = Merchant::query()->with('configuration')
            ->where('merchant_code', $merchantCode)
            ->firstOrFail();

        $config = $merchant->configuration;
        $serverKey = $config?->getServerKey();

        if (empty($serverKey)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Server Key Midtrans belum dikonfigurasi untuk merchant ini.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $startTime = microtime(true);
        // Test probe against Midtrans API (querying a dummy status or non-existent order to verify auth)
        $testOrderId = 'probe-test-'.Str::random(8);
        $result = $this->apiClient->checkStatus($serverKey, $testOrderId, $merchant->environment);
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        // Midtrans returns 404 for transaction not found (which means auth succeeded!)
        // or 401 if Server Key is invalid
        $statusCode = (int) $result['status_code'];
        $isAuthValid = ($statusCode === 404 || $statusCode === 200);

        $status = $isAuthValid ? 'success' : 'failed';
        $message = $isAuthValid
            ? 'Koneksi ke Midtrans Core API Sandbox berhasil. Kredensial valid.'
            : ($result['body']['status_message'] ?? 'Otentikasi ke Midtrans gagal. Periksa Server Key Anda.');

        $this->auditService->record(
            action: 'sandbox.connection_tested',
            status: $status,
            merchant: $merchant,
            responseCode: (string) $statusCode,
            latencyMs: $latencyMs,
            metadata: ['result_code' => $statusCode, 'latency_ms' => $latencyMs]
        );

        return response()->json([
            'status' => $status,
            'is_connected' => $isAuthValid,
            'http_status' => $statusCode,
            'latency_ms' => $latencyMs,
            'message' => $message,
            'environment' => $merchant->environment,
            'tested_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function createSandboxPayment(Request $request, string $merchantCode): JsonResponse
    {
        $merchant = Merchant::query()->with('configuration')
            ->where('merchant_code', $merchantCode)
            ->firstOrFail();

        $config = $merchant->configuration;
        $serverKey = $config?->getServerKey();

        if (empty($serverKey)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Server Key Midtrans belum dikonfigurasi.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate([
            'order_id' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1000'],
            'payment_type' => ['nullable', 'string', 'in:qris,bank_transfer,echannel,gopay'],
            'bank' => ['nullable', 'string', 'in:bca,bni,bri,permata,mandiri'],
        ]);

        $orderId = ! empty($validated['order_id'])
            ? trim($validated['order_id'])
            : 'MIDTRANS-TEST-'.now()->format('YmdHis').'-'.Str::random(4);

        $amount = (float) $validated['amount'];
        $paymentType = $validated['payment_type'] ?? 'qris';
        $bank = strtolower((string) ($validated['bank'] ?? 'bca'));

        // Build Midtrans charge payload
        $payload = [
            'payment_type' => $paymentType,
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) $amount,
            ],
            'customer_details' => [
                'first_name' => 'UDEV',
                'last_name' => 'Sandbox Tester',
                'email' => 'sandbox-tester@udumbara.my.id',
                'phone' => '08123456789',
            ],
        ];

        if ($paymentType === 'qris') {
            $payload['qris'] = ['acquirer' => 'gopay'];
        } elseif ($paymentType === 'bank_transfer') {
            if ($bank === 'mandiri') {
                $payload['payment_type'] = 'echannel';
                $payload['echannel'] = [
                    'bill_info1' => 'Payment For:',
                    'bill_info2' => 'UDEV Sandbox',
                ];
            } elseif ($bank === 'permata') {
                $payload['bank_transfer'] = ['bank' => 'permata'];
            } else {
                $payload['bank_transfer'] = ['bank' => $bank];
            }
        } elseif ($paymentType === 'echannel') {
            $payload['echannel'] = [
                'bill_info1' => 'Payment For:',
                'bill_info2' => 'UDEV Sandbox',
            ];
        } elseif ($paymentType === 'gopay') {
            $payload['gopay'] = [
                'enable_callback' => true,
                'callback_url' => 'https://udev.udumbara.my.id/payment-result',
            ];
        }

        $startTime = microtime(true);
        $result = $this->apiClient->charge($serverKey, $payload, $merchant->environment);
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        $body = $result['body'];
        $statusCode = (int) $result['status_code'];
        $isSuccess = ($statusCode >= 200 && $statusCode < 300) || ($body['status_code'] ?? '') === '201';

        // Extract QR string, VA numbers, or Mandiri bill codes
        $qrString = null;
        $checkoutUrl = null;
        $vaNumbers = null;
        $permataVaNumber = $body['permata_va_number'] ?? null;
        $billKey = $body['bill_key'] ?? null;
        $billerCode = $body['biller_code'] ?? null;

        if (! empty($body['actions'])) {
            foreach ($body['actions'] as $action) {
                if (($action['name'] ?? '') === 'generate-qr-code' || ($action['name'] ?? '') === 'deeplink-redirect') {
                    $checkoutUrl = $action['url'] ?? $checkoutUrl;
                }
            }
        }
        if (! empty($body['qr_string'])) {
            $qrString = $body['qr_string'];
        }
        if (! empty($body['va_numbers'])) {
            $vaNumbers = $body['va_numbers'];
        } elseif (! empty($permataVaNumber)) {
            $vaNumbers = [['bank' => 'permata', 'va_number' => $permataVaNumber]];
        } elseif (! empty($billKey)) {
            $vaNumbers = [['bank' => 'mandiri', 'va_number' => $billKey, 'biller_code' => $billerCode]];
        }

        // Store payment record locally
        $payment = Payment::create([
            'merchant_id' => $merchant->id,
            'merchant_order_id' => $orderId,
            'midtrans_transaction_id' => $body['transaction_id'] ?? null,
            'gross_amount' => $amount,
            'currency' => 'IDR',
            'payment_type' => $body['payment_type'] ?? $paymentType,
            'transaction_status' => $body['transaction_status'] ?? 'pending',
            'fraud_status' => $body['fraud_status'] ?? 'accept',
            'status_code' => $body['status_code'] ?? (string) $statusCode,
            'status_message' => $body['status_message'] ?? null,
            'checkout_url' => $checkoutUrl,
            'qr_string' => $qrString,
            'va_numbers' => $vaNumbers,
            'raw_response' => $body,
            'metadata' => [
                'test_run' => true,
                'environment' => $merchant->environment,
                'bank' => $bank,
                'permata_va_number' => $permataVaNumber,
                'bill_key' => $billKey,
                'biller_code' => $billerCode,
            ],
        ]);

        $this->auditService->record(
            action: 'sandbox.payment_created',
            status: $isSuccess ? 'success' : 'failed',
            merchant: $merchant,
            responseCode: (string) $statusCode,
            latencyMs: $latencyMs,
            metadata: [
                'order_id' => $orderId,
                'gross_amount' => $amount,
                'payment_type' => $body['payment_type'] ?? $paymentType,
                'bank' => $bank,
                'transaction_id' => $body['transaction_id'] ?? null,
                'va_numbers' => $vaNumbers,
                'bill_key' => $billKey,
                'biller_code' => $billerCode,
            ]
        );

        return response()->json([
            'status' => $isSuccess ? 'success' : 'failed',
            'message' => $body['status_message'] ?? ($isSuccess ? 'Pembayaran sandbox berhasil dibuat.' : 'Gagal membuat pembayaran sandbox.'),
            'data' => [
                'id' => $payment->id,
                'order_id' => $orderId,
                'transaction_id' => $body['transaction_id'] ?? null,
                'gross_amount' => $amount,
                'payment_type' => $body['payment_type'] ?? $paymentType,
                'bank' => $bank,
                'transaction_status' => $payment->transaction_status,
                'qr_string' => $qrString,
                'checkout_url' => $checkoutUrl,
                'va_numbers' => $vaNumbers,
                'permata_va_number' => $permataVaNumber,
                'bill_key' => $billKey,
                'biller_code' => $billerCode,
                'raw_response' => $body,
                'created_at' => $payment->created_at->format('Y-m-d H:i:s'),
            ],
        ], $isSuccess ? Response::HTTP_CREATED : Response::HTTP_BAD_REQUEST);
    }

    public function querySandboxPayment(string $merchantCode, string $orderId): JsonResponse
    {
        $merchant = Merchant::query()->with('configuration')
            ->where('merchant_code', $merchantCode)
            ->firstOrFail();

        $config = $merchant->configuration;
        $serverKey = $config?->getServerKey();

        if (empty($serverKey)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Server Key belum dikonfigurasi.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $startTime = microtime(true);
        $result = $this->apiClient->checkStatus($serverKey, $orderId, $merchant->environment);
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        $body = $result['body'];
        $statusCode = (int) $result['status_code'];
        $isSuccess = ($statusCode >= 200 && $statusCode < 300);

        // Update local payment if found
        $payment = Payment::query()->where('merchant_id', $merchant->id)
            ->where('merchant_order_id', $orderId)
            ->first();

        if ($payment !== null && ! empty($body['transaction_status'])) {
            $payment->update([
                'midtrans_transaction_id' => $body['transaction_id'] ?? $payment->midtrans_transaction_id,
                'transaction_status' => $body['transaction_status'],
                'fraud_status' => $body['fraud_status'] ?? $payment->fraud_status,
                'status_code' => $body['status_code'] ?? $payment->status_code,
                'status_message' => $body['status_message'] ?? $payment->status_message,
                'paid_at' => in_array($body['transaction_status'], ['settlement', 'capture'], true)
                    ? ($payment->paid_at ?? now())
                    : null,
            ]);
        }

        $this->auditService->record(
            action: 'sandbox.payment_queried',
            status: $isSuccess ? 'success' : 'failed',
            merchant: $merchant,
            responseCode: (string) $statusCode,
            latencyMs: $latencyMs,
            metadata: ['order_id' => $orderId, 'transaction_status' => $body['transaction_status'] ?? null]
        );

        return response()->json([
            'status' => $isSuccess ? 'success' : 'failed',
            'data' => [
                'order_id' => $orderId,
                'transaction_status' => $body['transaction_status'] ?? 'unknown',
                'status_message' => $body['status_message'] ?? null,
                'gross_amount' => $body['gross_amount'] ?? null,
                'payment_type' => $body['payment_type'] ?? null,
                'raw_response' => $body,
                'payment' => $payment,
            ],
        ]);
    }

    public function audits(Request $request, string $merchantCode): JsonResponse
    {
        $merchant = Merchant::query()->where('merchant_code', $merchantCode)->firstOrFail();

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $query = GatewayAudit::query()->where('merchant_id', $merchant->id);

        if ($search !== '') {
            $query->where('action', 'like', "%{$search}%");
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $perPage = max(5, min(100, (int) $request->query('per_page', 15)));
        $audits = $query->orderByDesc('id')->paginate($perPage);

        return response()->json($audits);
    }

    public function payments(Request $request, string $merchantCode): JsonResponse
    {
        $merchant = Merchant::query()->where('merchant_code', $merchantCode)->firstOrFail();

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $query = Payment::query()->where('merchant_id', $merchant->id);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('merchant_order_id', 'like', "%{$search}%")
                    ->orWhere('midtrans_transaction_id', 'like', "%{$search}%");
            });
        }
        if ($status !== '') {
            $query->where('transaction_status', $status);
        }

        $perPage = max(5, min(100, (int) $request->query('per_page', 15)));
        $payments = $query->orderByDesc('id')->paginate($perPage);

        return response()->json($payments);
    }

    public function paymentDetail(string $merchantCode, string $paymentId): JsonResponse
    {
        $merchant = Merchant::query()->where('merchant_code', $merchantCode)->firstOrFail();

        $payment = Payment::query()->with('webhooks')
            ->where('merchant_id', $merchant->id)
            ->where('id', $paymentId)
            ->firstOrFail();

        return response()->json(['data' => $payment]);
    }

    private function transformMerchant(Merchant $merchant): array
    {
        $config = $merchant->configuration;
        $hasServerKey = ! empty($config?->encrypted_server_key);
        $hasClientKey = ! empty($config?->client_key);
        $isConfigured = $hasServerKey;
        $isReady = $merchant->is_active && $isConfigured;

        return [
            'id' => $merchant->id,
            'merchant_code' => $merchant->merchant_code,
            'merchant_name' => $merchant->merchant_name,
            'environment' => $merchant->environment,
            'is_active' => (bool) $merchant->is_active,
            'is_configured' => $isConfigured,
            'is_ready' => $isReady,
            'readiness_status' => $isReady ? 'ready' : 'incomplete',
            'client_key' => $config?->client_key ?? '',
            'midtrans_merchant_id' => $config?->midtrans_merchant_id ?? '',
            'has_server_key' => $hasServerKey,
            'has_client_key' => $hasClientKey,
            'created_at' => $merchant->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $merchant->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
