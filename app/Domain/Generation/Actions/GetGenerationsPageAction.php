<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Actions\GetCreditSummaryAction;
use App\Domain\Generation\Enums\ImageQuality;
use App\Domain\Generation\Enums\ImageSize;
use App\Domain\Generation\Repository\GenerationRepository;
use App\Domain\Generation\Requests\StoreGenerationRequest;
use App\Domain\Generation\Resources\GenerationResource;
use App\Domain\Generation\Services\PixelforgePricing;
use App\Domain\User\Models\User;
use App\Support\Actions\ActionFactory;

/**
 * Props for the generate page: the form's options and the user's own history.
 *
 * Props are closures so Inertia only evaluates what a request asks for: the
 * 3-second status poll reloads "generations" and "credits", nothing else.
 * Showing the history also repairs rows that look stuck (see
 * RepairStaleGenerationsAction).
 */
class GetGenerationsPageAction extends ActionFactory
{
    public const int PER_PAGE = 12;

    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly PixelforgePricing $pricing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        return [
            'generations' => function () use ($user) {
                $page = $this->generations->paginateForUser($user, self::PER_PAGE);

                // Rows that look stuck get a rate-limited background check.
                RepairStaleGenerationsAction::run($page->items());

                return GenerationResource::collection($page);
            },
            'credits' => fn (): mixed => GetCreditSummaryAction::run($user),
            'pricingAvailable' => fn (): bool => $this->pricing->isAvailable(),
            'options' => [
                'sizes' => ImageSize::toSelectable(),
                'qualities' => ImageQuality::toSelectable(),
                'maxPromptCharacters' => StoreGenerationRequest::MAX_PROMPT_CHARACTERS,
                'maxReferenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
        ];
    }
}
