<?php

declare(strict_types=1);

namespace App\Domain\Billing\Dto;

use App\Support\DataObjects;

/**
 * A "Buy credits" click: one of the fixed packages, and a unique reference so
 * the same click can never add credits twice.
 */
final class PurchaseCreditsDto extends DataObjects
{
    public function __construct(
        public readonly string $amount,
        public readonly string $reference,
    ) {}
}
