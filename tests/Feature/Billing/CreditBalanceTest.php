<?php

use App\Domain\Billing\Enums\CreditTransactionType;
use App\Domain\Billing\Models\CreditTransaction;
use App\Domain\Billing\Services\CreditLedger;
use App\Domain\Generation\Actions\RecoverGenerationAction;
use App\Domain\Generation\Actions\SubmitGenerationAction;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Jobs\SubmitGenerationJob;
use App\Domain\Generation\Models\Generation;
use App\Domain\User\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Pixelforge;

function topUp(User $user, string $amount): void
{
    app(CreditLedger::class)->purchase($user->id, BigDecimal::of($amount), (string) Str::uuid());
}

function balanceOf(User $user): string
{
    return Money::toWire(app(CreditLedger::class)->balance($user->id));
}

function reservedFor(User $user): string
{
    return Money::toWire(app(CreditLedger::class)->reserved($user->id));
}

/**
 * Submit the default form (45-character prompt, medium, square): at most
 * (14 x 5 + 1100 x 40 + 2000 x 12.5) / 1M x 1.25 = $0.0863375.
 */
function submitGeneration(User $user): Generation
{
    test()->actingAs($user)
        ->post(route('generations.store'), [
            'client_request_id' => (string) Str::uuid(),
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])
        ->assertSessionHasNoErrors();

    return Generation::query()->latest()->firstOrFail();
}

/**
 * @return list<string>
 */
function ledgerTypes(Generation $generation): array
{
    return CreditTransaction::query()
        ->where('generation_id', $generation->id)
        ->orderBy('id')
        ->get()
        ->map(fn (CreditTransaction $row): string => $row->type->value)
        ->all();
}

beforeEach(function () {
    fakePixelforgePricing();
    // Only the submission is faked; webhook processing runs inline.
    Queue::fake([SubmitGenerationJob::class]);
    $this->user = User::factory()->create();
});

describe('buying credits', function () {
    it('adds a purchase once, however often the click is sent', function () {
        $reference = (string) Str::uuid();

        $this->actingAs($this->user)->post(route('credits.store'), ['amount' => '10.00', 'reference' => $reference])->assertRedirect();
        $this->actingAs($this->user)->post(route('credits.store'), ['amount' => '10.00', 'reference' => $reference])->assertRedirect();

        expect(balanceOf($this->user))->toBe('10')
            ->and(CreditTransaction::count())->toBe(1);
    });

    it('only sells the fixed test packages', function (string $amount) {
        $this->actingAs($this->user)
            ->post(route('credits.store'), ['amount' => $amount, 'reference' => (string) Str::uuid()])
            ->assertSessionHasErrors('amount');

        expect(balanceOf($this->user))->toBe('0');
    })->with(['1000000.00', '-5.00', '5', 'abc']);

    it('shows the balance and the amount reserved on the generate page', function () {
        topUp($this->user, '5.00');
        submitGeneration($this->user);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn ($page) => $page
                ->where('credits.balance', '4.9136625')
                ->where('credits.reserved', '0.0863375')
                ->where('credits.packages', ['5.00', '10.00', '25.00']));
    });
});

