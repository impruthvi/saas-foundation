<?php

declare(strict_types=1);

use App\Http\Controllers\Invitations\AcceptInvitationController;
use App\Http\Controllers\Organizations\InvitationController;
use App\Http\Controllers\Organizations\InvitationDeliveryController;
use App\Http\Controllers\Organizations\MemberController;
use App\Http\Controllers\Organizations\SwitchOrganizationController;
use App\Http\Middleware\ProtectInvitationToken;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::post('organizations/{organization}/switch', SwitchOrganizationController::class)
        ->name('organizations.switch');

    Route::get('organizations/members', [MemberController::class, 'index'])
        ->name('organizations.members.index');

    Route::patch('organizations/members/{membership}', [MemberController::class, 'update'])
        ->name('organizations.members.update');

    Route::delete('organizations/members/{membership}', [MemberController::class, 'destroy'])
        ->name('organizations.members.destroy');

    // Throttled because an unthrottled invite endpoint is an email cannon
    // pointed at arbitrary addresses, and the sender is us.
    Route::post('organizations/invitations', [InvitationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('organizations.invitations.store');

    // Sending again creates a delivery: its own resource, standard verb.
    Route::post('organizations/invitations/{invitation}/deliveries', [InvitationDeliveryController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('organizations.invitations.deliveries.store');

    Route::delete('organizations/invitations/{invitation}', [InvitationController::class, 'destroy'])
        ->name('organizations.invitations.destroy');
});

/*
 * Reachable by a stranger, and keyed by token rather than by key.
 *
 * No `auth` middleware: the recipient usually has no account yet, and the point
 * of the landing page is to tell them what they have been offered before asking
 * them to make one. No route model binding either — binding would query
 * `invitations` outside the audited repository, with no tenant to scope it by.
 */
Route::middleware(ProtectInvitationToken::class)->group(function (): void {
    Route::get('invitations/{token}', [AcceptInvitationController::class, 'show'])
        ->name('invitations.show');

    Route::post('invitations/{token}', [AcceptInvitationController::class, 'store'])
        ->middleware('auth')
        ->name('invitations.accept');

    Route::delete('invitations/{token}', [AcceptInvitationController::class, 'destroy'])
        ->middleware('auth')
        ->name('invitations.decline');
});

require __DIR__.'/settings.php';
