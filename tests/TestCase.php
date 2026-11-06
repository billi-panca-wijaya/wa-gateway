<?php

namespace BilliPancaWijaya\WhatsAppGateway\Tests;

use BilliPancaWijaya\WhatsAppGateway\WhatsAppGatewayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase($this->app);
    }

    protected function getPackageProviders($app)
    {
        return [
            WhatsAppGatewayServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('whatsapp-gateway.transport', 'mock');
        config()->set('whatsapp-gateway.hmac_secret', 'test-secret-key-1234567890123456');
        config()->set('whatsapp-gateway.turnstile.enabled', false);
    }

    protected function setUpDatabase($app)
    {
        $migrationFiles = [
            __DIR__.'/../database/migrations/0001_create_whatsapp_user_columns.php',
            __DIR__.'/../database/migrations/0002_create_whatsapp_otp_tables.php',
            __DIR__.'/../database/migrations/0003_create_whatsapp_sender_pools_table.php',
        ];

        foreach ($migrationFiles as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
}
