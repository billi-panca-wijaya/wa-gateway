<?php

namespace BilliPancaWijaya\WhatsAppGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppOtpDelivery extends Model
{
    protected $table = 'otp_deliveries';

    protected $guarded = [];

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(WhatsAppOtpChallenge::class, 'challenge_id');
    }
}
