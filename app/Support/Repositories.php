<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for a domain's data access. Actions talk to repositories, not to
 * Eloquent query builders, so every query a domain runs lives in one place.
 *
 * @template TModel of Model
 */
abstract class Repositories
{
    /**
     * @return TModel
     */
    abstract protected function getModel(): Model;

    /**
     * @return TModel|null
     */
    public function find(int|string $id): ?Model
    {
        return $this->query()->find($id);
    }

    /**
     * Re-reads the row with SELECT ... FOR UPDATE. Must run inside a transaction:
     * other writers of the same row wait until it commits.
     *
     * @return TModel
     */
    public function lockForUpdate(int|string $id): Model
    {
        return $this->query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * INSERT ... ON CONFLICT DO NOTHING. Returns how many rows were inserted, so a
     * unique key can make an operation idempotent without aborting the transaction.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        return $this->query()->insertOrIgnore($values);
    }

    /**
     * @return Builder<TModel>
     */
    protected function query(): Builder
    {
        return $this->getModel()->newQuery();
    }
}
