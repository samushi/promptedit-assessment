<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for domain models with UUID (v7) primary keys. The key and the
 * timestamps can never be mass-assigned.
 */
abstract class ReadModel extends Model
{
    use HasUuids;

    /**
     * @return list<string>
     */
    public function getGuarded(): array
    {
        return array_values(array_unique([...$this->guarded, 'id', 'created_at', 'updated_at']));
    }
}
