<?php

declare(strict_types=1);

namespace App\Support\Actions;

use LogicException;

/**
 * Base class for actions: one use case, one public handle() method.
 *
 * Actions are resolved from the container, so their constructor dependencies
 * (repositories, services) are injected:
 *
 *     $generation = CreateGenerationAction::run($user, $dto);
 */
abstract class ActionFactory
{
    public static function make(): static
    {
        return app(static::class);
    }

    public static function run(mixed ...$args): mixed
    {
        $action = static::make();

        if (! method_exists($action, 'handle')) {
            throw new LogicException(static::class.' must define a handle() method.');
        }

        return $action->handle(...$args);
    }
}
