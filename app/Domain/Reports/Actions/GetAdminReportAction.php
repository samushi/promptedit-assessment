<?php

declare(strict_types=1);

namespace App\Domain\Reports\Actions;

use App\Domain\Generation\Enums\TokenType;
use App\Domain\Generation\Exceptions\InvalidPixelforgePayload;
use App\Domain\Generation\Exceptions\PixelforgeException;
use App\Domain\Generation\Repository\GenerationRepository;
use App\Domain\Generation\Services\PixelforgeClient;
use App\Domain\Reports\Dto\CostTotals;
use App\Domain\Reports\Dto\ProviderUsageDay;
use App\Domain\Reports\Dto\ProviderUsageReport;
use App\Support\Actions\ActionFactory;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Operator report: what users were charged, what Pixelforge charged us and
 * the profit, per UTC day and per user, checked against GET /v1/usage.
 *
 * Like Pixelforge, a generation counts on the UTC day it *completed*, not the
 * day it was submitted. Only succeeded generations are billed. Pixelforge
 * reports per API key, so this matches exactly when this environment is the
 * only user of its key.
 */
class GetAdminReportAction extends ActionFactory
{
    /** Longest range GET /v1/usage accepts. */
    public const int MAX_DAYS = 92;

    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly PixelforgeClient $pixelforge,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->billedGenerations($start, $end);
        [$usage, $usageError] = $this->providerUsage($start, $end);

        $days = [];
        $users = [];
        $total = CostTotals::zero();

        for ($date = $end; $date->greaterThanOrEqualTo($start); $date = $date->subDay()) {
            $key = $date->toDateString();
            $dayTotal = CostTotals::zero();
            $dayUsers = [];

            foreach ($rows[$key] ?? [] as ['user' => $user, 'totals' => $totals]) {
                $dayTotal = $dayTotal->plus($totals);
                $dayUsers[] = ['user' => $user, ...$totals->toArray()];

                $userKey = (string) ($user['id'] ?? 'deleted');
                $users[$userKey] = [
                    'user' => $user,
                    'totals' => ($users[$userKey]['totals'] ?? CostTotals::zero())->plus($totals),
                ];
            }

            $total = $total->plus($dayTotal);

            $days[] = [
                'date' => $key,
                ...$dayTotal->toArray(),
                'users' => $dayUsers,
                'pixelforge' => $this->compare($dayTotal, $usage?->day($key)),
            ];
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'totals' => [...$total->toArray(), 'pixelforge' => $this->compare($total, $usage?->total)],
            'users' => array_values(array_map(
                fn (array $entry): array => ['user' => $entry['user'], ...$entry['totals']->toArray()],
                $users,
            )),
            'pixelforgeError' => $usageError,
        ];
    }

    /**
     * @return array{generations: int, cost: string, difference: string, matches: bool, tokens_match: bool}|null
     */
    private function compare(CostTotals $ours, ?ProviderUsageDay $reported): ?array
    {
        if ($reported === null) {
            return null;
        }

        // Exact: zero only if every fraction of a cent agrees.
        $difference = $ours->providerCost->minus($reported->cost);

        return [
            'generations' => $reported->generations,
            'cost' => Money::toWire($reported->cost),
            'difference' => Money::toWire($difference),
            'matches' => $difference->isZero(),
            'tokens_match' => $ours->tokens === $reported->tokens,
        ];
    }

    /**
     * Billed totals per UTC completion day and user, from GenerationRepository.
     *
     * @return array<string, list<array{user: array{id: int|null, name: string, email: string|null}, totals: CostTotals}>>
     */
    private function billedGenerations(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = [];

        foreach ($this->generations->billedTotalsByDayAndUser($start, $end) as $row) {
            $tokens = [];

            foreach (TokenType::cases() as $type) {
                $tokens[$type->value] = (int) $row->{$type->value};
            }

            $rows[(string) $row->day][] = [
                'user' => [
                    'id' => $row->user_id === null ? null : (int) $row->user_id,
                    'name' => $row->name === null ? 'Deleted user' : (string) $row->name,
                    'email' => $row->email === null ? null : (string) $row->email,
                ],
                'totals' => new CostTotals(
                    generations: (int) $row->generations,
                    providerCost: Money::of((string) $row->provider_cost),
                    charged: Money::of((string) $row->charged),
                    tokens: $tokens,
                ),
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: ProviderUsageReport|null, 1: string|null}
     */
    private function providerUsage(CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            $usage = $this->pixelforge->usage($start->toDateString(), $end->toDateString());

            return [ProviderUsageReport::fromArray($usage), null];
        } catch (PixelforgeException|InvalidPixelforgePayload $e) {
            return [null, 'Could not load Pixelforge usage: '.$e->getMessage()];
        }
    }
}
