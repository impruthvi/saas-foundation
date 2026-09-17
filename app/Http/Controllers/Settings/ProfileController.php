<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\DeleteUser;
use App\Exceptions\OwnershipTransferRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     *
     * Refused while the user solely owns an organization other people are in:
     * deleting them would orphan that organization and, from M4, its
     * subscription. The remedy is ownership transfer, so the refusal names it
     * rather than just failing (D25).
     */
    public function destroy(ProfileDeleteRequest $request, DeleteUser $deleteUser): RedirectResponse
    {
        $user = $request->user();

        try {
            $deleteUser->handle($user);
        } catch (OwnershipTransferRequired $ownershipTransferRequired) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $ownershipTransferRequired->getMessage()]);

            return to_route('profile.edit');
        }

        // Not Auth::logout(): it cycles the remember token, which saves the user
        // model. The row has just been deleted, so that save is an insert and the
        // account comes back. logoutCurrentDevice() clears the session without
        // touching the model.
        Auth::guard('web')->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
