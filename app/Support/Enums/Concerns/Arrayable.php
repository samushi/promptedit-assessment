<?php

declare(strict_types=1);

namespace App\Support\Enums\Concerns;

/**
 * Helpers for backed enums that are shown in the UI.
 */
trait Arrayable
{
    /**
     * @return list<string>
     */
    public static function toArray(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Options for a select or radio group.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function toSelectable(): array
    {
        return array_map(
            fn (self $case): array => ['id' => $case->value, 'name' => $case->label()],
            self::cases(),
        );
    }
}
