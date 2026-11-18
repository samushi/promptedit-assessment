<?php

declare(strict_types=1);

namespace App\Domain\Generation\Exceptions;

use RuntimeException;

/**
 * A webhook for a generation we have no record of (e.g. created with the same
 * API key outside this app). There is nothing to update.
 */
final class UnknownGeneration extends RuntimeException {}
