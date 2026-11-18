<?php

namespace App\Domain\Generation\Exceptions;

use UnexpectedValueException;

/**
 * Pixelforge sent data that doesn't match its documented shape.
 */
final class InvalidPixelforgePayload extends UnexpectedValueException {}
