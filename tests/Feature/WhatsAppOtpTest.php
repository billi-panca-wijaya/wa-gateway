<?php

namespace BilliPancaWijaya\WhatsAppGateway\Tests\Feature;

use BilliPancaWijaya\WhatsAppGateway\Facades\WhatsAppGateway;
use BilliPancaWijaya\WhatsAppGateway\Services\Transport\MockTransport;
use BilliPancaWijaya\WhatsAppGateway\Tests\TestCase;

class WhatsAppOtpTest extends TestCase
{
    public function test_can_request_and_verify_otp(): void
    {
        $phone = '+6281234567890';
        $response = WhatsAppGateway::requestOtp($phone);

        $this->assertArrayHasKey('challenge_id', $response);
        $this->assertEquals('pending', $response['state']);

        $code = MockTransport::lastCode($phone);
        $this->assertNotNull($code);
        $this->assertEquals(4, strlen($code));

        $verify = WhatsAppGateway::verifyOtp($response['challenge_id'], $code);
        $this->assertArrayHasKey('user', $verify);
        $this->assertTrue($verify['created']);
    }

    public function test_fails_on_wrong_code(): void
    {
        $this->expectException(\BilliPancaWijaya\WhatsAppGateway\Exceptions\OtpException::class);

        $phone = '+6281234567890';
        $response = WhatsAppGateway::requestOtp($phone);

        WhatsAppGateway::verifyOtp($response['challenge_id'], '0000');
    }
}
