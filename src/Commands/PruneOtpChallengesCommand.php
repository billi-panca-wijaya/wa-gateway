<?php

namespace BilliPancaWijaya\WhatsAppGateway\Commands;

use BilliPancaWijaya\WhatsAppGateway\Models\WhatsAppOtpChallenge;
use BilliPancaWijaya\WhatsAppGateway\Models\WhatsAppOtpDelivery;
use Illuminate\Console\Command;

class PruneOtpChallengesCommand extends Command
{
    protected $signature = 'whatsapp-gateway:prune {--days= : Override configured retention days}';

    protected $description = 'Prune old expired WhatsApp OTP challenges and deliveries';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('whatsapp-gateway.retention_days', config('whatsapp_otp.retention_days', 30)));
        $cutoff = now()->subDays($days);

        $this->info("Pruning OTP records older than {$days} days (before {$cutoff->toDateTimeString()})...");

        $challenges = WhatsAppOtpChallenge::where('created_at', '<', $cutoff)->pluck('id');
        $deliveriesCount = WhatsAppOtpDelivery::whereIn('challenge_id', $challenges)->delete();
        $challengesCount = WhatsAppOtpChallenge::whereIn('id', $challenges)->delete();

        $this->info("✔ Pruned {$challengesCount} challenges and {$deliveriesCount} delivery records.");

        return Command::SUCCESS;
    }
}
