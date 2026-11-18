<?php

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\User\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Pixelforge;

/**
 * A succeeded generation billed like the docs' example: 0.30551 provider, 0.3818875 user.
 */
function billedGeneration(User $user, string $createdAt, string $completedAt): Generation
{
    return Generation::factory()->for($user)->processing()->create([
        'status' => GenerationStatus::SUCCEEDED,
        'usage' => Pixelforge::USAGE,
        'actual_provider_cost_usd' => BigDecimal::of('0.30551'),
        'actual_customer_cost_usd' => BigDecimal::of('0.3818875'),
        'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
        'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
    ]);
}

/**
 * @param  array<string, array{0: int, 1: string}>  $days  date => [generations, cost_usd]
 */
function fakeUsage(array $days): void
{
    $rows = [];
    $total = ['generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'];

    foreach (CarbonImmutable::parse('2026-11-01')->toPeriod('2026-11-05') as $date) {
        [$generations, $cost] = $days[$date->toDateString()] ?? [0, '0.000000000'];
        $rows[] = [
            'date' => $date->toDateString(),
            'generations' => $generations,
            'input_text_tokens' => 12 * $generations,
            'input_image_tokens' => 765 * $generations,
            'output_tokens' => 6450 * $generations,
            'reasoning_tokens' => 3184 * $generations,
            'cost_usd' => $cost,
        ];
        $total['generations'] += $generations;
    }

    $total['cost_usd'] = collect($days)->reduce(fn (BigDecimal $sum, array $day) => $sum->plus($day[1]), BigDecimal::zero())->toScale(9)->toString();
    $total['input_text_tokens'] = 12 * $total['generations'];
    $total['input_image_tokens'] = 765 * $total['generations'];
    $total['output_tokens'] = 6450 * $total['generations'];
    $total['reasoning_tokens'] = 3184 * $total['generations'];

    Http::fake(['pixelforge.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-05',
        'data' => $rows,
        'total' => $total,
    ])]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'UTC'));
    $this->admin = User::factory()->create(['name' => 'Olive Operator']);
    $this->admin->forceFill(['is_admin' => true])->save();
});

it('is only for operators', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
});

it('reports charges, Pixelforge cost and profit per UTC completion day and user, matching /v1/usage exactly', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    billedGeneration($alice, '2026-11-03 10:00:00', '2026-11-03 10:00:20');
    // Submitted on the 3rd, completed after midnight UTC: billed on the 4th.
    billedGeneration($alice, '2026-11-03 23:59:50', '2026-11-04 00:00:10');
    billedGeneration($bob, '2026-11-04 12:00:00', '2026-11-04 12:00:25');
    // Not billed: failed, and still running.
    Generation::factory()->for($bob)->processing()->create([
        'status' => GenerationStatus::FAILED,
        'actual_provider_cost_usd' => BigDecimal::zero(),
        'actual_customer_cost_usd' => BigDecimal::zero(),
        'completed_at' => CarbonImmutable::parse('2026-11-04 13:00:00', 'UTC'),
    ]);
    Generation::factory()->for($bob)->processing()->create();

    fakeUsage(['2026-11-03' => [1, '0.305510000'], '2026-11-04' => [2, '0.611020000']]);

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-05']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('report.pixelforgeError', null)
            ->has('report.days', 5)
            ->where('report.days.1.date', '2026-11-04')
            ->where('report.days.1.generations', 2)
            ->where('report.days.1.charged', '0.763775')
            ->where('report.days.1.provider_cost', '0.61102')
            ->where('report.days.1.profit', '0.152755')
            ->where('report.days.1.pixelforge.cost', '0.61102')
            ->where('report.days.1.pixelforge.difference', '0')
            ->where('report.days.1.pixelforge.matches', true)
            ->where('report.days.1.pixelforge.tokens_match', true)
            ->has('report.days.1.users', 2)
            ->where('report.days.1.users.0.user.name', 'Alice')
            ->where('report.days.1.users.0.charged', '0.3818875')
            ->where('report.days.2.date', '2026-11-03')
            ->where('report.days.2.generations', 1)
            ->where('report.days.2.pixelforge.matches', true)
            ->where('report.totals.generations', 3)
            ->where('report.totals.provider_cost', '0.91653')
            ->where('report.totals.charged', '1.1456625')
            ->where('report.totals.pixelforge.matches', true)
            ->where('report.users.0.user.name', 'Alice')
            ->where('report.users.0.generations', 2)
            ->where('report.users.1.user.name', 'Bob')
            ->where('report.users.1.charged', '0.3818875'));

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://pixelforge.test/v1/usage')
        && $request['start_date'] === '2026-11-01'
        && $request['end_date'] === '2026-11-05'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
});

it('flags a day where our records and Pixelforge disagree, to the fraction of a cent', function () {
    billedGeneration(User::factory()->create(), '2026-11-04 12:00:00', '2026-11-04 12:00:25');
    fakeUsage(['2026-11-04' => [1, '0.305510001']]);

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.days.1.pixelforge.matches', false)
            ->where('report.days.1.pixelforge.difference', '-0.000000001')
            ->where('report.totals.pixelforge.matches', false));
});

it('still shows our numbers when Pixelforge usage is unavailable', function () {
    billedGeneration(User::factory()->create(), '2026-11-04 12:00:00', '2026-11-04 12:00:25');
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::error('overloaded'), 503)]);

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-05']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.days.1.provider_cost', '0.30551')
            ->where('report.days.1.pixelforge', null)
            ->where('report.pixelforgeError', fn (string $error) => str_contains($error, 'Could not load Pixelforge usage')));
});

it('defaults to the last 14 UTC days', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::error('overloaded'), 503)]);

    $this->actingAs($this->admin)
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.start', '2026-10-23')
            ->where('report.end', '2026-11-05')
            ->has('report.days', 14));
});

it('rejects ranges Pixelforge cannot report', function (array $query) {
    Http::fake();

    $this->actingAs($this->admin)->get(route('admin.report', $query))->assertSessionHasErrors('start');
    Http::assertNothingSent();
})->with([
    'more than 92 days' => [['start' => '2026-08-01', 'end' => '2026-11-05']],
    'start after end' => [['start' => '2026-11-05', 'end' => '2026-11-01']],
]);

it('lets operators be granted from the command line', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('users:make-admin', ['email' => 'ops@example.com'])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeTrue();

    $this->artisan('users:make-admin', ['email' => 'ops@example.com', '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeFalse();

    $this->artisan('users:make-admin', ['email' => 'nobody@example.com'])->assertFailed();
});
