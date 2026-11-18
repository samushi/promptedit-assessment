<?php

use App\Domain\Generation\Exceptions\InvalidWebhookSignature;
use App\Domain\Generation\Services\PixelforgeWebhookVerifier;
use Tests\Support\Pixelforge;

const SIGNED_BODY = '{"id":"evt_1","object":"event","type":"ping","created_at":"2026-11-04T14:03:29Z","data":{}}';
const SIGNED_AT = 1_762_265_009;

function verifier(?string $secret = Pixelforge::WEBHOOK_SECRET): PixelforgeWebhookVerifier
{
    return new PixelforgeWebhookVerifier($secret, 300);
}

it('accepts a valid signature over the raw body', function () {
    verifier()->verify(SIGNED_BODY, Pixelforge::signature(SIGNED_BODY, SIGNED_AT), SIGNED_AT);
})->throwsNoExceptions();

it('accepts any of several v1 signatures (secret rotation)', function () {
    $valid = Pixelforge::signature(SIGNED_BODY, SIGNED_AT);
    $header = 't='.SIGNED_AT.',v1='.str_repeat('0', 64).','.substr($valid, strpos($valid, 'v1='));

    verifier()->verify(SIGNED_BODY, $header, SIGNED_AT);
})->throwsNoExceptions();

it('rejects a body that differs by a single byte', function () {
    verifier()->verify(SIGNED_BODY.' ', Pixelforge::signature(SIGNED_BODY, SIGNED_AT), SIGNED_AT);
})->throws(InvalidWebhookSignature::class, 'does not match');

it('rejects a signature made with another secret', function () {
    verifier()->verify(SIGNED_BODY, Pixelforge::signature(SIGNED_BODY, SIGNED_AT, 'whsec_other'), SIGNED_AT);
})->throws(InvalidWebhookSignature::class, 'does not match');

it('rejects malformed or missing headers', function (?string $header) {
    verifier()->verify(SIGNED_BODY, $header, SIGNED_AT);
})->with([
    'missing' => null,
    'empty' => '',
    'no timestamp' => 'v1='.str_repeat('a', 64),
    'no signature' => 't='.SIGNED_AT,
    'non-numeric timestamp' => 't=abc,v1='.str_repeat('a', 64),
    'short signature' => 't='.SIGNED_AT.',v1=abc',
    'unknown scheme only' => 't='.SIGNED_AT.',v0='.str_repeat('a', 64),
])->throws(InvalidWebhookSignature::class, 'Malformed');

it('rejects timestamps more than 300 seconds old or ahead', function (int $skew) {
    verifier()->verify(SIGNED_BODY, Pixelforge::signature(SIGNED_BODY, SIGNED_AT + $skew), SIGNED_AT);
})->with([-301, 301, -86_400])->throws(InvalidWebhookSignature::class, 'tolerance');

it('accepts timestamps within the tolerance', function (int $skew) {
    verifier()->verify(SIGNED_BODY, Pixelforge::signature(SIGNED_BODY, SIGNED_AT + $skew), SIGNED_AT);
})->with([-300, 0, 300])->throwsNoExceptions();

it('fails loudly when no signing secret is configured', function () {
    verifier(null)->verify(SIGNED_BODY, Pixelforge::signature(SIGNED_BODY, SIGNED_AT), SIGNED_AT);
})->throws(RuntimeException::class, 'PIXELFORGE_WEBHOOK_SECRET');
