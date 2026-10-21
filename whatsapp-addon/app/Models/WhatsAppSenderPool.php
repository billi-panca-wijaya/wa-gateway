<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class WhatsAppSenderPool extends Model
{
    use HasFactory;

    protected $table = 'sender_pool';

    protected $fillable = [
        'tenant_key',
        'session_id',
        'label',
        'api_key',
        'api_key_encrypted',
        'status',
        'phone_e164',
        'push_name',
        'last_used_at',
        'suspended_at',
        'suspend_reason',
        'fail_count',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    protected $appends = [
        'has_api_key',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'suspended_at' => 'datetime',
            'fail_count' => 'integer',
        ];
    }

    public function setApiKeyAttribute(?string $value): void
    {
        if (! empty($value)) {
            $this->attributes['api_key_encrypted'] = Crypt::encryptString($value);
        }
    }

    public function getApiKeyAttribute(): ?string
    {
        if (empty($this->attributes['api_key_encrypted'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['api_key_encrypted']);
        } catch (Throwable) {
            return null;
        }
    }

    public function getHasApiKeyAttribute(): bool
    {
        return ! empty($this->attributes['api_key_encrypted']);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForTenant($query, string $tenantKey)
    {
        return $query->where('tenant_key', $tenantKey);
    }
}
