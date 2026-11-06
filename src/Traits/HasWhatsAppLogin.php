<?php

namespace BilliPancaWijaya\WhatsAppGateway\Traits;

/**
 * Trait to be used on the application's User / Member Eloquent model.
 *
 * Example usage:
 *
 *   use BilliPancaWijaya\WhatsAppGateway\Traits\HasWhatsAppLogin;
 *
 *   class User extends Authenticatable
 *   {
 *       use HasWhatsAppLogin;
 *   }
 */
trait HasWhatsAppLogin
{
    /**
     * Automatically registers WhatsApp login columns in fillable attributes.
     */
    public function initializeHasWhatsAppLogin(): void
    {
        $this->fillable = array_unique(array_merge($this->fillable, [
            'tenant_key',
            'phone_e164',
            'phone_verified_at',
            'onboarding_completed_at',
            'is_active',
            'last_login_at',
        ]));

        if (! isset($this->casts['phone_verified_at'])) {
            $this->casts['phone_verified_at'] = 'datetime';
        }
        if (! isset($this->casts['onboarding_completed_at'])) {
            $this->casts['onboarding_completed_at'] = 'datetime';
        }
        if (! isset($this->casts['last_login_at'])) {
            $this->casts['last_login_at'] = 'datetime';
        }
        if (! isset($this->casts['is_active'])) {
            $this->casts['is_active'] = 'boolean';
        }
    }

    /**
     * Check if the member has a verified WhatsApp phone number.
     */
    public function isPhoneVerified(): bool
    {
        return ! is_null($this->phone_verified_at);
    }

    /**
     * Mark the member's WhatsApp phone number as verified.
     */
    public function markPhoneAsVerified(): bool
    {
        return $this->forceFill([
            'phone_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Query scope to find a user by their E.164 phone number.
     */
    public function scopeWherePhone($query, string $phone, ?string $tenantKey = null)
    {
        $query->where('phone_e164', $phone);

        if ($tenantKey !== null) {
            $query->where('tenant_key', $tenantKey);
        }

        return $query;
    }
}
