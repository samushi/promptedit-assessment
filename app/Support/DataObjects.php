<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Base class for request DTOs: a validated payload as a typed, immutable object.
 *
 * Constructor parameters are camelCase and filled from snake_case keys:
 * `reference_images` → `$referenceImages`. Missing keys use the parameter's
 * default, or null.
 */
abstract class DataObjects
{
    public static function fromRequest(FormRequest $request): static
    {
        return static::fromArray($request->validated());
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $class = new ReflectionClass(static::class);
        $arguments = [];

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $key = Str::snake($parameter->getName());
            $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;

            $arguments[] = array_key_exists($key, $data) ? $data[$key] : $default;
        }

        return $class->newInstanceArgs($arguments);
    }

    /**
     * Public properties with snake_case keys.
     *
     * @param  list<string>  $except
     * @return array<string, mixed>
     */
    public function toArray(array $except = []): array
    {
        $properties = [];

        foreach (get_object_vars($this) as $name => $value) {
            $properties[Str::snake($name)] = $value;
        }

        return Arr::except($properties, $except);
    }
}
