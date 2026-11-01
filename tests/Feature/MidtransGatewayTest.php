<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MidtransGatewayTest extends TestCase
{
    use RefreshDatabase;

    private string $internalToken = 'midtrans_service_secret_token_sandbox_64chars_key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.udev_internal.token' => $this->internalToken]);
    }

    public function test_internal_merchant_can_be_provisioned_and_listed(): void
    {
        $response = $this->withToken($this->internalToken)
            ->postJson('/api/v1/internal/merchants', [
                'merchant_code' => 'test-merchant',
                'merchant_name' => 'Test Merchant UDEV',
                'environment' => 'sandbox',
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.merchant_code', 'test-merchant')
            ->assertJsonPath('data.is_configured', false);

        $listResponse = $this->withToken($this->internalToken)
            ->getJson('/api/v1/internal/merchants');

        $listResponse->assertOk()
            ->assertJsonFragment(['merchant_code' => 'test-merchant']);
    }

    public function test_configuration_can_be_stored_encrypted_and_never_returns_plaintext_secret(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'udev-test',
            'merchant_name' => 'UDEV Test',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        $saveResponse = $this->withToken($this->internalToken)
            ->putJson("/api/v1/internal/merchants/{$merchant->merchant_code}/configuration", [
                'midtrans_merchant_id' => 'G123456789',
                'client_key' => 'SB-Mid-client-abcdef12345',
                'server_key' => 'SB-Mid-server-secretkey9999',
                'signature_secret_key' => 'supersecret123',
            ]);

        $saveResponse->assertOk()
            ->assertJsonPath('data.has_server_key', true)
            ->assertJsonPath('data.has_signature_key', true);

        // Verify plaintext is NOT in the response
        $content = $saveResponse->getContent();
        $this->assertStringNotContainsString('SB-Mid-server-secretkey9999', $content);
        $this->assertStringNotContainsString('supersecret123', $content);

        // Verify GET configuration also does not return plaintext
        $getResponse = $this->withToken($this->internalToken)
            ->getJson("/api/v1/internal/merchants/{$merchant->merchant_code}/configuration");

        $getResponse->assertOk()
            ->assertJsonPath('data.has_server_key', true);
        $this->assertStringNotContainsString('SB-Mid-server-secretkey9999', $getResponse->getContent());
    }

    public function test_readiness_reflects_configuration_state(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'readiness-test',
            'merchant_name' => 'Readiness Test',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $merchant->configuration()->create(['is_active' => true]);

        $res1 = $this->withToken($this->internalToken)
            ->getJson("/api/v1/internal/merchants/{$merchant->merchant_code}/readiness");
        $res1->assertOk()
            ->assertJsonPath('data.is_ready', false)
            ->assertJsonPath('data.readiness_status', 'incomplete');

        // Configure server key
        $merchant->configuration->setServerKeyAttribute('SB-Mid-server-xyz');
        $merchant->configuration->save();

        $res2 = $this->withToken($this->internalToken)
            ->getJson("/api/v1/internal/merchants/{$merchant->merchant_code}/readiness");
        $res2->assertOk()
            ->assertJsonPath('data.is_ready', true)
            ->assertJsonPath('data.readiness_status', 'ready');
    }

    public function test_payment_creation_is_idempotent(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $merchant->configuration()->create(['is_active' => true]);

        $payload = [
            'merchant_order_id' => 'ORDER-IDEMP-001',
            'amount' => 50000,
            'payment_type' => 'qris',
            'merchant_code' => 'udev-central',
        ];

        $res1 = $this->postJson('/api/v1/payments', $payload);
        $res1->assertStatus(201)
            ->assertJsonPath('data.merchant_order_id', 'ORDER-IDEMP-001');

        $res2 = $this->postJson('/api/v1/payments', $payload);
        $res2->assertStatus(200)
            ->assertJsonPath('data.merchant_order_id', 'ORDER-IDEMP-001');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_webhook_verifies_signature_and_updates_payment(): void
    {
        $serverKey = 'SB-Mid-server-sample123456';
        $merchant = Merchant::create([
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $config = $merchant->configuration()->create(['is_active' => true]);
        $config->setServerKeyAttribute($serverKey);
        $config->save();

        $orderId = 'ORDER-WEBHOOK-TEST-01';
        $grossAmount = '75000.00';
        $statusCode = '200';

        $payment = Payment::create([
            'merchant_id' => $merchant->id,
            'merchant_order_id' => $orderId,
            'gross_amount' => 75000,
            'currency' => 'IDR',
            'payment_type' => 'qris',
            'transaction_status' => 'pending',
        ]);

        // Calculate valid signature: SHA512(order_id + status_code + gross_amount + ServerKey)
        $validSignature = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        $webhookPayload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $validSignature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'midtrans-trans-999',
            'payment_type' => 'qris',
        ];

        $response = $this->postJson('/api/v1/webhooks/midtrans', $webhookPayload);

        $response->assertOk()
            ->assertJsonPath('status', 'OK');

        $payment->refresh();
        $this->assertEquals('settlement', $payment->transaction_status);
        $this->assertNotNull($payment->paid_at);

        // Test invalid signature
        $invalidPayload = $webhookPayload;
        $invalidPayload['signature_key'] = 'invalid_hash_value_here';

        $invalidResponse = $this->postJson('/api/v1/webhooks/midtrans', $invalidPayload);
        $invalidResponse->assertStatus(403);
    }

    public function test_sandbox_charge_for_bca_virtual_account(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'bca-merchant',
            'merchant_name' => 'BCA Merchant',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $config = $merchant->configuration()->create(['is_active' => true]);
        $config->setServerKeyAttribute('SB-Mid-server-bca123');
        $config->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => \Illuminate\Support\Facades\Http::response([
                'status_code' => '201',
                'status_message' => 'Success, BCA VA created',
                'transaction_id' => 'midtrans-bca-001',
                'order_id' => 'TEST-BCA-001',
                'gross_amount' => '20000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'va_numbers' => [
                    ['bank' => 'bca', 'va_number' => '9880123456789012'],
                ],
            ], 201),
        ]);

        $res = $this->withToken($this->internalToken)
            ->postJson("/api/v1/internal/merchants/{$merchant->merchant_code}/sandbox-test/charge", [
                'order_id' => 'TEST-BCA-001',
                'amount' => 20000,
                'payment_type' => 'bank_transfer',
                'bank' => 'bca',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_type', 'bank_transfer')
            ->assertJsonPath('data.bank', 'bca')
            ->assertJsonPath('data.va_numbers.0.bank', 'bca')
            ->assertJsonPath('data.va_numbers.0.va_number', '9880123456789012');

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request['payment_type'] === 'bank_transfer'
                && ($request['bank_transfer']['bank'] ?? '') === 'bca';
        });
    }

    public function test_sandbox_charge_for_bni_virtual_account(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'bni-merchant',
            'merchant_name' => 'BNI Merchant',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $config = $merchant->configuration()->create(['is_active' => true]);
        $config->setServerKeyAttribute('SB-Mid-server-bni123');
        $config->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => \Illuminate\Support\Facades\Http::response([
                'status_code' => '201',
                'status_message' => 'Success, BNI VA created',
                'transaction_id' => 'midtrans-bni-001',
                'order_id' => 'TEST-BNI-001',
                'gross_amount' => '25000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'va_numbers' => [
                    ['bank' => 'bni', 'va_number' => '9880987654321098'],
                ],
            ], 201),
        ]);

        $res = $this->withToken($this->internalToken)
            ->postJson("/api/v1/internal/merchants/{$merchant->merchant_code}/sandbox-test/charge", [
                'order_id' => 'TEST-BNI-001',
                'amount' => 25000,
                'payment_type' => 'bank_transfer',
                'bank' => 'bni',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.bank', 'bni')
            ->assertJsonPath('data.va_numbers.0.bank', 'bni')
            ->assertJsonPath('data.va_numbers.0.va_number', '9880987654321098');

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request['payment_type'] === 'bank_transfer'
                && ($request['bank_transfer']['bank'] ?? '') === 'bni';
        });
    }

    public function test_sandbox_charge_for_bri_virtual_account(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'bri-merchant',
            'merchant_name' => 'BRI Merchant',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $config = $merchant->configuration()->create(['is_active' => true]);
        $config->setServerKeyAttribute('SB-Mid-server-bri123');
        $config->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => \Illuminate\Support\Facades\Http::response([
                'status_code' => '201',
                'status_message' => 'Success, BRI VA created',
                'transaction_id' => 'midtrans-bri-001',
                'order_id' => 'TEST-BRI-001',
                'gross_amount' => '30000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'va_numbers' => [
                    ['bank' => 'bri', 'va_number' => '9880112233445566'],
                ],
            ], 201),
        ]);

        $res = $this->withToken($this->internalToken)
            ->postJson("/api/v1/internal/merchants/{$merchant->merchant_code}/sandbox-test/charge", [
                'order_id' => 'TEST-BRI-001',
                'amount' => 30000,
                'payment_type' => 'bank_transfer',
                'bank' => 'bri',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.bank', 'bri')
            ->assertJsonPath('data.va_numbers.0.bank', 'bri')
            ->assertJsonPath('data.va_numbers.0.va_number', '9880112233445566');

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request['payment_type'] === 'bank_transfer'
                && ($request['bank_transfer']['bank'] ?? '') === 'bri';
        });
    }

    public function test_sandbox_charge_for_permata_virtual_account(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'permata-merchant',
            'merchant_name' => 'Permata Merchant',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $config = $merchant->configuration()->create(['is_active' => true]);
        $config->setServerKeyAttribute('SB-Mid-server-permata123');
        $config->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => \Illuminate\Support\Facades\Http::response([
                'status_code' => '201',
                'status_message' => 'Success, Permata VA created',
                'transaction_id' => 'midtrans-permata-001',
                'order_id' => 'TEST-PERMATA-001',
                'gross_amount' => '35000.00',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'permata_va_number' => '8562000087654321',
            ], 201),
        ]);

        $res = $this->withToken($this->internalToken)
            ->postJson("/api/v1/internal/merchants/{$merchant->merchant_code}/sandbox-test/charge", [
                'order_id' => 'TEST-PERMATA-001',
                'amount' => 35000,
                'payment_type' => 'bank_transfer',
                'bank' => 'permata',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.bank', 'permata')
            ->assertJsonPath('data.permata_va_number', '8562000087654321')
            ->assertJsonPath('data.va_numbers.0.bank', 'permata')
            ->assertJsonPath('data.va_numbers.0.va_number', '8562000087654321');

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request['payment_type'] === 'bank_transfer'
                && ($request['bank_transfer']['bank'] ?? '') === 'permata';
        });
    }

    public function test_sandbox_charge_for_mandiri_bill_payment(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'mandiri-merchant',
            'merchant_name' => 'Mandiri Merchant',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $config = $merchant->configuration()->create(['is_active' => true]);
        $config->setServerKeyAttribute('SB-Mid-server-mandiri123');
        $config->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => \Illuminate\Support\Facades\Http::response([
                'status_code' => '201',
                'status_message' => 'Success, Mandiri Bill created',
                'transaction_id' => 'midtrans-mandiri-001',
                'order_id' => 'TEST-MANDIRI-001',
                'gross_amount' => '40000.00',
                'payment_type' => 'echannel',
                'transaction_status' => 'pending',
                'biller_code' => '70012',
                'bill_key' => '990000000123',
            ], 201),
        ]);

        $res = $this->withToken($this->internalToken)
            ->postJson("/api/v1/internal/merchants/{$merchant->merchant_code}/sandbox-test/charge", [
                'order_id' => 'TEST-MANDIRI-001',
                'amount' => 40000,
                'payment_type' => 'bank_transfer',
                'bank' => 'mandiri',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_type', 'echannel')
            ->assertJsonPath('data.bank', 'mandiri')
            ->assertJsonPath('data.biller_code', '70012')
            ->assertJsonPath('data.bill_key', '990000000123')
            ->assertJsonPath('data.va_numbers.0.bank', 'mandiri')
            ->assertJsonPath('data.va_numbers.0.va_number', '990000000123');

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request['payment_type'] === 'echannel'
                && isset($request['echannel']['bill_info1']);
        });
    }

    public function test_sandbox_charge_for_qris(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'qris-merchant',
            'merchant_name' => 'QRIS Merchant',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $config = $merchant->configuration()->create(['is_active' => true]);
        $config->setServerKeyAttribute('SB-Mid-server-qris123');
        $config->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => \Illuminate\Support\Facades\Http::response([
                'status_code' => '201',
                'status_message' => 'Success, QRIS created',
                'transaction_id' => 'midtrans-qris-001',
                'order_id' => 'TEST-QRIS-001',
                'gross_amount' => '15000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => '00020101021226680016ID.CO.SHOPEE.WWW...',
                'actions' => [
                    ['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/001/qr-code'],
                ],
            ], 201),
        ]);

        $res = $this->withToken($this->internalToken)
            ->postJson("/api/v1/internal/merchants/{$merchant->merchant_code}/sandbox-test/charge", [
                'order_id' => 'TEST-QRIS-001',
                'amount' => 15000,
                'payment_type' => 'qris',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_type', 'qris')
            ->assertJsonPath('data.qr_string', '00020101021226680016ID.CO.SHOPEE.WWW...')
            ->assertJsonPath('data.checkout_url', 'https://api.sandbox.midtrans.com/v2/qris/001/qr-code');

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request['payment_type'] === 'qris'
                && ($request['qris']['acquirer'] ?? '') === 'gopay';
        });
    }

    public function test_sandbox_charge_for_gopay(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'gopay-merchant',
            'merchant_name' => 'GoPay Merchant',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $config = $merchant->configuration()->create(['is_active' => true]);
        $config->setServerKeyAttribute('SB-Mid-server-gopay123');
        $config->save();

        \Illuminate\Support\Facades\Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => \Illuminate\Support\Facades\Http::response([
                'status_code' => '201',
                'status_message' => 'Success, GoPay created',
                'transaction_id' => 'midtrans-gopay-001',
                'order_id' => 'TEST-GOPAY-001',
                'gross_amount' => '12000.00',
                'payment_type' => 'gopay',
                'transaction_status' => 'pending',
                'actions' => [
                    ['name' => 'deeplink-redirect', 'url' => 'https://simulator.sandbox.midtrans.com/gopay/partner/app/payment-pin?id=123'],
                ],
            ], 201),
        ]);

        $res = $this->withToken($this->internalToken)
            ->postJson("/api/v1/internal/merchants/{$merchant->merchant_code}/sandbox-test/charge", [
                'order_id' => 'TEST-GOPAY-001',
                'amount' => 12000,
                'payment_type' => 'gopay',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_type', 'gopay')
            ->assertJsonPath('data.checkout_url', 'https://simulator.sandbox.midtrans.com/gopay/partner/app/payment-pin?id=123');

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request['payment_type'] === 'gopay'
                && ($request['gopay']['enable_callback'] ?? false) === true;
        });
    }

    public function test_sandbox_charge_rejects_unsupported_bank(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'invalid-bank-merchant',
            'merchant_name' => 'Invalid Bank Merchant',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);
        $config = $merchant->configuration()->create(['is_active' => true]);
        $config->setServerKeyAttribute('SB-Mid-server-invalid123');
        $config->save();

        $res = $this->withToken($this->internalToken)
            ->postJson("/api/v1/internal/merchants/{$merchant->merchant_code}/sandbox-test/charge", [
                'order_id' => 'TEST-INVALID-001',
                'amount' => 10000,
                'payment_type' => 'bank_transfer',
                'bank' => 'cimb_unsupported',
            ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['bank']);
    }
}
