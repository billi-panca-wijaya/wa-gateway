<?php

namespace Tests\Feature;

use App\Jobs\WhatsAppSendOtp;
use App\Models\WhatsAppOtpChallenge as OtpChallenge;
use App\Services\WhatsAppOtp\OtpService;
use App\Services\WhatsAppOtp\Transport\DeliveryResult;
use App\Services\WhatsAppOtp\Transport\MockTransport;
use App\Services\WhatsAppOtp\Transport\OpenWaTransport;
use App\Services\WhatsAppOtp\Transport\OtpTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WhatsAppQueuedLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'whatsapp_otp.transport' => 'mock',
            'whatsapp_otp.hmac_secret' => 'queue-test-secret',
            'whatsapp_otp.queue.enabled' => true,
            'whatsapp_otp.queue.connection' => 'redis',
            'whatsapp_otp.turnstile.enabled' => true,
            'whatsapp_otp.turnstile.secret_key' => 'server-only-secret',
            'whatsapp_otp.turnstile.site_key' => 'public-site-key',
            'whatsapp_otp.turnstile.hostnames' => 'localhost',
            'whatsapp_otp.sender_limits.enabled' => false,
        ]);
        if (is_file(__DIR__.'/../../routes/whatsapp.php')) {
            Route::middleware('api')->prefix('api')->group(__DIR__.'/../../routes/whatsapp.php');
            require __DIR__.'/../../routes/whatsapp_otp_delivery.php';
        }
        MockTransport::$forceStatus = null;
        $this->withHeader('Referer', 'http://localhost');
    }

    private function passBot(string $action = 'otp_request'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => $action])]);
    }

    private function requestCode(string $token = 'valid-token', string $phone = '081234567890')
    {
        return $this->postJson('/api/v1/auth/whatsapp/request-otp', ['phone' => $phone, 'challenge_token' => $token]);
    }

    public function test_missing_bot_token_never_queues_or_sends(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/auth/whatsapp/request-otp', ['phone' => '081234567890'])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_wrong_hostname_or_action_is_rejected(): void
    {
        Queue::fake();
        foreach ([['evil.test', 'otp_request'], ['localhost', 'another_action']] as [$host, $action]) {
            Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => $host, 'action' => $action])]);
            $this->requestCode(uniqid())->assertUnprocessable()->assertJson(['code' => 'BOT_CHECK_FAILED']);
        }
        Queue::assertNothingPushed();
    }

    public function test_challenge_outage_and_missing_configuration_fail_closed(): void
    {
        Queue::fake();
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::failedConnection()]);
        $this->requestCode()->assertStatus(503)->assertJson(['code' => 'BOT_CHECK_UNAVAILABLE']);
        config(['whatsapp_otp.turnstile.secret_key' => '']);
        $this->requestCode('another-token')->assertStatus(503);
        Queue::assertNothingPushed();
    }

    public function test_configuration_never_exposes_secret(): void
    {
        $this->getJson('/api/v1/auth/whatsapp/challenge-config')->assertOk()
            ->assertJson(['site_key' => 'public-site-key', 'enabled' => true])->assertDontSee('server-only-secret');
    }

    public function test_request_only_queues_id_then_worker_sends_four_digits_once(): void
    {
        Queue::fake();
        $this->passBot();
        $this->freezeTime();
        $response = $this->requestCode()->assertAccepted()->assertJson(['delivery_status' => 'queued', 'expires_at' => null]);
        $id = $response->json('challenge_id');
        $this->assertNull(OtpChallenge::findOrFail($id)->verifier);
        $this->assertSame([], MockTransport::messages('+6281234567890'));
        Queue::assertPushedOn('whatsapp-otp', WhatsAppSendOtp::class, fn ($job) => $job->challengeId === $id && ! str_contains(serialize($job), '+6281234567890'));
        $this->travel(10)->seconds();
        $job = new WhatsAppSendOtp($id);
        $job->handle(app(OtpService::class));
        $code = MockTransport::lastCode('+6281234567890');
        $this->assertMatchesRegularExpression('/^[0-9]{4}$/D', $code);
        $this->getJson('/api/v1/auth/whatsapp/challenges/'.$id)->assertOk()->assertJson(['delivery_status' => 'submitted', 'expires_in' => 300, 'resend_after' => 20]);
        $job->handle(app(OtpService::class));
        $this->assertCount(1, MockTransport::messages('+6281234567890'));
        $this->postJson('/api/v1/auth/whatsapp/verify-otp', ['challenge_id' => $id, 'code' => $code])->assertOk()->assertJson(['authenticated' => true]);
    }

    public function test_idempotent_retry_does_not_revalidate_token_or_enqueue_twice(): void
    {
        Queue::fake();
        $this->passBot();
        $this->withHeader('Idempotency-Key', 'request-one');
        $id = $this->requestCode()->assertAccepted()->json('challenge_id');
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->requestCode()->assertAccepted()->assertJson(['challenge_id' => $id, 'replayed' => true]);
        Queue::assertPushed(WhatsAppSendOtp::class, 1);
        Http::assertSentCount(1);
        $this->requestCode('valid-token', '081298765432')->assertUnprocessable()->assertJson(['code' => 'BOT_CHECK_FAILED']);
    }

    public function test_replayed_token_cannot_authorize_new_request(): void
    {
        Queue::fake();
        $this->passBot();
        $this->withHeader('Idempotency-Key', 'first')->requestCode()->assertAccepted();
        $this->withHeader('Idempotency-Key', 'second')->requestCode()->assertUnprocessable();
        Queue::assertPushed(WhatsAppSendOtp::class, 1);
    }

    public function test_expired_queue_job_is_never_sent(): void
    {
        Queue::fake();
        $this->passBot();
        $this->freezeTime();
        $id = $this->requestCode()->assertAccepted()->json('challenge_id');
        $this->travel(31)->seconds();
        (new WhatsAppSendOtp($id))->handle(app(OtpService::class));
        $this->getJson('/api/v1/auth/whatsapp/challenges/'.$id)->assertOk()->assertJson(['delivery_status' => 'failed', 'state' => 'cancelled']);
        $this->assertSame([], MockTransport::messages('+6281234567890'));
    }

    public function test_status_expires_job_when_workers_are_offline(): void
    {
        Queue::fake();
        $this->passBot();
        $this->freezeTime();
        $id = $this->requestCode()->assertAccepted()->json('challenge_id');
        $this->travel(31)->seconds();
        $this->getJson('/api/v1/auth/whatsapp/challenges/'.$id)->assertOk()->assertJson(['delivery_status' => 'failed']);
        $this->assertNull(OtpChallenge::findOrFail($id)->verifier);
    }

    public function test_status_does_not_expose_another_sessions_request(): void
    {
        Queue::fake();
        $this->passBot();
        $id = $this->requestCode()->assertAccepted()->json('challenge_id');
        $this->withSession(['whatsapp_otp_challenges' => []])->getJson('/api/v1/auth/whatsapp/challenges/'.$id)->assertNotFound();
    }

    public function test_resend_requires_challenge_and_supersedes_unsent_job(): void
    {
        Queue::fake();
        $this->passBot();
        $this->freezeTime();
        $first = $this->requestCode()->assertAccepted()->json('challenge_id');
        $this->postJson('/api/v1/auth/whatsapp/resend-otp', ['challenge_id' => $first])->assertUnprocessable();
        $this->travel(30)->seconds();
        $this->passBot('otp_resend');
        $second = $this->postJson('/api/v1/auth/whatsapp/resend-otp', ['challenge_id' => $first, 'challenge_token' => 'resend-token'])->assertAccepted()->json('challenge_id');
        (new WhatsAppSendOtp($first))->handle(app(OtpService::class));
        $this->assertSame([], MockTransport::messages('+6281234567890'));
        (new WhatsAppSendOtp($second))->handle(app(OtpService::class));
        $this->assertCount(1, MockTransport::messages('+6281234567890'));
    }

    public function test_pending_queue_limit_rejects_extra_requests(): void
    {
        Queue::fake();
        $this->passBot();
        config(['whatsapp_otp.queue.max_pending' => 1]);
        $this->requestCode()->assertAccepted();
        $this->requestCode('token-two', '081298765432')->assertStatus(503)->assertJson(['code' => 'QUEUE_FULL']);
        Queue::assertPushed(WhatsAppSendOtp::class, 1);
    }

    public function test_queue_connection_must_be_redis(): void
    {
        Queue::fake();
        $this->passBot();
        config(['whatsapp_otp.queue.connection' => 'sync']);
        $this->requestCode()->assertStatus(503)->assertJson(['code' => 'QUEUE_UNAVAILABLE']);
        Queue::assertNothingPushed();
    }

    public function test_uncertain_delivery_is_not_retried(): void
    {
        Queue::fake();
        $this->passBot();
        $transport = \Mockery::mock(OtpTransport::class);
        $transport->shouldReceive('name')->andReturn('mock');
        $transport->shouldReceive('senderRef')->andReturn('mock');
        $transport->shouldReceive('send')->once()->andReturn(DeliveryResult::unknown('TIMEOUT'));
        app()->instance(OtpTransport::class, $transport);
        $id = $this->requestCode()->assertAccepted()->json('challenge_id');
        $job = new WhatsAppSendOtp($id);
        $job->handle(app(OtpService::class));
        $job->handle(app(OtpService::class));
        $this->getJson('/api/v1/auth/whatsapp/challenges/'.$id)->assertOk()->assertJson(['delivery_status' => 'unknown']);
    }

    public function test_verified_token_without_idempotency_key_cannot_be_reused(): void
    {
        Queue::fake();
        $this->passBot();
        $this->requestCode()->assertAccepted();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->requestCode()->assertUnprocessable()->assertJson(['code' => 'BOT_CHECK_FAILED']);
        Queue::assertPushed(WhatsAppSendOtp::class, 1);
    }

    public function test_null_idempotency_key_does_not_allow_token_reuse(): void
    {
        Queue::fake();
        $this->passBot();
        $body = ['phone' => '081234567890', 'challenge_token' => 'valid-token', 'idempotency_key' => null];
        $this->postJson('/api/v1/auth/whatsapp/request-otp', $body)->assertAccepted();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->postJson('/api/v1/auth/whatsapp/request-otp', $body)->assertUnprocessable()->assertJson(['code' => 'BOT_CHECK_FAILED']);
        Queue::assertPushed(WhatsAppSendOtp::class, 1);
    }

    public function test_busy_sender_defers_without_retaining_a_code(): void
    {
        Queue::fake();
        $this->passBot();
        $transport = \Mockery::mock(OtpTransport::class);
        $transport->shouldReceive('name')->andReturn('mock');
        $transport->shouldReceive('send')->once()->andReturn(DeliveryResult::failed('SENDER_BUSY'));
        app()->instance(OtpTransport::class, $transport);
        $id = $this->requestCode()->assertAccepted()->json('challenge_id');
        $this->assertFalse(app(OtpService::class)->deliverQueued($id));
        $this->assertNull(OtpChallenge::findOrFail($id)->verifier);
        $this->getJson('/api/v1/auth/whatsapp/challenges/'.$id)->assertOk()->assertJson(['delivery_status' => 'queued', 'expires_at' => null]);
        $this->postJson('/api/v1/auth/whatsapp/verify-otp', ['challenge_id' => $id, 'code' => '1234'])->assertConflict()->assertJson(['code' => 'OTP_NOT_SENT']);
    }

    public function test_dispatch_failure_cancels_challenge_without_code(): void
    {
        $this->passBot();
        Queue::shouldReceive('connection')->with('redis')->andThrow(new \RuntimeException('Redis unavailable'));
        $this->requestCode()->assertStatus(503)->assertJson(['code' => 'QUEUE_UNAVAILABLE']);
        $challenge = OtpChallenge::firstOrFail();
        $this->assertSame('cancelled', $challenge->state);
        $this->assertNull($challenge->verifier);
        $this->assertSame([], MockTransport::messages('+6281234567890'));
    }

    public function test_queued_requests_consume_the_system_rate_limit(): void
    {
        Queue::fake();
        $this->passBot();
        config(['whatsapp_otp.limits.send_system' => 1]);
        $this->requestCode()->assertAccepted();
        $this->requestCode('token-two', '081298765432')->assertStatus(429)->assertJson(['code' => 'RATE_LIMITED']);
        Queue::assertPushed(WhatsAppSendOtp::class, 1);
    }

    public function test_disabling_bot_verification_is_refused_in_production(): void
    {
        Queue::fake();
        config(['whatsapp_otp.turnstile.enabled' => false, 'whatsapp_otp.transport' => 'openwa']);
        $this->app->instance('env', 'production');
        $this->getJson('/api/v1/auth/whatsapp/challenge-config')->assertStatus(503)->assertJson(['code' => 'BOT_CHECK_UNAVAILABLE']);
        Queue::assertNothingPushed();
    }

    public function test_sender_interval_and_lock_limit_outbound_calls(): void
    {
        config(['whatsapp_otp.sender_limits.enabled' => true, 'whatsapp_otp.sender_limits.min_interval_seconds' => 3]);
        $this->freezeTime();
        Http::fake(['http://gateway.test/api/sessions/sender/messages/send-text' => Http::response(['messageId' => 'one'], 201)]);
        $transport = new OpenWaTransport(['base_url' => 'http://gateway.test', 'api_key' => 'fake', 'session_id' => 'sender']);
        $this->assertSame('submitted', $transport->send('+6281234567890', 'test', 'one')->status);
        $this->assertSame('SENDER_BUSY', $transport->send('+6281234567890', 'test', 'two')->errorCode);
        $this->travel(4)->seconds();
        $key = 'wa-otp:sender:'.hash('sha256', 'http://gateway.test|sender').':lock';
        $lock = Cache::lock($key, 120);
        $lock->get();
        $this->assertSame('SENDER_BUSY', $transport->send('+6281234567890', 'test', 'three')->errorCode);
        $lock->release();
        $this->assertSame('submitted', $transport->send('+6281234567890', 'test', 'four')->status);
        Http::assertSentCount(2);
    }
}
