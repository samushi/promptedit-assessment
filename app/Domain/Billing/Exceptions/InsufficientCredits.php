<?php

namespace App\Domain\Billing\Exceptions;

use Brick\Math\BigDecimal;
use RuntimeException;

final class InsufficientCredits extends RuntimeException
{
    public function __construct(
        public readonly BigDecimal $required,
        public readonly BigDecimal $available,
    ) {
        parent::__construct('Not enough credits for this generation.');
    }
}
