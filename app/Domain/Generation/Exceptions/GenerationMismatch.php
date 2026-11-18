<?php

namespace App\Domain\Generation\Exceptions;

use RuntimeException;

/**
 * A snapshot claims to belong to a local generation that is already linked to a
 * different Pixelforge generation. We refuse to apply it rather than guess.
 */
final class GenerationMismatch extends RuntimeException {}
