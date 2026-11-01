<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Services\MidtransAuditService;
use App\Services\MidtransCoreApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MidtransWebhookController extends Controller
{
    public function __construct(
        private MidtransCoreApiClient $apiClient,
        private MidtransAuditService $auditService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();

        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = $payload['gross_amount'] ?? '';
        $signatureKey = (string) ($payload['signature_key'] ?? '');
        $transactionStatus = (string) ($payload['transaction_status'] ?? '');
        $fraudStatus = (string) ($payload['fraud_status'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? '');

        if ($orderId === '' || $signatureKey === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Format payload webhook Midtrans tidak valid.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Find payment and associated merchant
        $payment = Payment::query()->with('merchant.configuration')
            ->where('merchant_order_id', $orderId)
            ->first();

        $merchant = $payment?->merchant;

        // If merchant is not linked to payment, find merchant via merchant_id in payload if provided
        if ($merchant === null && ! empty($payload['merchant_id'])) {
            $merchant = Merchant::query()->with('configuration')
                ->whereHas('configuration', fn ($q) => $q->where('midtrans_merchant_id', $payload['merchant_id']))
                ->first();
        }

        // Or fallback to default merchant
        if ($merchant === null) {
            $merchant = Merchant::query()->with('configuration')->where('is_active', true)->first();
        }

        $serverKey = $merchant?->configuration?->getServerKey();

        if (empty($serverKey)) {
            $this->auditService->record(
                action: 'webhook.rejected_no_server_key',
                status: 'failed',
                merchant: $merchant,
                responseCode: '422',
                metadata: ['order_id' => $orderId, 'transaction_id' => $transactionId]
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Server key untuk merchant tidak ditemukan.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Verify SHA512 signature
        $isSignatureValid = $this->apiClient->verifySignature(
            $orderId,
            $statusCode,
            $grossAmount,
            $serverKey,
            $signatureKey
        );

        // Record incoming webhook
        $webhook = PaymentWebhook::create([
            'merchant_id' => $merchant?->id,
            'payment_id' => $payment?->id,
            'order_id' => $orderId,
            'transaction_id' => $transactionId,
            'signature_key' => $signatureKey,
            'is_signature_valid' => $isSignatureValid,
            'payload' => $payload,
            'processed_at' => $isSignatureValid ? now() : null,
            'error_message' => $isSignatureValid ? null : 'Signature key verifikasi gagal.',
        ]);

        if (! $isSignatureValid) {
            $this->auditService->record(
                action: 'webhook.invalid_signature',
                status: 'failed',
                merchant: $merchant,
                responseCode: '403',
                metadata: ['order_id' => $orderId, 'signature_key' => $signatureKey]
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Signature Midtrans tidak valid.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Idempotent payment state transition
        if ($payment !== null) {
            $isPaid = in_array($transactionStatus, ['settlement', 'capture'], true)
                && ($fraudStatus === '' || $fraudStatus === 'accept');

            $payment->update([
                'midtrans_transaction_id' => $transactionId ?: $payment->midtrans_transaction_id,
                'transaction_status' => $transactionStatus,
                'fraud_status' => $fraudStatus ?: $payment->fraud_status,
                'status_code' => $statusCode,
                'status_message' => $payload['status_message'] ?? $payment->status_message,
                'paid_at' => $isPaid ? ($payment->paid_at ?? now()) : $payment->paid_at,
            ]);
        }

        $this->auditService->record(
            action: 'webhook.processed',
            status: 'success',
            merchant: $merchant,
            responseCode: '200',
            metadata: [
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
                'transaction_id' => $transactionId,
                'webhook_id' => $webhook->id,
            ]
        );

        return response()->json([
            'status' => 'OK',
            'message' => 'Webhook berhasil diproses.',
            'order_id' => $orderId,
            'transaction_status' => $transactionStatus,
        ]);
    }
}
