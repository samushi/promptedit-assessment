<?php

namespace App\Domain\Generation\Enums;

use App\Support\Enums\Concerns\Arrayable;

enum ImageSize: string
{
    use Arrayable;

    case SQUARE = '1024x1024';
    case LANDSCAPE = '1536x1024';
    case PORTRAIT = '1024x1536';

    public function label(): string
    {
        return match ($this) {
            self::SQUARE => 'Square',
            self::LANDSCAPE => 'Landscape',
            self::PORTRAIT => 'Portrait',
        };
    }
}
