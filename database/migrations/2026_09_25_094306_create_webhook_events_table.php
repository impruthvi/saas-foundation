<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Stripe delivery this application received, one row per event.
 *
 * Cashier overwrites subscription rows in place and keeps no event history, so
 * without this table nothing can say which event set an organization's plan.
 *
 * There is no organization column. An event arrives before any organization is
 * known and some never find one, so rows are keyed by the Stripe customer and
 * read per organization through `organizations.stripe_id`.
 *
 * `outcome` describes the latest delivery. `applied_at` is written the first time
 * the event changes state and never cleared, because a redelivery that arrives
 * after a newer event is superseded without undoing what the first one did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id')->nullable()->unique();
            $table->string('type')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_object_id')->nullable();
            $table->unsignedBigInteger('stripe_created_at')->nullable();
            $table->string('outcome');
            $table->string('outcome_reason')->nullable();
            $table->text('outcome_message')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('deliveries')->default(1);
            $table->json('payload');
            $table->timestamp('first_received_at');
            $table->timestamp('last_received_at');

            $table->index(['stripe_customer_id', 'stripe_created_at']);
            $table->index(['outcome', 'last_received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
