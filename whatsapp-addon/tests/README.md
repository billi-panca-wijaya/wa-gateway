# Queued login regression tests

Run this suite against a **disposable Laravel headless starter fixture**, with dependencies installed, the documented User/session contract, add-on provider and exception renderer registered, and the three add-on migrations available. Use SQLite `:memory:`, array cache/session, and `APP_ENV=testing` in the fixture PHPUnit configuration. Never target a production database.

The bootstrap maps application classes to this repository's add-on source. Tests register the add-on routes, fake outbound HTTP and the Redis queue, and execute jobs explicitly. No WhatsApp messages are sent. The suite covers token verification/replay, idempotency, queue expiry/admission, delivery ambiguity, resend, OTP login, and sender locks. It does **not** replace a real Redis concurrency/load test or real Turnstile/gateway validation.

PowerShell (run from repository root; replace the fixture path):

```powershell
$env:WA_OTP_TEST_HOST = 'C:/path/to/laravel-headless-starter/api'
php "$env:WA_OTP_TEST_HOST/vendor/phpunit/phpunit/phpunit" -c "$env:WA_OTP_TEST_HOST/phpunit.xml" --bootstrap "$PWD/whatsapp-addon/tests/bootstrap.php" "$PWD/whatsapp-addon/tests/Feature" --do-not-cache-result
```

If copying this suite into the existing starter instead, use its `App\Models\OtpChallenge` import. The canonical add-on suite uses `WhatsAppOtpChallenge`.
