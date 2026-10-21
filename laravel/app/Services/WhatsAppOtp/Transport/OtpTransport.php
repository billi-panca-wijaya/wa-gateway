<?php

namespace App\Services\WhatsAppOtp\Transport;

interface OtpTransport
{
    /**
     * Hand one message to the provider. Implementations must never log or persist $message.
     */
    public function send(string $phoneE164, #[\SensitiveParameter] string $message, string $idempotencyKey): DeliveryResult;

    public function name(): string;

    public function senderRef(): ?string;
}
