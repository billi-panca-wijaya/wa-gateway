<?php

namespace BilliPancaWijaya\WhatsAppGateway;

use BilliPancaWijaya\WhatsAppGateway\Commands\CheckGatewayStatusCommand;
use BilliPancaWijaya\WhatsAppGateway\Commands\InstallWhatsAppGatewayCommand;
use BilliPancaWijaya\WhatsAppGateway\Commands\PruneOtpChallengesCommand;
use BilliPancaWijaya\WhatsAppGateway\Services\OpenWaSessionClient;
use BilliPancaWijaya\WhatsAppGateway\Services\OtpService;
use BilliPancaWijaya\WhatsAppGateway\Services\SenderPoolService;
use BilliPancaWijaya\WhatsAppGateway\Services\TenantResolver;
use BilliPancaWijaya\WhatsAppGateway\Services\Transport\MockTransport;
use BilliPancaWijaya\WhatsAppGateway\Services\Transport\OtpTransport;
use BilliPancaWijaya\WhatsAppGateway\Services\Transport\PooledTransport;
use BilliPancaWijaya\WhatsAppGateway\Services\WhatsAppBotChallenge;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class WhatsAppGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configPath = __DIR__.'/../config/whatsapp-gateway.php';
        $this->mergeConfigFrom($configPath, 'whatsapp-gateway');

        // Backwards compatibility for applications referencing whatsapp_otp
        if (! config()->has('whatsapp_otp')) {
            config(['whatsapp_otp' => config('whatsapp-gateway')]);
        }

        $this->app->singleton(OpenWaSessionClient::class, function () {
            $config = (array) config('whatsapp-gateway.openwa', config('whatsapp_otp.openwa', []));

            return new OpenWaSessionClient($config);
        });

        $this->app->singleton(SenderPoolService::class, function ($app) {
            $poolConfig = (array) config('whatsapp-gateway.pool', config('whatsapp_otp.pool', []));

            return new SenderPoolService(
                $app->make(OpenWaSessionClient::class),
                $poolConfig
            );
        });

        $this->app->singleton(OtpTransport::class, function ($app) {
            $driver = config('whatsapp-gateway.transport', config('whatsapp_otp.transport', 'openwa'));

            if ($driver === 'openwa') {
                return new PooledTransport(
                    $app->make(SenderPoolService::class),
                    (array) config('whatsapp-gateway.openwa', config('whatsapp_otp.openwa', []))
                );
            }

            if ($driver === 'mock' && $app->environment(['local', 'testing'])) {
                return new MockTransport;
            }

            throw new RuntimeException('Invalid WA_OTP_TRANSPORT for this environment (mock is restricted to local/testing).');
        });

        $this->app->singleton(TenantResolver::class, fn () => new TenantResolver);
        $this->app->singleton(WhatsAppBotChallenge::class, fn () => new WhatsAppBotChallenge);

        $this->app->singleton(OtpService::class, function ($app) {
            return new OtpService($app->make(OtpTransport::class));
        });

        $this->app->singleton(WhatsAppGateway::class, function ($app) {
            return new WhatsAppGateway(
                $app->make(OtpService::class),
                $app->make(SenderPoolService::class),
                $app->make(OpenWaSessionClient::class),
                $app->make(OtpTransport::class)
            );
        });

        $this->app->alias(WhatsAppGateway::class, 'whatsapp-gateway');
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerCommands();
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // 1. Publish Configuration
        $this->publishes([
            __DIR__.'/../config/whatsapp-gateway.php' => config_path('whatsapp-gateway.php'),
        ], 'whatsapp-gateway-config');

        // 2. Publish Migrations
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'whatsapp-gateway-migrations');

        // 3. Publish Frontend UI Components & Assets
        if (is_dir(__DIR__.'/../resources/frontend')) {
            $this->publishes([
                __DIR__.'/../resources/frontend' => resource_path('js/whatsapp-gateway'),
            ], 'whatsapp-gateway-frontend');
        }
    }

    protected function registerRoutes(): void
    {
        $enabled = (bool) config('whatsapp-gateway.routes.enabled', true);
        if (! $enabled) {
            return;
        }

        $prefix = (string) config('whatsapp-gateway.routes.prefix', 'api/v1');
        $middleware = (array) config('whatsapp-gateway.routes.middleware', ['api']);

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(__DIR__.'/../routes/api.php');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallWhatsAppGatewayCommand::class,
                CheckGatewayStatusCommand::class,
                PruneOtpChallengesCommand::class,
            ]);
        }
    }
}
