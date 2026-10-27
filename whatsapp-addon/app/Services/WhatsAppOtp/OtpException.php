<?php

namespace App\Services\WhatsAppOtp;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class OtpException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $extra = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, int $status, array $extra = [], ?int $retryAfter = null): self
    {
        $messages = [
            'PHONE_INVALID' => 'Enter a valid WhatsApp number.',
            'OTP_INVALID' => 'Incorrect or invalid code.',
            'OTP_EXPIRED' => 'Code expired. Request a new code.',
            'OTP_ATTEMPTS_EXCEEDED' => 'Too many attempts. Request a new code.',
            'CHALLENGE_SUPERSEDED' => 'This code was replaced. Use the latest code.',
            'CHALLENGE_NOT_RESENDABLE' => 'This request cannot be resent. Start again.',
            'RATE_LIMITED' => 'Too many requests. Try again later.',
            'RESEND_COOLDOWN' => 'Please wait before requesting another code.',
            'REQUEST_IN_PROGRESS' => 'Your previous request is still processing.',
            'IDEMPOTENCY_KEY_REUSED' => 'This request key was used for another request.',
            'TRANSPORT_UNAVAILABLE' => 'WhatsApp delivery is temporarily unavailable. Please try again.',
            'ACCOUNT_DISABLED' => 'Your account is inactive. Contact support.',
        ];

        return new self($code, $messages[$code] ?? 'Request failed.', $status, $extra, $retryAfter);
    }

    /**
     * Expected domain outcome: never written to the error log (stack args could carry phone numbers).
     */
    public function report(): bool
    {
        return true;
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra;
        $headers = [];
        if ($this->retryAfter !== null) {
            $body['retry_after'] = $this->retryAfter;
            $headers['Retry-After'] = (string) $this->retryAfter;
        }

        return response()->json($body, $this->status, $headers);
    }
}
