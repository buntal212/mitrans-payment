<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'merchant_id',
        'merchant_order_id',
        'midtrans_transaction_id',
        'gross_amount',
        'currency',
        'payment_type',
        'transaction_status',
        'fraud_status',
        'status_code',
        'status_message',
        'checkout_url',
        'qr_string',
        'va_numbers',
        'raw_response',
        'metadata',
        'paid_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'va_numbers' => 'array',
            'raw_response' => 'array',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(PaymentWebhook::class);
    }

    public function isSuccess(): bool
    {
        return in_array($this->transaction_status, ['settlement', 'capture'], true);
    }

    public function isPending(): bool
    {
        return $this->transaction_status === 'pending';
    }

    public function isFailed(): bool
    {
        return in_array($this->transaction_status, ['deny', 'cancel', 'expire', 'failure'], true);
    }
}
