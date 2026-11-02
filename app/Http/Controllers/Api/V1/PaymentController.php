<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Payment;
use App\Services\MidtransAuditService;
use App\Services\MidtransCoreApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(
        private MidtransCoreApiClient $apiClient,
        private MidtransAuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $merchantOrderId = $request->query('merchant_order_id');
        $merchantCode = $request->query('merchant_code');

        $query = Payment::query();

        if (! empty($merchantOrderId)) {
            $query->where('merchant_order_id', $merchantOrderId);
        }
        if (! empty($merchantCode)) {
            $query->whereHas('merchant', fn ($q) => $q->where('merchant_code', $merchantCode));
        }

        $payments = $query->latest('id')->limit(50)->get();

        return response()->json(['data' => $payments]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_order_id' => ['required', 'string', 'max:100'],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'amount' => ['nullable', 'numeric', 'min:1'],
            'currency' => ['nullable', 'string', 'in:IDR'],
            'merchant_code' => ['nullable', 'string'],
            'payment_type' => ['nullable', 'string'],
            'bank' => ['nullable', 'string', 'in:bca,bni,bri,permata,mandiri'],
            'metadata' => ['nullable', 'array'],
        ]);

        $merchantCode = $validated['merchant_code'] ?? 'udev-central';
        $merchant = Merchant::query()->with('configuration')
            ->where('merchant_code', $merchantCode)
            ->first();

        if ($merchant === null) {
            $merchant = Merchant::query()->with('configuration')->where('is_active', true)->firstOrFail();
        }

        $grossAmount = ! empty($validated['amount'])
            ? (float) $validated['amount']
            : ((float) ($validated['amount_minor'] ?? 10000));

        $orderId = $validated['merchant_order_id'];
        $paymentType = $validated['payment_type'] ?? 'qris';
        $bank = strtolower((string) ($validated['bank'] ?? ($validated['metadata']['bank'] ?? 'bca')));

        // Check if payment already exists (Idempotency)
        $existing = Payment::query()
            ->where('merchant_id', $merchant->id)
            ->where('merchant_order_id', $orderId)
            ->first();

        if ($existing !== null) {
            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi sudah terdaftar sebelumnya (Idempoten).',
                'data' => $this->transformPayment($existing),
            ], Response::HTTP_OK);
        }

        $serverKey = $merchant->configuration?->getServerKey();
        $checkoutUrl = null;
        $qrString = null;
        $vaNumbers = null;
        $rawResponse = [];
        $transactionId = null;
        $statusCode = '201';

        if (! empty($serverKey)) {
            $payload = [
                'payment_type' => $paymentType,
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => (int) $grossAmount,
                ],
                'customer_details' => [
                    'first_name' => 'UDEV',
                    'last_name' => 'Customer',
                    'email' => 'billing@udumbara.my.id',
                ],
            ];

            if ($paymentType === 'qris') {
                $payload['qris'] = ['acquirer' => 'gopay'];
            } elseif ($paymentType === 'bank_transfer') {
                if ($bank === 'mandiri') {
                    $payload['payment_type'] = 'echannel';
                    $payload['echannel'] = [
                        'bill_info1' => 'Payment For:',
                        'bill_info2' => 'UDEV Invoice',
                    ];
                } elseif ($bank === 'permata') {
                    $payload['bank_transfer'] = ['bank' => 'permata'];
                } else {
                    $payload['bank_transfer'] = ['bank' => $bank];
                }
            } elseif ($paymentType === 'echannel') {
                $payload['echannel'] = [
                    'bill_info1' => 'Payment For:',
                    'bill_info2' => 'UDEV Invoice',
                ];
            } elseif ($paymentType === 'gopay') {
                $payload['gopay'] = [
                    'enable_callback' => true,
                    'callback_url' => 'https://udev.udumbara.my.id/payment-result',
                ];
            }

            $res = $this->apiClient->charge($serverKey, $payload, $merchant->environment);
            $rawResponse = $res['body'] ?? [];
            $transactionId = $rawResponse['transaction_id'] ?? null;
            $statusCode = $rawResponse['status_code'] ?? (string) $res['status_code'];

            if ($statusCode === '406' || ($rawResponse['status_code'] ?? '') === '406' || $res['status_code'] === 406) {
                $statusRes = $this->apiClient->checkStatus($serverKey, $orderId, $merchant->environment);
                if ($statusRes['successful'] && ! empty($statusRes['body']['transaction_status'])) {
                    $rawResponse = $statusRes['body'];
                    $transactionId = $rawResponse['transaction_id'] ?? $transactionId;
                    $statusCode = $rawResponse['status_code'] ?? (string) $statusRes['status_code'];
                }
            }

            $qrUrl = null;
            if (! empty($rawResponse['actions'])) {
                foreach ($rawResponse['actions'] as $action) {
                    if (($action['name'] ?? '') === 'generate-qr-code') {
                        $qrUrl = $action['url'] ?? null;
                        $checkoutUrl = $action['url'] ?? $checkoutUrl;
                    } elseif (($action['name'] ?? '') === 'deeplink-redirect') {
                        $checkoutUrl = $action['url'] ?? $checkoutUrl;
                    }
                }
            }
            if (! empty($rawResponse['qr_string'])) {
                $qrString = $rawResponse['qr_string'];
            }
            if ($paymentType === 'qris' && empty($qrUrl) && ! empty($transactionId)) {
                $qrUrl = "https://api.".($merchant->environment === 'production' ? '' : 'sandbox.')."midtrans.com/v2/qris/{$transactionId}/qr-code";
                $checkoutUrl = $checkoutUrl ?: $qrUrl;
            }
            if (! empty($rawResponse['va_numbers'])) {
                $vaNumbers = $rawResponse['va_numbers'];
            } elseif (! empty($rawResponse['permata_va_number'])) {
                $vaNumbers = [['bank' => 'permata', 'va_number' => $rawResponse['permata_va_number']]];
            } elseif (! empty($rawResponse['bill_key'])) {
                $vaNumbers = [['bank' => 'mandiri', 'va_number' => $rawResponse['bill_key'], 'biller_code' => $rawResponse['biller_code'] ?? null]];
            }
        }

        $payment = Payment::updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'merchant_order_id' => $orderId,
            ],
            [
                'midtrans_transaction_id' => $transactionId,
                'gross_amount' => $grossAmount,
                'currency' => 'IDR',
                'payment_type' => $rawResponse['payment_type'] ?? $paymentType,
                'transaction_status' => $rawResponse['transaction_status'] ?? 'pending',
                'fraud_status' => $rawResponse['fraud_status'] ?? 'accept',
                'status_code' => $statusCode,
                'status_message' => $rawResponse['status_message'] ?? null,
                'checkout_url' => $checkoutUrl,
                'qr_string' => $qrString,
                'va_numbers' => $vaNumbers,
                'raw_response' => $rawResponse,
                'metadata' => array_merge($validated['metadata'] ?? [], ['bank' => $bank, 'qr_url' => $qrUrl ?? null]),
            ]
        );

        $this->auditService->record(
            action: 'payment.created',
            status: 'success',
            merchant: $merchant,
            responseCode: $statusCode,
            metadata: ['order_id' => $orderId, 'gross_amount' => $grossAmount]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Pembayaran berhasil diinisiasi.',
            'data' => $this->transformPayment($payment),
        ], Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $payment = Payment::query()->with('merchant')->findOrFail($id);

        return response()->json(['data' => $this->transformPayment($payment)]);
    }

    public function status(string $id): JsonResponse
    {
        $payment = Payment::query()->with('merchant.configuration')->findOrFail($id);

        // Optionally refresh status from Midtrans if pending
        if ($payment->transaction_status === 'pending') {
            $serverKey = $payment->merchant?->configuration?->getServerKey();
            if (! empty($serverKey) && ! empty($payment->merchant_order_id)) {
                $res = $this->apiClient->checkStatus($serverKey, $payment->merchant_order_id, $payment->merchant->environment);
                if ($res['successful'] && ! empty($res['body']['transaction_status'])) {
                    $payment->update([
                        'midtrans_transaction_id' => $res['body']['transaction_id'] ?? $payment->midtrans_transaction_id,
                        'transaction_status' => $res['body']['transaction_status'],
                        'status_code' => $res['body']['status_code'] ?? $payment->status_code,
                        'paid_at' => in_array($res['body']['transaction_status'], ['settlement', 'capture'], true) ? now() : null,
                    ]);
                }
            }
        }

        return response()->json(['data' => $this->transformPayment($payment->fresh())]);
    }

    private function transformPayment(Payment $payment): array
    {
        $qrUrl = $payment->metadata['qr_url'] ?? null;
        if (empty($qrUrl) && ! empty($payment->raw_response['actions'])) {
            foreach ($payment->raw_response['actions'] as $action) {
                if (($action['name'] ?? '') === 'generate-qr-code') {
                    $qrUrl = $action['url'] ?? null;
                    break;
                }
            }
        }
        if (empty($qrUrl) && $payment->payment_type === 'qris' && ! empty($payment->midtrans_transaction_id)) {
            $env = $payment->merchant?->environment ?? 'sandbox';
            $qrUrl = "https://api.".($env === 'production' ? '' : 'sandbox.')."midtrans.com/v2/qris/{$payment->midtrans_transaction_id}/qr-code";
        }
        if (empty($qrUrl) && ! empty($payment->checkout_url) && str_contains((string) $payment->checkout_url, 'qr')) {
            $qrUrl = $payment->checkout_url;
        }

        $expiryTime = $payment->raw_response['expiry_time'] ?? ($payment->metadata['expiry_time'] ?? null);
        $expiresAt = null;
        if (! empty($expiryTime)) {
            $rawExp = trim((string) $expiryTime);
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $rawExp)) {
                $expiresAt = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $rawExp, 'Asia/Jakarta')
                    ->setTimezone('UTC')
                    ->toIso8601String();
            } elseif (str_contains($rawExp, 'T') || str_contains($rawExp, '+') || str_ends_with($rawExp, 'Z')) {
                $expiresAt = CarbonImmutable::parse($rawExp)->setTimezone('UTC')->toIso8601String();
            } else {
                try {
                    $expiresAt = CarbonImmutable::parse($rawExp, 'Asia/Jakarta')->setTimezone('UTC')->toIso8601String();
                } catch (\Throwable) {
                    $expiresAt = null;
                }
            }
        }

        return [
            'id' => $payment->id,
            'merchant_order_id' => $payment->merchant_order_id,
            'midtrans_transaction_id' => $payment->midtrans_transaction_id,
            'transaction_id' => $payment->midtrans_transaction_id,
            'gross_amount' => $payment->gross_amount,
            'amount_minor' => (int) round($payment->gross_amount),
            'currency' => $payment->currency,
            'payment_type' => $payment->payment_type,
            'transaction_status' => $payment->transaction_status,
            'status' => $payment->transaction_status,
            'status_code' => $payment->status_code,
            'checkout_url' => $payment->checkout_url,
            'qr_string' => $payment->qr_string,
            'qr_url' => $qrUrl,
            'expiry_time' => $expiryTime,
            'expires_at' => $expiresAt ?? $expiryTime,
            'va_numbers' => $payment->va_numbers,
            'permata_va_number' => $payment->raw_response['permata_va_number'] ?? null,
            'bill_key' => $payment->raw_response['bill_key'] ?? null,
            'biller_code' => $payment->raw_response['biller_code'] ?? null,
            'metadata' => $payment->metadata,
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
