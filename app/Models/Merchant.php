<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_code',
        'merchant_name',
        'environment',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function configuration(): HasOne
    {
        return $this->hasOne(MerchantConfiguration::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(GatewayAudit::class);
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }

    public function isConfigured(): bool
    {
        return $this->configuration !== null
            && ! empty($this->configuration->encrypted_server_key);
    }

    public function isReady(): bool
    {
        return $this->is_active && $this->isConfigured();
    }
}
