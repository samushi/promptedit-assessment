<?php

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Jobs\ProcessPixelforgeEventJob;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Models\PixelforgeWebhookEvent;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pixelforge;

describe('ingestion', function () {
    beforeEach(fn () => Queue::fake());

    it('stores a correctly signed event once and queues its processing', function () {
        $generation = Generation::factory()->queued('gen_A')->create();
        $event = Pixelforge::event('generation.processing', Pixelforge::generationFor($generation, 'processing'));

        postPixelforgeWebhook($event)->assertNoContent();

        $stored = PixelforgeWebhookEvent::sole();

        expect($stored->event_id)->toBe($event['id'])
            ->and($stored->type)->toBe('generation.processing')
            ->and($stored->provider_generation_id)->toBe('gen_A')
            ->and($stored->processed_at)->toBeNull();

        Queue::assertPushed(ProcessPixelforgeEventJob::class, fn (ProcessPixelforgeEventJob $job) => $job->eventId === $event['id']);
    });

    it('acknowledges a redelivered event without storing or queueing it again', function () {
        $generation = Generation::factory()->queued('gen_A')->create();
        $event = Pixelforge::event('generation.succeeded', Pixelforge::generationFor($generation, 'succeeded'));

        postPixelforgeWebhook($event)->assertNoContent();
        // A retry is signed again, with a fresh timestamp.
        postPixelforgeWebhook($event, time() + 10)->assertNoContent();

        expect(PixelforgeWebhookEvent::count())->toBe(1);
        Queue::assertPushed(ProcessPixelforgeEventJob::class, 1);
    });

    it('rejects deliveries that fail verification, storing nothing', function (Closure $deliver) {
        $deliver()->assertStatus(400)->assertJson(['error' => 'invalid_signature']);

        expect(PixelforgeWebhookEvent::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'signed with another secret' => fn () => postPixelforgeWebhook(
            Pixelforge::event('generation.succeeded', Pixelforge::generation('succeeded')),
            secret: 'whsec_someone_else',
        ),
        'timestamp older than 5 minutes' => fn () => postPixelforgeWebhook(
            Pixelforge::event('generation.succeeded', Pixelforge::generation('succeeded')),
            timestamp: time() - 301,
        ),
        'body changed after signing' => function () {
            [$body, $headers] = Pixelforge::signedDelivery(Pixelforge::event('generation.succeeded', Pixelforge::generation('succeeded')));

            return postRawWebhook(str_replace('"succeeded"', '"failed"', $body), $headers);
        },
        'malformed signature header' => function () {
            [$body, $headers] = Pixelforge::signedDelivery(Pixelforge::event('ping', []));

            return postRawWebhook($body, ['Pixelforge-Signature' => 'v1=deadbeef'] + $headers);
        },
        'missing signature header' => fn () => postRawWebhook(
            (string) json_encode(Pixelforge::event('ping', [])),
            ['Content-Type' => 'application/json'],
        ),
    ]);

    it('needs no CSRF token or session, only the signature', function () {
        $response = postPixelforgeWebhook(Pixelforge::event('ping', []));

        $response->assertNoContent();
        expect($response->headers->getCookies())->toBeEmpty();
    });

    it('acknowledges a ping without doing any work', function () {
        postPixelforgeWebhook(['id' => 'evt_PING', 'object' => 'event', 'type' => 'ping', 'created_at' => now()->toIso8601ZuluString()])
            ->assertNoContent();

        expect(PixelforgeWebhookEvent::sole()->processed_at)->not->toBeNull();
        Queue::assertNothingPushed();
    });

    it('rejects a signed body that is not an event', function () {
        postPixelforgeWebhook(['hello' => 'world'])->assertStatus(400);

        expect(PixelforgeWebhookEvent::count())->toBe(0);
    });
});

describe('processing', function () {
    it('bills a success exactly once, even when the event is delivered twice', function () {
        $generation = Generation::factory()->processing('gen_A')->create();
        $event = Pixelforge::event('generation.succeeded', Pixelforge::generationFor($generation, 'succeeded'));

        postPixelforgeWebhook($event)->assertNoContent();
        $settledAt = $generation->refresh()->updated_at;

        $this->travel(5)->seconds();
        postPixelforgeWebhook($event)->assertNoContent();

        $generation->refresh();

        expect(PixelforgeWebhookEvent::count())->toBe(1)
            ->and($generation->status)->toBe(GenerationStatus::SUCCEEDED)
            ->and(Money::toStorage($generation->actual_provider_cost_usd))->toBe('0.305510000000')
            ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.381887500000')
            ->and($generation->usage)->toBe(Pixelforge::USAGE)
            ->and($generation->image_url)->toStartWith('https://pixelforge.promptedit.dev/images/')
            ->and($generation->image_url_expires_at?->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
            ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
            ->and($generation->updated_at->equalTo($settledAt))->toBeTrue();
    });

    it('never moves a generation backwards when events arrive out of order', function () {
        $generation = Generation::factory()->queued('gen_A')->create();

        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', Pixelforge::generationFor($generation, 'succeeded')));
        postPixelforgeWebhook(Pixelforge::event('generation.processing', Pixelforge::generationFor($generation, 'processing')));
        postPixelforgeWebhook(Pixelforge::event('generation.processing', Pixelforge::generationFor($generation, 'queued')));

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
            ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.381887500000')
            ->and($generation->image_url)->not->toBeNull()
            ->and(PixelforgeWebhookEvent::whereNull('processed_at')->count())->toBe(0);
    });

    it('records an accepted-then-failed generation at exactly $0', function () {
        $generation = Generation::factory()->processing('gen_A')->create();

        postPixelforgeWebhook(Pixelforge::event('generation.failed', Pixelforge::generationFor($generation, 'failed')));

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::FAILED)
            ->and($generation->error_code)->toBe('internal_error')
            ->and($generation->error_message)->toContain('You were not charged')
            ->and(Money::toStorage($generation->actual_provider_cost_usd))->toBe('0.000000000000')
            ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.000000000000')
            ->and($generation->usage)->toBeNull();
    });

    it('finds the generation through metadata when the webhook beats the POST response', function () {
        $generation = Generation::factory()->create();

        // Pixelforge's "processing" webhook lands while our POST is still in flight;
        // the POST then returns the older "queued" snapshot.
        Http::fake(['pixelforge.test/v1/generations' => function () use ($generation) {
            postPixelforgeWebhook(Pixelforge::event('generation.processing', Pixelforge::generationFor($generation, 'processing', ['id' => 'gen_EARLY'])));

            return Http::response(Pixelforge::generationFor($generation, 'queued', ['id' => 'gen_EARLY']), 202);
        }]);

        runSubmitJob($generation);

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::PROCESSING)
            ->and($generation->provider_generation_id)->toBe('gen_EARLY')
            ->and($generation->rates)->toBe(Pixelforge::RATES)
            ->and($generation->started_at?->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z');
    });

    it('bills at the rates snapshotted on the generation, not current pricing', function () {
        $generation = Generation::factory()->processing('gen_A')->create();
        // Pixelforge raises its prices after the generation was created...
        Http::fake(['pixelforge.test/v1/pricing' => Http::response(array_replace_recursive(Pixelforge::pricing(), [
            'rates' => ['output_tokens' => '80.00'],
        ]))]);

        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', Pixelforge::generationFor($generation, 'succeeded')));

        // ...but this generation is still billed at its own 40.00 output rate.
        expect(Money::toStorage($generation->refresh()->actual_provider_cost_usd))->toBe('0.305510000000');
        Http::assertNothingSent();
    });

    it('keeps the first final status if Pixelforge ever contradicts itself', function () {
        Log::spy();
        $generation = Generation::factory()->processing('gen_A')->create();

        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', Pixelforge::generationFor($generation, 'succeeded')));
        postPixelforgeWebhook(Pixelforge::event('generation.failed', Pixelforge::generationFor($generation, 'failed')));

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
            ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.381887500000');

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'different final status'))->once();
    });

    it('refuses an event that claims our generation but carries another Pixelforge id', function () {
        $generation = Generation::factory()->queued('gen_A')->create();

        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', Pixelforge::generationFor($generation, 'succeeded', ['id' => 'gen_OTHER'])))
            ->assertNoContent();

        expect($generation->refresh()->status)->toBe(GenerationStatus::QUEUED)
            ->and(PixelforgeWebhookEvent::sole()->last_error)->toContain('gen_OTHER');
    });

    it('closes events for generations it does not know', function () {
        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', Pixelforge::generation('succeeded', ['id' => 'gen_UNKNOWN'])))
            ->assertNoContent();

        $event = PixelforgeWebhookEvent::sole();

        expect($event->processed_at)->not->toBeNull()
            ->and($event->last_error)->toContain('gen_UNKNOWN');
    });

    it('ignores a metadata id that is not a UUID instead of crashing the lookup', function () {
        $generation = Generation::factory()->queued('gen_A')->create();

        postPixelforgeWebhook(Pixelforge::event('generation.processing', Pixelforge::generationFor($generation, 'processing', [
            'metadata' => ['generation_id' => "1' OR '1'='1"],
        ])))->assertNoContent();

        // Still matched through the provider id.
        expect($generation->refresh()->status)->toBe(GenerationStatus::PROCESSING);
    });

    it('lets Pixelforge evidence override a submission we had given up on', function () {
        $generation = Generation::factory()->create([
            'status' => GenerationStatus::REJECTED,
            'error_code' => 'overloaded',
            'actual_provider_cost_usd' => '0',
            'actual_customer_cost_usd' => '0',
        ]);

        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', Pixelforge::generationFor($generation, 'succeeded', ['id' => 'gen_LATE'])));

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
            ->and($generation->provider_generation_id)->toBe('gen_LATE')
            ->and(Money::toStorage($generation->actual_customer_cost_usd))->toBe('0.381887500000')
            ->and($generation->error_code)->toBeNull();
    });
});
