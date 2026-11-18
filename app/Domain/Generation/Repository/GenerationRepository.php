<?php

declare(strict_types=1);

namespace App\Domain\Generation\Repository;

use App\Domain\Generation\Dto\ProviderGeneration;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\TokenType;
use App\Domain\Generation\Models\Generation;
use App\Domain\User\Models\User;
use App\Support\Repositories;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @extends Repositories<Generation>
 */
class GenerationRepository extends Repositories
{
    /**
     * A user's history, newest first. Always scoped to that user.
     *
     * @return LengthAwarePaginator<int, Generation>
     */
    public function paginateForUser(User $user, int $perPage): LengthAwarePaginator
    {
        return $user->generations()->latest()->latest('id')->paginate($perPage);
    }

    /** The generation a form submission already created, if any. */
    public function findByClientRequestId(User $user, string $clientRequestId): ?Generation
    {
        return $this->query()
            ->where('user_id', $user->id)
            ->where('client_request_id', $clientRequestId)
            ->first();
    }

    /**
     * Our id travels in metadata.generation_id, so this also finds the row when a
     * webhook beats the POST response that tells us Pixelforge's id.
     */
    public function findForSnapshot(ProviderGeneration $snapshot): ?Generation
    {
        if ($snapshot->localGenerationId !== null) {
            $generation = $this->find($snapshot->localGenerationId);

            if ($generation !== null) {
                return $generation;
            }
        }

        return $this->query()->where('provider_generation_id', $snapshot->id)->first();
    }

    /**
     * Saves a new generation with its pre-generated id (which is already part of
     * the provider request body).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createWithId(array $attributes): Generation
    {
        $generation = new Generation;
        $generation->forceFill($attributes)->save();

        return $generation;
    }

    /** Generations created since a moment, by one user or by everyone. */
    public function countCreatedSince(CarbonImmutable $since, ?User $user = null): int
    {
        return $this->query()
            ->when($user !== null, fn ($query) => $query->where('user_id', $user?->id))
            ->where('created_at', '>=', $since)
            ->count();
    }

    public function countSubmissionAttempt(Generation $generation): void
    {
        $generation->increment('submission_attempts');
    }

    /**
     * Notes a failed POST on a generation that is still pending (a webhook may
     * have advanced it in the meantime; then nothing is written).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateIfPending(Generation $generation, array $attributes): void
    {
        $this->query()
            ->whereKey($generation->id)
            ->where('status', GenerationStatus::PENDING)
            ->update($attributes);
    }

    /**
     * Accepted but unfinished generations nobody has heard about for a while.
     *
     * @return Collection<int, Generation>
     */
    public function quietInFlight(CarbonImmutable $quietSince, CarbonImmutable $createdAfter, int $limit): Collection
    {
        return $this->query()
            ->whereIn('status', [GenerationStatus::QUEUED, GenerationStatus::PROCESSING])
            ->where('updated_at', '<', $quietSince)
            ->where('created_at', '>', $createdAfter)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id']);
    }

    /**
     * Pending generations with no submission activity for a while.
     *
     * @return Collection<int, Generation>
     */
    public function stalePending(CarbonImmutable $quietSince, int $limit): Collection
    {
        return $this->query()
            ->where('status', GenerationStatus::PENDING)
            ->where('updated_at', '<', $quietSince)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Succeeded generations grouped by the UTC day Pixelforge completed them (its
     * billing day) and by user. Postgres sums the NUMERIC columns exactly and
     * returns them as strings.
     *
     * Rows: day, user_id, name, email, generations, provider_cost, charged and one
     * column per token type.
     *
     * @return array<int, stdClass>
     */
    public function billedTotalsByDayAndUser(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = DB::table('generations')
            ->leftJoin('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::SUCCEEDED->value)
            // Timestamps are stored in UTC (app timezone), so these are UTC day boundaries.
            ->where('generations.completed_at', '>=', $start->startOfDay())
            ->where('generations.completed_at', '<', $end->addDay()->startOfDay())
            ->selectRaw("to_char(generations.completed_at, 'YYYY-MM-DD') as day")
            ->addSelect('generations.user_id', 'users.name', 'users.email')
            ->selectRaw('count(*) as generations')
            ->selectRaw('sum(generations.actual_provider_cost_usd) as provider_cost')
            ->selectRaw('sum(generations.actual_customer_cost_usd) as charged');

        foreach (TokenType::cases() as $type) {
            $query->selectRaw("sum((generations.usage->>'{$type->value}')::bigint) as {$type->value}");
        }

        return $query->groupBy('day', 'generations.user_id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get()
            ->all();
    }

    protected function getModel(): Generation
    {
        return new Generation;
    }
}
