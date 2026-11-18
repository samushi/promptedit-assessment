<?php

namespace App\Domain\Generation\Enums;

/**
 * The four token types Pixelforge bills, each at its own per-1M-token rate.
 * The values are Pixelforge's keys in "usage", "rates" and GET /v1/pricing.
 */
enum TokenType: string
{
    case INPUT_TEXT = 'input_text_tokens';
    case INPUT_IMAGE = 'input_image_tokens';
    case OUTPUT = 'output_tokens';
    case REASONING = 'reasoning_tokens';

    public function label(): string
    {
        return match ($this) {
            self::INPUT_TEXT => 'Prompt text',
            self::INPUT_IMAGE => 'Reference images',
            self::OUTPUT => 'Image output',
            self::REASONING => 'Reasoning',
        };
    }
}
