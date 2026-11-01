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
}
