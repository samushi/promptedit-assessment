<?php

namespace App\Domain\Generation\Resources;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\PixelforgeError;
use App\Domain\Generation\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as the owner sees it. Amounts are exact decimal strings; only
 * the customer price is exposed (Pixelforge's cost is for operators).
 *
 * @property Generation $resource
 */
final class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $generation = $this->resource;
        $actualCost = $generation->actual_customer_cost_usd;

        return [
            'id' => $generation->id,
            'status' => $generation->status->value,
            'is_final' => $generation->status->isTerminal(),
            'status_note' => $this->statusNote($generation),
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'estimated_cost' => Money::toWire($generation->estimated_customer_cost_usd),
            'max_cost' => Money::toWire($generation->max_customer_cost_usd),
            'actual_cost' => $actualCost === null ? null : Money::toWire($actualCost),
            'image_url' => $generation->isImageExpired() ? null : $generation->image_url,
            'image_expired' => $generation->image_url !== null && $generation->isImageExpired(),
            'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601ZuluString(),
            'error_code' => $generation->status->isTerminal() ? $generation->error_code : null,
            'error_message' => $generation->status->isTerminal() ? $generation->error_message : null,
            'created_at' => $generation->created_at->toIso8601ZuluString(),
            'completed_at' => $generation->completed_at?->toIso8601ZuluString(),
        ];
    }

    /** Explains a submission that is still being retried. */
    private function statusNote(Generation $generation): ?string
    {
        if ($generation->status !== GenerationStatus::PENDING || $generation->error_code === null) {
            return null;
        }

        if ($generation->error_code === 'submission_unconfirmed') {
            return 'Pixelforge hasn\'t confirmed this yet. Retrying safely: you can never be charged twice.';
        }

        return PixelforgeError::tryFrom($generation->error_code)?->retryingMessage();
    }
}
