<?php

namespace BilliPancaWijaya\WhatsAppGateway\Commands;

use BilliPancaWijaya\WhatsAppGateway\Services\OpenWaSessionClient;
use BilliPancaWijaya\WhatsAppGateway\Services\SenderPoolService;
use Illuminate\Console\Command;
use Throwable;

class CheckGatewayStatusCommand extends Command
{
    protected $signature = 'whatsapp-gateway:status {--session= : Check specific session ID}';

    protected $description = 'Check connectivity to OpenWA Gateway and sender pool status';

    public function handle(OpenWaSessionClient $client, SenderPoolService $poolService): int
    {
        $this->info('Checking WhatsApp Gateway Status...');

        $baseUrl = config('whatsapp-gateway.openwa.base_url', config('whatsapp_otp.openwa.base_url'));
        $sessionId = $this->option('session') ?: config('whatsapp-gateway.openwa.session_id', config('whatsapp_otp.openwa.session_id', 'default'));
        $transport = config('whatsapp-gateway.transport', config('whatsapp_otp.transport', 'openwa'));

        $this->table(
            ['Parameter', 'Configured Value'],
            [
                ['Transport Driver', $transport],
                ['Gateway Base URL', $baseUrl ?: '(not set)'],
                ['Default Session ID', $sessionId ?: '(not set)'],
                ['Configured', $client->configured($sessionId) ? 'Yes' : 'No'],
            ]
        );

        if (! $client->configured($sessionId)) {
            $this->warn('Gateway is not fully configured. Please set OPENWA_BASE_URL and OPENWA_API_KEY in .env.');

            return Command::FAILURE;
        }

        try {
            $status = $client->status($sessionId);
            $this->info('✔ Successfully connected to OpenWA Gateway!');
            $this->table(
                ['Key', 'Value'],
                [
                    ['Session ID', $status['id'] ?? '-'],
                    ['Status', $status['status'] ?? '-'],
                    ['Phone', $status['phone'] ?? '-'],
                    ['Push Name', $status['push_name'] ?? '-'],
                    ['Connected At', $status['connected_at'] ?? '-'],
                    ['Restriction', $status['restriction'] ?? 'None'],
                ]
            );

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('✘ Failed connecting to OpenWA Gateway: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
