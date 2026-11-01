<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Payment;
use App\Services\MidtransAuditService;
use App\Services\MidtransCoreApiClient;
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
            }

            $res = $this->apiClient->charge($serverKey, $payload, $merchant->environment);
            $rawResponse = $res['body'];
            $transactionId = $rawResponse['transaction_id'] ?? null;
            $statusCode = $rawResponse['status_code'] ?? (string) $res['status_code'];

            if (! empty($rawResponse['actions'])) {
                foreach ($rawResponse['actions'] as $action) {
                    if (($action['name'] ?? '') === 'generate-qr-code') {
                        $checkoutUrl = $action['url'] ?? null;
                    }
                }
            }
            if (! empty($rawResponse['qr_string'])) {
                $qrString = $rawResponse['qr_string'];
            }
            if (! empty($rawResponse['va_numbers'])) {
                $vaNumbers = $rawResponse['va_numbers'];
            }
        }

        $payment = Payment::create([
            'merchant_id' => $merchant->id,
            'merchant_order_id' => $orderId,
            'midtrans_transaction_id' => $transactionId,
            'gross_amount' => $grossAmount,
            'currency' => 'IDR',
            'payment_type' => $paymentType,
            'transaction_status' => $rawResponse['transaction_status'] ?? 'pending',
            'fraud_status' => $rawResponse['fraud_status'] ?? 'accept',
            'status_code' => $statusCode,
            'status_message' => $rawResponse['status_message'] ?? null,
            'checkout_url' => $checkoutUrl,
            'qr_string' => $qrString,
            'va_numbers' => $vaNumbers,
            'raw_response' => $rawResponse,
            'metadata' => $validated['metadata'] ?? [],
        ]);

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
        return [
            'id' => $payment->id,
            'merchant_order_id' => $payment->merchant_order_id,
            'midtrans_transaction_id' => $payment->midtrans_transaction_id,
            'gross_amount' => $payment->gross_amount,
            'amount_minor' => (int) round($payment->gross_amount),
            'currency' => $payment->currency,
            'payment_type' => $payment->payment_type,
            'transaction_status' => $payment->transaction_status,
            'status' => $payment->transaction_status,
            'status_code' => $payment->status_code,
            'checkout_url' => $payment->checkout_url,
            'qr_string' => $payment->qr_string,
            'va_numbers' => $payment->va_numbers,
            'metadata' => $payment->metadata,
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