describe('reserving', function () {
    it('holds the most a generation can cost when it is submitted', function () {
        topUp($this->user, '5.00');

        $generation = submitGeneration($this->user);

        expect(Money::toWire($generation->max_customer_cost_usd))->toBe('0.0863375')
            ->and(balanceOf($this->user))->toBe('4.9136625')
            ->and(reservedFor($this->user))->toBe('0.0863375')
            ->and(ledgerTypes($generation))->toBe(['hold']);
    });

    it('blocks a generation the balance cannot cover, without charging or queueing anything', function () {
        $this->actingAs($this->user)
            ->post(route('generations.store'), [
                'client_request_id' => (string) Str::uuid(),
                'prompt' => 'A lighthouse',
                'size' => '1024x1024',
                'quality' => 'medium',
            ])
            ->assertSessionHasErrors(['credits' => 'This could cost up to $0.086275 and your balance is $0. Buy credits to continue.']);

        expect(Generation::count())->toBe(0)
            ->and(CreditTransaction::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('counts generations still in progress against the balance', function () {
        topUp($this->user, '0.10');

        submitGeneration($this->user);

        $this->actingAs($this->user)
            ->post(route('generations.store'), [
                'client_request_id' => (string) Str::uuid(),
                'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
                'size' => '1024x1024',
                'quality' => 'medium',
            ])
            ->assertSessionHasErrors('credits');

        expect(Generation::count())->toBe(1)
            ->and(balanceOf($this->user))->toBe('0.0136625');
    });
});

describe('settling', function () {
    it('charges the exact price and gives back the rest of the hold on success', function () {
        topUp($this->user, '5.00');
        $generation = submitGeneration($this->user);

        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', Pixelforge::generationFor($generation, 'succeeded')));

        expect($generation->refresh()->status)->toBe(GenerationStatus::SUCCEEDED)
            ->and(balanceOf($this->user))->toBe('4.6181125')
            ->and(reservedFor($this->user))->toBe('0')
            ->and(ledgerTypes($generation))->toBe(['hold', 'release', 'charge'])
            ->and(Money::toWire(CreditTransaction::where('type', CreditTransactionType::CHARGE)->sole()->amount_usd))->toBe('-0.3818875');
    });

    it('returns the whole hold when a generation fails', function () {
        topUp($this->user, '5.00');
        $generation = submitGeneration($this->user);

        postPixelforgeWebhook(Pixelforge::event('generation.failed', Pixelforge::generationFor($generation, 'failed')));

        expect(balanceOf($this->user))->toBe('5')
            ->and(ledgerTypes($generation))->toBe(['hold', 'release']);
    });

    it('returns the whole hold when Pixelforge refuses the submission', function () {
        topUp($this->user, '5.00');
        $generation = submitGeneration($this->user);
        Http::fake(['pixelforge.test/v1/generations' => Http::response(Pixelforge::error('content_policy_violation'), 400)]);

        runSubmitJob($generation);

        expect($generation->refresh()->status)->toBe(GenerationStatus::REJECTED)
            ->and(balanceOf($this->user))->toBe('5')
            ->and(ledgerTypes($generation))->toBe(['hold', 'release']);
    });

    it('never charges twice: duplicate events, a replayed POST and a poll all agree', function () {
        topUp($this->user, '5.00');
        $generation = submitGeneration($this->user);
        $succeeded = Pixelforge::generationFor($generation, 'succeeded');
        $event = Pixelforge::event('generation.succeeded', $succeeded);

        postPixelforgeWebhook($event);
        postPixelforgeWebhook($event);
        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', $succeeded));

        Http::fake([
            'pixelforge.test/v1/generations/*' => Http::response($succeeded),
            'pixelforge.test/v1/generations' => Http::response($succeeded, 202, ['Idempotent-Replayed' => 'true']),
        ]);
        $this->travel(RecoverGenerationAction::SYNC_AFTER_SECONDS + 1)->seconds();
        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        expect(balanceOf($this->user))->toBe('4.6181125')
            ->and(ledgerTypes($generation))->toBe(['hold', 'release', 'charge']);
    });

    it('charges a submission we had given up on if Pixelforge proves it succeeded', function () {
        topUp($this->user, '5.00');
        $generation = submitGeneration($this->user);
        Http::fake(['pixelforge.test/v1/generations' => Http::response(Pixelforge::error('overloaded'), 503)]);
        $generation->forceFill(['submission_failures' => SubmitGenerationAction::MAX_FAILURES - 1])->save();
        runSubmitJob($generation, attempt: SubmitGenerationAction::MAX_FAILURES);

        expect($generation->refresh()->status)->toBe(GenerationStatus::REJECTED)
            ->and(balanceOf($this->user))->toBe('5');

        postPixelforgeWebhook(Pixelforge::event('generation.succeeded', Pixelforge::generationFor($generation, 'succeeded')));

        expect($generation->refresh()->status)->toBe(GenerationStatus::SUCCEEDED)
            ->and(balanceOf($this->user))->toBe('4.6181125')
            ->and(ledgerTypes($generation))->toBe(['hold', 'release', 'charge']);
    });
});
