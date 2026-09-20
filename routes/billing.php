<?php

declare(strict_types=1);

use App\Http\Controllers\Billing\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// This route stays outside the web group because Stripe sends neither a
// session nor a CSRF token. The controller resolves its tenant from the event.
Route::post(config('cashier.path', 'stripe').'/webhook', StripeWebhookController::class)
    ->name('cashier.webhook');
