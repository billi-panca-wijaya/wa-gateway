---
name: turnstile-spin
description: Set up Cloudflare Turnstile end-to-end in a project. Scan the codebase, create or link an existing widget via Cloudflare API/Dashboard, embed it where user requests need bot verification, wire canonical server-side siteverify in the backend, validate, and persist the skill. Follows developers.cloudflare.com/turnstile/spin.
references:
  - vanilla-html
  - nextjs-app
  - nextjs-pages
  - astro
  - sveltekit
  - hugo
  - laravel-php
---

# Cloudflare Turnstile Spin Skill

This skill provides an automated, standardized procedure for integrating Cloudflare Turnstile bot protection into web applications.

## Integration Overview

- **Widget Mode**: Managed (`appearance: 'execute'`)
- **Integration Principle**: Gate, Don't Replace (the user's existing handler logic remains intact; Turnstile adds validation before processing)
- **Token Lifecycle**: Single-use token. Verified server-side via Cloudflare Siteverify API.

## Configuration (.env)

Set the following variables in the host application environment:

```env
# Cloudflare Turnstile Bot Protection
WA_OTP_TURNSTILE_ENABLED=true
WA_OTP_TURNSTILE_SITE_KEY=<your-cloudflare-turnstile-site-key>
WA_OTP_TURNSTILE_SECRET_KEY=<your-cloudflare-turnstile-secret-key>
WA_OTP_TURNSTILE_HOSTNAMES=your-domain.com,login.your-domain.com,localhost,127.0.0.1
```

## Architecture & Integration Flow

### 1. Frontend Integration ("Gate, Don't Replace")
The frontend dynamically loads Turnstile and requests execution only after the user triggers a submit action (e.g. Request OTP or Resend OTP):

```ts
// 1. Fetch challenge config from backend
const { data } = await axios.get('/api/v1/auth/whatsapp/challenge-config');
if (!data.enabled) return;

// 2. Load Turnstile script explicitly if not yet loaded
// Script: https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit

// 3. Render and execute challenge
const widgetId = window.turnstile.render(containerElement, {
    sitekey: data.site_key,
    action: 'otp_request',
    execution: 'execute',
    callback: (token: string) => {
        // Send challenge_token to backend alongside request payload
        submitWithToken(token);
    },
    'error-callback': () => handleError(),
});
window.turnstile.execute(widgetId);
```

### 2. Backend Siteverify Validation Contract
The backend verifies the incoming `challenge_token` against Cloudflare's canonical verification endpoint before executing business logic:

```php
$response = Http::asForm()
    ->connectTimeout(2)
    ->timeout(5)
    ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'secret'   => config('whatsapp_otp.turnstile.secret_key'),
        'response' => $request->input('challenge_token'),
        'remoteip' => $request->ip(),
    ]);

if (! $response->successful() || ! $response->json('success')) {
    throw new OtpException('BOT_CHECK_FAILED', 'Verification failed. Please try again.', 422);
}

// Ensure action and hostname match expectations
if ($response->json('action') !== $expectedAction || ! in_array($response->json('hostname'), $allowedHosts, true)) {
    throw new OtpException('BOT_CHECK_FAILED', 'Verification failed. Please try again.', 422);
}
```

### 3. Server-side Validation Test
To verify that the Secret Key is valid without submitting a real user request, send a probe with a dummy token:

```bash
curl -s -X POST https://challenges.cloudflare.com/turnstile/v0/siteverify \
  -d "secret=<your-secret-key>&response=XXXX.DUMMY.TOKEN.XXXX"
```

Expected response for a valid secret:
```json
{"success": false, "error-codes": ["invalid-input-response"]}
```
*(If the secret is invalid, Cloudflare returns `"invalid-input-secret"`).*
