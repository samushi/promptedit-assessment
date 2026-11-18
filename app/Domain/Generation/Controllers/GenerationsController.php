<?php

declare(strict_types=1);

namespace App\Domain\Generation\Controllers;

use App\Domain\Billing\Exceptions\InsufficientCredits;
use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\Actions\EstimateGenerationCostAction;
use App\Domain\Generation\Actions\GetGenerationsPageAction;
use App\Domain\Generation\Dto\CreateGenerationDto;
use App\Domain\Generation\Exceptions\GenerationLimitReached;
use App\Domain\Generation\Exceptions\PricingUnavailable;
use App\Domain\Generation\Requests\EstimateGenerationRequest;
use App\Domain\Generation\Requests\StoreGenerationRequest;
use App\Domain\User\Models\User;
use App\Support\InertiaControllers;
use App\Support\Money;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class GenerationsController extends InertiaControllers
{
    /** The generate form and the user's own history (only theirs). */
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('generations/Index', GetGenerationsPageAction::run($user));
    }

    public function store(StoreGenerationRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        try {
            CreateGenerationAction::run($user, CreateGenerationDto::fromRequest($request));
        } catch (PricingUnavailable) {
            throw ValidationException::withMessages([
                'pricing' => 'Pixelforge pricing is temporarily unavailable, so we can\'t price this request. Please try again shortly.',
            ]);
        } catch (GenerationLimitReached $e) {
            throw ValidationException::withMessages(['limit' => $e->getMessage()]);
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages([
                'credits' => sprintf(
                    'This could cost up to $%s and your balance is $%s. Buy credits to continue.',
                    Money::toWire($e->required),
                    Money::toWire($e->available),
                ),
            ]);
        }

        $this->toast('Queued. Your image will appear below as soon as it\'s ready.');

        return to_route('generations.index');
    }

    /** The server is the only source of prices: the browser just displays this. */
    public function estimate(EstimateGenerationRequest $request): JsonResponse
    {
        try {
            $estimate = EstimateGenerationCostAction::run($request->toDto());
        } catch (PricingUnavailable) {
            return response()->json(['message' => 'Pricing is temporarily unavailable.'], 503);
        }

        return response()->json(['estimate' => $estimate->toArray()]);
    }
}
