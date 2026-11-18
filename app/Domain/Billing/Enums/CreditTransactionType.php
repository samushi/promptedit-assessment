<?php

namespace App\Domain\Billing\Enums;

enum CreditTransactionType: string
{
    /** Fake "Buy credits" top-up (no real payment). */
    case PURCHASE = 'purchase';

    /** Reserves the most a submitted generation can cost. */
    case HOLD = 'hold';

    /** Gives a finished generation's reservation back. */
    case RELEASE = 'release';

    /** The exact final price of a succeeded generation. */
    case CHARGE = 'charge';
}
