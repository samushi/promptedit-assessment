<?php

use App\Domain\Generation\Jobs\SubmitGenerationJob;
use App\Domain\Generation\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\Pixelforge;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against a real PostgreSQL database (see phpunit.xml) so
| NUMERIC money columns, unique constraints and row locks behave as in
| production. No test may reach the real Pixelforge API.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function fakePixelforgePricing(): void
{
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::pricing())]);
}

/**
 * Deliver a webhook exactly as Pixelforge does: raw JSON body + signature header.
 *
 * @param  array<string, mixed>  $event
 */
function postPixelforgeWebhook(array $event, ?int $timestamp = null, string $secret = Pixelforge::WEBHOOK_SECRET): TestResponse
{
    [$body, $headers] = Pixelforge::signedDelivery($event, $timestamp, $secret);

    return postRawWebhook($body, $headers);
}

/**
 * POST an exact body (no re-encoding) with the given headers.
 *
 * @param  array<string, string>  $headers
 */
function postRawWebhook(string $body, array $headers): TestResponse
{
    $server = [];

    foreach ($headers as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
    }

    return test()->call('POST', route('webhooks.pixelforge'), server: $server, content: $body);
}

/**
 * Run one attempt of the submission job, as the queue worker would.
 */
function runSubmitJob(Generation $generation, int $attempt = 1, ?SubmitGenerationJob $job = null): SubmitGenerationJob
{
    $job ??= new SubmitGenerationJob($generation->id);
    $job->withFakeQueueInteractions();
    $job->job->attempts = $attempt;

    app()->call([$job, 'handle']);

    return $job;
}
