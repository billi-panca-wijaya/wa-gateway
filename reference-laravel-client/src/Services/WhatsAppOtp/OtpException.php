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
            'PHONE_INVALID' => 'Nomor WhatsApp tidak valid.',
            'OTP_INVALID' => 'Kode OTP salah atau tidak berlaku.',
            'OTP_EXPIRED' => 'Kode OTP sudah kedaluwarsa. Minta kode baru.',
            'OTP_ATTEMPTS_EXCEEDED' => 'Terlalu banyak percobaan. Minta kode baru.',
            'CHALLENGE_SUPERSEDED' => 'Kode ini sudah diganti. Gunakan kode terbaru yang dikirim.',
            'CHALLENGE_NOT_RESENDABLE' => 'Permintaan kode tidak dapat dikirim ulang. Mulai dari awal.',
            'RATE_LIMITED' => 'Terlalu banyak permintaan. Coba lagi nanti.',
            'RESEND_COOLDOWN' => 'Tunggu sebentar sebelum meminta kode baru.',
            'REQUEST_IN_PROGRESS' => 'Permintaan sebelumnya masih diproses.',
            'IDEMPOTENCY_KEY_REUSED' => 'Idempotency key sudah dipakai untuk permintaan lain.',
            'TRANSPORT_UNAVAILABLE' => 'Layanan WhatsApp sedang tidak tersedia. Coba lagi beberapa saat.',
            'ACCOUNT_DISABLED' => 'Akun tidak dapat digunakan. Hubungi dukungan.',
        ];

        return new self($code, $messages[$code] ?? 'Permintaan gagal.', $status, $extra, $retryAfter);
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
