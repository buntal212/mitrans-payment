<?php

use App\Http\Controllers\Api\V1\Internal\MidtransInternalMerchantController;
use App\Http\Controllers\Api\V1\MidtransWebhookController;
use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Route;

// Webhook dari Midtrans
Route::post('v1/webhooks/midtrans', MidtransWebhookController::class)
    ->name('midtrans.webhook');

// Merchant Payment API (Public / Internal)
Route::prefix('v1')->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index'])->name('midtrans.payments.index');
    Route::post('payments', [PaymentController::class, 'store'])->name('midtrans.payments.store');
    Route::get('payments/{id}', [PaymentController::class, 'show'])->name('midtrans.payments.show');
    Route::get('payments/{id}/status', [PaymentController::class, 'status'])->name('midtrans.payments.status');
});

// Internal Service-to-Service API (terautentikasi oleh token apiudev-billing)
Route::prefix('v1/internal')->middleware('internal')->group(function (): void {
    Route::get('merchants', [MidtransInternalMerchantController::class, 'index'])
        ->name('internal.midtrans.merchants.index');
    Route::post('merchants', [MidtransInternalMerchantController::class, 'store'])
        ->name('internal.midtrans.merchants.store');
    Route::get('merchants/{merchantCode}', [MidtransInternalMerchantController::class, 'show'])
        ->name('internal.midtrans.merchants.show');
    Route::put('merchants/{merchantCode}', [MidtransInternalMerchantController::class, 'update'])
        ->name('internal.midtrans.merchants.update');
    Route::get('merchants/{merchantCode}/readiness', [MidtransInternalMerchantController::class, 'readiness'])
        ->name('internal.midtrans.merchants.readiness');
    Route::get('merchants/{merchantCode}/configuration', [MidtransInternalMerchantController::class, 'getConfiguration'])
        ->name('internal.midtrans.merchants.configuration.get');
    Route::put('merchants/{merchantCode}/configuration', [MidtransInternalMerchantController::class, 'updateConfiguration'])
        ->name('internal.midtrans.merchants.configuration.update');
    Route::post('merchants/{merchantCode}/test-connection', [MidtransInternalMerchantController::class, 'testConnection'])
        ->name('internal.midtrans.test.connection');
    Route::post('merchants/{merchantCode}/sandbox-test/charge', [MidtransInternalMerchantController::class, 'createSandboxPayment'])
        ->name('internal.midtrans.sandbox.charge');
    Route::get('merchants/{merchantCode}/sandbox-test/status/{orderId}', [MidtransInternalMerchantController::class, 'querySandboxPayment'])
        ->name('internal.midtrans.sandbox.status');
    Route::get('merchants/{merchantCode}/audits', [MidtransInternalMerchantController::class, 'audits'])
        ->name('internal.midtrans.audits.index');
    Route::get('merchants/{merchantCode}/payments', [MidtransInternalMerchantController::class, 'payments'])
        ->name('internal.midtrans.payments.index');
    Route::get('merchants/{merchantCode}/payments/{paymentId}', [MidtransInternalMerchantController::class, 'paymentDetail'])
        ->name('internal.midtrans.payments.detail');
});
