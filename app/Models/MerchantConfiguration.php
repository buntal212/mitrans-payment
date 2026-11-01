<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class MerchantConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'midtrans_merchant_id',
        'client_key',
        'encrypted_server_key',
        'encrypted_signature_key',
        'is_active',
    ];

    protected $hidden = [
        'encrypted_server_key',
        'encrypted_signature_key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function setServerKeyAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['encrypted_server_key'] = null;
        } else {
            $this->attributes['encrypted_server_key'] = Crypt::encryptString(trim($value));
        }
    }

    public function getServerKey(): ?string
    {
        if (empty($this->attributes['encrypted_server_key'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['encrypted_server_key']);
        } catch (Throwable) {
            return null;
        }
    }

    public function setSignatureKeyAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['encrypted_signature_key'] = null;
        } else {
            $this->attributes['encrypted_signature_key'] = Crypt::encryptString(trim($value));
        }
    }

    public function getSignatureKey(): ?string
    {
        if (empty($this->attributes['encrypted_signature_key'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['encrypted_signature_key']);
        } catch (Throwable) {
            return null;
        }
    }

    public function getMaskedServerKeyAttribute(): ?string
    {
        $key = $this->getServerKey();
        if ($key === null || $key === '') {
            return null;
        }

        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 8).str_repeat('*', max(4, $length - 12)).substr($key, -4);
    }

    public function getMaskedSignatureKeyAttribute(): ?string
    {
        $key = $this->getSignatureKey();
        if ($key === null || $key === '') {
            return null;
        }

        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4).str_repeat('*', max(4, $length - 8)).substr($key, -4);
    }
}
