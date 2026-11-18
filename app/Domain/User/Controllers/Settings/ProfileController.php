<?php

declare(strict_types=1);

namespace App\Domain\User\Controllers\Settings;

use App\Domain\User\Requests\Settings\ProfileDeleteRequest;
use App\Domain\User\Requests\Settings\ProfileUpdateRequest;
use App\Support\InertiaControllers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Response;

class ProfileController extends InertiaControllers
{
    public function edit(Request $request): Response
    {
        return $this->render('settings/Profile', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        $this->toast('Profile updated.');

        return to_route('profile.edit');
    }

    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
