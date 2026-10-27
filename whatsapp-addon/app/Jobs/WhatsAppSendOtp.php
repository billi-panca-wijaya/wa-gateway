<?php

namespace App\Jobs;

use App\Services\WhatsAppOtp\OtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class WhatsAppSendOtp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 20;

    public int $timeout = 60;

    public function __construct(public readonly string $challengeId) {}

    public function handle(OtpService $otp): void
    {
        if (! $otp->deliverQueued($this->challengeId)) {
            $this->release(2);
        }
    }
}
