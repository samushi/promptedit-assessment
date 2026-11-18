<?php

namespace App\Domain\Generation\Exceptions;

use RuntimeException;

final class InvalidWebhookSignature extends RuntimeException
{
    public static function malformedHeader(): self
    {
        return new self('Malformed or missing Pixelforge-Signature header.');
    }

    public static function timestampOutsideTolerance(): self
    {
        return new self('Signature timestamp is outside the allowed tolerance.');
    }

    public static function mismatch(): self
    {
        return new self('Signature does not match the payload.');
    }
}
