<?php

namespace BilliPancaWijaya\WhatsAppGateway\Commands;

use Illuminate\Console\Command;

class InstallWhatsAppGatewayCommand extends Command
{
    protected $signature = 'whatsapp-gateway:install {--force : Overwrite existing files}';

    protected $description = 'Install and configure WhatsApp Gateway package resources';

    public function handle(): int
    {
        $this->info('Installing WhatsApp Gateway Package...');

        $params = ['--tag' => 'whatsapp-gateway-config'];
        if ($this->option('force')) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);
        $this->info('✔ Config published to config/whatsapp-gateway.php');

        $migParams = ['--tag' => 'whatsapp-gateway-migrations'];
        if ($this->option('force')) {
            $migParams['--force'] = true;
        }

        $this->call('vendor:publish', $migParams);
        $this->info('✔ Migrations published to database/migrations');

        if ($this->confirm('Would you like to run the database migrations now?', true)) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('🎉 WhatsApp Gateway package successfully installed!');
        $this->line('Next steps:');
        $this->line('1. Set your gateway environment variables in .env (OPENWA_BASE_URL, OPENWA_API_KEY, WA_OTP_HMAC_SECRET)');
        $this->line('2. Add "use BilliPancaWijaya\WhatsAppGateway\Traits\HasWhatsAppLogin;" to your User model.');
        $this->line('3. Test gateway connectivity with "php artisan whatsapp-gateway:status"');

        return Command::SUCCESS;
    }
}
