<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\ConsumePendingInvitation;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\Invitation;
use App\Models\Organization;
use App\Tenancy\InvitationRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        // Someone who arrived holding an invitation registers against the address
        // it names, and nothing else: the token is spent on the way through, and
        // a mismatch would drop it silently. So the address is supplied here and
        // the field is locked rather than merely pre-filled.
        Fortify::registerView(fn (Request $request) => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'invitation' => fn (): ?array => $this->pendingInvitation($request),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', fn (Request $request) => Limit::perMinute(10)->by(
            ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
        ));
    }

    /**
     * The invitation whose token this visitor is carrying, if any.
     *
     * The visitor is unauthenticated, so there is no tenant to scope the lookup
     * by. A token that no longer
     * resolves, or an invitation that has lapsed, simply produces nothing — the
     * register form then behaves normally rather than refusing to load.
     *
     * @return array{email: string, organization: string}|null
     */
    private function pendingInvitation(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $token = $request->session()->get(ConsumePendingInvitation::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $invitation = resolve(InvitationRepository::class)->findByToken($token);

        if (! $invitation instanceof Invitation || ! $invitation->isAcceptable()) {
            return null;
        }

        return [
            'email' => $invitation->email,
            'organization' => Organization::query()->findOrFail($invitation->organization_id)->name,
        ];
    }
}
