<?php

namespace App\Services\WhatsAppOtp\Transport;

/**
 * Outcome of handing a message to the provider.
 *  - submitted: provider accepted the message (NOT proof of delivered/read).
 *  - unknown:   the request may have reached the provider (timeout / ambiguous 5xx). Do not auto-resend.
 *  - failed:    proven not sent (connection refused, provider refused before sending, not configured).
 */
final class DeliveryResult
{
    public const SUBMITTED = 'submitted';
    public const UNKNOWN = 'unknown';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
    ) {}

    public static function submitted(?string $providerMessageId): self
    {
        return new self(self::SUBMITTED, $providerMessageId);
    }

    public static function unknown(string $errorCode): self
    {
        return new self(self::UNKNOWN, null, $errorCode);
    }

    public static function failed(string $errorCode): self
    {
        return new self(self::FAILED, null, $errorCode);
    }
}
