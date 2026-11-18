<?php

namespace App\Domain\Generation\Enums;

use App\Support\Enums\Concerns\Arrayable;

enum ImageQuality: string
{
    use Arrayable;

    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
