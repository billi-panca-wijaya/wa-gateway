<?php

namespace BilliPancaWijaya\WhatsAppGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppOtpChallenge extends Model
{
    protected $table = 'otp_challenges';

    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const EXPIRED = 'expired';
    public const LOCKED = 'locked';
    public const SUPERSEDED = 'superseded';
    public const CANCELLED = 'cancelled';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['verifier'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'consumed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WhatsAppOtpDelivery::class, 'challenge_id');
    }
}
