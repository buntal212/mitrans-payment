<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantConfiguration;
use App\Models\Payment;
use App\Services\MidtransCoreApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentFeeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_charge_stores_fee_breakdown_metadata(): void
    {
        $merchant = Merchant::create([
            'merchant_code' => 'udev-central',
            'merchant_name' => 'UDEV Central',
            'environment' => 'sandbox',
            'is_active' => true,
        ]);

        $config = new MerchantConfiguration([
            'merchant_id' => $merchant->id,
            'is_active' => true,
        ]);
        $config->setServerKeyAttribute('SB-Mid-server-TEST1234');
        $config->save();

        $mockClient = Mockery::mock(MidtransCoreApiClient::class);
        $mockClient->shouldReceive('charge')
            ->once()
            ->andReturn([
                'successful' => true,
                'status_code' => 201,
                'body' => [
                    'status_code' => '201',
                    'transaction_status' => 'pending',
                    'transaction_id' => 'tx-fee-001',
                    'order_id' => 'ORDER-FEE-001',
                    'gross_amount' => '100700.00',
                    'actions' => [
                        ['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/tx-fee-001/qr-code'],
                    ],
                ],
            ]);
        $this->app->instance(MidtransCoreApiClient::class, $mockClient);

        $feeBreakdown = [
            'package_name' => 'Paket Pro',
            'base_price' => 100000,
            'discount_amount' => 0,
            'subtotal_amount' => 100000,
            'payment_method' => 'qris',
            'fee_type' => 'percent',
            'fee_rate' => 0.7,
            'fee_amount' => 700,
            'total_amount' => 100700,
        ];

        $response = $this->postJson('/api/v1/payments', [
            'merchant_code' => 'udev-central',
            'merchant_order_id' => 'ORDER-FEE-001',
            'amount' => 100700,
            'payment_type' => 'qris',
            'fee_breakdown' => $feeBreakdown,
            'item_details' => [
                ['id' => 'package', 'price' => 100000, 'quantity' => 1, 'name' => 'Paket Pro'],
                ['id' => 'fee', 'price' => 700, 'quantity' => 1, 'name' => 'Biaya Admin'],
            ],
        ]);

        $response->assertCreated();

        $payment = Payment::query()->where('merchant_order_id', 'ORDER-FEE-001')->firstOrFail();
        $this->assertEquals('100700.00', $payment->gross_amount);
        $this->assertEquals($feeBreakdown, $payment->metadata['fee_breakdown']);
    }
}
