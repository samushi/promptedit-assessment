<?php

declare(strict_types=1);

namespace App\Domain\Generation\Controllers;

use App\Domain\Generation\Actions\RecordWebhookEventAction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Pixelforge webhooks (already verified by VerifyPixelforgeSignature):
 * store once, queue, answer 2xx fast. The state change happens in
 * ProcessPixelforgeEventJob.
 */
final class PixelforgeWebhookController
{
    public function __invoke(Request $request): Response
    {
        // Dedupe on the signed body's id, not the (unsigned) Pixelforge-Event-Id header.
        // Only id and type are required here: ping payloads aren't documented.
        $event = json_decode($request->getContent(), true);
        $eventId = is_array($event) ? $this->identifier($event['id'] ?? null) : null;
        $type = is_array($event) ? $this->identifier($event['type'] ?? null) : null;

        if ($eventId === null || $type === null) {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        RecordWebhookEventAction::run(
            eventId: $eventId,
            type: $type,
            providerGenerationId: $this->identifier(data_get($event, 'data.object.id'), 'gen_'),
            rawBody: $request->getContent(),
            deliveryAttempt: ctype_digit((string) $request->header('Pixelforge-Delivery-Attempt'))
                ? (int) $request->header('Pixelforge-Delivery-Attempt')
                : null,
        );

        // Also for redeliveries: we already have the event, so Pixelforge can stop retrying.
        return response()->noContent();
    }

    private function identifier(mixed $value, string $prefix = ''): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 && str_starts_with($value, $prefix)
            ? $value
            : null;
    }
}
