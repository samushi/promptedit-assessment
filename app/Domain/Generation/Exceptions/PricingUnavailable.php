<?php

namespace App\Domain\Generation\Exceptions;

use RuntimeException;

/**
 * GET /v1/pricing failed and there is no recent copy to fall back on,
 * so we can't show (or hold) an estimate.
 */
final class PricingUnavailable extends RuntimeException {}
