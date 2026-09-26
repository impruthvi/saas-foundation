<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the events that were kept rather than applied into `webhook_events`.
 *
 * The old table wrote one row per delivery behind a non-unique index, so a
 * redelivered event already has several rows. They collapse into one: the
 * delivery count is the number of rows, the received-at bounds are the oldest
 * and newest, and the newest row supplies the payload and reason. A row with no
 * event id cannot be matched with anything and is copied on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('failed_webhook_events')->oldest()->orderBy('id')->get();

        foreach ($rows->whereNull('stripe_event_id') as $row) {
            DB::table('webhook_events')->insert($this->fold(collect([$row])));
        }

        foreach ($rows->whereNotNull('stripe_event_id')->groupBy('stripe_event_id') as $deliveries) {
            DB::table('webhook_events')->insert($this->fold($deliveries));
        }

        Schema::drop('failed_webhook_events');
    }

    public function down(): void
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

        $kept = DB::table('webhook_events')
            ->whereIn('outcome', ['unplaceable', 'superseded'])
            ->orderBy('id')
            ->get();

        foreach ($kept as $event) {
            DB::table('failed_webhook_events')->insert([
                'stripe_event_id' => $event->stripe_event_id,
                'type' => $event->type,
                'stripe_customer_id' => $event->stripe_customer_id,
                'payload' => $event->payload,
                'reason' => $event->outcome_reason ?? 'Unknown',
                'message' => $event->outcome_message ?? '',
                'created_at' => $event->last_received_at,
            ]);
        }

        DB::table('webhook_events')->whereIn('id', $kept->pluck('id'))->delete();
    }

    /**
     * @param  Collection<int, stdClass>  $deliveries  oldest first
     * @return array<string, mixed>
     */
    private function fold(Collection $deliveries): array
    {
        $newest = $deliveries->last();
        $payload = json_decode((string) $newest->payload, true);

        return [
            'stripe_event_id' => $newest->stripe_event_id,
            'type' => $newest->type,
            'stripe_customer_id' => $newest->stripe_customer_id,
            'stripe_object_id' => is_array($payload) ? data_get($payload, 'data.object.id') : null,
            'stripe_created_at' => is_array($payload) && is_int($payload['created'] ?? null) ? $payload['created'] : null,
            'outcome' => $newest->reason === 'SupersededDelivery' ? 'superseded' : 'unplaceable',
            'outcome_reason' => $newest->reason,
            'outcome_message' => $newest->message,
            'applied_at' => null,
            'deliveries' => $deliveries->count(),
            'payload' => $newest->payload,
            'first_received_at' => $deliveries->first()->created_at,
            'last_received_at' => $newest->created_at,
        ];
    }
};
