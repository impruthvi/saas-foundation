<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe stops redelivering once answered, so the whole payload is kept for replay. No
 * organization column: not belonging to one is why a row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id')->nullable()->index();
            $table->string('type')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->json('payload');
            $table->string('reason');
            $table->text('message');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_webhook_events');
    }
};
