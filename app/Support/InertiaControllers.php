<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Base class for a domain's page controllers (the Inertia counterpart of an
 * API controller). Controllers stay thin: validate with a FormRequest, call an
 * action, then render a page or redirect.
 */
abstract class InertiaControllers extends Controller
{
    use AuthorizesRequests;

    /**
     * @param  array<string, mixed>  $props
     */
    protected function render(string $component, array $props = []): Response
    {
        return Inertia::render($component, $props);
    }

    /**
     * A toast shown after the next page visit.
     *
     * @param  'success'|'info'|'warning'|'error'  $type
     */
    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
