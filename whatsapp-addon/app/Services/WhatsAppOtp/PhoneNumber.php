<?php

namespace App\Services\WhatsAppOtp;

/**
 * Server-side phone normalisation to E.164.
 *
 * Numbers typed with a leading "+" (or "00") are validated against the generic E.164 shape. Numbers
 * without a country prefix are interpreted with the configured default region only; region rules are
 * deliberately limited to the regions listed in REGIONS (no Indonesian rules applied to other countries).
 */
final class PhoneNumber
{
    private const REGIONS = [
        // country code, trunk prefix, national significant number pattern (mobile)
        'ID' => ['cc' => '62', 'trunk' => '0', 'nsn' => '/^8[1-9]\d{7,10}$/'],
    ];

    public static function normalize(string $input, string $defaultRegion = 'ID'): string
    {
        $raw = trim($input);
        if ($raw === '' || strlen($raw) > 32 || ! preg_match('/^[+\d\s\-().]+$/', $raw)) {
            throw OtpException::make('PHONE_INVALID', 422);
        }

        $international = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw);
        if (! $international && str_starts_with($digits, '00')) {
            $international = true;
            $digits = substr($digits, 2);
        }

        $region = self::REGIONS[strtoupper($defaultRegion)] ?? null;

        if (! $international) {
            if ($region === null) {
                throw OtpException::make('PHONE_INVALID', 422);
            }
            if (str_starts_with($digits, $region['cc'])) {
                $digits = substr($digits, strlen($region['cc']));
            } elseif ($region['trunk'] !== '' && str_starts_with($digits, $region['trunk'])) {
                $digits = substr($digits, strlen($region['trunk']));
            }
            if (! preg_match($region['nsn'], $digits)) {
                throw OtpException::make('PHONE_INVALID', 422);
            }

            return '+'.$region['cc'].$digits;
        }

        foreach (self::REGIONS as $rule) {
            if (str_starts_with($digits, $rule['cc'])) {
                if (! preg_match($rule['nsn'], substr($digits, strlen($rule['cc'])))) {
                    throw OtpException::make('PHONE_INVALID', 422);
                }

                return '+'.$digits;
            }
        }

        if (! preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            throw OtpException::make('PHONE_INVALID', 422);
        }

        return '+'.$digits;
    }

    public static function mask(string $e164): string
    {
        $len = strlen($e164);
        if ($len <= 7) {
            return str_repeat('*', $len);
        }

        return substr($e164, 0, 5).str_repeat('*', $len - 8).substr($e164, -3);
    }
}
